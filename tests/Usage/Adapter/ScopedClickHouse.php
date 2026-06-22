<?php

namespace Utopia\Tests\Usage\Adapter;

use Utopia\Usage\Adapter\ClickHouse;

/**
 * Test-only ClickHouse adapter that carries a fixed tenant and applies it to
 * writes (per-row) and reads (per-query) automatically.
 *
 * The real adapter has no tenant state — tenancy is per-row on writes and an
 * explicit argument on reads. Threading that through every existing test call
 * would be noise, so this helper supplies a default tenant while still
 * exercising the real per-row / per-query code paths in the parent adapter.
 */
class ScopedClickHouse extends ClickHouse
{
    private ?string $defaultTenant = null;

    public function __construct(
        string $host,
        string $username = 'default',
        string $password = '',
        int $port = 8123,
        bool $secure = false,
        string $namespace = '',
        bool $sharedTables = false,
        ?string $tenant = null
    ) {
        parent::__construct($host, $username, $password, $port, $secure, $namespace, $sharedTables);
        $this->defaultTenant = $tenant;
    }

    /**
     * Build a scoped adapter from the standard CLICKHOUSE_* test env vars.
     */
    public static function fromEnv(string $namespace, bool $sharedTables = true, ?string $tenant = '1'): self
    {
        $adapter = new self(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            $namespace,
            $sharedTables,
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

    public function find(array $queries = [], ?string $type = null, ?string $tenant = null): array
    {
        return parent::find($queries, $type, $tenant ?? $this->defaultTenant);
    }

    public function count(array $queries = [], ?string $type = null, ?int $max = null, ?string $tenant = null): int
    {
        return parent::count($queries, $type, $max, $tenant ?? $this->defaultTenant);
    }

    public function sum(array $queries = [], string $attribute = 'value', string $type = \Utopia\Usage\Usage::TYPE_EVENT, ?string $tenant = null): int
    {
        return parent::sum($queries, $attribute, $type, $tenant ?? $this->defaultTenant);
    }

    public function getTotal(string $metric, array $queries = [], ?string $type = null, ?string $tenant = null): int
    {
        return parent::getTotal($metric, $queries, $type, $tenant ?? $this->defaultTenant);
    }

    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null, ?string $tenant = null): array
    {
        return parent::getTotalBatch($metrics, $queries, $type, $tenant ?? $this->defaultTenant);
    }

    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null, ?string $tenant = null): array
    {
        return parent::getTimeSeries($metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type, $tenant ?? $this->defaultTenant);
    }

    public function purge(array $queries = [], ?string $type = null, ?string $tenant = null): bool
    {
        return parent::purge($queries, $type, $tenant ?? $this->defaultTenant);
    }

    public function findDaily(array $queries = [], ?string $tenant = null): array
    {
        return parent::findDaily($queries, $tenant ?? $this->defaultTenant);
    }

    public function sumDaily(array $queries = [], string $attribute = 'value', ?string $tenant = null): int
    {
        return parent::sumDaily($queries, $attribute, $tenant ?? $this->defaultTenant);
    }

    public function sumDailyBatch(array $metrics, array $queries = [], ?string $tenant = null): array
    {
        return parent::sumDailyBatch($metrics, $queries, $tenant ?? $this->defaultTenant);
    }
}
