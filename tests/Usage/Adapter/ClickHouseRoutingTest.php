<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class ClickHouseRoutingTest extends TestCase
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

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_routing');
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge();

        $this->seedHistoricalRow('routed.metric', 100, '-5 days', ['path' => '/v1/a']);
        $this->seedHistoricalRow('routed.metric', 200, '-3 days', ['path' => '/v1/b']);
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'routed.metric', 'value' => 50, 'tags' => ['path' => '/v1/c']],
        ], Usage::TYPE_EVENT));
    }

    protected function tearDown(): void
    {
        $this->usage->purge();
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $reflection = new ReflectionClass($this->adapter);
        $getEvents = $reflection->getMethod('getEventsTableName');
        $getEvents->setAccessible(true);
        $events = $getEvents->invoke($this->adapter);
        $eventsTable = is_string($events) ? $events : '';
        $getDb = $reflection->getProperty('database');
        $getDb->setAccessible(true);
        $databaseValue = $getDb->getValue($this->adapter);
        $database = is_string($databaseValue) ? $databaseValue : '';

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $id = bin2hex(random_bytes(16));
        $rawPath = $tags['path'] ?? null;
        $path = is_string($rawPath) ? $rawPath : null;

        $rawSql = sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, path, tenant) VALUES ('%s', '%s', %d, '%s', %s, '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $time,
            $path === null ? 'NULL' : "'" . addslashes($path) . "'"
        );

        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $rawSql, []);
    }

    public function testClosedDayWindowRoutesToDaily(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum([
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'daily MV must re-aggregate to the same total as raw');
    }

    public function testWindowStraddlesTodayRoutesHybrid(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum([
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'hybrid must equal full raw scan');
    }

    public function testDimensionPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum([
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

        $this->usage->sum([
            Query::equal('metric', ['routed.metric']),
            Query::equal('country', ['us']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testHybridSumFloorsDailyLowerBoundToStartOfDay(): void
    {
        $metric = 'routed.metric.hybrid_boundary';
        $this->seedHistoricalRow($metric, 70, '-2 days 03:00:00', ['path' => '/v1/floor']);

        $this->adapter->clearRouteLog();

        $start = (new DateTime('-2 days 14:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $sum = $this->usage->sum([
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame(70, $sum, 'hybrid daily branch must include rollup row on the start day');
    }

    public function testIntervalPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum([
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

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $startTighter = (new DateTime('-3 days'))->format('Y-m-d H:i:s');
        $endLoose = (new DateTime('+5 days'))->format('Y-m-d H:i:s');
        $endTighter = (new DateTime('-1 day'))->format('Y-m-d H:i:s');

        $this->usage->sum([
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

        $this->usage->sum([
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
            $this->usage->find([
                UsageQuery::groupBy('path'),
                Query::equal('metric', ['routed.metric']),
                Query::greaterThanEqual('time', 'not-a-date'),
                Query::lessThanEqual('time', 'not-a-date-either'),
            ], Usage::TYPE_EVENT);
        } catch (Exception $e) {
        }

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    private function sumRaw(string $metric, string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();
        return is_int($result) ? $result : 0;
    }
}
