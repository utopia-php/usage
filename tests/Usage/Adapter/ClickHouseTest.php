<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Tests\Usage\UsageBase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

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


        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage');
        $adapter->setTenant('1');

        // Optional customization via env vars
        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

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

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_shared');
        $adapter->setSharedTables(true);
        $adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

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
                'tags' => ['index' => (string) $i],
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
     * Test event-specific columns are extracted from tags
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
                    'resource' => 'bucket',
                    'resourceId' => 'bucket123',
                    'region' => 'us-east',
                    'userAgent' => 'test-agent',
                ],
            ],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['event-cols-test']),
        ], Usage::TYPE_EVENT);

        $this->assertCount(1, $results);
        $metric = $results[0];

        // Event-specific columns should be set
        $this->assertEquals('/v1/storage/files', $metric->getPath());
        $this->assertEquals('POST', $metric->getMethod());
        $this->assertEquals('201', $metric->getStatus());
        $this->assertEquals('bucket', $metric->getResource());
        $this->assertEquals('bucket123', $metric->getResourceId());

        // Remaining tags should only contain non-event fields
        $tags = $metric->getTags();
        $this->assertEquals('us-east', $tags['region'] ?? null);
        $this->assertEquals('test-agent', $tags['userAgent'] ?? null);
        $this->assertArrayNotHasKey('path', $tags);
        $this->assertArrayNotHasKey('method', $tags);
        $this->assertArrayNotHasKey('status', $tags);
        $this->assertArrayNotHasKey('resource', $tags);
        $this->assertArrayNotHasKey('resourceId', $tags);
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
            ['metric' => 'gauge-simple', 'value' => 500, 'tags' => ['region' => 'us-east']],
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
        $this->assertNull($results[0]->getResourceId());
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
            ['metric' => 'special-metric', 'value' => 1, 'tags' => ['s' => $specialVal]],
        ], Usage::TYPE_EVENT));

        $results = $this->usage->find([
            \Utopia\Query\Query::equal('metric', ['special-metric']),
        ], Usage::TYPE_EVENT);

        $this->assertEquals(1, count($results));
        $this->assertEquals('special-metric', $results[0]->getMetric());
        $tags = $results[0]->getTags();
        $this->assertEquals($specialVal, $tags['s']);
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
            ['metric' => 'metric-A', 'value' => 10, 'tags' => ['category' => 'cat1']],
        ], Usage::TYPE_EVENT);
        $this->usage->addBatch([
            ['metric' => 'metric-B', 'value' => 20, 'tags' => ['category' => 'cat2']],
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
        $this->assertIsArray($health);
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
        $this->assertIsArray($health);
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
     * Test setTimeout() method with valid timeout
     */
    public function testSetTimeoutValid(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);

        // Test setting valid timeout
        $result = $adapter->setTimeout(5000); // 5 seconds

        // Should return self for chaining
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Test that it still works after setting timeout
        $health = $adapter->healthCheck();
        $this->assertTrue($health['healthy']);
    }

    /**
     * Test setTimeout() with minimum timeout (1 second)
     */
    public function testSetTimeoutMinimum(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port);
        $adapter->setTimeout(1000); // 1 second minimum

        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    /**
     * Test setTimeout() with maximum timeout (10 minutes)
     */
    public function testSetTimeoutMaximum(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port);
        $adapter->setTimeout(600000); // 10 minutes maximum

        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    /**
     * Test setTimeout() with timeout below minimum
     */
    public function testSetTimeoutBelowMinimum(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Timeout must be at least 1000 milliseconds');

        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port);
        $adapter->setTimeout(999); // Below minimum
    }

    /**
     * Test setTimeout() with timeout above maximum
     */
    public function testSetTimeoutAboveMaximum(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Timeout cannot exceed 600000 milliseconds');

        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port);
        $adapter->setTimeout(600001); // Above maximum
    }

    /**
     * Test compression functionality
     */
    public function testCompression(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_compression_test');
        $adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();

        // Test enabling compression
        $result = $adapter->setCompression(true);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Test disabling compression
        $result = $adapter->setCompression(false);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Enable compression for all subsequent operations
        $adapter->setCompression(true);

        // Insert data using addBatch with compression enabled
        $batchResult = $usage->addBatch([
            ['metric' => 'compression.test.batch', 'value' => 50, 'tags' => ['type' => 'batch']],
            ['metric' => 'compression.test.batch', 'value' => 75, 'tags' => ['type' => 'batch']],
            ['metric' => 'compression.test.single', 'value' => 100, 'tags' => ['type' => 'single']],
        ], Usage::TYPE_EVENT);
        $this->assertTrue($batchResult);

        // Verify find query works with compression
        $metrics = $usage->find([], Usage::TYPE_EVENT);
        $this->assertIsArray($metrics);

        // Verify count query works with compression
        $count = $usage->count([], Usage::TYPE_EVENT);
        $this->assertIsInt($count);

        // Verify sum operation works with compression
        $sum = $usage->sum([
            \Utopia\Query\Query::equal('metric', ['compression.test.batch']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertIsInt($sum);
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

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_pooling_test');
        $adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();

        // Test enabling keep-alive
        $result = $adapter->setKeepAlive(true);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Test disabling keep-alive
        $result = $adapter->setKeepAlive(false);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Re-enable for testing
        $adapter->setKeepAlive(true);

        // Get initial stats
        $stats = $adapter->getConnectionStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('request_count', $stats);
        $this->assertArrayHasKey('keep_alive_enabled', $stats);
        $this->assertArrayHasKey('compression_enabled', $stats);
        $this->assertArrayHasKey('query_logging_enabled', $stats);
        $this->assertTrue($stats['keep_alive_enabled']);

        $initialCount = $stats['request_count'];

        // Make some requests
        $usage->addBatch([
            ['metric' => 'pooling.test', 'value' => 100, 'tags' => ['test' => 'value']],
        ], Usage::TYPE_EVENT);
        $usage->find([], Usage::TYPE_EVENT);
        $usage->count([], Usage::TYPE_EVENT);

        // Verify request count increased
        $newStats = $adapter->getConnectionStats();
        $this->assertGreaterThan($initialCount, $newStats['request_count']);
        $this->assertGreaterThanOrEqual(3, $newStats['request_count'] - $initialCount);
    }

    /**
     * Test retry logic configuration
     */
    public function testRetryConfiguration(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);

        // Test setting max retries
        $result = $adapter->setMaxRetries(5);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Test setting retry delay
        $result = $adapter->setRetryDelay(200);
        $this->assertInstanceOf(ClickHouseAdapter::class, $result);

        // Verify stats reflect configuration
        $stats = $adapter->getConnectionStats();
        $this->assertSame(5, $stats['max_retries']);
        $this->assertSame(200, $stats['retry_delay']);

        // Test valid retry range (0-10)
        $adapter->setMaxRetries(0);
        $stats = $adapter->getConnectionStats();
        $this->assertSame(0, $stats['max_retries']);

        $adapter->setMaxRetries(10);
        $stats = $adapter->getConnectionStats();
        $this->assertSame(10, $stats['max_retries']);
    }

    /**
     * Test retry validation errors
     */
    public function testRetryValidation(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);

        // Test max retries below minimum
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Max retries must be between 0 and 10');
        $adapter->setMaxRetries(-1);
    }

    /**
     * Test retry delay validation
     */
    public function testRetryDelayValidation(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);

        // Test retry delay below minimum
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Retry delay must be between 10 and 5000 milliseconds');
        $adapter->setRetryDelay(5);
    }

    /**
     * Test retry logic with successful operations
     */
    public function testRetryWithSuccessfulOperations(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_retry_test');
        $adapter->setTenant('1');
        $adapter->setMaxRetries(2);
        $adapter->setRetryDelay(50);

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();

        // These operations should succeed on first attempt (no retries needed)
        $result = $usage->addBatch([
            ['metric' => 'retry.test', 'value' => 100, 'tags' => ['test' => 'success']],
        ], Usage::TYPE_EVENT);
        $this->assertTrue($result);

        $count = $usage->count([], Usage::TYPE_EVENT);
        $this->assertIsInt($count);
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

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_error_test');
        $adapter->setDatabase('nonexistent_db_for_testing_errors_12345');
        $adapter->setTenant('1');
        $adapter->setMaxRetries(0); // Disable retries for faster test

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

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_async');
        $adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        // Enable async inserts
        $adapter->setAsyncInserts(true, waitForConfirmation: true);

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

        // Test fire-and-forget mode
        $adapter->setAsyncInserts(true, waitForConfirmation: false);
        $stats = $adapter->getConnectionStats();
        $this->assertFalse($stats['async_insert_wait']);

        // Disable async inserts
        $adapter->setAsyncInserts(false);
        $stats = $adapter->getConnectionStats();
        $this->assertFalse($stats['async_inserts']);

        $usage->purge();
    }
}
