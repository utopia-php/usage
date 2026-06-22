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
     * Callers must explicitly pass the metric type so event and gauge
     * writes are never confused at the call site.
     *
     * @param  array<array{metric: string, value: int, tags?: array<string,mixed>}>  $metrics
     * @param  string  $type  Metric type: 'event' or 'gauge'
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     *
     * @throws \Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        return $this->adapter->addBatch($metrics, $type, $batchSize);
    }

    /**
     * Get time series data for metrics.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  string  $interval  '1h' or '1d'
     * @param  string  $startDate  Start datetime
     * @param  string  $endDate  End datetime
     * @param  array<\Utopia\Query\Query>  $queries  Additional filters
     * @param  bool  $zeroFill  Whether to fill gaps with zero values
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     *
     * @throws \Exception
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null, ?string $tenant = null): array
    {
        return $this->adapter->getTimeSeries($metrics, $interval, $startDate, $endDate, $queries, $zeroFill, $type, $tenant);
    }

    /**
     * Get total value for a single metric.
     *
     * @param  string  $metric  Metric name
     * @param  array<\Utopia\Query\Query>  $queries  Additional filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     *
     * @throws \Exception
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null, ?string $tenant = null): int
    {
        return $this->adapter->getTotal($metric, $queries, $type, $tenant);
    }

    /**
     * Get totals for multiple metrics in a single query.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, int>
     *
     * @throws \Exception
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null, ?string $tenant = null): array
    {
        return $this->adapter->getTotalBatch($metrics, $queries, $type, $tenant);
    }

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (purge both)
     *
     * @throws \Exception
     */
    public function purge(array $queries = [], ?string $type = null, ?string $tenant = null): bool
    {
        return $this->adapter->purge($queries, $type, $tenant);
    }

    /**
     * Find metrics using Query objects.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     *
     * @throws \Exception
     */
    public function find(array $queries = [], ?string $type = null, ?string $tenant = null): array
    {
        return $this->adapter->find($queries, $type, $tenant);
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level.
     * Callers that only need a capped total (e.g. to render "5000+") should
     * pass $max so the adapter can short-circuit the count for large tables.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (count both)
     * @param  int|null  $max  Optional upper bound for the count (inclusive)
     *
     * @throws \Exception
     */
    public function count(array $queries = [], ?string $type = null, ?int $max = null, ?string $tenant = null): int
    {
        return $this->adapter->count($queries, $type, $max, $tenant);
    }

    /**
     * Sum metric values using Query objects.
     *
     * Defaults to events because summing gauges (point-in-time snapshots)
     * is semantically meaningless — it averages/accumulates snapshots rather
     * than producing a useful total. Callers that truly want a gauge sum
     * must opt in explicitly.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string  $attribute  Attribute to sum (default: 'value')
     * @param  string  $type  Metric type: 'event' or 'gauge'
     *
     * @throws \Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', string $type = self::TYPE_EVENT, ?string $tenant = null): int
    {
        if ($type !== self::TYPE_EVENT && $type !== self::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Allowed: ".self::TYPE_EVENT.', '.self::TYPE_GAUGE);
        }

        return $this->adapter->sum($queries, $attribute, $type, $tenant);
    }

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily MV for fast billing/analytics.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @return array<Metric>
     *
     * @throws \Exception
     */
    public function findDaily(array $queries = [], ?string $tenant = null): array
    {
        return $this->adapter->findDaily($queries, $tenant);
    }

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Use this for billing queries — reads pre-aggregated daily rows
     * instead of scanning billions of raw events.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<\Utopia\Query\Query>  $queries
     * @param  string  $attribute  Attribute to sum (default: 'value')
     *
     * @throws \Exception
     */
    public function sumDaily(array $queries = [], string $attribute = 'value', ?string $tenant = null): int
    {
        return $this->adapter->sumDaily($queries, $attribute, $tenant);
    }

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     *
     * @throws \Exception
     */
    public function sumDailyBatch(array $metrics, array $queries = [], ?string $tenant = null): array
    {
        return $this->adapter->sumDailyBatch($metrics, $queries, $tenant);
    }

    /**
     * Enable parity sampling for routed reads. At rate=0 the sampler is
     * disabled (default). At rate>0 each routed read is re-executed against
     * the raw table with the given probability and logs a `dual_read_warning`
     * entry when totals diverge by more than 1%. Pass 1.0 for every-read
     * sampling (CI use) or small values (0.01) for production canaries.
     *
     * @param  float  $rate  0.0 (off) … 1.0 (every read)
     */
    public function setDualReadSampleRate(float $rate): self
    {
        $this->adapter->setDualReadSampleRate($rate);

        return $this;
    }

    /**
     * Collect a metric into the in-memory buffer for deferred flushing.
     *
     * For event type: multiple collect() calls for the same metric are summed.
     * For gauge type: last-write-wins semantics.
     * No period fan-out — raw timestamps are used.
     *
     * @param  string  $metric  Metric name
     * @param  int  $value  Value
     * @param  string  $type  Metric type: 'event' or 'gauge'
     * @param  array<string,mixed>  $tags  Optional tags
     * @param  string|null  $tenant  Per-row tenant (shared-tables mode); when null
     *                               the adapter's own tenant is used at write time
     */
    public function collect(string $metric, int $value, string $type, array $tags = [], ?string $tenant = null): self
    {
        if (empty($metric)) {
            throw new \InvalidArgumentException('Metric name cannot be empty');
        }
        if ($value < 0) {
            throw new \InvalidArgumentException('Value cannot be negative');
        }
        if ($type !== self::TYPE_EVENT && $type !== self::TYPE_GAUGE) {
            throw new \InvalidArgumentException("Invalid metric type '{$type}'. Allowed: ".self::TYPE_EVENT.', '.self::TYPE_GAUGE);
        }

        $tagsHash = ! empty($tags) ? md5(json_encode($tags, JSON_THROW_ON_ERROR)) : '';
        // Tenant is part of the identity so a single buffer can hold many
        // tenants at once — callers no longer flush between tenant switches.
        $key = $metric.':'.$type.':'.$tagsHash.':'.($tenant ?? '');

        $entry = [
            'metric' => $metric,
            'value' => $value,
            'type' => $type,
            'tags' => $tags,
        ];
        // Only stamp the tenant when explicitly provided; otherwise the adapter
        // falls back to its own tenant (set via setTenant) for backwards compat.
        if ($tenant !== null) {
            $entry['$tenant'] = $tenant;
        }

        if ($type === self::TYPE_EVENT && isset($this->buffer[$key])) {
            // Additive: sum values for the same metric + tags + tenant combination
            $this->buffer[$key]['value'] += $value;
        } else {
            // Event (first sighting) or gauge (last-write-wins)
            $this->buffer[$key] = $entry;
        }

        $this->bufferCount++;

        return $this;
    }

    /**
     * Flush the in-memory buffer to storage.
     *
     * Separates buffered metrics into events and gauges, then writes each batch
     * to the appropriate table via addBatch(). Only entries whose batch write
     * succeeds are removed from the buffer, so a partial failure preserves
     * the unwritten metrics for retry on the next flush.
     *
     * If addBatch() throws mid-flush, any earlier successful batches have
     * already been cleared from the buffer — the exception is allowed to
     * propagate so the caller can observe the failure.
     *
     * @return bool True if all batches succeeded (or buffer was empty)
     *
     * @throws \Exception
     */
    public function flush(): bool
    {
        if (empty($this->buffer)) {
            $this->lastFlushTime = microtime(true);

            return true;
        }

        // Separate events and gauges; keep track of buffer keys so we can
        // selectively unset only the entries whose write succeeded.
        $eventKeys = [];
        $gaugeKeys = [];
        $events = [];
        $gauges = [];

        foreach ($this->buffer as $key => $entry) {
            if ($entry['type'] === self::TYPE_EVENT) {
                $events[] = $entry;
                $eventKeys[] = $key;
            } else {
                $gauges[] = $entry;
                $gaugeKeys[] = $key;
            }
        }

        $overallResult = true;

        // Flush events — clear buffer entries only on success.
        if (! empty($events)) {
            if ($this->adapter->addBatch($events, self::TYPE_EVENT)) {
                foreach ($eventKeys as $key) {
                    unset($this->buffer[$key]);
                }
            } else {
                $overallResult = false;
            }
        }

        // Flush gauges — clear buffer entries only on success.
        if (! empty($gauges)) {
            if ($this->adapter->addBatch($gauges, self::TYPE_GAUGE)) {
                foreach ($gaugeKeys as $key) {
                    unset($this->buffer[$key]);
                }
            } else {
                $overallResult = false;
            }
        }

        $this->bufferCount = count($this->buffer);
        $this->lastFlushTime = microtime(true);

        return $overallResult;
    }

    /**
     * Check if the buffer should be flushed based on thresholds.
     *
     * Returns true if either:
     * - The number of collect() calls meets the flush threshold
     * - The time since last flush exceeds the flush interval
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
     */
    public function getBufferCount(): int
    {
        return $this->bufferCount;
    }

    /**
     * Get the number of unique metric entries in the buffer.
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Set the flush threshold (number of collect() calls before flush is recommended).
     *
     * @param  int  $threshold  Must be >= 1
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
     * @param  int  $seconds  Must be >= 1
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
     */
    public function getFlushThreshold(): int
    {
        return $this->flushThreshold;
    }

    /**
     * Get the flush interval in seconds.
     */
    public function getFlushInterval(): int
    {
        return $this->flushInterval;
    }
}
