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
     * @param string $table Table name for storing usage metrics
     * @param array<int,array<string,mixed>> $columns Column definitions
     * @param array<int,array<string,mixed>> $indexes Index definitions
     * @throws \Exception
     */
    public function setup(string $table = Adapter::DEFAULT_TABLE, array $columns = [], array $indexes = []): void
    {
        // Use legacy constants if no columns/indexes provided (for backward compatibility)
        if (empty($columns)) {
            $columns = self::ATTRIBUTES;
        }
        if (empty($indexes)) {
            $indexes = self::INDEXES;
        }

        $this->adapter->setup($table, $columns, $indexes);
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
     * @return bool
     * @throws \Exception
     */
    public function logBatch(array $metrics): bool
    {
        return $this->adapter->logBatch($metrics);
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<\Utopia\Database\Query>  $queries
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
     * @param  array<\Utopia\Database\Query>  $queries
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
     * @param  array<\Utopia\Database\Query>  $queries
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
     * @param  array<\Utopia\Database\Query>  $queries
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
     * @deprecated Use Adapter::DEFAULT_TABLE instead
     *
     * @internal Legacy support - will be removed in future version
     */
    public const COLLECTION = Adapter::DEFAULT_TABLE;

    /**
     * @deprecated Use Adapter::PERIODS instead
     *
     * @var array<string,string>
     */
    public const PERIODS = Adapter::PERIODS;

    public const ATTRIBUTES = [
        [
            '$id' => 'metric',
            'type' => 'string',
            'size' => 255,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'value',
            'type' => 'integer',
            'size' => 0,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'period',
            'type' => 'string',
            'size' => 16,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'time',
            'type' => 'datetime',
            'format' => '',
            'size' => 0,
            'signed' => true,
            'required' => false,
            'array' => false,
            'filters' => ['datetime'],
        ],
        [
            '$id' => 'tags',
            'type' => 'string',
            'size' => 16777216,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => ['json'],
        ],
    ];

    public const INDEXES = [
        [
            '$id' => 'index-metric',
            'type' => 'key',
            'attributes' => ['metric'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-period',
            'type' => 'key',
            'attributes' => ['period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-metric-period',
            'type' => 'key',
            'attributes' => ['metric', 'period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-time',
            'type' => 'key',
            'attributes' => ['time'],
            'lengths' => [],
            'orders' => ['desc'],
        ],
    ];
}
