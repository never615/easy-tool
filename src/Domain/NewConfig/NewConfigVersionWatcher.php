<?php

namespace Mallto\Tool\Domain\NewConfig;

class NewConfigVersionWatcher
{
    public function __construct(private NewConfigGenerationStore $generationStore)
    {
    }

    public function currentGeneration(): int
    {
        return $this->generationStore->current();
    }

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
