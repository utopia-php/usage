<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Routing tests for the per-dim MV slate: by_path, by_country, by_service,
 * by_method_status. Each case asserts (a) the route_decision the adapter
 * picked and (b) that the routed read returns the same totals as a raw
 * scan.
 */
class ClickHouseDimRoutingTest extends TestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'dim.routing.metric';

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_dim_routing');
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge();

        // Historical rows: the MVs only catch new INSERTs after they're
        // created, so the seed below INSERTs after setup() so each row
        // fans out to all rollups.
        $this->seedHistoricalRow($this->metric, 10, '-5 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 20, '-4 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 30, '-3 days', ['path' => '/v1/b', 'method' => 'POST', 'status' => '201', 'service' => 'databases', 'country' => 'de']);
        $this->seedHistoricalRow($this->metric, 40, '-3 days', ['path' => '/v1/c', 'method' => 'POST', 'status' => '500', 'service' => 'functions', 'country' => 'fr']);
        // One row in today's partial for hybrid tests
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
        $reflection = new ReflectionClass($this->adapter);
        $events = $reflection->getMethod('getEventsTableName');
        $events->setAccessible(true);
        $eventsRaw = $events->invoke($this->adapter);
        $eventsTable = is_string($eventsRaw) ? $eventsRaw : '';
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
        foreach (['path', 'method', 'status', 'service', 'country'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$eventsTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $sql, []);
    }

    public function testTopNByPathRoutesToPathMv(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily_by_path', $log[0]['route_decision']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testTopNByCountryRoutesToCountryMv(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily_by_country', $log[0]['route_decision']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testTopNByServiceRoutesToServiceMv(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily_by_service', $log[0]['route_decision']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testTopNByMethodStatusRoutesToCombinedMv(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('method'),
            UsageQuery::groupBy('status'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('daily_by_method_status', $log[0]['route_decision']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testMultiDimNotInAnySingleMvFallsBackToRaw(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupBy('path'),
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testFilterOnNonMvColumnFallsBackToRaw(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resource', ['function']),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testSubDayIntervalForcesRaw(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $this->usage->find([
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')),
            Query::lessThanEqual('time', (new \DateTime('-2 days'))->format('Y-m-d H:i:s')),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route_decision']);
    }

    public function testWindowStraddlesTodayUsesHybridDim(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rolled = $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid_by_path', $log[0]['route_decision']);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
    }

    public function testDualReadSamplerActivates(): void
    {
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->setDualReadSampleRate(1.0);
        $this->adapter->clearRouteLog();

        $start = (new \DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->find([
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        // At rate=1.0 the sampler always runs but only logs on >1% delta.
        // The MV is correct, so we shouldn't expect a warning — but we do
        // expect at least the routing entry.
        $log = $this->adapter->getRouteLog();
        $this->assertNotEmpty($log);
        $this->assertSame('daily_by_path', $log[0]['route_decision']);

        $this->adapter->setDualReadSampleRate(0.0);
    }

    private function rawTotal(string $start, string $end): int
    {
        $this->adapter->setUseDailyRollups(false);
        $this->adapter->clearRouteLog();
        $sum = $this->usage->sum([
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->setUseDailyRollups(true);
        $this->adapter->clearRouteLog();
        return $sum;
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
}
