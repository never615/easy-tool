<?php
/**
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Webview;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mallto\Tool\Exception\InternalHttpException;
use Mallto\Tool\Exception\InvalidParamException;

class WebviewParamStore
{
    private const KEY_PREFIX = 'webview_params';

    public function store(string $uuid, array $params): array
    {
        $this->assertPayloadSize($params);

        $ttlSeconds = $this->ttlSeconds();
        $cache = $this->cache();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = Str::random(40);
            if ($cache->add($this->key($uuid, $id), $params, $ttlSeconds)) {
                return [
                    'id' => $id,
                    'expires_in' => $ttlSeconds,
                ];
            }
        }

        throw new InternalHttpException('WebView 参数标识生成失败，请重试');
    }

    public function resolve(string $uuid, string $id): ?array
    {
        $params = $this->cache()->get($this->key($uuid, $id));

        return is_array($params) ? $params : null;
    }

    private function assertPayloadSize(array $params): void
    {
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new InvalidParamException('WebView 参数必须可以 JSON 编码');
        }

        if (strlen($payload) > $this->maxPayloadBytes()) {
            throw new InvalidParamException('WebView 参数过大');
        }
    }

    private function cache(): Repository
    {
        return Cache::store($this->cacheStore());
    }

    private function key(string $uuid, string $id): string
    {
        return self::KEY_PREFIX . ':' . $this->sanitizeKeyPart($uuid) . ':' . $id;
    }

    private function sanitizeKeyPart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?: 'unknown';
    }

    private function cacheStore(): string
    {
        return (string)config('webview.params.cache_store', 'redis');
    }

    private function ttlSeconds(): int
    {
        return max(60, (int)config('webview.params.ttl_seconds', 86400));
    }

    private function maxPayloadBytes(): int
    {
        return max(1024, (int)config('webview.params.max_payload_bytes', 262144));
    }
}
