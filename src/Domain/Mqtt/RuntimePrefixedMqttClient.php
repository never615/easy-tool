<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Mqtt;

use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\Exceptions\ConfigurationInvalidException;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Message;
use PhpMqtt\Client\MessageType;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Psr\Log\LoggerInterface;

/**
 * RuntimePrefixedMqttClient：easy-tool 的 runtime prefixed mqtt client 组件。
 * 它通过明确的职责边界供包内其他模块复用。
 */
class RuntimePrefixedMqttClient extends MqttClient
{
    protected ?string $runtimeClientIdPrefix = null;

    protected int $runtimeClientIdMaxLength = 23;

    private int $idleSleepMicroseconds;

    private Repository $publishRepository;
    private LoggerInterface $publishLogger;
    private \SplObjectStorage $confirmedReceivedHooks;
    private ?\Closure $confirmedPublishBatchHandler = null;
    private array $confirmedPublishBatch = [];
    private int $confirmedPublishBatchBytes = 0;
    private int $confirmedPublishBatchLimit = 8;
    private bool $flushingConfirmedPublishBatch = false;
    private bool $confirmedTimingEnabled = false;
    private int $confirmedTimingMessages = 0;
    private int $confirmedTimingSamples = 0;
    private int $confirmedTimingLoops = 0;
    private array $confirmedLoopTiming = [];
    private array $confirmedQueuedTiming = [];
    private ?array $confirmedCallbackTiming = null;
    private int $confirmedTimingBatches = 0;
    private ?array $confirmedPreviousBatch = null;

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        string $host,
        int $port = 1883,
        ?string $clientId = null,
        string $protocol = self::MQTT_3_1,
        ?Repository $repository = null,
        ?LoggerInterface $logger = null,
        ?string $runtimeClientIdPrefix = null,
        int $runtimeClientIdMaxLength = 23,
        int $idleSleepMicroseconds = 100000
    )
    {
        $this->runtimeClientIdPrefix = $runtimeClientIdPrefix;
        $this->runtimeClientIdMaxLength = max(1, $runtimeClientIdMaxLength);
        if ($idleSleepMicroseconds < 5000 || $idleSleepMicroseconds > 100000) {
            throw new ConfigurationInvalidException('MQTT idle sleep must be between 5000 and 100000 microseconds.');
        }
        $this->idleSleepMicroseconds = $idleSleepMicroseconds;

        $this->publishRepository = $repository ?? new MemoryRepository();
        $this->publishLogger = $logger ?? new \Psr\Log\NullLogger();
        $this->confirmedReceivedHooks = new \SplObjectStorage();
        parent::__construct($host, $port, $clientId, $protocol, $this->publishRepository, $this->publishLogger);
    }

    public function loopOnce(float $loopStartedAt, bool $allowSleep = false, int $sleepMicroseconds = 100000): void
    {
        if ($this->confirmedTimingEnabled) {
            $this->confirmedLoopTiming = ['loop_started_ns' => hrtime(true),
                'loop_sequence' => ++$this->confirmedTimingLoops];
        }
        // 父类 loop() 省略第三个参数。保留调用方的显式覆盖，
        // 仅在套接字无可读数据时，才允许父类休眠。
        if ($allowSleep && func_num_args() < 3) {
            $sleepMicroseconds = $this->idleSleepMicroseconds;
        }

        try {
            parent::loopOnce($loopStartedAt, $allowSleep, $sleepMicroseconds);
            // 本轮立即刷新尚未满的批次，无需等待
            // 其他输入、定时器或完整批次来释放尾部消息。
            $this->flushConfirmedPublishBatch();
        } catch (\Throwable $error) {
            if ($this->confirmedPublishBatchHandler !== null) $this->abortConfirmedPublishBatch();
            throw $error;
        } finally {
            $this->confirmedLoopTiming = [];
        }
    }

    /** 按需启用接收时钟：前 32 条消息，此后每 32 条采样一次，每客户端最多 256 条。 */
    public function enableConfirmedPublishTiming(): void { $this->confirmedTimingEnabled = true; }

    /** 仅在原始订阅回调内可用，不包含数据源身份。 */
    public function confirmedPublishTiming(): ?array { return $this->confirmedCallbackTiming; }

    /** 当前循环及最近完整写入的 ACK 批次，不代表代理端交付。 */
    public function confirmedLoopTiming(): array
    {
        return $this->confirmedTimingEnabled
            ? ['loop' => $this->confirmedLoopTiming, 'previous_batch' => $this->confirmedPreviousBatch] : [];
    }

    protected function readAllAvailableDataFromSocket(bool $withAutoReconnectIfConfigured = false): string
    {
        if (!$this->confirmedTimingEnabled) return parent::readAllAvailableDataFromSocket($withAutoReconnectIfConfigured);
        $started = hrtime(true);
        $data = parent::readAllAvailableDataFromSocket($withAutoReconnectIfConfigured);
        $this->confirmedLoopTiming += ['read_started_ns' => $started,
            'read_returned_ns' => hrtime(true), 'read_bytes' => strlen($data)];
        // 此处记录本次非阻塞读取，不是每个缓冲
        // 字节的到达时间。部分报文可能包含上一次循环保留的字节。
        return $data;
    }

    /**
     * 按需启用的 QoS1 入口钩子。处理器恰好调用一次 $deliver，然后
     * 返回前确认已持久准入。返回前不发送 PUBACK。
     * 这是至少一次投递，不保证副作用恰好执行一次。
     * 可选的第二个参数包含本次有界批次的主题和负载值，
     * 用于共享查询；投递仍使用原始消息。
     */
    public function registerConfirmedPublishBatchHandler(\Closure $handler, int $maxMessages = 8): void
    {
        if ($maxMessages < 1 || $maxMessages > 16 || $this->confirmedPublishBatchHandler !== null) {
            throw new \InvalidArgumentException('One bounded confirmed publish handler is required');
        }
        $this->confirmedPublishBatchHandler = $handler;
        $this->confirmedPublishBatchLimit = $maxMessages;
    }

    protected function handleMessage(Message $message): void
    {
        if ($this->confirmedPublishBatchHandler === null) {
            parent::handleMessage($message);
            return;
        }
        try {
            if ($message->getType()->equals(MessageType::PUBLISH())
                && $message->getQualityOfService() === self::QOS_AT_LEAST_ONCE) {
                $bytes = strlen($message->getTopic()) + strlen($message->getContent());
                if ($bytes > 262144) {
                    throw new \LengthException('Confirmed MQTT publish exceeds the 256 KiB admission budget');
                }
                if ($this->confirmedPublishBatchBytes + $bytes > 262144) $this->flushConfirmedPublishBatch();
                if ($this->confirmedTimingEnabled) {
                    $ordinal = ++$this->confirmedTimingMessages;
                    if ($this->confirmedTimingSamples < 256 && ($ordinal <= 32 || $ordinal % 32 === 0)) {
                        ++$this->confirmedTimingSamples;
                        $this->confirmedQueuedTiming[spl_object_id($message)] = $this->confirmedLoopTiming
                            + ['decoded_ns' => hrtime(true), 'message_ordinal' => $ordinal];
                    }
                }
                $this->confirmedPublishBatch[] = $message;
                $this->confirmedPublishBatchBytes += $bytes;
                if (count($this->confirmedPublishBatch) >= $this->confirmedPublishBatchLimit
                    || $this->confirmedPublishBatchBytes >= 262144) $this->flushConfirmedPublishBatch();
                return;
            }
            // 保留控制报文及非 QoS1 消息的顺序。
            $this->flushConfirmedPublishBatch();
            parent::handleMessage($message);
        } catch (\Throwable $error) {
            $this->abortConfirmedPublishBatch();
            throw $error;
        }
    }

    protected function flushConfirmedPublishBatch(): void
    {
        if ($this->confirmedPublishBatch === []) return;
        if ($this->flushingConfirmedPublishBatch) throw new \LogicException('Reentrant confirmed publish batch');
        $messages = $this->confirmedPublishBatch;
        $timings = $this->confirmedQueuedTiming;
        $this->confirmedQueuedTiming = [];
        $batch = $this->confirmedTimingEnabled ? ['batch_sequence' => ++$this->confirmedTimingBatches,
            'loop_sequence' => $this->confirmedLoopTiming['loop_sequence'] ?? null,
            'started_ns' => hrtime(true), 'messages' => count($messages)] : null;
        $this->confirmedPublishBatch = [];
        $this->confirmedPublishBatchBytes = 0;
        $this->flushingConfirmedPublishBatch = true;
        $delivered = false;
        try {
            ($this->confirmedPublishBatchHandler)(function () use ($messages, $timings, $batch, &$delivered): void {
                if ($delivered) throw new \LogicException('Confirmed publish callbacks already delivered');
                $delivered = true;
                foreach ($messages as $message) {
                    $timing = $timings[spl_object_id($message)] ?? null;
                    $this->confirmedCallbackTiming = $timing === null ? null : $timing
                        + ['batch_started_ns' => $batch['started_ns'], 'batch_sequence' => $batch['batch_sequence'],
                            'callback_started_ns' => hrtime(true), 'previous_batch' => $this->confirmedPreviousBatch];
                    try {
                        $this->deliverPublishedMessage($message->getTopic(), $message->getContent(),
                            $message->getQualityOfService(), $message->getRetained());
                    } finally {
                        $this->confirmedCallbackTiming = null;
                    }
                }
            }, array_map(static fn (Message $message): array => [
                'topic' => $message->getTopic(), 'payload' => $message->getContent(),
            ], $messages));
            if (!$delivered) throw new \LogicException('Confirmed publish handler skipped delivery');
            if ($batch !== null) $batch['handler_returned_ns'] = $batch['ack_started_ns'] = hrtime(true);
            foreach ($messages as $message) parent::sendPublishAcknowledgement($message->getMessageId());
            if ($batch !== null) {
                $batch['ack_returned_ns'] = hrtime(true);
                $this->confirmedPreviousBatch = $batch;
            }
        } catch (\Throwable $error) {
            // 部分 Redis 命令或 ACK 写入可能已经成功。绝不在本地重试
            // 此批次，也不能在新的 MQTT 会话发送旧报文 ID。
            $this->abortConfirmedPublishBatch();
            throw $error;
        } finally {
            $this->flushingConfirmedPublishBatch = false;
        }
    }

    protected function deliverPublishedMessage(string $topic, string $message, int $qualityOfServiceLevel,
        bool $retained = false): void
    {
        if (!$this->flushingConfirmedPublishBatch) {
            parent::deliverPublishedMessage($topic, $message, $qualityOfServiceLevel, $retained);
            return;
        }
        // 上游投递方法会记录并吞掉回调异常。
        // 需要确认准入时，必须向上传播异常并暂缓本批次的 ACK。
        foreach ($this->publishRepository->getSubscriptionsMatchingTopic($topic) as $subscriber) {
            if ($subscriber->getCallback() !== null) {
                ($subscriber->getCallback())($topic, $message, $retained, $subscriber->getMatchedWildcards($topic));
            }
        }
        // 上游钩子执行器是私有的。严格投递绕过它时，保留公开注册方式及
        // 异常仅观测的行为。
        foreach ($this->confirmedReceivedHooks as $hook) {
            try { $hook($this, $topic, $message, $qualityOfServiceLevel, $retained); }
            catch (\Throwable $error) {
                $this->publishLogger->error('Received message hook callback threw exception.', ['exception' => $error]);
            }
        }
    }

    public function registerMessageReceivedEventHandler(\Closure $callback): \PhpMqtt\Client\Contracts\MqttClient
    {
        $this->confirmedReceivedHooks->attach($callback);
        return parent::registerMessageReceivedEventHandler($callback);
    }

    public function unregisterMessageReceivedEventHandler(?\Closure $callback = null): \PhpMqtt\Client\Contracts\MqttClient
    {
        if ($callback === null) $this->confirmedReceivedHooks->removeAll($this->confirmedReceivedHooks);
        else $this->confirmedReceivedHooks->detach($callback);
        return parent::unregisterMessageReceivedEventHandler($callback);
    }

    protected function reconnect(): void
    {
        if ($this->confirmedPublishBatchHandler !== null) {
            throw new \RuntimeException('Confirmed MQTT admission requires process restart after disconnect');
        }
        parent::reconnect();
    }

    private function abortConfirmedPublishBatch(): void
    {
        $this->confirmedQueuedTiming = [];
        $this->confirmedCallbackTiming = null;
        $this->confirmedPublishBatch = [];
        $this->confirmedPublishBatchBytes = 0;
        $this->closeSocket();
    }

    /**
     * 处理「generateRandomClientId」相关的内部协作步骤。
     */
    protected function generateRandomClientId(): string
    {
        $randomClientId = parent::generateRandomClientId();
        $prefix = $this->normalizedClientIdPrefix();

        if ($prefix === '') {
            return substr($randomClientId, 0, $this->runtimeClientIdMaxLength);
        }

        $suffixLength = max(1, $this->runtimeClientIdMaxLength - strlen($prefix) - 1);

        return $prefix . '-' . substr($randomClientId, 0, $suffixLength);
    }

    /**
     * 处理「normalizedClientIdPrefix」相关的内部协作步骤。
     */
    protected function normalizedClientIdPrefix(): string
    {
        $prefix = trim((string)$this->runtimeClientIdPrefix);
        if ($prefix === '') {
            return '';
        }

        $prefix = strtolower($prefix);
        $prefix = preg_replace('/[^a-z0-9]+/', '-', $prefix);
        $prefix = trim((string)$prefix, '-');

        if ($prefix === '') {
            return '';
        }

        // 环境名和连接名均存在时，为随机后缀预留四个字符；
        // 仅含环境名的前缀
        // 通过下方计算保留较长后缀。
        $maxPrefixLength = max(0, $this->runtimeClientIdMaxLength - 4 - 1);
        if ($maxPrefixLength === 0) {
            return '';
        }

        if (strlen($prefix) > $maxPrefixLength) {
            $prefix = rtrim(substr($prefix, 0, $maxPrefixLength), '-');
        }

        return $prefix;
    }
}
