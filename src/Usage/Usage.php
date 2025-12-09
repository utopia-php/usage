<?php

namespace Utopia\Usage;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Usage\Adapter\ClickHouse;

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
     * @param  array<int,array<string,mixed>>  $metrics
     *
     * @throws \Exception
     */
    public function logBatch(array $metrics): bool
    {
        return $this->adapter->logBatch($metrics);
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<int,mixed>  $queries
     * @return array<Document>
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
     * @param  array<int,mixed>  $queries
     * @return array<Document>
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
     * @param  array<int,mixed>  $queries
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
     * @param  array<int,mixed>  $queries
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
     * @deprecated Use constructor with adapter instead
     *
     * @internal Legacy support - will be removed in future version
     */
    public const COLLECTION = 'usage';

    /**
     * @deprecated Use Adapter\Database::PERIODS instead
     *
     * @var array<string,string>
     */
    public const PERIODS = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00',
    ];

    /**
     * @deprecated Use Adapter\Database::ATTRIBUTES instead
     */
    public const ATTRIBUTES = [
        [
            '$id' => 'metric',
            'type' => \Utopia\Database\Database::VAR_STRING,
            'size' => 255,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'value',
            'type' => \Utopia\Database\Database::VAR_INTEGER,
            'size' => 0,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'period',
            'type' => \Utopia\Database\Database::VAR_STRING,
            'size' => 16,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'time',
            'type' => \Utopia\Database\Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => true,
            'required' => false,
            'array' => false,
            'filters' => ['datetime'],
        ],
        [
            '$id' => 'tags',
            'type' => \Utopia\Database\Database::VAR_STRING,
            'size' => 16777216,
            'required' => false,
            'signed' => true,
            'array' => false,
            'filters' => ['json'],
        ],
    ];

    /**
     * @deprecated Use Adapter\Database::INDEXES instead
     */
    public const INDEXES = [
        [
            '$id' => 'index-metric',
            'type' => \Utopia\Database\Database::INDEX_KEY,
            'attributes' => ['metric'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-period',
            'type' => \Utopia\Database\Database::INDEX_KEY,
            'attributes' => ['period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-metric-period',
            'type' => \Utopia\Database\Database::INDEX_KEY,
            'attributes' => ['metric', 'period'],
            'lengths' => [],
            'orders' => [],
        ],
        [
            '$id' => 'index-time',
            'type' => \Utopia\Database\Database::INDEX_KEY,
            'attributes' => ['time'],
            'lengths' => [],
            'orders' => [\Utopia\Database\Database::ORDER_DESC],
        ],
    ];
}
