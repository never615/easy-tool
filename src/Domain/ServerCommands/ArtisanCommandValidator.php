<?php

namespace Mallto\Tool\Domain\ServerCommands;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;

class ArtisanCommandValidator
{
    public function validate(array $argv): ?string
    {
        $commands = app(Kernel::class)->all();
        if (!isset($commands[$argv[0]])) { throw new \InvalidArgumentException('当前实例未安装此 Artisan 命令。'); }
        // Bind the declared definition only; never call initialize/interact/execute in HTTP.
        $command = clone $commands[$argv[0]];
        $command->mergeApplicationDefinition();
        try {
            $input = new ArgvInput(['artisan', '--no-interaction', '--no-ansi', ...$argv]);
            $input->bind($command->getDefinition());
            // Symfony handles help/version before checking required business arguments.
            // Binding still rejects unknown or malformed options without executing command code.
            $metadataOnly = ($input->hasOption('help') && $input->getOption('help'))
                || ($input->hasOption('version') && $input->getOption('version'));
            if (!$metadataOnly) { $input->validate(); }
            $environment = $input->hasOption('env') ? $input->getOption('env') : null;
            return $environment === null ? null : (string)$environment;
        } catch (\Symfony\Component\Console\Exception\ExceptionInterface) {
            // Parser errors can contain the supplied secret value; do not echo them.
            throw new \InvalidArgumentException('命令参数不符合当前版本定义，请检查必填参数、选项和值。');
        }
    }
}
