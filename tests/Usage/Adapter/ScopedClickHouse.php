<?php

namespace Utopia\Tests\Usage\Adapter;

use Utopia\Usage\Adapter\SharedTables;

/**
 * Test-only multi-tenant adapter pinned to a single tenant.
 *
 * Production code scopes reads via SharedTables::withTenant() and stamps the
 * tenant per-row on writes. Threading that through every existing test call
 * would be noise, so this helper pins one tenant: reads are auto-scoped (it
 * sets the read scope permanently) and writes get the tenant stamped on any
 * row that doesn't already carry one. It still exercises the real tenant SQL.
 */
class ScopedClickHouse extends SharedTables
{
    private ?string $defaultTenant = null;

    public function __construct(
        string $host,
        string $username = 'default',
        string $password = '',
        int $port = 8123,
        bool $secure = false,
        string $namespace = '',
        ?string $tenant = null
    ) {
        parent::__construct($host, $username, $password, $port, $secure, $namespace);
        $this->defaultTenant = $tenant;
        $this->scopeTenant = $tenant;
    }

    /**
     * Build a pinned adapter from the standard CLICKHOUSE_* test env vars.
     */
    public static function fromEnv(string $namespace, ?string $tenant = '1'): self
    {
        $adapter = new self(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            $namespace,
            $tenant,
        );

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        return $adapter;
    }

    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        foreach ($metrics as $i => $metric) {
            if (! array_key_exists('$tenant', $metric)) {
                $metrics[$i]['$tenant'] = $this->defaultTenant;
            }
        }

        return parent::addBatch($metrics, $type, $batchSize);
    }
}
