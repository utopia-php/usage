<?php

namespace Utopia\Usage;

/**
 * Usage Metrics Manager
 *
 * This class manages usage metrics using pluggable adapters.
 * Adapters can be used to store metrics in different backends (Database, ClickHouse, etc.)
 *
 * Metrics are stored in two separate tables:
 * - Events table: additive metrics (bandwidth, requests, etc.) aggregated with SUM
 * - Gauges table: point-in-time snapshots (storage, user count, etc.) aggregated with argMax
 */
class Usage
{
    public const TYPE_EVENT = 'event';
    public const TYPE_GAUGE = 'gauge';

    private const DEFAULT_FLUSH_THRESHOLD = 10_000;
    private const DEFAULT_FLUSH_INTERVAL = 20;

    private Adapter $adapter;

    /**
     * In-memory buffer for metrics.
     * Keyed by "{metric}:{type}" — events are summed, gauges use last-write-wins.
     *
     * @var array<string, array{metric: string, value: int, type: string, tags: array<string, mixed>}>
     */
    private array $buffer = [];

    /** @var int Number of collect() calls since last flush */
    private int $bufferCount = 0;

    /** @var int Flush when buffer reaches this many entries */
    private int $flushThreshold = self::DEFAULT_FLUSH_THRESHOLD;

    /** @var int Flush when this many seconds have elapsed since last flush */
    private int $flushInterval = self::DEFAULT_FLUSH_INTERVAL;

    /** @var float Timestamp of the last flush */
    private float $lastFlushTime;

    /**
     * Constructor.
     *
     * @param  Adapter  $adapter  The adapter to use for storing usage metrics
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Get the current adapter.
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Check adapter health and connection status.
     *
     * @return array<string, mixed> Health check result with 'healthy' bool and additional adapter-specific information
     */
    public function healthCheck(): array
    {
        return $this->adapter->healthCheck();
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
     * Add metrics in batch (raw append).
     *
     * @param array<array{metric: string, value: int, tags?: array<string,mixed>}> $metrics
     * @param string $type Metric type: 'event' or 'gauge'
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type = self::TYPE_EVENT, int $batchSize = 1000): bool
    {
        return $this->adapter->addBatch($metrics, $type, $batchSize);
    }

    /**
     * Get time series data for metrics.
     *
     * @param array<string> $metrics List of metric names
     * @param string $interval '1h' or '1d'
     * @param string $startDate Start datetime
     * @param string $endDate End datetime
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param bool $zeroFill Whether to fill gaps with zero values
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     * @throws \Exception
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        return $this->adapter->getTimeSeries($metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type);
    }

    /**
     * Get total value for a single metric.
     *
     * @param string $metric Metric name
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return int
     * @throws \Exception
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        return $this->adapter->getTotal($metric, $queries, $type);
    }

    /**
     * Get totals for multiple metrics in a single query.
     *
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, int>
     * @throws \Exception
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array
    {
        return $this->adapter->getTotalBatch($metrics, $queries, $type);
    }

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (purge both)
     * @throws \Exception
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
        return $this->adapter->purge($queries, $type);
    }

    /**
     * Find metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     * @throws \Exception
     */
    public function find(array $queries = [], ?string $type = null): array
    {
        return $this->adapter->find($queries, $type);
    }

    /**
     * Count metrics using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (count both)
     * @return int
     * @throws \Exception
     */
    public function count(array $queries = [], ?string $type = null): int
    {
        return $this->adapter->count($queries, $type);
    }

    /**
     * Sum metric values using Query objects.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string|null $type Metric type: 'event', 'gauge', or null (sum both)
     * @return int
     * @throws \Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', ?string $type = null): int
    {
        return $this->adapter->sum($queries, $attribute, $type);
    }

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily MV for fast billing/analytics.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @return array<Metric>
     * @throws \Exception
     */
    public function findDaily(array $queries = []): array
    {
        return $this->adapter->findDaily($queries);
    }

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Use this for billing queries — reads pre-aggregated daily rows
     * instead of scanning billions of raw events.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     * @throws \Exception
     */
    public function sumDaily(array $queries = [], string $attribute = 'value'): int
    {
        return $this->adapter->sumDaily($queries, $attribute);
    }

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     * @throws \Exception
     */
    public function sumDailyBatch(array $metrics, array $queries = []): array
    {
        return $this->adapter->sumDailyBatch($metrics, $queries);
    }

    /**
     * Set the namespace prefix for table names.
     *
     * @param string $namespace
     * @return $this
     * @throws \Exception
     */
    public function setNamespace(string $namespace): self
    {
        $this->adapter->setNamespace($namespace);
        return $this;
    }

    /**
     * Set the tenant ID for multi-tenant support.
     *
     * @param string|null $tenant
     * @return $this
     */
    public function setTenant(?string $tenant): self
    {
        $this->adapter->setTenant($tenant);
        return $this;
    }

