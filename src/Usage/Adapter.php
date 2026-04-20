<?php

namespace Utopia\Usage;

abstract class Adapter
{
    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Check adapter health and connection status
     *
     * @return array<string, mixed> Health check result with 'healthy' bool and additional adapter-specific information
     */
    abstract public function healthCheck(): array;

    /**
     * Setup database structure
     */
    abstract public function setup(): void;

    /**
     * Add metrics in batch (raw append).
     *
     * Routes rows to the correct table based on the $type parameter.
     * For events, path/method/status/resource/resourceId are extracted from tags
     * into dedicated columns; remaining tags stay in the tags JSON.
     *
     * @param  array<array{metric: string, value: int, tags?: array<string,mixed>}>  $metrics
     * @param  string  $type  Metric type: 'event' or 'gauge' — determines which table to write to
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool;

    /**
     * Get time series data for metrics with query-time aggregation.
     *
     * Groups data by the specified interval (1h or 1d) and applies
     * SUM for event metrics and argMax for gauge metrics.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  string  $interval  Aggregation interval: '1h' or '1d'
     * @param  string  $startDate  Start datetime string
     * @param  string  $endDate  End datetime string
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  bool  $zeroFill  Whether to fill gaps with zero values
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     */
    abstract public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array;

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * When $type is null, queries both tables.
     *
     * @param  string  $metric  Metric name
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return int
     */
    abstract public function getTotal(string $metric, array $queries = [], ?string $type = null): int;

    /**
     * Get totals for multiple metrics in a single query.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, int>
     */
    abstract public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array;

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (purge both)
     */
    abstract public function purge(array $queries = [], ?string $type = null): bool;

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     */
    abstract public function find(array $queries = [], ?string $type = null): array;

    /**
     * Count metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (count both)
     * @return int
     */
    abstract public function count(array $queries = [], ?string $type = null): int;

    /**
     * Sum metric values using Query objects.
     *
     * Events-only by default because summing gauges is semantically meaningless
     * (adding point-in-time snapshots doesn't produce a useful total).
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string $type Metric type: 'event' or 'gauge'
     * @return int
     */
    abstract public function sum(array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int;

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily materialized view for fast billing/analytics.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<\Utopia\Query\Query> $queries  Filters (metric, time range, resource, etc.)
     * @return array<Metric>
     */
    abstract public function findDaily(array $queries = []): array;

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     */
    abstract public function sumDaily(array $queries = [], string $attribute = 'value'): int;

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     */
    abstract public function sumDailyBatch(array $metrics, array $queries = []): array;

    /**
     * Set the namespace prefix for table names.
     *
     * @param string $namespace
     * @return self
     */
    abstract public function setNamespace(string $namespace): self;

    /**
     * Set the tenant ID for multi-tenant support.
     *
     * @param string|null $tenant
     * @return self
     */
    abstract public function setTenant(?string $tenant): self;

    /**
     * Enable or disable shared tables mode (multi-tenant with tenant column).
     *
     * @param bool $sharedTables
     * @return self
     */
    abstract public function setSharedTables(bool $sharedTables): self;
}
