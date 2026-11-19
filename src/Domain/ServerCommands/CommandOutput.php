<?php

namespace Mallto\Tool\Domain\ServerCommands;

class CommandOutput
{
    /** Best-effort presentation masking; encrypted records still require operations permission. */
    public static function sanitize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/\x1B(?:\][^\x07]*(?:\x07|\x1B\\\\)|\[[0-?]*[ -\/]*[@-~])/', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? '';
        $text = preg_replace('/(\b(?:password|passwd|secret|token|authorization|api[_-]?key|app_key)\b["\x27]?\s*[:=]\s*)("[^"]*"|\x27[^\x27]*\x27|[^\s,}]+)/i', '$1[已隐藏]', $text) ?? '';
        return preg_replace('~([a-z][a-z0-9+.-]*://[^\s/:]+:)[^\s@]+@~i', '$1[已隐藏]@', $text) ?? '';
    }

    public static function command(array $argv): string
    {
        $hideNext = false;
        foreach ($argv as &$arg) {
            if ($hideNext) { $arg = '[已隐藏]'; $hideNext = false; continue; }
            if (preg_match('/^--[^=]*(?:password|secret|token|key|execute)(?:=(.*))?$/i', $arg)) {
                if (str_contains($arg, '=')) { $arg = explode('=', $arg, 2)[0] . '=[已隐藏]'; }
                else { $hideNext = true; }
            }
        }
        unset($arg);
        return self::sanitize((new CommandTextParser())->format($argv));
    }
}
