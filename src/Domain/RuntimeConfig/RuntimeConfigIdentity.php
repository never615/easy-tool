<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

final class RuntimeConfigIdentity
{
    private function __construct(private readonly string $identity)
    {
    }

    public static function global(string $key): self
    {
        return new self('global:' . $key);
    }

    public static function subjectPrivate(int $subjectId, string $key): self
    {
        return new self('subject-private:' . $subjectId . ':' . $key);
    }

    public static function subjectPublic(int $subjectId, string $key): self
    {
        return new self('subject-public:' . $subjectId . ':' . $key);
    }

    public function fingerprint(): string
    {
        return sha1($this->identity);
    }

    public function redisKey(): string
    {
        return 'runtime_config:value:' . $this->fingerprint();
    }

    public function swooleKey(): string
    {
        return 'rc:' . $this->fingerprint();
    }

    /** @return array{swoole_key: string} */
    public function broadcastPayload(): array
    {
        return ['swoole_key' => $this->swooleKey()];
    }
}
