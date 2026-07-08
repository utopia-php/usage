<?php

namespace Utopia\Tests\Usage\Adapter;

use Utopia\Query\Query;
use Utopia\Usage\Accumulator;
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
 * Most tests insert delta rows (including the `-1` closes) with raw SQL to keep
 * the query under test isolated from the write path. The per-second-fold test
 * instead drives the real Accumulator -> addBatch path, which now persists
 * negative event deltas.
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

    public function testPeakOverPerSecondNetRowsCapturesBurst(): void
    {
        // A burst that rises to 4 and falls back, all inside one flush window.
        // Per-second nets: s0 +2, s1 +2 (running 4 = peak), s2 -2, s3 -1.
        // Collected through the Accumulator with foldSeconds=1 so each second
        // survives as its own net row; allowNegative lets the -1 closes persist.
        $foldMetric = 'rt-peak-persec-' . uniqid();
        $acc = new Accumulator($this->usage);

        $deltas = [
            [1, '2026-06-01 03:00:00.100'],
            [1, '2026-06-01 03:00:00.900'],
            [1, '2026-06-01 03:00:01.100'],
            [1, '2026-06-01 03:00:01.900'],
            [-1, '2026-06-01 03:00:02.100'],
            [-1, '2026-06-01 03:00:02.900'],
            [-1, '2026-06-01 03:00:03.100'],
        ];
        foreach ($deltas as [$value, $time]) {
            $acc->collect('1', $foldMetric, $value, Usage::TYPE_EVENT, [], new \DateTime($time), 1, allowNegative: true);
        }

        // Four per-second net rows (+2, +2, -2, -1), not one folded delta.
        $this->assertEquals(4, $acc->count());
        $this->assertTrue($acc->flush());

        $window = [
            Query::greaterThanEqual('time', '2026-06-01 03:00:00'),
            Query::lessThanEqual('time', '2026-06-01 03:01:00'),
        ];

        $peak = $this->usage->find('1', array_merge([
            Query::equal('metric', [$foldMetric]),
        ], $window, [UsageQuery::aggregate('peak')]), Usage::TYPE_EVENT);

        $this->assertCount(1, $peak);
        $this->assertEquals(4, $peak[0]->getValue());

        // Contrast: the same deltas folded WITHOUT a per-second key collapse to
        // a single net row (+1), whose running-sum peak is only 1 — the burst
        // to 4 is invisible. This is exactly what the per-second fold fixes.
        $flatMetric = 'rt-peak-perflush-' . uniqid();
        $accFlat = new Accumulator($this->usage);
        foreach ($deltas as [$value, $time]) {
            $accFlat->collect('1', $flatMetric, $value, Usage::TYPE_EVENT, [], new \DateTime($time), allowNegative: true);
        }
        $this->assertEquals(1, $accFlat->count());
        $this->assertTrue($accFlat->flush());

        $flatPeak = $this->usage->find('1', array_merge([
            Query::equal('metric', [$flatMetric]),
        ], $window, [UsageQuery::aggregate('peak')]), Usage::TYPE_EVENT);

        $this->assertCount(1, $flatPeak);
        $this->assertEquals(1, $flatPeak[0]->getValue());
    }

    public function testAddBatchRejectsNegativeEventByDefault(): void
    {
        // Strict by default: a negative event without the opt-in is rejected
        // at write time, so a buggy negative count never reaches storage.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Value cannot be negative');
        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'rt-neg-' . uniqid(), 'value' => -1, 'tags' => []],
        ], Usage::TYPE_EVENT);
    }

    public function testAddBatchPersistsNegativeEventWhenOptedIn(): void
    {
        // With allowNegative on the row, the -1 close persists and nets with
        // the +1 opens so the peak path sees true concurrency.
        $metric = 'rt-neg-allowed-' . uniqid();

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => $metric, 'value' => 1, 'time' => new \DateTime('2026-06-01 04:00:01'), 'tags' => [], 'allowNegative' => true],
            ['tenant' => '1', 'metric' => $metric, 'value' => 1, 'time' => new \DateTime('2026-06-01 04:00:02'), 'tags' => [], 'allowNegative' => true],
            ['tenant' => '1', 'metric' => $metric, 'value' => -1, 'time' => new \DateTime('2026-06-01 04:00:03'), 'tags' => [], 'allowNegative' => true],
        ], Usage::TYPE_EVENT));

        // Net total is +1 (SUM), peak concurrency is 2 (running 1,2,1).
        $this->assertEquals(1, $this->usage->getTotal('1', $metric, [], Usage::TYPE_EVENT));

        $peak = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 04:00:00'),
            Query::lessThanEqual('time', '2026-06-01 04:01:00'),
            UsageQuery::aggregate('peak'),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $peak);
        $this->assertEquals(2, $peak[0]->getValue());
    }
}
