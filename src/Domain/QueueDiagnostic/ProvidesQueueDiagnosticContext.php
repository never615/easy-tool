<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

/**
 * ProvidesQueueDiagnosticContext：队列诊断模块的 provides queue diagnostic context 组件。
 * 诊断记录必须保持为旁路，不能影响实际队列任务的执行。
 */
interface ProvidesQueueDiagnosticContext
{
    /**
     * 返回用于队列诊断的小型安全上下文负载。
     */
    public function queueDiagnosticContext(): array;
}
