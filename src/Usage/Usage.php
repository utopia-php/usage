<?php

namespace Utopia\Usage;

/**
 * Usage Metrics Manager
 *
 * This class manages usage metrics using pluggable adapters.
 * Adapters can be used to store metrics in different backends (Database, ClickHouse, etc.)
 *
 * Metrics are stored in two separate tables:
 * - Events table: additive metrics (bandwidth, requests, etc.) aggregated with SUM
 * - Gauges table: point-in-time snapshots (storage, user count, etc.) aggregated with argMax
 */
class Usage
{
    public const TYPE_EVENT = 'event';
    public const TYPE_GAUGE = 'gauge';

    private Adapter $adapter;

    /**
     * Constructor.
     *
     * @param  Adapter  $adapter  The adapter to use for storing usage metrics
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Get the current adapter.
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Check adapter health and connection status.
     *
     * @return array<string, mixed> Health check result with 'healthy' bool and additional adapter-specific information
     */
    public function healthCheck(): array
    {
        return $this->adapter->healthCheck();
    }

    /**
     * Setup the usage metrics storage.
     *
     * @throws \Exception
     */
    public function setup(): void
    {
        $this->adapter->setup();
    }

    /**
     * Add metrics in batch (raw append).
     *
     * Callers must explicitly pass the metric type so event and gauge
     * writes are never confused at the call site.
     *
     * @param array<array{metric: string, value: int, tags?: array<string,mixed>}> $metrics
     * @param string $type Metric type: 'event' or 'gauge'
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        return $this->adapter->addBatch($metrics, $type, $batchSize);
    }

    /**
     * Get time series data for metrics.
     *
     * @param array<string> $metrics List of metric names
     * @param string $interval '1h' or '1d'
     * @param string $startDate Start datetime
     * @param string $endDate End datetime
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param bool $zeroFill Whether to fill gaps with zero values
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     * @throws \Exception
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        return $this->adapter->getTimeSeries($metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type);
    }

    /**
     * Get total value for a single metric.
     *
     * @param string $metric Metric name
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return int
     * @throws \Exception
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        return $this->adapter->getTotal($metric, $queries, $type);
    }

    /**
     * Get totals for multiple metrics in a single query.
     *
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, int>
     * @throws \Exception
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array
    {
        return $this->adapter->getTotalBatch($metrics, $queries, $type);
    }

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (purge both)
     * @throws \Exception
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
        return $this->adapter->purge($queries, $type);
    }

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     * @throws \Exception
     */
    public function find(array $queries = [], ?string $type = null): array
    {
        return $this->adapter->find($queries, $type);
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level.
     * Callers that only need a capped total (e.g. to render "5000+") should
     * pass $max so the adapter can short-circuit the count for large tables.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (count both)
     * @param int|null $max Optional upper bound for the count (inclusive)
     * @return int
     * @throws \Exception
     */
    public function count(array $queries = [], ?string $type = null, ?int $max = null): int
    {
        return $this->adapter->count($queries, $type, $max);
    }

    /**
     * Sum metric values using Query objects.
     *
     * Defaults to events because summing gauges (point-in-time snapshots)
     * is semantically meaningless — it averages/accumulates snapshots rather
     * than producing a useful total. Callers that truly want a gauge sum
     * must opt in explicitly.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string $type Metric type: 'event' or 'gauge'
     * @return int
     * @throws \Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', string $type = self::TYPE_EVENT): int
    {
        if ($type !== self::TYPE_EVENT && $type !== self::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: " . self::TYPE_EVENT . ', ' . self::TYPE_GAUGE);
        }

        return $this->adapter->sum($queries, $attribute, $type);
    }

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily MV for fast billing/analytics.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @return array<Metric>
     * @throws \Exception
     */
    public function findDaily(array $queries = []): array
    {
        return $this->adapter->findDaily($queries);
    }

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Use this for billing queries — reads pre-aggregated daily rows
     * instead of scanning billions of raw events.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     * @throws \Exception
     */
    public function sumDaily(array $queries = [], string $attribute = 'value'): int
    {
        return $this->adapter->sumDaily($queries, $attribute);
    }

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     * @throws \Exception
     */
    public function sumDailyBatch(array $metrics, array $queries = []): array
    {
        return $this->adapter->sumDailyBatch($metrics, $queries);
    }

    /**
     * Set the tenant ID for multi-tenant support.
     *
     * @param string|null $tenant
     * @return $this
     * @throws \Exception
     */
    public function setTenant(?string $tenant): self
    {
        if (method_exists($this->adapter, 'setTenant')) {
            $this->adapter->setTenant($tenant);
        }
        return $this;
    }
}
