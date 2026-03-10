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

    private const DEFAULT_FLUSH_THRESHOLD = 10_000;
    private const DEFAULT_FLUSH_INTERVAL = 20;

    private Adapter $adapter;

    /**
     * In-memory buffer for increment metrics (additive upsert).
     * Keyed by "{metric}:{period}" with values accumulated (summed).
     *
     * @var array<string, array{metric: string, value: int, period: string, tags: array<string,mixed>}>
     */
    private array $incrementBuffer = [];

    /**
     * In-memory buffer for counter metrics (replace upsert).
     * Keyed by "{metric}:{period}" with last value winning.
     *
     * @var array<string, array{metric: string, value: int, period: string, tags: array<string,mixed>}>
     */
    private array $counterBuffer = [];

    /** @var int Number of collect()/collectSet() calls since last flush */
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
     * Increment metrics in batch (additive upsert).
     *
     * Values with the same deterministic ID are summed together.
     *
     * @param array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}> $metrics
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function incrementBatch(array $metrics, int $batchSize = 1000): bool
    {
        return $this->adapter->incrementBatch($metrics, $batchSize);
    }

    /**
     * Set metrics in batch (replace upsert).
     *
     * Values with the same deterministic ID are replaced (last write wins).
     *
     * @param array<array{metric: string, value: int, period?: string, tags?: array<string,mixed>}> $metrics
     * @param int $batchSize Maximum number of metrics per INSERT statement
     * @return bool
     * @throws \Exception
     */
    public function setBatch(array $metrics, int $batchSize = 1000): bool
    {
        return $this->adapter->setBatch($metrics, $batchSize);
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
     * Sum usage metrics by period for multiple metrics in a single query.
     *
     * Collapses N sumByPeriod() calls into 1 query using WHERE metric IN (...).
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<string, int>
     *
     * @throws \Exception
     */
    public function sumByPeriodBatch(array $metrics, string $period, array $queries = []): array
    {
        return $this->adapter->sumByPeriodBatch($metrics, $period, $queries);
    }

    /**
     * Get usage metrics by period for multiple metrics in a single query.
     *
     * Collapses N getByPeriod() calls into 1 query using WHERE metric IN (...).
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Usage\Query>  $queries
     * @return array<string, array<Metric>>
     *
     * @throws \Exception
     */
    public function getByPeriodBatch(array $metrics, string $period, array $queries = []): array
    {
        return $this->adapter->getByPeriodBatch($metrics, $period, $queries);
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
     * @param int|null $tenant
     * @return $this
     */
    public function setTenant(?int $tenant): self
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
     * Increment a metric across all periods (1h, 1d, inf).
     *
     * Additive upsert: value is added to any existing value for the same
     * metric/period/time bucket. This is the primary method for event-driven
     * metrics like request counts, bandwidth, etc.
     *
     * @param string $metric Metric name
     * @param int $value Value to add
     * @param array<string,mixed> $tags Optional tags
     * @return bool
     * @throws \Exception
     */
    public function increment(string $metric, int $value, array $tags = []): bool
    {
        return $this->adapter->increment($metric, $value, $tags);
    }

    /**
     * Set a metric to an absolute value across all periods (1h, 1d, inf).
     *
     * Replace upsert: value overwrites any existing value for the same
     * metric/period/time bucket. Use this for periodic recounts or
     * resource gauges (e.g., current storage size, active user count).
     *
     * @param string $metric Metric name
     * @param int $value Absolute value
     * @param array<string,mixed> $tags Optional tags
     * @return bool
     * @throws \Exception
     */
    public function set(string $metric, int $value, array $tags = []): bool
    {
        return $this->adapter->set($metric, $value, $tags);
    }

    /**
     * Collect a metric into the in-memory buffer for deferred flushing.
     *
     * Uses additive upsert semantics: multiple collect() calls for the same
     * metric within the same time bucket are summed together.
     * Automatically fans out across all periods (1h, 1d, inf).
     *
     * @param string $metric Metric name
     * @param int $value Value to accumulate
     * @param array<string,mixed> $tags Optional tags
     * @return self
     */
    public function collect(string $metric, int $value, array $tags = []): self
    {
        foreach (array_keys(self::PERIODS) as $period) {
            $key = $metric . ':' . $period;

            if (isset($this->incrementBuffer[$key])) {
                $this->incrementBuffer[$key]['value'] += $value;
            } else {
                $this->incrementBuffer[$key] = [
                    'metric' => $metric,
                    'value' => $value,
                    'period' => $period,
                    'tags' => $tags,
                ];
            }
        }

        $this->bufferCount++;

        return $this;
    }

    /**
     * Collect a counter metric into the in-memory buffer for deferred flushing.
     *
     * Uses replace upsert semantics: multiple collectSet() calls for the same
     * metric within the same time bucket keep the last value (last-write-wins).
     * Automatically fans out across all periods (1h, 1d, inf).
     *
     * @param string $metric Metric name
     * @param int $value Absolute value to set
     * @param array<string,mixed> $tags Optional tags
     * @return self
     */
    public function collectSet(string $metric, int $value, array $tags = []): self
    {
        foreach (array_keys(self::PERIODS) as $period) {
            $key = $metric . ':' . $period;

            // Last-write-wins: always overwrite
            $this->counterBuffer[$key] = [
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'tags' => $tags,
            ];
        }

        $this->bufferCount++;

        return $this;
    }

    /**
     * Flush the in-memory buffer to storage.
     *
     * Writes increment metrics using additive upsert (incrementBatch) and
     * set metrics using replace upsert (setBatch), then clears both buffers.
     *
     * @return bool True if flush succeeded (or buffer was empty)
     * @throws \Exception
     */
    public function flush(): bool
    {
        if (empty($this->incrementBuffer) && empty($this->counterBuffer)) {
            $this->lastFlushTime = microtime(true);
            return true;
        }

        $result = true;

        if (!empty($this->incrementBuffer)) {
            $result = $this->adapter->incrementBatch(array_values($this->incrementBuffer));
        }

        if ($result && !empty($this->counterBuffer)) {
            $result = $this->adapter->setBatch(array_values($this->counterBuffer));
        }

        $this->incrementBuffer = [];
        $this->counterBuffer = [];
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
     * Get the number of unique metric/period entries in the buffer.
     *
     * @return int
     */
    public function getBufferSize(): int
    {
        return count($this->incrementBuffer) + count($this->counterBuffer);
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
