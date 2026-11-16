<?php

namespace Mallto\Tool\Domain\Log;

use Illuminate\Support\Str;

/** 采集前脱敏，避免凭据进入 Redis 队列或数据库；限制大响应占用。 */
class OwnerApiLogPayload
{
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (in_array($normalized, ['authorization', 'proxy_authorization', 'cookie', 'set_cookie',
                'password', 'password_confirmation', 'secret', 'app_secret', 'signature', 'signature_nonce',
                'token', 'access_token', 'refresh_token', 'api_token', 'api_key', 'x_api_key'], true)
                || Str::endsWith($normalized, ['_password', '_secret', '_token'])) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    public function text(string $value): string
    {
        $limit = max(256, (int) config('owner_api_log.max_text_bytes', 65536));
        $marker = '...[truncated]';

        return strlen($value) > $limit
            ? mb_strcut($value, 0, $limit - strlen($marker), 'UTF-8') . $marker
            : $value;
    }

    public function json(array $value): string
    {
        return $this->text(json_encode($this->redact($value),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function url(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
            $url = $parts[0] . '?' . http_build_query($this->redact($query));
        }

        return $this->text($url);
    }
}
