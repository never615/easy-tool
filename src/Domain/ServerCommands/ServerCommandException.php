<?php

namespace Mallto\Tool\Domain\ServerCommands;

/** Messages from this exception are safe to show; never include raw command output. */
class ServerCommandException extends \RuntimeException {}
