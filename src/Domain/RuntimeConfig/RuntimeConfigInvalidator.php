<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final class RuntimeConfigInvalidator
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuntimeConfigSharedStore $sharedStore,
        private readonly RuntimeConfigSwooleStore $swooleStore,
        private readonly RuntimeConfigRevisionStore $revisionStore,
        private readonly RuntimeConfigBroadcaster $broadcaster
    ) {
    }

    /** @param array{found: bool, value: mixed} $payload */
    public function replaceAfterCommit(RuntimeConfigIdentity $identity, string $mode, array $payload): void
    {
        $normalizedMode = RuntimeConfigCacheMode::normalize($mode);
        if (!RuntimeConfigCacheMode::isRuntime($normalizedMode)) {
            throw new InvalidArgumentException('Bootstrap reload config cannot use runtime cache invalidation.');
        }

        $normalizedPayload = [
            'found' => (bool) ($payload['found'] ?? false),
            'value' => $payload['value'] ?? null,
        ];

        $this->database->connection()->afterCommit(function () use ($identity, $normalizedMode, $normalizedPayload): void {
            // RuntimeConfigSharedStore::fresh uses the same identity lock as cache-miss fills.
            $this->sharedStore->fresh($identity, static fn (): array => $normalizedPayload);

            if (RuntimeConfigCacheMode::usesSwoole($normalizedMode)) {
                $this->swooleStore->delete($identity);
            }

            $revision = $this->revisionStore->bump();
            $this->broadcaster->broadcast([$identity], $revision);
        });
    }
}
