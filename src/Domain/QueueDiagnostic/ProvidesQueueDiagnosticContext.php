<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

interface ProvidesQueueDiagnosticContext
{
    /**
     * Return a small, safe context payload for queue diagnostics.
     */
    public function queueDiagnosticContext(): array;
}
