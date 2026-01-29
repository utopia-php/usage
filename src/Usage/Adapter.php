<?php

namespace Utopia\Usage;

abstract class Adapter
{
    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Setup database structure
     */
    abstract public function setup(): void;

    /**
     * Log usage metric
     *
     * @param  array<string,mixed>  $tags
     */
    abstract public function log(string $metric, int $value, string $period = Usage::PERIOD_1H, array $tags = []): bool;

    /**
     * Log multiple metrics in batch
     *
     * @param  array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function logBatch(array $metrics, int $batchSize = 1000): bool;

    /**
     * Log usage counter metric (individual entry without aggregation)
     *
     * @param  array<string,mixed>  $tags
     */
    abstract public function logCounter(string $metric, int $value, string $period = Usage::PERIOD_1H, array $tags = []): bool;

    /**
     * Log multiple counter metrics in batch (individual entries without aggregation)
     *
     * @param  array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function logBatchCounter(array $metrics, int $batchSize = 1000): bool;

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
     * Purge old usage metrics
     */
    abstract public function purge(string $datetime): bool;

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
}
