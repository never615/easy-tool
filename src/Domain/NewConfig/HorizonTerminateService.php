<?php

namespace Mallto\Tool\Domain\NewConfig;

use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\Terminate;
use Throwable;

/**
 * HorizonTerminateService：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class HorizonTerminateService
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private ?MasterSupervisorRepository $masters = null,
        private ?HorizonCommandQueue $commands = null
    ) {
    }

    /**
     * 处理「requestTerminate」相关的内部协作步骤。
     */
    public function requestTerminate(): array
    {
        if (!class_exists(MasterSupervisor::class) || !class_exists(Terminate::class)) {
            return [
                'skipped' => true,
                'reason' => 'laravel horizon is not installed',
                'masters' => [],
            ];
        }

        try {
            $masters = $this->masterSupervisors();
            if ($masters === []) {
                return [
                    'skipped' => true,
                    'reason' => 'no active horizon master supervisors',
                    'masters' => [],
                ];
            }

            $masterNames = [];
            foreach ($masters as $master) {
                $name = $master->name ?? null;
                if (!$name) {
                    continue;
                }

                $this->commandQueue()->push(
                    MasterSupervisor::commandQueueFor($name),
                    Terminate::class,
                    ['status' => 0]
                );
                $masterNames[] = $name;
            }

            if ($masterNames === []) {
                return [
                    'skipped' => true,
                    'reason' => 'active horizon masters have no names',
                    'masters' => [],
                ];
            }

            return [
                'skipped' => false,
                'masters' => $masterNames,
            ];
        } catch (Throwable $exception) {
            return [
                'skipped' => true,
                'reason' => $exception->getMessage(),
                'masters' => [],
            ];
        }
    }

    /**
     * 处理「masterSupervisors」相关的内部协作步骤。
     */
    private function masterSupervisors(): array
    {
        return $this->masters
            ? $this->masters->all()
            : app(MasterSupervisorRepository::class)->all();
    }

    /**
     * 处理「commandQueue」相关的内部协作步骤。
     */
    private function commandQueue(): HorizonCommandQueue
    {
        return $this->commands ?: app(HorizonCommandQueue::class);
    }
}
