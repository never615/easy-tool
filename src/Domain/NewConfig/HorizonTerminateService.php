<?php

namespace Mallto\Tool\Domain\NewConfig;

use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\Terminate;
use Throwable;

class HorizonTerminateService
{
    public function __construct(
        private ?MasterSupervisorRepository $masters = null,
        private ?HorizonCommandQueue $commands = null
    ) {
    }

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

    private function masterSupervisors(): array
    {
        return $this->masters
            ? $this->masters->all()
            : app(MasterSupervisorRepository::class)->all();
    }

    private function commandQueue(): HorizonCommandQueue
    {
        return $this->commands ?: app(HorizonCommandQueue::class);
    }
}
