<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mallto\Tool\Domain\AppStats\Contracts\AppStatsSectionProvider;
use Psr\Log\LoggerInterface;
use Throwable;
use Traversable;
use UnexpectedValueException;

/**
 * 将 Pod 只读视图与业务包提供的全局分区聚合为稳定的 AppStats API 快照。
 *
 * tagged iterable 在构造时只物化一次；每次 snapshot() 对每个 provider 只调用一次 key()
 * 和一次 read()。任何 provider 失败都只降级自己的分区，不影响 Pod、汇总或其他分区。
 */
class AppStatsSnapshotService
{
    /** @var array<int, AppStatsSectionProvider> */
    private readonly array $sectionProviders;

    private readonly Closure $clock;

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private readonly AppStatsPodRepository $pods,
        iterable $sectionProviders,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null
    ) {
        $providers = $sectionProviders instanceof Traversable
            ? iterator_to_array($sectionProviders)
            : (array) $sectionProviders;
        $this->sectionProviders = array_values($providers);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $podState = $this->pods->read();
        $sections = [];

        foreach ($this->sectionProviders as $provider) {
            // key() 自身失败或返回非法值时仍需要一个确定且不冲突于当前结果的降级字段名。
            $key = 'section_'.count($sections);

            try {
                $candidate = $provider->key();
                if (preg_match('/^[a-z][a-z0-9_]*$/', $candidate) !== 1) {
                    throw new UnexpectedValueException('Invalid AppStats section key');
                }

                // section key 是稳定 API 字段；重复 key 若继续写入，会让后注册的 provider
                // 静默覆盖先注册结果，管理页便无法知道有一项配置错误。这里保留首个结果，
                // 并将重复项按独立失败 section 暴露，既不丢失已采集数据，也便于发现配置问题。
                if (array_key_exists($candidate, $sections)) {
                    throw new UnexpectedValueException('Duplicate AppStats section key');
                }

                $key = $candidate;
                $sections[$key] = $provider->read();
            } catch (Throwable $exception) {
                // 仅记录 provider 类型和稳定 key，禁止记录可能含凭据的异常文本或 payload。
                $this->logger->warning('AppStats section read failed', [
                    'section' => $key,
                    'error_type' => $exception::class,
                ]);
                $sections[$key] = [
                    'title' => $key,
                    'status' => 'error',
                    'sampled_at' => null,
                    'metrics' => [],
                    'message' => 'section_read_failed',
                ];
            }
        }

        $podMetrics = array_column($podState['pods'], 'metrics');
        $summary = [
            'worker_used' => array_sum(array_column($podMetrics, 'worker_used')),
            'worker_total' => array_sum(array_column($podMetrics, 'worker_total')),
            'task_worker_used' => array_sum(array_column($podMetrics, 'task_worker_used')),
            'task_worker_total' => array_sum(array_column($podMetrics, 'task_worker_total')),
            'tasking_num' => array_sum(array_column($podMetrics, 'tasking_num')),
        ];

        return [
            'generated_at' => date(DATE_ATOM, ($this->clock)()),
            'refresh_seconds' => max(
                1,
                (int) $this->config->get('app_stats.page_refresh_seconds', 5)
            ),
            'cluster' => [
                'status' => ! $podState['ok']
                    ? 'error'
                    : ($podState['delayed_pods'] > 0
                        ? 'warning'
                        : ($podState['online_pods'] > 0 ? 'normal' : 'unavailable')),
                'online_pods' => $podState['online_pods'],
                'delayed_pods' => $podState['delayed_pods'],
                'expired_pods' => $podState['expired_pods'],
                'invalid_pod_snapshots' => $podState['invalid_pod_snapshots'],
                'message' => $podState['message'],
            ],
            'summary' => $summary,
            'pods' => $podState['pods'],
            'sections' => $sections,
        ];
    }
}
