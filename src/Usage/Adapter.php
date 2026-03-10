<?php

namespace Utopia\Usage;

abstract class Adapter
{
    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Increment a metric across all periods (1h, 1d, inf).
     *
     * Uses additive upsert semantics: if a row with the same deterministic ID exists,
     * the value is added to the existing value (SummingMergeTree in ClickHouse,
     * upsertDocumentsWithIncrease in Database).
     *
     * @param string $metric Metric name
     * @param int $value Value to add (must be positive)
     * @param array<string,mixed> $tags Optional tags
     * @return bool
     * @throws \Exception
     */
    public function increment(string $metric, int $value, array $tags = []): bool
    {
        $metrics = [];
        foreach (array_keys(Usage::PERIODS) as $period) {
            $metrics[] = [
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'tags' => $tags,
            ];
        }

        return $this->incrementBatch($metrics);
    }

    /**
     * Set a metric to an absolute value across all periods (1h, 1d, inf).
     *
     * Uses replace upsert semantics: if a row with the same deterministic ID exists,
     * the value replaces the existing value (ReplacingMergeTree in ClickHouse,
     * upsertDocuments in Database).
     *
     * @param string $metric Metric name
     * @param int $value Absolute value to set
     * @param array<string,mixed> $tags Optional tags
     * @return bool
     * @throws \Exception
     */
    public function set(string $metric, int $value, array $tags = []): bool
    {
        $metrics = [];
        foreach (array_keys(Usage::PERIODS) as $period) {
            $metrics[] = [
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'tags' => $tags,
            ];
        }

        return $this->setBatch($metrics);
    }

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
     * Increment metrics in batch (additive upsert).
     *
     * Values with the same deterministic ID are summed together
     * (SummingMergeTree in ClickHouse, upsertDocumentsWithIncrease in Database).
     *
     * @param  array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function incrementBatch(array $metrics, int $batchSize = 1000): bool;

    /**
     * Set metrics in batch (replace upsert).
     *
     * Values with the same deterministic ID are replaced (last write wins)
     * (ReplacingMergeTree in ClickHouse, upsertDocuments in Database).
     *
     * @param  array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function setBatch(array $metrics, int $batchSize = 1000): bool;

    /**
     * Get usage metrics by period
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<Metric>
     */
    abstract public function getByPeriod(string $metric, string $period, array $queries = []): array;

    /**
     * Get usage metrics between dates
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<Metric>
     */
    abstract public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array;

    /**
     * Count usage metrics by period
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     */
    abstract public function countByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Sum usage metrics by period
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     */
    abstract public function sumByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Sum usage metrics by period for multiple metrics in a single query.
     *
     * Returns an associative array keyed by metric name with the sum as value.
     * Metrics not found will have a value of 0.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<string, int>
     */
    abstract public function sumByPeriodBatch(array $metrics, string $period, array $queries = []): array;

    /**
     * Get usage metrics by period for multiple metrics in a single query.
     *
     * Returns an associative array keyed by metric name with arrays of Metric objects as values.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<string, array<Metric>>
     */
    abstract public function getByPeriodBatch(array $metrics, string $period, array $queries = []): array;

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param array<\Utopia\Usage\Query> $queries
     */
    abstract public function purge(array $queries = []): bool;

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Usage\Query> $queries
     * @return array<Metric>
     */
    abstract public function find(array $queries = []): array;

    /**
     * Count metrics using Query objects.
     *
     * @param array<\Utopia\Usage\Query> $queries
     * @return int
     */
    abstract public function count(array $queries = []): int;

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
     * @param int|null $tenant
     * @return self
     */
    abstract public function setTenant(?int $tenant): self;

    /**
     * Enable or disable shared tables mode (multi-tenant with tenant column).
     *
     * @param bool $sharedTables
     * @return self
     */
    abstract public function setSharedTables(bool $sharedTables): self;
}
