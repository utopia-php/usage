<?php

namespace Utopia\Usage;

use Utopia\Database\Document;

abstract class Adapter
{
    /**
     * Get adapter name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Setup database structure
     *
     * @return void
     */
    abstract public function setup(): void;

    /**
     * Log usage metric
     *
     * @param string $metric
     * @param int $value
     * @param string $period
     * @param array<string,mixed> $tags
     * @return bool
     */
    abstract public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool;

    /**
     * Log multiple metrics in batch
     *
     * @param array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}> $metrics
     * @return bool
     */
    abstract public function logBatch(array $metrics): bool;

    /**
     * Get usage metrics by period
     *
     * @param string $metric
     * @param string $period
     * @param array<\Utopia\Database\Query> $queries
     * @return array<Document>
     */
    abstract public function getByPeriod(string $metric, string $period, array $queries = []): array;

    /**
     * Get usage metrics between dates
     *
     * @param string $metric
     * @param string $startDate
     * @param string $endDate
     * @param array<\Utopia\Database\Query> $queries
     * @return array<Document>
     */
    abstract public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array;

    /**
     * Count usage metrics by period
     *
     * @param string $metric
     * @param string $period
     * @param array<\Utopia\Database\Query> $queries
     * @return int
     */
    abstract public function countByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Sum usage metrics by period
     *
     * @param string $metric
     * @param string $period
     * @param array<\Utopia\Database\Query> $queries
     * @return int
     */
    abstract public function sumByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Purge old usage metrics
     *
     * @param string $datetime
     * @return bool
     */
    abstract public function purge(string $datetime): bool;
}
