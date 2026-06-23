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
 * Routing tests for the per-dim projection slate: p_by_path, p_by_country,
 * p_by_service. Each grouped scenario asserts:
 *   (a) the totals match the raw scan, and
 *   (b) the ClickHouse optimizer picked the matching projection per
 *       `system.query_log.projections`.
 */
class ClickHouseDimRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'dim.routing.metric';

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_dim_routing',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = $this->adapter;
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow($this->metric, 10, '-5 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 20, '-4 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 30, '-3 days', ['path' => '/v1/b', 'method' => 'POST', 'status' => '201', 'service' => 'databases', 'country' => 'de']);
        $this->seedHistoricalRow($this->metric, 40, '-3 days', ['path' => '/v1/c', 'method' => 'POST', 'status' => '500', 'service' => 'functions', 'country' => 'fr']);
        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => $this->metric, 'value' => 5, 'tags' => ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
        ], Usage::TYPE_EVENT);
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
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
    public static function topNProjectionProvider(): array
    {
        return [
            'by_path'    => [['path'], 'p_by_path'],
            'by_country' => [['country'], 'p_by_country'],
            'by_service' => [['service'], 'p_by_service'],
        ];
    }

    /**
     * @dataProvider topNProjectionProvider
     * @param array<int, string> $dims
     */
    public function testTopNGroupedQueryRoutesToMatchingProjection(array $dims, string $expectedProjection): void
    {
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

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', $queries, Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertProjectionUsed($queryId, $expectedProjection);
    }

    public function testMultiDimNotInAnyProjectionFallsBackToTable(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testFilterOnExtraColumnStillRoutesToProjectionWhenColumnPresent(): void
    {
        // resource is a column on the events table but not in p_by_path's
        // projection; the optimizer cannot satisfy this query from the
        // projection and must scan the base table.
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resource', ['function']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testSubDayIntervalStillRoutesToProjection(): void
    {
        // Projections retain raw `time`, so the 1h bucket query
        // toStartOfInterval(time, 1 HOUR) can still be satisfied from
        // the projection — and that's a net win over scanning the base
        // table.
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testWindowStraddlesTodayStillRoutesToProjection(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        // Projections are derived in the same write transaction as the
        // parent insert, so straddle-today windows still route through
        // them — no hybrid plumbing needed.
        $this->assertProjectionUsed($queryId, 'p_by_path');
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
        $route = $select->invoke($this->adapter, $plan);
        return is_string($route) ? $route : '';
    }

    private function rawTotal(string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, '1', [
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
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

    private function assertProjectionUsed(string $queryId, string $projectionName): void
    {
        $projections = $this->projectionsForQueryId($queryId);
        $matches = array_filter($projections, fn (string $p): bool => str_ends_with($p, '.' . $projectionName) || $p === $projectionName);
        $this->assertNotEmpty(
            $matches,
            "expected projection {$projectionName} to fire for query_id {$queryId}; saw: " . implode(', ', $projections)
        );
    }

    private function assertNoProjectionUsed(string $queryId): void
    {
        $projections = $this->projectionsForQueryId($queryId);
        $this->assertEmpty(
            $projections,
            "expected no projection to fire for query_id {$queryId}; saw: " . implode(', ', $projections)
        );
    }

    /**
     * @return array<int, string>
     */
    private function projectionsForQueryId(string $queryId): array
    {
        $this->queryRaw($this->adapter, 'SYSTEM FLUSH LOGS');

        $escaped = addslashes($queryId);
        $sql = "SELECT projections FROM system.query_log "
            . "WHERE query_id = '{$escaped}' AND type = 'QueryFinish' "
            . "ORDER BY event_time DESC LIMIT 1 FORMAT JSON";

        $raw = $this->queryRaw($this->adapter, $sql);
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return [];
        }
        $row = $json['data'][0];
        $projections = is_array($row) ? ($row['projections'] ?? []) : [];
        $out = [];
        foreach (is_array($projections) ? $projections : [] as $p) {
            if (is_string($p)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function testIdFilterForcesRaw(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $route = $this->routeFor([
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
            Query::equal('metric', [$this->metric]),
            Query::greaterThan('value', 10),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame('raw', $route);
    }
}
