<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Routing tests for the per-dim MV slate: by_path, by_country, by_service,
 * by_method_status. Each case asserts (a) the route the adapter
 * picked and (b) that the routed read returns the same totals as a raw
 * scan.
 */
class ClickHouseDimRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'dim.routing.metric';

    protected function setUp(): void
    {
        $this->adapter = $this->makeAdapter('utopia_usage_dim_routing');
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge();

        $this->seedHistoricalRow($this->metric, 10, '-5 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 20, '-4 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 30, '-3 days', ['path' => '/v1/b', 'method' => 'POST', 'status' => '201', 'service' => 'databases', 'country' => 'de']);
        $this->seedHistoricalRow($this->metric, 40, '-3 days', ['path' => '/v1/c', 'method' => 'POST', 'status' => '500', 'service' => 'functions', 'country' => 'fr']);
        $this->usage->addBatch([
            ['metric' => $this->metric, 'value' => 5, 'tags' => ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
        ], Usage::TYPE_EVENT);
    }

    protected function tearDown(): void
    {
        $this->usage->purge();
    }

    /**
     * @param array<string, string> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $cols = ['id', 'metric', 'value', 'time', 'tenant'];
        $vals = [
            "'{$id}'",
            "'" . addslashes($metric) . "'",
            (string) $value,
            "'{$time}'",
            "'1'",
        ];
        foreach (['path', 'method', 'status', 'service', 'country'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$eventsTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $this->queryRaw($this->adapter, $sql);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function topNRoutingProvider(): array
    {
        return [
            'by_path'           => [['path'], 'daily_by_path'],
            'by_country'        => [['country'], 'daily_by_country'],
            'by_service'        => [['service'], 'daily_by_service'],
            'by_method_status'  => [['method', 'status'], 'daily_by_method_status'],
        ];
    }

    /**
     * @dataProvider topNRoutingProvider
     * @param array<int, string> $dims
     */
    public function testTopNGroupedQueryRoutesToMatchingMv(array $dims, string $expectedRoute): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queries = [];
        foreach ($dims as $dim) {
            $queries[] = UsageQuery::groupBy($dim);
        }
        $queries[] = Query::equal('metric', [$this->metric]);
        $queries[] = Query::greaterThanEqual('time', $start);
        $queries[] = Query::lessThanEqual('time', $end);
        $queries[] = Query::limit(50);

        $rolled = $this->usage->find($queries, Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame($expectedRoute, $log[0]['route']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testMultiDimNotInAnySingleMvFallsBackToRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupBy('path'),
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testFilterOnNonMvColumnFallsBackToRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resource', ['function']),
            Query::greaterThanEqual('time', (new DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testSubDayIntervalForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testWindowStraddlesTodayUsesHybridDim(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid_by_path', $log[0]['route']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testHybridFloorsDailyLowerBoundToStartOfDay(): void
    {
        $isolatedMetric = 'dim.routing.hybrid_boundary';

        $this->seedHistoricalRow($isolatedMetric, 100, '-2 days 03:00:00', [
            'path' => '/v1/floor',
            'method' => 'GET',
            'status' => '200',
            'service' => 'storage',
            'country' => 'us',
        ]);

        $this->adapter->clearRouteLog();

        $start = (new DateTime('-2 days 14:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$isolatedMetric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid_by_path', $log[0]['route']);

        $total = 0;
        foreach ($rolled as $m) {
            $v = $m->getValue(0);
            if (is_int($v)) {
                $total += $v;
            } elseif (is_float($v)) {
                $total += (int) $v;
            }
        }
        $this->assertSame(100, $total, 'daily branch must floor start to toStartOfDay so a mid-day start still picks up the day rollup');
    }

    public function testDualReadSamplerLogsWarningOnDivergence(): void
    {
        $divergentMetric = 'dim.routing.divergent';
        $this->seedHistoricalRow($divergentMetric, 10, '-5 days', [
            'path' => '/v1/divergent',
            'method' => 'GET',
            'status' => '200',
            'service' => 'storage',
            'country' => 'us',
        ]);

        $this->insertRollupRow('by_path', $divergentMetric, 9999, '-5 days', ['path' => '/v1/divergent']);

        $this->adapter->setDualReadSampleRate(1.0);
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$divergentMetric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $operations = array_column($log, 'operation');
        $this->assertContains('dual_read_warning', $operations, 'sampler should log a dual_read_warning when totals diverge');

        $this->adapter->setDualReadSampleRate(0.0);
    }

    public function testDualReadSamplerLogsWarningOnPerGroupDivergenceWithSameTotal(): void
    {
        $metric = 'dim.routing.per-group-divergent';

        $this->seedHistoricalRow($metric, 50, '-5 days', ['path' => '/v1/group-a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($metric, 50, '-5 days', ['path' => '/v1/group-b', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);

        $this->insertRollupRow('by_path', $metric, 80, '-5 days', ['path' => '/v1/group-a']);
        $this->insertRollupRow('by_path', $metric, 20, '-5 days', ['path' => '/v1/group-b']);

        $this->adapter->setDualReadSampleRate(1.0);
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $operations = array_column($log, 'operation');
        $this->assertContains(
            'dual_read_warning',
            $operations,
            'sampler must compare per-group, not just totals — the divergence here cancels out across groups'
        );

        $this->adapter->setDualReadSampleRate(0.0);
    }

    public function testDualReadSamplerDoesNotWarnOnAgreement(): void
    {
        $this->adapter->setDualReadSampleRate(1.0);
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $operations = array_column($log, 'operation');
        $this->assertNotContains('dual_read_warning', $operations, 'sampler must not log when rollup and raw agree');

        $this->adapter->setDualReadSampleRate(0.0);
    }

    /**
     * @param array<string, string> $tags
     */
    private function insertRollupRow(string $rollupName, string $metric, int $value, string $modifier, array $tags): void
    {
        $table = $this->resolveTableName($this->adapter, 'getDimRollupTableName', [$rollupName]);
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s.v');

        $cols = ['metric', 'value', 'time', 'tenant'];
        $vals = [
            "'" . addslashes($metric) . "'",
            (string) $value,
            "'{$time}'",
            "'1'",
        ];
        foreach ($tags as $tag => $tagVal) {
            $cols[] = $tag;
            $vals[] = "'" . addslashes($tagVal) . "'";
        }

        $sql = "INSERT INTO `{$database}`.`{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $this->queryRaw($this->adapter, $sql);
    }

    private function rawTotal(string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, [
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();
        return is_int($result) ? $result : 0;
    }

    /**
     * @param array<\Utopia\Usage\Metric> $metrics
     */
    private function totalOf(array $metrics): int
    {
        $sum = 0;
        foreach ($metrics as $m) {
            $v = $m->getValue(0);
            if (is_int($v)) {
                $sum += $v;
            } elseif (is_float($v)) {
                $sum += (int) $v;
            }
        }
        return $sum;
    }

    /**
     * @param array<Query|UsageQuery> $queries
     */
    private function routeFor(array $queries, string $type = Usage::TYPE_EVENT): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $extract = $reflection->getMethod('extractRoutingPlan');
        $extract->setAccessible(true);
        $select = $reflection->getMethod('selectAggregateSource');
        $select->setAccessible(true);
        $plan = $extract->invoke($this->adapter, $queries);
        $route = $select->invoke($this->adapter, $plan, $type);
        return is_string($route) ? $route : '';
    }

    public function testIdFilterForcesRaw(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $route = $this->routeFor([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('id', ['fixed-id']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame('raw', $route);
    }

    public function testValueFilterForcesRaw(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $route = $this->routeFor([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThan('value', 10),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame('raw', $route);
    }

    public function testOrderByTimeOnGroupedQueryForcesRaw(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $route = $this->routeFor([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::orderDesc('time'),
        ]);

        $this->assertSame('raw', $route);
    }

    public function testCursorAfterForcesRaw(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $route = $this->routeFor([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::cursorAfter(['$id' => 'cursor-token']),
        ]);

        $this->assertSame('raw', $route);
    }
}
