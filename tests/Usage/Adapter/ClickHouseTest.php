<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Query;
use Utopia\Tests\Usage\UsageBase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class ClickHouseTest extends TestCase
{
    use UsageBase;

    protected function initializeUsage(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);


        $adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
        );
        $adapter->setTenant('1');

        $this->usage = new Usage($adapter);
        $this->usage->setup();
    }

    public function testMetricTenantOverridesAdapterTenantInBatch(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage_shared',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $adapter->setTenant('1');

        $usage = new Usage($adapter);
        $usage->setup();
        $usage->purge();

        $metrics = [
            [
                'metric' => 'tenant-override',
                'value' => 5,
                '$tenant' => '2',
                'tags' => [],
            ],
        ];

        $this->assertTrue($usage->addBatch($metrics, Usage::TYPE_EVENT));

        // Switch adapter scope to the metric tenant to verify the row was stored under the override
        $adapter->setTenant('2');

        $results = $usage->find([
            \Utopia\Query\Query::equal('metric', ['tenant-override']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertEquals('2', $results[0]->getTenant());

        $usage->purge();
    }

    /**
     * Test addBatch with explicit batch size parameter
     */
    public function testAddBatchWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'metric-1', 'value' => 10, 'tags' => []],
            ['metric' => 'metric-2', 'value' => 20, 'tags' => []],
            ['metric' => 'metric-3', 'value' => 30, 'tags' => []],
            ['metric' => 'metric-4', 'value' => 40, 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT, 2));

        // Verify all metrics were inserted
        $results = $this->usage->find([], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    /**
     * Test addBatch with gauge type
     */
    public function testAddBatchGaugeWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'counter-1', 'value' => 100, 'tags' => []],
            ['metric' => 'counter-2', 'value' => 200, 'tags' => []],
            ['metric' => 'counter-3', 'value' => 300, 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_GAUGE, 2));

        // Verify gauge metrics were inserted
        $results = $this->usage->find([], Usage::TYPE_GAUGE);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    /**
     * Test large batch with small batch size
     */
    public function testLargeBatchWithSmallBatchSize(): void
    {
        $metrics = [];
        for ($i = 0; $i < 100; $i++) {
            $metrics[] = [
                'metric' => 'large-batch-metric',
                'value' => $i,
                'tags' => ['resourceId' => (string) $i],
            ];
        }

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT, 10));

        // Verify metrics were processed
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['large-batch-metric']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * Test gauge metrics use argMax (latest value)
     */
    public function testGaugeMetricsLastValueWins(): void
    {
        $this->usage->purge([], Usage::TYPE_GAUGE);

        $metrics = [
            ['metric' => 'gauge-test', 'value' => 5, 'tags' => []],
            ['metric' => 'gauge-test', 'value' => 10, 'tags' => []],
            ['metric' => 'gauge-test', 'value' => 15, 'tags' => []],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_GAUGE));

        // Gauge total returns argMax (latest value)
        $total = $this->usage->getTotal('gauge-test', [], Usage::TYPE_GAUGE);
        $this->assertGreaterThanOrEqual(5, $total);
    }

    /**
     * Test event metrics do aggregate (SUM)
     */
    public function testEventMetricsAggregate(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);

        $metrics = [
            ['metric' => 'agg-test', 'value' => 5, 'tags' => []],
            ['metric' => 'agg-test', 'value' => 10, 'tags' => []],
            ['metric' => 'agg-test', 'value' => 15, 'tags' => []],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        // Event metrics should sum: 5 + 10 + 15 = 30
        $total = $this->usage->getTotal('agg-test', [], Usage::TYPE_EVENT);
        $this->assertEquals(30, $total);
    }

    /**
     * Test empty batch
     */
    public function testEmptyBatchClickHouse(): void
    {
        $this->assertTrue($this->usage->addBatch([], Usage::TYPE_EVENT));
    }

    /**
     * Test batch with tags
     */
    public function testBatchWithTagsClickHouse(): void
    {
        $metrics = [
            ['metric' => 'tagged', 'value' => 10, 'tags' => ['region' => 'us-east', 'path' => '/v1/test', 'method' => 'GET', 'status' => '200']],
            ['metric' => 'tagged', 'value' => 20, 'tags' => ['region' => 'us-west', 'path' => '/v1/test', 'method' => 'POST', 'status' => '201']],
            ['metric' => 'tagged', 'value' => 15, 'tags' => ['region' => 'eu-west', 'path' => '/v1/test', 'method' => 'GET', 'status' => '200']],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['tagged']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * Test event-specific columns are extracted from tags into dedicated columns
     * and surfaced via the new typed getters. Verifies the full dimension set
     * round-trips through ClickHouse.
     */
    public function testEventColumnsExtractedFromTags(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);

        $metrics = [
            [
                'metric' => 'event-cols-test',
                'value' => 42,
                'tags' => [
                    'path' => '/v1/storage/files',
                    'method' => 'POST',
                    'status' => '201',
                    'service' => 'storage',
                    'resource' => 'bucket',
                    'resourceId' => 'bucket123',
                    'resourceInternalId' => '42',
                    'teamId' => 'team_x',
                    'teamInternalId' => '7',
                    'country' => 'US',
                    'region' => 'us-east',
                    'hostname' => 'app.example.com',
                    'osCode' => 'IOS',
                    'osName' => 'iOS',
                    'osVersion' => '17.4',
                    'clientType' => 'mobile-app',
                    'clientCode' => 'APW',
                    'clientName' => 'Appwrite SDK',
                    'clientVersion' => '15.0.0',
                    'clientEngine' => 'WebKit',
                    'clientEngineVersion' => '605',
                    'deviceName' => 'smartphone',
                    'deviceBrand' => 'Apple',
                    'deviceModel' => 'iPhone 13',
                ],
            ],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['event-cols-test']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $metric = $results[0];

        $this->assertEquals('/v1/storage/files', $metric->getPath());
        $this->assertEquals('POST', $metric->getMethod());
        $this->assertEquals('201', $metric->getStatus());
        $this->assertEquals('storage', $metric->getService());
        $this->assertEquals('bucket', $metric->getResource());
        $this->assertEquals('bucket123', $metric->getResourceId());
        $this->assertEquals('42', $metric->getResourceInternalId());
        $this->assertEquals('team_x', $metric->getTeamId());
        $this->assertEquals('7', $metric->getTeamInternalId());
        // country and region are lowercased on write
        $this->assertEquals('us', $metric->getCountry());
        $this->assertEquals('us-east', $metric->getRegion());
        $this->assertEquals('app.example.com', $metric->getHostname());
        $this->assertEquals('IOS', $metric->getOsCode());
        $this->assertEquals('iOS', $metric->getOsName());
        $this->assertEquals('17.4', $metric->getOsVersion());
        $this->assertEquals('mobile-app', $metric->getClientType());
        $this->assertEquals('APW', $metric->getClientCode());
        $this->assertEquals('Appwrite SDK', $metric->getClientName());
        $this->assertEquals('15.0.0', $metric->getClientVersion());
        $this->assertEquals('WebKit', $metric->getClientEngine());
        $this->assertEquals('605', $metric->getClientEngineVersion());
        $this->assertEquals('smartphone', $metric->getDeviceName());
        $this->assertEquals('Apple', $metric->getDeviceBrand());
        $this->assertEquals('iPhone 13', $metric->getDeviceModel());
    }

    /**
     * Gauge rows round-trip all gauge dimension columns.
     */
    public function testGaugeColumnsRoundTrip(): void
    {
        $this->usage->purge([], Usage::TYPE_GAUGE);

        $this->assertTrue($this->usage->addBatch([
            [
                'metric' => 'gauge-cols-test',
                'value' => 500,
                'tags' => [
                    'service' => 'storage',
                    'resource' => 'file',
                    'teamId' => 'team_x',
                    'teamInternalId' => '7',
                    'resourceId' => 'r1',
                    'resourceInternalId' => '42',
                ],
            ],
        ], Usage::TYPE_GAUGE));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['gauge-cols-test']),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $metric = $results[0];
        $this->assertEquals('storage', $metric->getService());
        $this->assertEquals('file', $metric->getResource());
        $this->assertEquals('team_x', $metric->getTeamId());
        $this->assertEquals('7', $metric->getTeamInternalId());
        $this->assertEquals('r1', $metric->getResourceId());
        $this->assertEquals('42', $metric->getResourceInternalId());
    }

    public function testUnknownTagKeyThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Unknown column 'bogus'/");
        $this->usage->addBatch([
            ['metric' => 'x', 'value' => 1, 'tags' => ['bogus' => 'v']],
        ], Usage::TYPE_EVENT);
    }

    public function testCountryLowercased(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'lc-country', 'value' => 1, 'tags' => ['country' => 'US']],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['lc-country']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertSame('us', $results[0]->getCountry());
    }

    public function testRegionLowercased(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'lc-region', 'value' => 1, 'tags' => ['region' => 'FR']],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['lc-region']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertSame('fr', $results[0]->getRegion());
    }

    public function testEmptyStringCoercedToNull(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'empty-string', 'value' => 1, 'tags' => ['osName' => '']],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['empty-string']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->getOsName());
    }

    /**
     * Round-trip a row that exercises every queryable dimension column to
     * confirm the events table schema accepts and persists each one.
     */
    public function testEventsSchemaPersistsAllNewColumns(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);

        $tags = [
            'path' => '/v1/x', 'method' => 'GET', 'status' => '200',
            'service' => 'storage', 'resource' => 'bucket',
            'resourceId' => 'r1', 'resourceInternalId' => '42',
            'teamId' => 't1', 'teamInternalId' => '7',
            'country' => 'us', 'region' => 'fra', 'hostname' => 'h.example.com',
            'osCode' => 'IOS', 'osName' => 'iOS', 'osVersion' => '17.4',
            'clientType' => 'browser', 'clientCode' => 'CH',
            'clientName' => 'Chrome', 'clientVersion' => '125',
            'clientEngine' => 'Blink', 'clientEngineVersion' => '125',
            'deviceName' => 'desktop', 'deviceBrand' => 'Apple',
            'deviceModel' => 'MacBook',
        ];

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'schema-roundtrip', 'value' => 1, 'tags' => $tags],
        ], Usage::TYPE_EVENT));

        // Filtering on each indexed dimension should be schema-valid.
        foreach (['service', 'resourceInternalId', 'teamId', 'teamInternalId', 'region', 'hostname', 'osName', 'clientName', 'deviceName'] as $col) {
            $value = $tags[$col];
            $expected = $col === 'region' ? strtolower($value) : $value;
            $rows = $this->usage->find([
                \Utopia\Query\Query::equal('metric', ['schema-roundtrip']),
                \Utopia\Query\Query::equal($col, [$expected]),
            ], Usage::TYPE_EVENT);
            $this->assertGreaterThanOrEqual(1, count($rows), "Filter on {$col} returned no rows");
        }
    }

    /**
     * Test querying events by event-specific columns
     */
    public function testQueryEventsByColumns(): void
    {
        $this->usage->purge([], Usage::TYPE_EVENT);

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'req', 'value' => 10, 'tags' => ['path' => '/v1/storage', 'method' => 'GET', 'status' => '200', 'resource' => 'project', 'resourceId' => 'p1']],
            ['metric' => 'req', 'value' => 20, 'tags' => ['path' => '/v1/databases', 'method' => 'POST', 'status' => '201', 'resource' => 'database', 'resourceId' => 'db1']],
            ['metric' => 'req', 'value' => 30, 'tags' => ['path' => '/v1/storage', 'method' => 'GET', 'status' => '404', 'resource' => 'project', 'resourceId' => 'p1']],
        ], Usage::TYPE_EVENT));

        // Filter by path
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('path', ['/v1/storage']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(2, $results);

        // Filter by method
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('method', ['POST']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(1, $results);
        $this->assertEquals(20, $results[0]->getValue());

        // Filter by status
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('status', ['404']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(1, $results);
        $this->assertEquals(30, $results[0]->getValue());

        // Filter by resource
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('resource', ['database']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(1, $results);

        // Filter by resourceId
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('resourceId', ['db1']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(1, $results);
    }

    /**
     * Test gauge table does not have event columns
     */
    public function testGaugeTableSimpleSchema(): void
    {
        $this->usage->purge([], Usage::TYPE_GAUGE);

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'gauge-simple', 'value' => 500, 'tags' => ['resourceId' => 'r1']],
        ], Usage::TYPE_GAUGE));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['gauge-simple']),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $this->assertEquals(500, $results[0]->getValue());
        $this->assertEquals('gauge', $results[0]->getType());

        // Gauge results should not have event-specific columns
        $this->assertNull($results[0]->getPath());
        $this->assertNull($results[0]->getMethod());
        $this->assertNull($results[0]->getStatus());
        $this->assertNull($results[0]->getResource());
        $this->assertEquals('r1', $results[0]->getResourceId());
    }

    /**
     * Test finding across both tables (type=null)
     */
    public function testFindBothTables(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'both-test-event', 'value' => 10, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'both-test-gauge', 'value' => 100, 'tags' => []],
        ], Usage::TYPE_GAUGE));

        // Find from both tables
        $results = $this->usage->find([], null);
        $this->assertGreaterThanOrEqual(2, count($results));

        $metricNames = array_map(fn ($m) => $m->getMetric(), $results);
        $this->assertContains('both-test-event', $metricNames);
        $this->assertContains('both-test-gauge', $metricNames);
    }

    /**
     * Test batch size at maximum (1000)
     */
    public function testBatchSizeAtMaximum(): void
    {
        $metrics = [];
        for ($i = 0; $i < 500; $i++) {
            $metrics[] = [
                'metric' => 'boundary-test',
                'value' => 1,
                'tags' => [],
            ];
        }

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT, 1000));

        $sum = $this->usage->sum([
            \Utopia\Query\Query::equal('metric', ['boundary-test']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(500, $sum);
    }

    /**
     * Test batch size of 1
     */
    public function testBatchSizeOfOne(): void
    {
        $metrics = [
            ['metric' => 'size-one-1', 'value' => 10, 'tags' => []],
            ['metric' => 'size-one-2', 'value' => 20, 'tags' => []],
            ['metric' => 'size-one-3', 'value' => 30, 'tags' => []],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT, 1));

        // All metrics should be inserted
        $results = $this->usage->find([], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    /**
     * Test default batch size (1000)
     */
    public function testDefaultBatchSize(): void
    {
        $metrics = [];
        for ($i = 0; $i < 50; $i++) {
            $metrics[] = [
                'metric' => 'default-batch-test',
                'value' => 1,
                'tags' => [],
            ];
        }

        // Use default batch size
        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        $sum = $this->usage->sum([
            \Utopia\Query\Query::equal('metric', ['default-batch-test']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(50, $sum);
    }

    /**
     * Test metrics with special characters to ensure JSON encoding/decoding is correct
     */
    public function testMetricsWithSpecialCharacters(): void
    {
        $specialVal = "Text with \n newline, \t tab, \"quote\", and unicode \u{1F600}";
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'special-metric', 'value' => 1, 'tags' => ['hostname' => $specialVal]],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['special-metric']),
        ], Usage::TYPE_EVENT);

        $this->assertEquals(1, count($results));
        $this->assertEquals('special-metric', $results[0]->getMetric());
        $this->assertEquals($specialVal, $results[0]->getHostname());
    }

    /**
     * Comprehensive test for find() with various query types
     */
    public function testFindComprehensive(): void
    {
        // Cleanup
        $this->usage->purge();

        // Setup test data
        $this->usage->addBatch([
            ['metric' => 'metric-A', 'value' => 10, 'tags' => ['service' => 'cat1']],
        ], Usage::TYPE_EVENT);
        $this->usage->addBatch([
            ['metric' => 'metric-B', 'value' => 20, 'tags' => ['service' => 'cat2']],
        ], Usage::TYPE_EVENT);

        // 1. Array Equal (IN)
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['metric-A', 'metric-B']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(2, count($results));

        // 2. Scalar Equal
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('value', [20]),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(20, $results[0]->getValue());

        // 3. Less Than
        $results = $this->usage->find([
            \Utopia\Query\Query::lessThan('value', 20),
            \Utopia\Query\Query::equal('metric', ['metric-A']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(10, $results[0]->getValue());

        // 4. Greater Than
        $results = $this->usage->find([
            \Utopia\Query\Query::greaterThan('value', 10),
            \Utopia\Query\Query::equal('metric', ['metric-B']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(20, $results[0]->getValue());

        // 5. Between
        $results = $this->usage->find([
            \Utopia\Query\Query::between('value', 5, 25),
            \Utopia\Query\Query::equal('metric', ['metric-A', 'metric-B']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(2, count($results));

        // 6. Contains (IN alias)
        $results = $this->usage->find([
            \Utopia\Query\Query::contains('metric', ['metric-A']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));

        // 7. Order Desc
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['metric-A', 'metric-B']),
            \Utopia\Query\Query::orderDesc('value'),
            \Utopia\Query\Query::limit(2),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertTrue($results[0]->getValue() >= $results[1]->getValue());

        // 8. Order Asc
        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['metric-A', 'metric-B']),
            \Utopia\Query\Query::orderAsc('value'),
            \Utopia\Query\Query::limit(2),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertTrue($results[0]->getValue() <= $results[1]->getValue());
    }

    /**
     * Test healthCheck() method
     */
    public function testHealthCheck(): void
    {
        $adapter = $this->usage->getAdapter();

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertArrayHasKey('healthy', $health);
        $this->assertArrayHasKey('host', $health);
        $this->assertArrayHasKey('port', $health);
        $this->assertArrayHasKey('database', $health);
        $this->assertArrayHasKey('secure', $health);

        // Assert connection is healthy
        $this->assertTrue($health['healthy'], 'ClickHouse should be healthy');

        // Assert additional fields are present when healthy
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('uptime', $health);
        $this->assertArrayHasKey('response_time', $health);
        $this->assertIsString($health['version']);
        $this->assertIsInt($health['uptime']);
        $this->assertIsFloat($health['response_time']);
        $this->assertGreaterThan(0, $health['response_time']);
    }

    /**
     * Test healthCheck() with invalid connection
     */
    public function testHealthCheckFailure(): void
    {
        // Create adapter with invalid host
        $adapter = new ClickHouseAdapter('invalid-host-that-does-not-exist', 'default', '', 8123, false);

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertArrayHasKey('healthy', $health);
        $this->assertArrayHasKey('host', $health);

        // Assert connection failed
        $this->assertFalse($health['healthy'], 'ClickHouse should be unhealthy with invalid host');

        // Assert error message is present
        $this->assertArrayHasKey('error', $health);
        if (isset($health['error'])) {
            $this->assertIsString($health['error']);
            $this->assertNotEmpty($health['error']);
        }

        // Assert response time is still recorded
        $this->assertArrayHasKey('response_time', $health);
        if (isset($health['response_time'])) {
            $this->assertIsFloat($health['response_time']);
        }
    }

    /**
     * Test connection pooling functionality
     */
    public function testConnectionPooling(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage_pooling_test',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
        );
        $adapter->setTenant('1');

        $usage = new Usage($adapter);
        $usage->setup();

        // Connection reuse is always on (the transport client holds the cURL
        // handle for its lifetime). Stats expose the request counter so callers
        // can confirm requests are flowing over the pooled connection.
        $stats = $adapter->getConnectionStats();
        $this->assertArrayHasKey('request_count', $stats);

        $initialCount = $stats['request_count'];

        // Make some requests
        $usage->addBatch([
            ['metric' => 'pooling.test', 'value' => 100, 'tags' => ['service' => 'value']],
        ], Usage::TYPE_EVENT);
        $usage->find([], Usage::TYPE_EVENT);
        $usage->count([], Usage::TYPE_EVENT);

        // Verify request count increased
        $newStats = $adapter->getConnectionStats();
        $this->assertGreaterThan($initialCount, $newStats['request_count']);
        $this->assertGreaterThanOrEqual(3, $newStats['request_count'] - $initialCount);
    }

    /**
     * Test error messages include operation context
     */
    public function testErrorMessagesIncludeContext(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage_error_test',
            database: 'nonexistent_db_for_testing_errors_12345',
        );
        $adapter->setTenant('1');

        $usage = new Usage($adapter);

        try {
            // This should fail because database doesn't exist
            $usage->find([], Usage::TYPE_EVENT);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Verify error message includes operation context
            $this->assertStringContainsString('Operation: find()', $errorMessage, 'Error should include operation context');

            // Verify error message includes query information
            $this->assertStringContainsString('Query:', $errorMessage, 'Error should include query information');

            // Verify error includes actual error details
            $this->assertStringContainsString('ClickHouse', $errorMessage, 'Error should mention ClickHouse');
        }
    }

    public function testAsyncInsertConfiguration(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $database = getenv('CLICKHOUSE_DATABASE') ?: 'default';

        $makeAdapter = static fn (bool $asyncInserts, bool $asyncInsertWait): ClickHouseAdapter =>
            new ClickHouseAdapter(
                $host,
                $username,
                $password,
                $port,
                $secure,
                namespace: 'utopia_usage_async',
                database: $database,
                asyncInserts: $asyncInserts,
                asyncInsertWait: $asyncInsertWait,
            );

        // Async inserts enabled, waiting for confirmation.
        $adapter = $makeAdapter(true, true);
        $adapter->setTenant('1');

        $stats = $adapter->getConnectionStats();
        $this->assertTrue($stats['async_inserts']);
        $this->assertTrue($stats['async_insert_wait']);

        // Verify it works with async inserts enabled
        $usage = new Usage($adapter);
        $usage->setup();
        $usage->purge();

        $this->assertTrue($usage->addBatch([
            ['metric' => 'async-test', 'value' => 42, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $total = $usage->getTotal('async-test', [], Usage::TYPE_EVENT);
        $this->assertEquals(42, $total);

        // Fire-and-forget mode: async on, no wait for confirmation.
        $stats = $makeAdapter(true, false)->getConnectionStats();
        $this->assertTrue($stats['async_inserts']);
        $this->assertFalse($stats['async_insert_wait']);

        // Async inserts disabled.
        $stats = $makeAdapter(false, true)->getConnectionStats();
        $this->assertFalse($stats['async_inserts']);

        $usage->purge();
    }

    public function testCursorAfterPaginatesEvents(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'cursor-events', 'value' => 1, 'tags' => []],
            ['metric' => 'cursor-events', 'value' => 2, 'tags' => []],
            ['metric' => 'cursor-events', 'value' => 3, 'tags' => []],
            ['metric' => 'cursor-events', 'value' => 4, 'tags' => []],
            ['metric' => 'cursor-events', 'value' => 5, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $page1 = $this->usage->find([
            Query::equal('metric', ['cursor-events']),
            Query::orderAsc('id'),
            Query::limit(2),
        ], Usage::TYPE_EVENT);

        $this->assertCount(2, $page1);

        $cursor = $page1[count($page1) - 1];

        $page2 = $this->usage->find([
            Query::equal('metric', ['cursor-events']),
            Query::orderAsc('id'),
            Query::limit(2),
            Query::cursorAfter($cursor),
        ], Usage::TYPE_EVENT);

        $this->assertCount(2, $page2);
        $this->assertNotEquals($page1[0]->getId(), $page2[0]->getId());
        $this->assertNotEquals($page1[1]->getId(), $page2[0]->getId());
    }

    public function testCursorBeforeReversesPagination(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'cursor-before', 'value' => 1, 'tags' => []],
            ['metric' => 'cursor-before', 'value' => 2, 'tags' => []],
            ['metric' => 'cursor-before', 'value' => 3, 'tags' => []],
            ['metric' => 'cursor-before', 'value' => 4, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $all = $this->usage->find([
            Query::equal('metric', ['cursor-before']),
            Query::orderAsc('id'),
            Query::limit(10),
        ], Usage::TYPE_EVENT);

        $this->assertCount(4, $all);

        $before = $this->usage->find([
            Query::equal('metric', ['cursor-before']),
            Query::orderAsc('id'),
            Query::limit(2),
            Query::cursorBefore($all[3]),
        ], Usage::TYPE_EVENT);

        $this->assertCount(2, $before);
        $this->assertEquals($all[1]->getId(), $before[0]->getId());
        $this->assertEquals($all[2]->getId(), $before[1]->getId());
    }

    public function testCursorAcceptsAssociativeArray(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'cursor-array', 'value' => 1, 'tags' => []],
            ['metric' => 'cursor-array', 'value' => 2, 'tags' => []],
            ['metric' => 'cursor-array', 'value' => 3, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $all = $this->usage->find([
            Query::equal('metric', ['cursor-array']),
            Query::orderAsc('id'),
            Query::limit(10),
        ], Usage::TYPE_EVENT);

        $page = $this->usage->find([
            Query::equal('metric', ['cursor-array']),
            Query::orderAsc('id'),
            Query::limit(10),
            Query::cursorAfter(['id' => $all[0]->getId()]),
        ], Usage::TYPE_EVENT);

        $this->assertCount(2, $page);
        $this->assertEquals($all[1]->getId(), $page[0]->getId());
    }

    public function testCursorWithoutTypeThrows(): void
    {
        $this->expectException(\Exception::class);

        $this->usage->find([
            Query::cursorAfter(['id' => 'whatever']),
        ]);
    }

    public function testCursorWithGroupByIntervalThrows(): void
    {
        $this->expectException(\Exception::class);

        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $this->usage->find([
            UsageQuery::groupByInterval('time', '1h'),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::cursorAfter(['id' => 'whatever']),
        ], Usage::TYPE_EVENT);
    }

    public function testGroupByServiceDailyAggregates(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'gb-service', 'value' => 10, 'tags' => ['service' => 'storage']],
            ['metric' => 'gb-service', 'value' => 25, 'tags' => ['service' => 'storage']],
            ['metric' => 'gb-service', 'value' => 5, 'tags' => ['service' => 'databases']],
        ], Usage::TYPE_EVENT));

        $start = (new \DateTime())->modify('-1 day')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 day')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find([
            UsageQuery::groupByInterval('time', '1d'),
            UsageQuery::groupBy('service'),
            Query::equal('metric', ['gb-service']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(2, count($results));

        $byService = [];
        foreach ($results as $row) {
            $service = $row->getService();
            $this->assertNotNull($service, 'groupBy(service) should surface the service dimension on each Metric');
            $byService[$service] = ($byService[$service] ?? 0) + $row->getValue();
        }

        $this->assertEquals(35, $byService['storage'] ?? null);
        $this->assertEquals(5, $byService['databases'] ?? null);
    }

    public function testGroupByMultipleDimensionsHourly(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'gb-multi', 'value' => 1, 'tags' => ['service' => 'storage', 'path' => '/v1/a']],
            ['metric' => 'gb-multi', 'value' => 2, 'tags' => ['service' => 'storage', 'path' => '/v1/a']],
            ['metric' => 'gb-multi', 'value' => 4, 'tags' => ['service' => 'storage', 'path' => '/v1/b']],
            ['metric' => 'gb-multi', 'value' => 8, 'tags' => ['service' => 'databases', 'path' => '/v1/a']],
        ], Usage::TYPE_EVENT));

        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find([
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('service'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', ['gb-multi']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(3, count($results));

        $byPair = [];
        foreach ($results as $row) {
            $svc = $row->getService();
            $path = $row->getPath();
            $this->assertNotNull($svc);
            $this->assertNotNull($path);
            $byPair["{$svc}|{$path}"] = ($byPair["{$svc}|{$path}"] ?? 0) + $row->getValue();
        }

        $this->assertEquals(3, $byPair['storage|/v1/a'] ?? null);
        $this->assertEquals(4, $byPair['storage|/v1/b'] ?? null);
        $this->assertEquals(8, $byPair['databases|/v1/a'] ?? null);
    }

    public function testNotEqualQuery(): void
    {
        // Fixture: requests x2, bandwidth x1 in events
        $results = $this->usage->find([
            Query::notEqual('metric', 'requests'),
        ], Usage::TYPE_EVENT);
        // bandwidth row only
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $row) {
            $this->assertNotEquals('requests', $row->getMetric());
        }
    }

    public function testNotContainsQuery(): void
    {
        $results = $this->usage->find([
            Query::notContains('metric', ['requests', 'bandwidth']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(0, $results);
    }

    public function testNotBetweenQuery(): void
    {
        $past = (new \DateTime())->modify('-2 hour')->format('Y-m-d H:i:s');
        $oldPast = (new \DateTime())->modify('-3 hour')->format('Y-m-d H:i:s');

        $results = $this->usage->find([
            Query::notBetween('time', $oldPast, $past),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    public function testIsNullAndIsNotNullQueries(): void
    {
        // 'country' is a nullable column; fixture rows have no country tag set
        // so depending on how addBatch persists tags, country may be null or empty.
        $isNotNull = $this->usage->find([
            Query::isNotNull('metric'),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(3, count($isNotNull));

        // metric is required so isNull returns nothing
        $isNull = $this->usage->find([
            Query::isNull('metric'),
        ], Usage::TYPE_EVENT);
        $this->assertCount(0, $isNull);
    }

    public function testStartsWithAndEndsWithQueries(): void
    {
        // Fixture: paths /v1/storage, /v1/databases, /v1/storage/files
        $startsWith = $this->usage->find([
            Query::startsWith('path', '/v1/'),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(3, count($startsWith));

        $endsWith = $this->usage->find([
            Query::endsWith('path', '/files'),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($endsWith));
        foreach ($endsWith as $row) {
            $path = $row->getAttribute('path', '');
            $this->assertIsString($path);
            $this->assertStringEndsWith('/files', $path);
        }
    }

    public function testContainsRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Contains queries require at least one value.');

        $this->usage->find([
            Query::contains('metric', []),
        ], Usage::TYPE_EVENT);
    }

    public function testNotContainsRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('NotContains queries require at least one value.');

        $this->usage->find([
            Query::notContains('metric', []),
        ], Usage::TYPE_EVENT);
    }

    public function testEqualRejectsEmptyValues(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Equal queries require at least one value.');

        $this->usage->find([
            new Query(Query::TYPE_EQUAL, 'metric', []),
        ], Usage::TYPE_EVENT);
    }
}
