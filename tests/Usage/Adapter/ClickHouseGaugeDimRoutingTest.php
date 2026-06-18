<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Routing tests for the gauge per-dim AMT slate (by_service, by_resource).
 * Each case asserts (a) the route_decision the adapter picked and (b) that
 * the routed read returns the same latest-per-group values as a raw scan
 * against the gauges table.
 */
class ClickHouseGaugeDimRoutingTest extends TestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'gauge.routing.metric';

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_gauge_dim_routing');
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge();

        // Historical snapshots seeded directly into the raw table so the
        // MV captures them (the MV only fires on raw inserts after creation,
        // which happened during setup()).
        $this->seedHistoricalRow($this->metric, 100, '-5 days', ['service' => 'storage', 'resource' => 'file']);
        $this->seedHistoricalRow($this->metric, 200, '-4 days', ['service' => 'storage', 'resource' => 'file']);
        $this->seedHistoricalRow($this->metric, 50, '-3 days', ['service' => 'databases', 'resource' => 'database']);
        $this->seedHistoricalRow($this->metric, 80, '-3 days', ['service' => 'functions', 'resource' => 'function']);

        // One snapshot in today's partial for hybrid tests.
        $this->usage->addBatch([
            ['metric' => $this->metric, 'value' => 999, 'tags' => ['service' => 'storage', 'resource' => 'file']],
        ], Usage::TYPE_GAUGE);
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
        $reflection = new ReflectionClass($this->adapter);
        $gauges = $reflection->getMethod('getGaugesTableName');
        $gauges->setAccessible(true);
        $raw = $gauges->invoke($this->adapter);
        $gaugesTable = is_string($raw) ? $raw : '';
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbRaw = $dbProp->getValue($this->adapter);
        $database = is_string($dbRaw) ? $dbRaw : '';

        $time = (new \DateTime($modifier, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $cols = ['id', 'metric', 'value', 'time', 'tenant'];
        $vals = [
            "'{$id}'",
            "'" . addslashes($metric) . "'",
            (string) $value,
            "'{$time}'",
            "'1'",
        ];
        foreach (['service', 'resource'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$gaugesTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $sql, []);
    }

    public function testTopGaugesByServiceRoutesToServiceMv(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('gauges_daily_by_service', $log[0]['route_decision']);

        $rawByService = $this->rawTopByDim('service', $start, $end);
        $rolledByService = $this->toMap($rolled, 'service');
        $this->assertSame($rawByService, $rolledByService);
    }

    public function testTopGaugesByResourceRoutesToResourceMv(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('resource'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('gauges_daily_by_resource', $log[0]['route_decision']);

        $rawByResource = $this->rawTopByDim('resource', $start, $end);
        $rolledByResource = $this->toMap($rolled, 'resource');
        $this->assertSame($rawByResource, $rolledByResource);
    }

    public function testGaugesSubDayIntervalForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testGaugesFilterOnNonMvColumnFallsBackToRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resourceId', ['x']),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testGaugesUngroupedFallsBackToRaw(): void
    {
        $this->adapter->clearRouteLog();

        $this->usage->find([
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testGaugesWindowStraddlesTodayUsesHybridDim(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('gauges_hybrid_by_service', $log[0]['route_decision']);

        $rawByService = $this->rawTopByDim('service', $start, $end);
        $rolledByService = $this->toMap($rolled, 'service');

        foreach ($rawByService as $svc => $rawVal) {
            $this->assertArrayHasKey($svc, $rolledByService);
            $rolledVal = $rolledByService[$svc];
            $denom = $rawVal === 0 ? max(abs($rolledVal), 1) : abs($rawVal);
            $this->assertLessThan(0.0001, abs($rolledVal - $rawVal) / $denom, "hybrid value for {$svc} should match raw within 0.01%");
        }
    }

    public function testGaugesDualReadSamplerActivates(): void
    {
        $this->adapter->setDualReadSampleRate(1.0);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->find([
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $log = $this->adapter->getRouteLog();
        $this->assertNotEmpty($log);
        $this->assertSame('gauges_daily_by_service', $log[0]['route_decision']);

        $this->adapter->setDualReadSampleRate(0.0);
    }

    /**
     * @return array<string, int>
     */
    private function rawTopByDim(string $dim, string $start, string $end): array
    {
        $reflection = new ReflectionClass($this->adapter);
        $findFromTable = $reflection->getMethod('findFromTable');
        $findFromTable->setAccessible(true);
        $rowsRaw = $findFromTable->invoke($this->adapter, [
            UsageQuery::groupBy($dim),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_GAUGE);
        $this->adapter->clearRouteLog();
        $rows = is_array($rowsRaw) ? $rowsRaw : [];
        return $this->toMap($rows, $dim);
    }

    /**
     * @param array<\Utopia\Usage\Metric> $metrics
     * @return array<string, int>
     */
    private function toMap(array $metrics, string $dim): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $key = $m->getAttribute($dim);
            if (!is_string($key)) {
                continue;
            }
            $v = $m->getValue(0);
            if (is_int($v)) {
                $out[$key] = $v;
            } elseif (is_float($v)) {
                $out[$key] = (int) $v;
            }
        }
        ksort($out);
        return $out;
    }
}
