<?php

namespace Utopia\Tests\Usage\Adapter;

use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Integration tests for the `aggregate('peak')` running-sum-max path.
 *
 * `realtime.connections` is emitted as `+1` on connect / `-1` on disconnect
 * and stored as an event, aggregated with SUM. A plain MAX(value) over `±1`
 * rows is meaningless — the true peak concurrent value is max(running_sum(value))
 * ordered by time. These tests exercise that path end-to-end.
 *
 * Delta rows (including the `-1` closes) are inserted with raw SQL: the public
 * addBatch() write path rejects negative values by design, so the concurrency
 * deltas are written directly to the events table here.
 */
class ClickHousePeakTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage_peak',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
        );

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
    }

    /**
     * Insert raw concurrency deltas straight into the events table.
     *
     * @param  array<int, array{value: int, time: string}>  $rows
     */
    private function insertDeltas(string $metric, array $rows): void
    {
        $table = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);
        $ref = '`'.$database.'`.`'.$table.'`';

        $tuples = [];
        foreach ($rows as $i => $row) {
            $id = $metric.'-'.$i;
            $value = (int) $row['value'];
            $time = $row['time'];
            $tuples[] = "('{$id}', '{$metric}', {$value}, '{$time}')";
        }

        $sql = "INSERT INTO {$ref} (id, metric, value, time) VALUES ".implode(', ', $tuples);
        $this->queryRaw($this->adapter, $sql);
    }

    public function test_peak_flat_aggregate(): void
    {
        $metric = 'rt-peak-flat-'.uniqid();

        // +1,+1,+1,+1,-1 → running 1,2,3,4,3 → peak = 4.
        $this->insertDeltas($metric, [
            ['value' => 1, 'time' => '2026-06-01 00:01:00'],
            ['value' => 1, 'time' => '2026-06-01 00:02:00'],
            ['value' => 1, 'time' => '2026-06-01 00:03:00'],
            ['value' => 1, 'time' => '2026-06-01 00:04:00'],
            ['value' => -1, 'time' => '2026-06-01 00:05:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::aggregate('peak'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertEquals(4, $results[0]->getValue());
    }

    public function test_peak_with_group_by_interval(): void
    {
        $metric = 'rt-peak-interval-'.uniqid();

        // Bucket 00: +1,+1 → running 1,2 → peak 2.
        // Bucket 01: +1,-1 → running 3,2 → peak 3 (carries the still-open
        //            connections from bucket 00 forward, as concurrency should).
        $this->insertDeltas($metric, [
            ['value' => 1, 'time' => '2026-06-01 00:10:00'],
            ['value' => 1, 'time' => '2026-06-01 00:20:00'],
            ['value' => 1, 'time' => '2026-06-01 01:10:00'],
            ['value' => -1, 'time' => '2026-06-01 01:20:00'],
        ]);

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 02:00:00'),
            UsageQuery::aggregate('peak'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(2, $results);

        $byHour = [];
        foreach ($results as $row) {
            $time = $row->getTime();
            $this->assertNotNull($time);
            $hour = (new \DateTime($time))->format('H');
            $byHour[$hour] = $row->getValue();
        }

        $this->assertEquals(2, $byHour['00'] ?? null);
        $this->assertEquals(3, $byHour['01'] ?? null);
    }

    public function test_peak_includes_pre_window_baseline(): void
    {
        $metric = 'rt-peak-baseline-'.uniqid();

        // Two connections opened before the window start (still open at start):
        // baseline = 2. In-window deltas +1,+1,-1 → cumulative 1,2,1 →
        // running = 2 + [1,2,1] = 3,4,3 → peak = 4. Without the baseline the
        // in-window max would understate to 2.
        $this->insertDeltas($metric, [
            ['value' => 1, 'time' => '2026-05-31 23:00:00'],
            ['value' => 1, 'time' => '2026-05-31 23:30:00'],
            ['value' => 1, 'time' => '2026-06-01 00:01:00'],
            ['value' => 1, 'time' => '2026-06-01 00:02:00'],
            ['value' => -1, 'time' => '2026-06-01 00:03:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::aggregate('peak'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertEquals(4, $results[0]->getValue());
    }

    public function test_peak_sums_interleaved_producers(): void
    {
        $metric = 'rt-peak-pods-'.uniqid();

        // Rows as if emitted by two realtime pods, interleaved in time. The
        // running sum combines them (no per-pod partition), so the peak
        // reflects the global concurrency, not any single pod's max.
        //   A:+1(01) B:+1(02) A:+1(03) B:+1(04) A:-1(05)
        //   running: 1,     2,     3,     4,     3  → peak = 4
        $this->insertDeltas($metric, [
            ['value' => 1, 'time' => '2026-06-01 00:01:00'],
            ['value' => 1, 'time' => '2026-06-01 00:02:00'],
            ['value' => 1, 'time' => '2026-06-01 00:03:00'],
            ['value' => 1, 'time' => '2026-06-01 00:04:00'],
            ['value' => -1, 'time' => '2026-06-01 00:05:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::aggregate('peak'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertEquals(4, $results[0]->getValue());
    }

    public function test_sum_aggregate_path_unchanged(): void
    {
        $metric = 'rt-peak-sum-'.uniqid();

        // Same deltas — the default `sum` aggregate must still return the net
        // total (1+1+1+1-1 = 3), proving the peak routing does not leak into
        // the existing path.
        $this->insertDeltas($metric, [
            ['value' => 1, 'time' => '2026-06-01 00:01:00'],
            ['value' => 1, 'time' => '2026-06-01 00:02:00'],
            ['value' => 1, 'time' => '2026-06-01 00:03:00'],
            ['value' => 1, 'time' => '2026-06-01 00:04:00'],
            ['value' => -1, 'time' => '2026-06-01 00:05:00'],
        ]);

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::aggregate('sum'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertEquals(3, $results[0]->getValue());
    }
}
