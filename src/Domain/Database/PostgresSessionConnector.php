<?php

namespace Mallto\Tool\Domain\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Preserve Laravel's lazy PDO resolver and initialize each acquired PG session.
 * Metadata-only Connection access must never execute session SQL.
 */
class PostgresSessionConnector extends PostgresConnector
{
    public function __construct(
        private readonly array $defaults,
        private readonly LoggerInterface $logger
    ) {
    }

    public function connect(array $config)
    {
        // Keep Laravel's timezone, search_path, isolation and synchronous_commit.
        // Failures opening the connection retain the framework's normal behavior.
        $pdo = parent::connect($config);

        try {
            foreach (['lock_timeout' => '8s', 'statement_timeout' => '30s'] as $name => $fallback) {
                $value = array_key_exists($name, $config)
                    ? $config[$name] : ($this->defaults[$name] ?? $fallback);
                // Session scope matches the former SET listener. Do not run this
                // per query: transaction-local overrides must remain effective.
                $statement = $pdo->prepare('select set_config(?, ?, false)');
                $statement->execute([$name, (string) $value]);
            }
        } catch (Throwable $error) {
            // Preserve the existing best-effort policy without logging DSNs,
            // configuration values or driver exception text.
            $this->logger->error('[PostgresSessionConnector] PostgreSQL timeout config failed', [
                'exception_class' => get_class($error),
            ]);
        }

        return $pdo;
    }
}
