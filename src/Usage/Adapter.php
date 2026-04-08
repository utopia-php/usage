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
     * Appends rows to the single MergeTree table. Each row must include
     * a 'type' field ('event' or 'gauge') and a 'metric' name.
     *
     * @param  array<array{metric: string, value: int, type: string, tags?: array<string,mixed>}>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function addBatch(array $metrics, int $batchSize = 1000): bool;

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
     * @return array<string, array{total: int, data: array<array{value: int, date: string}>}>
     */
    abstract public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true): array;

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * Auto-detects type from stored data.
     *
     * @param  string  $metric  Metric name
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @return int
     */
    abstract public function getTotal(string $metric, array $queries = []): int;

    /**
     * Get totals for multiple metrics in a single query.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @return array<string, int>
     */
    abstract public function getTotalBatch(array $metrics, array $queries = []): array;

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param array<\Utopia\Query\Query> $queries
     */
    abstract public function purge(array $queries = []): bool;

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @return array<Metric>
     */
    abstract public function find(array $queries = []): array;

    /**
     * Count metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @return int
     */
    abstract public function count(array $queries = []): int;

    /**
     * Sum metric values using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     */
    abstract public function sum(array $queries = [], string $attribute = 'value'): int;

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
