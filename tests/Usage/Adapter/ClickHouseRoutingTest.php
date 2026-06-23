<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Exception;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class ClickHouseRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_routing',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow('routed.metric', 100, '-5 days', ['path' => '/v1/a']);
        $this->seedHistoricalRow('routed.metric', 200, '-3 days', ['path' => '/v1/b']);
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'routed.metric', 'value' => 50, 'tags' => ['path' => '/v1/c']],
        ], Usage::TYPE_EVENT));
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $id = bin2hex(random_bytes(16));
        $rawPath = $tags['path'] ?? null;
        $path = is_string($rawPath) ? $rawPath : null;

        $sql = sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, path, tenant) VALUES ('%s', '%s', %d, '%s', %s, '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $time,
            $path === null ? 'NULL' : "'" . addslashes($path) . "'"
        );

        $this->queryRaw($this->adapter, $sql);
    }

    public function testClosedDayWindowRoutesToDaily(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'daily MV must re-aggregate to the same total as raw');
    }

    public function testInclusiveMidnightUpperBoundExcludesEndDayOnDailyRoute(): void
    {
        $this->adapter->clearRouteLog();

        $this->seedHistoricalRow('routed.metric', 9999, '-2 days +14 hours', ['path' => '/v1/late']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'daily MV must not include the end-day full-day row for inclusive-midnight upper bounds');
        $this->assertSame(300, $sum);
    }

    public function testMidDayClosedWindowFallsBackToRaw(): void
    {
        // Daily rows are stored at midnight; a mid-day caller bound
        // would exclude the partial first day and over-include the
        // last day if forwarded to the daily MV. Routing must reject
        // non-day-aligned bounds and fall through to the raw scan.
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days 12:30:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days 12:30:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testWindowStraddlesTodayRoutesHybrid(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'hybrid must equal full raw scan');
    }

    public function testHybridSumKeepsParamsDistinctWhenMetricFollowsTimeBounds(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::equal('metric', ['routed.metric']),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'hybrid sum must match the raw scan even when the metric filter is appended after the time bounds');
    }

    public function testDimensionPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::equal('path', ['/v1/a']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testFilterOnNonDailyColumnForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::equal('country', ['us']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testMidDayStartWithHybridWindowFallsBackToRaw(): void
    {
        $metric = 'routed.metric.hybrid_mid_day';
        $this->seedHistoricalRow($metric, 70, '-2 days 03:00:00', ['path' => '/v1/early']);

        $this->adapter->clearRouteLog();

        $start = (new DateTime('-2 days 14:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $sum = $this->usage->sum('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route'], 'a mid-day start with a hybrid window must fall back to raw');
        $this->assertSame(0, $sum, 'pre-start events on the same day must not be included in the result');
    }

    public function testIntervalPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testDuplicateTimeFiltersTakeTightestBound(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $startTighter = (new DateTime('-3 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endLoose = (new DateTime('+5 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endTighter = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::greaterThanEqual('time', $startTighter),
            Query::lessThanEqual('time', $endLoose),
            Query::lessThanEqual('time', $endTighter),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily', $log[0]['route']);
        $this->assertSame($startTighter, $log[0]['start']);
        $this->assertSame($endTighter, $log[0]['end']);
    }

    public function testOpenEndedWindowForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testMalformedTimeStringForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        try {
            $this->usage->sum('1', [
                Query::equal('metric', ['routed.metric']),
                Query::greaterThanEqual('time', 'not-a-date'),
                Query::lessThanEqual('time', 'not-a-date-either'),
            ], 'value', Usage::TYPE_EVENT);
        } catch (Exception $e) {
        }

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    /**
     * A purge whose filters reference a column the daily MV does not
     * carry (e.g. `path`) AND has no `time` bound must not issue an
     * unbounded delete on the daily table: that would wipe rows for
     * unrelated metrics. The raw events table still gets the narrow
     * delete; the daily MV is left untouched until the next ingest
     * cycle overwrites it.
     */
    public function testNarrowPurgeWithNoTimeBoundDoesNotWipeDailyMv(): void
    {
        $this->usage->purge('1');

        // Seed two metrics on two different days; let the daily MV
        // capture both as fully closed-day rows.
        $this->seedHistoricalRow('purge.keep', 100, '-3 days', ['path' => '/v1/keep']);
        $this->seedHistoricalRow('purge.remove', 50, '-3 days', ['path' => '/v1/remove']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        // Purge by a daily-incompatible column (`path`) with no time
        // bound. The raw delete narrows on path; the daily side has
        // no path column so the legacy logic would fall through to
        // DELETE WHERE 1=1 and wipe both metrics' daily rows.
        $this->usage->purge('1', [
            Query::equal('path', ['/v1/remove']),
        ], Usage::TYPE_EVENT);

        $this->adapter->clearRouteLog();
        $keepSum = $this->usage->sum('1', [
            Query::equal('metric', ['purge.keep']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $this->assertSame(100, $keepSum, 'unrelated daily rows must survive a narrow purge');
    }

    /**
     * Purging by `value` against the daily MV applies the predicate to
     * the SUMmed daily value, not the raw per-event value. Allowing
     * `value` as a daily-safe filter would delete unrelated aggregate
     * rows whose daily total happens to match the predicate. The
     * routing layer treats `value` as raw-only.
     */
    public function testValueFilterPurgeDoesNotMatchAggregateDailyRows(): void
    {
        $this->usage->purge('1');

        // Seed two raw rows whose values sum to 10 on the same day,
        // so the daily MV row has value = 10. A naive purge with
        // `value = 10` would delete the daily row and undercount.
        $this->seedHistoricalRow('purge.value', 4, '-3 days', ['path' => '/v1/a']);
        $this->seedHistoricalRow('purge.value', 6, '-3 days', ['path' => '/v1/b']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->usage->purge('1', [
            Query::equal('value', [10]),
        ], Usage::TYPE_EVENT);

        // value is raw-only, so the routed sum stays on raw — it
        // sees the still-present rows. The point of this test is the
        // daily MV; check it directly via sumDaily.
        $dailySum = $this->usage->sumDaily('1', [
            Query::equal('metric', ['purge.value']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame(10, $dailySum, 'daily MV row must survive a value-only purge');
    }

    private function sumRaw(string $metric, string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, '1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();
        return is_int($result) ? $result : 0;
    }
}
