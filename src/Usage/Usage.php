<?php

namespace Utopia\Usage;

/**
 * Usage Metrics Manager
 *
 * This class manages usage metrics using pluggable adapters.
 * Adapters can be used to store metrics in different backends (Database, ClickHouse, etc.)
 */
class Usage
{
    public const PERIOD_1H = '1h';
    public const PERIOD_1D = '1d';
    public const PERIOD_INF = 'inf';
    public const PERIODS = [
        self::PERIOD_1H => 'Y-m-d H:00',
        self::PERIOD_1D => 'Y-m-d 00:00',
        self::PERIOD_INF => '0000-00-00 00:00',
    ];


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
     * Setup the usage metrics storage.
     *
     * @throws \Exception
     */
    public function setup(): void
    {
        $this->adapter->setup();
    }

    /**
     * Log a usage metric.
     *
     * @param  array<string,mixed>  $tags
     *
     * @throws \Exception
     */
    public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool
    {
        return $this->adapter->log($metric, $value, $period, $tags);
    }

    /**
     * Log multiple usage metrics in batch.
     *
     * @param array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}> $metrics
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function logBatch(array $metrics, int $batchSize = 1000): bool
    {
        return $this->adapter->logBatch($metrics, $batchSize);
    }

    /**
     * Log a usage counter metric (individual entry without aggregation).
     *
     * @param  array<string,mixed>  $tags
     *
     * @throws \Exception
     */
    public function logCounter(string $metric, int $value, string $period = '1h', array $tags = []): bool
    {
        return $this->adapter->logCounter($metric, $value, $period, $tags);
    }

    /**
     * Log multiple usage counter metrics in batch (individual entries without aggregation).
     *
     * @param array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}> $metrics
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function logBatchCounter(array $metrics, int $batchSize = 1000): bool
    {
        return $this->adapter->logBatchCounter($metrics, $batchSize);
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<Metric>
     *
     * @throws \Exception
     */
    public function getByPeriod(string $metric, string $period, array $queries = []): array
    {
        return $this->adapter->getByPeriod($metric, $period, $queries);
    }

    /**
     * Get usage metrics between dates.
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<Metric>
     *
     * @throws \Exception
     */
    public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array
    {
        return $this->adapter->getBetweenDates($metric, $startDate, $endDate, $queries);
    }

    /**
     * Count usage metrics by period.
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     *
     * @throws \Exception
     */
    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        return $this->adapter->countByPeriod($metric, $period, $queries);
    }

    /**
     * Sum usage metric values by period.
     *
     * @param  array<\Utopia\Usage\Query>  $queries
     *
     * @throws \Exception
     */
    public function sumByPeriod(string $metric, string $period, array $queries = []): int
    {
        return $this->adapter->sumByPeriod($metric, $period, $queries);
    }

    /**
     * Purge usage metrics older than the specified datetime.
     *
     * @throws \Exception
     */
    public function purge(string $datetime): bool
    {
        return $this->adapter->purge($datetime);
    }

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Usage\Query> $queries
     * @return array<Metric>
     * @throws \Exception
     */
    public function find(array $queries = []): array
    {
        return $this->adapter->find($queries);
    }

    /**
     * Count metrics using Query objects.
     *
     * @param array<\Utopia\Usage\Query> $queries
     * @return int
     * @throws \Exception
     */
    public function count(array $queries = []): int
    {
        return $this->adapter->count($queries);
    }
}