    /**
     * Enable or disable shared tables mode (multi-tenant with tenant column).
     *
     * @param bool $sharedTables
     * @return $this
     */
    public function setSharedTables(bool $sharedTables): self
    {
        $this->adapter->setSharedTables($sharedTables);
        return $this;
    }

    /**
     * Collect a metric into the in-memory buffer for deferred flushing.
     *
     * For event type: multiple collect() calls for the same metric are summed.
     * For gauge type: last-write-wins semantics.
     * No period fan-out — raw timestamps are used.
     *
     * @param string $metric Metric name
     * @param int $value Value
     * @param string $type Metric type: 'event' or 'gauge'
     * @param array<string,mixed> $tags Optional tags
     * @return self
     */
    public function collect(string $metric, int $value, string $type, array $tags = []): self
    {
        if (empty($metric)) {
            throw new \InvalidArgumentException('Metric name cannot be empty');
        }
        if ($value < 0) {
            throw new \InvalidArgumentException('Value cannot be negative');
        }
        if ($type !== self::TYPE_EVENT && $type !== self::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid metric type '{$type}'. Allowed: " . self::TYPE_EVENT . ', ' . self::TYPE_GAUGE);
        }

        $tagsHash = !empty($tags) ? md5(json_encode($tags, JSON_THROW_ON_ERROR)) : '';
        $key = $metric . ':' . $type . ':' . $tagsHash;

        if ($type === 'event') {
            // Additive: sum values for the same metric + tags combination
            if (isset($this->buffer[$key])) {
                $this->buffer[$key]['value'] += $value;
            } else {
                $this->buffer[$key] = [
                    'metric' => $metric,
                    'value' => $value,
                    'type' => $type,
                    'tags' => $tags,
                ];
            }
        } else {
            // Gauge: last-write-wins
            $this->buffer[$key] = [
                'metric' => $metric,
                'value' => $value,
                'type' => $type,
                'tags' => $tags,
            ];
        }

        $this->bufferCount++;

        return $this;
    }

    /**
     * Flush the in-memory buffer to storage.
     *
     * Separates buffered metrics into events and gauges, then writes each batch
     * to the appropriate table via addBatch().
     *
     * @return bool True if flush succeeded (or buffer was empty)
     * @throws \Exception
     */
    public function flush(): bool
    {
        if (empty($this->buffer)) {
            $this->lastFlushTime = microtime(true);
            return true;
        }

        // Separate events and gauges
        $events = [];
        $gauges = [];

        foreach ($this->buffer as $entry) {
            if ($entry['type'] === self::TYPE_EVENT) {
                $events[] = $entry;
            } else {
                $gauges[] = $entry;
            }
        }

        $result = true;

        // Flush events to events table
        if (!empty($events)) {
            $result = $this->adapter->addBatch($events, self::TYPE_EVENT);
        }

        // Flush gauges to gauges table
        if (!empty($gauges)) {
            $result = $this->adapter->addBatch($gauges, self::TYPE_GAUGE) && $result;
        }

        $this->buffer = [];
        $this->bufferCount = 0;
        $this->lastFlushTime = microtime(true);

        return $result;
    }

    /**
     * Check if the buffer should be flushed based on thresholds.
     *
     * Returns true if either:
     * - The number of collect() calls meets the flush threshold
     * - The time since last flush exceeds the flush interval
     *
     * @return bool
     */
    public function shouldFlush(): bool
    {
        if ($this->bufferCount >= $this->flushThreshold) {
            return true;
        }

        $elapsed = microtime(true) - $this->lastFlushTime;
        if ($elapsed >= $this->flushInterval) {
            return true;
        }

        return false;
    }

    /**
     * Get the number of collect() calls since the last flush.
     *
     * @return int
     */
    public function getBufferCount(): int
    {
        return $this->bufferCount;
    }

    /**
     * Get the number of unique metric entries in the buffer.
     *
     * @return int
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Set the flush threshold (number of collect() calls before flush is recommended).
     *
     * @param int $threshold Must be >= 1
     * @return self
     */
    public function setFlushThreshold(int $threshold): self
    {
        if ($threshold < 1) {
            throw new \InvalidArgumentException('Flush threshold must be at least 1');
        }
        $this->flushThreshold = $threshold;
        return $this;
    }

    /**
     * Set the flush interval in seconds.
     *
     * @param int $seconds Must be >= 1
     * @return self
     */
    public function setFlushInterval(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Flush interval must be at least 1 second');
        }
        $this->flushInterval = $seconds;
        return $this;
    }

    /**
     * Get the flush threshold.
     *
     * @return int
     */
    public function getFlushThreshold(): int
    {
        return $this->flushThreshold;
    }

    /**
     * Get the flush interval in seconds.
     *
     * @return int
     */
    public function getFlushInterval(): int
    {
        return $this->flushInterval;
    }
}
