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
     * @param  string|null  $tenant  Tenant to scope the query to (shared-tables mode)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     */
    abstract public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null, ?string $tenant = null): array;

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * When $type is null, queries both tables.
     *
     * @param  string  $metric  Metric name
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     */
    abstract public function getTotal(string $metric, array $queries = [], ?string $type = null, ?string $tenant = null): int;

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
    abstract public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null, ?string $tenant = null): array;

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (purge both)
     */
    abstract public function purge(array $queries = [], ?string $type = null, ?string $tenant = null): bool;

    /**
     * Find metrics using Query objects.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     */
    abstract public function find(array $queries = [], ?string $type = null, ?string $tenant = null): array;

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level —
     * the adapter must stop counting once $max rows have been matched.
     * This keeps large counts cheap for endpoints that only need a capped
     * total. When $max is null the count is unbounded.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (count both)
     * @param  int|null  $max  Optional upper bound for the count (inclusive)
     */
    abstract public function count(array $queries = [], ?string $type = null, ?int $max = null, ?string $tenant = null): int;

    /**
     * Sum metric values using Query objects.
     *
     * Events-only by default because summing gauges is semantically meaningless
     * (adding point-in-time snapshots doesn't produce a useful total).
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string  $attribute  Attribute to sum (default: 'value')
     * @param  string  $type  Metric type: 'event' or 'gauge'
     */
    abstract public function sum(array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT, ?string $tenant = null): int;

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily materialized view for fast billing/analytics.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<\Utopia\Query\Query>  $queries  Filters (metric, time range, resource, etc.)
     * @return array<Metric>
     */
    abstract public function findDaily(array $queries = [], ?string $tenant = null): array;

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string  $attribute  Attribute to sum (default: 'value')
     */
    abstract public function sumDaily(array $queries = [], string $attribute = 'value', ?string $tenant = null): int;

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     */
    abstract public function sumDailyBatch(array $metrics, array $queries = [], ?string $tenant = null): array;

    /**
     * Enable parity sampling for routed reads. At rate=0 the sampler is
     * disabled (default). At rate>0 each routed read is re-executed against
     * the raw table with the given probability and logs a dual_read_warning
     * entry when totals diverge by more than 1%. Pass 1.0 for every-read
     * sampling (CI use) or small values (0.01) for production canaries.
     *
     * Adapters without parity sampling override this with a no-op.
     */
    public function setDualReadSampleRate(float $rate): self
    {
        return $this;
    }
}
