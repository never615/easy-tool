<?php

namespace Mallto\Tool\Domain\ServerCommands;

use InvalidArgumentException;

/** A single argv invocation, deliberately not a shell language. */
class CommandTextParser
{
    public function parse(string $text): array
    {
        if (strlen($text) > (int)config('server_commands.max_input_bytes', 16384) || str_contains($text, "\0")) {
            throw new InvalidArgumentException('命令过长或包含无效字符。');
        }
        $tokens = []; $token = ''; $quote = null; $active = false;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === $quote) { $quote = null; continue; }
                // Single quotes preserve JSON/PHP literally. Double quotes allow escaped quote/backslash.
                if ($quote === '"' && $char === '\\' && $i + 1 < $length && in_array($text[$i + 1], [$quote, '\\'], true)) {
                    $token .= $text[++$i];
                } else { $token .= $char; }
                continue;
            }
            if ($char === "'" || $char === '"') { $quote = $char; $active = true; continue; }
            if (str_contains(";|&<>`$\r\n", $char)) {
                throw new InvalidArgumentException('只支持一条 Artisan 命令；外层不支持 Shell 运算、换行或变量替换。');
            }
            if ($char === ' ' || $char === "\t") {
                if ($active) { $tokens[] = $token; $token = ''; $active = false; }
                continue;
            }
            if ($char === '\\' && $i + 1 < $length && in_array($text[$i + 1], [' ', "\t", "'", '"', '\\'], true)) {
                $token .= $text[++$i];
            } else { $token .= $char; }
            $active = true;
        }
        if ($quote !== null) { throw new InvalidArgumentException('命令引号未闭合。'); }
        if ($active) { $tokens[] = $token; }
        if (array_slice($tokens, 0, 2) === ['php', 'artisan']) { $tokens = array_slice($tokens, 2); }
        elseif (($tokens[0] ?? null) === 'artisan') { array_shift($tokens); }
        elseif (($tokens[0] ?? null) === 'php') { throw new InvalidArgumentException('只支持固定 php artisan 前缀。'); }
        if (!$tokens || !preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/D', $tokens[0])) {
            throw new InvalidArgumentException('请输入 Artisan 命令名，可省略 php artisan 前缀。');
        }
        return $tokens;
    }

    public function format(array $argv): string
    {
        return implode(' ', array_map(static function (string $value): string {
            if ($value !== '' && preg_match('/^[a-zA-Z0-9_:,.\/=+@-]+$/D', $value)) { return $value; }
            return "'" . str_replace("'", "'\\''", $value) . "'";
        }, $argv));
    }
}
