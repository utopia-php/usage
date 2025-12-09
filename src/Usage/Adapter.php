<?php

namespace Utopia\Usage;

abstract class Adapter
{
    /**
     * Default table name for usage metrics
     */
    public const DEFAULT_TABLE = 'usage';

    /**
     * Period format mappings
     *
     * @var array<string,string>
     */
    public const PERIODS = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00',
    ];

    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Setup database structure
     *
     * @param  string  $table  Table name
     * @param  array<int,array<string,mixed>>  $columns  Column definitions
     * @param  array<int,array<string,mixed>>  $indexes  Index definitions
     */
    abstract public function setup(string $table, array $columns, array $indexes): void;

    /**
     * Log usage metric
     *
     * @param  array<string,mixed>  $tags
     */
    abstract public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool;

    /**
     * Log multiple metrics in batch
     *
     * @param  array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}>  $metrics
     */
    abstract public function logBatch(array $metrics): bool;

    /**
     * Get usage metrics by period
     *
     * @param  array<\Utopia\Database\Query>  $queries
     * @return array<Metric>
     */
    abstract public function getByPeriod(string $metric, string $period, array $queries = []): array;

    /**
     * Get usage metrics between dates
     *
     * @param  array<\Utopia\Database\Query>  $queries
     * @return array<Metric>
     */
    abstract public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array;

    /**
     * Count usage metrics by period
     *
     * @param  array<\Utopia\Database\Query>  $queries
     */
    abstract public function countByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Sum usage metrics by period
     *
     * @param  array<\Utopia\Database\Query>  $queries
     */
    abstract public function sumByPeriod(string $metric, string $period, array $queries = []): int;

    /**
     * Purge old usage metrics
     */
    abstract public function purge(string $datetime): bool;
}
