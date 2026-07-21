<?php

namespace Mallto\Tool\Domain\NewConfig;

/**
 * NewConfigVersionWatcher：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigVersionWatcher
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(private NewConfigGenerationStore $generationStore)
    {
    }

    /**
     * 处理「currentGeneration」相关的内部协作步骤。
     */
    public function currentGeneration(): int
    {
        return $this->generationStore->current();
    }

    /**
     * 处理「changedSince」相关的内部协作步骤。
     */
    public function changedSince(int $lastSeenGeneration): array
    {
        $currentGeneration = $this->currentGeneration();

        return [
            'changed' => $currentGeneration > $lastSeenGeneration,
            'current_generation' => $currentGeneration,
            'last_seen_generation' => $lastSeenGeneration,
        ];
    }
}
