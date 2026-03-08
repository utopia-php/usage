<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Database\DateTime;
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
        $adapter->setTenant(1);

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
        $adapter->setTenant(1);

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();
        $usage->purge(DateTime::now());

        $metrics = [
            [
                'metric' => 'tenant-override',
                'value' => 5,
                'period' => '1h',
                '$tenant' => 2,
                'tags' => [],
            ],
        ];

        $this->assertTrue($usage->incrementBatch($metrics));

        // Switch adapter scope to the metric tenant to verify the row was stored under the override
        $adapter->setTenant(2);

        $results = $usage->getByPeriod('tenant-override', '1h');

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->getTenant());

        $usage->purge(DateTime::now());
    }

    /**
     * Test incrementBatch with explicit batch size parameter
     */
    public function testIncrementBatchWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'metric-1', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-2', 'value' => 20, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-3', 'value' => 30, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-4', 'value' => 40, 'period' => '1h', 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->incrementBatch($metrics, 2));

        // Verify all metrics were inserted
        $results = $this->usage->find();
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    /**
     * Test setBatch with explicit batch size parameter
     */
    public function testSetBatchWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'counter-1', 'value' => 100, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-2', 'value' => 200, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-3', 'value' => 300, 'period' => '1h', 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->setBatch($metrics, 2));

        // Verify counter metrics were inserted (they don't aggregate)
        $results = $this->usage->find();
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
                'period' => '1h',
                'tags' => ['index' => (string) $i],
            ];
        }

        $this->assertTrue($this->usage->incrementBatch($metrics, 10));

        // Verify metrics were processed (will be aggregated due to SummingMergeTree)
        $results = $this->usage->getByPeriod('large-batch-metric', '1h');
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    /**
     * Test counter metrics don't aggregate
     */
    public function testCounterMetricsNoAggregation(): void
    {
        $metrics = [
            ['metric' => 'counter-test', 'value' => 5, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-test', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-test', 'value' => 15, 'period' => '1h', 'tags' => []],
        ];

        $this->assertTrue($this->usage->setBatch($metrics));

        // Counter metrics should replace, not aggregate
        $results = $this->usage->find([]);
        $this->assertGreaterThanOrEqual(1, count($results));

        // Get the sum - should be just the last value (15) since counter replaces
        $sum = $this->usage->sumByPeriod('counter-test', '1h');
        $this->assertEquals(15, $sum);
    }

    /**
     * Test aggregated metrics do aggregate
     */
    public function testAggregatedMetricsAggregate(): void
    {
        $metrics = [
            ['metric' => 'agg-test', 'value' => 5, 'period' => '1h', 'tags' => []],
            ['metric' => 'agg-test', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'agg-test', 'value' => 15, 'period' => '1h', 'tags' => []],
        ];

        $this->assertTrue($this->usage->incrementBatch($metrics));

        // Aggregated metrics should sum: 5 + 10 + 15 = 30
        $sum = $this->usage->sumByPeriod('agg-test', '1h');
        $this->assertEquals(30, $sum);
    }

    /**
     * Test empty batch
     */
    public function testEmptyBatch(): void
    {
        $this->assertTrue($this->usage->incrementBatch([]));
        $this->assertTrue($this->usage->setBatch([]));
    }

    /**
     * Test batch with different periods
     */
    public function testBatchWithMultiplePeriods(): void
    {
        $metrics = [
            ['metric' => 'multi-period', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'multi-period', 'value' => 20, 'period' => '1d', 'tags' => []],
            ['metric' => 'multi-period', 'value' => 30, 'period' => 'inf', 'tags' => []],
        ];

        $this->assertTrue($this->usage->incrementBatch($metrics));

        // Verify each period has its own aggregated value
        $sum1h = $this->usage->sumByPeriod('multi-period', '1h');
        $sum1d = $this->usage->sumByPeriod('multi-period', '1d');
        $sumInf = $this->usage->sumByPeriod('multi-period', 'inf');

        $this->assertEquals(10, $sum1h);
        $this->assertEquals(20, $sum1d);
        $this->assertEquals(30, $sumInf);
    }

    /**
     * Test batch with tags
     */
    public function testBatchWithTags(): void
    {
        $metrics = [
            ['metric' => 'tagged', 'value' => 10, 'period' => '1h', 'tags' => ['region' => 'us-east']],
            ['metric' => 'tagged', 'value' => 20, 'period' => '1h', 'tags' => ['region' => 'us-west']],
            ['metric' => 'tagged', 'value' => 15, 'period' => '1h', 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->incrementBatch($metrics));

        // Verify metrics with different tags are separate entries
        $results = $this->usage->getByPeriod('tagged', '1h');
        $this->assertGreaterThanOrEqual(1, count($results));
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
                'period' => '1h',
                'tags' => [],
            ];
        }

        $this->assertTrue($this->usage->incrementBatch($metrics, 1000));

        $sum = $this->usage->sumByPeriod('boundary-test', '1h');
        $this->assertEquals(500, $sum);
    }

    /**
     * Test batch size of 1
     */
    public function testBatchSizeOfOne(): void
    {
        $metrics = [
            ['metric' => 'size-one-1', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'size-one-2', 'value' => 20, 'period' => '1h', 'tags' => []],
            ['metric' => 'size-one-3', 'value' => 30, 'period' => '1h', 'tags' => []],
        ];

        $this->assertTrue($this->usage->incrementBatch($metrics, 1));

        // All metrics should be inserted
        $results = $this->usage->find();
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
                'period' => '1h',
                'tags' => [],
            ];
        }

        // Use default batch size
        $this->assertTrue($this->usage->incrementBatch($metrics));

        $sum = $this->usage->sumByPeriod('default-batch-test', '1h');
        $this->assertEquals(50, $sum);
    }
    /**
     * Test metrics with special characters to ensure JSON encoding/decoding is correct
     */
    public function testMetricsWithSpecialCharacters(): void
    {
        $specialVal = "Text with \n newline, \t tab, \"quote\", and unicode \u{1F600}";
        $this->assertTrue($this->usage->incrementBatch([['metric' => 'special-metric', 'value' => 1, 'period' => '1h', 'tags' => ['s' => $specialVal]]]));

        $results = $this->usage->find([
            \Utopia\Usage\Query::equal('metric', ['special-metric']),
        ]);

        $this->assertEquals(1, count($results));
        $this->assertEquals('special-metric', $results[0]->getMetric());
        $tags = $results[0]->getTags();
        $this->assertEquals($specialVal, $tags['s']);
    }

    /**
     * Comprehensive test for find() with various query types
     */
    public function testFind(): void
    {
        // Cleanup
        $this->usage->purge(DateTime::now());

        // Setup test data
        $now = DateTime::now();
        // metric A: value 10, time NOW
        $this->usage->incrementBatch([['metric' => 'metric-A', 'value' => 10, 'period' => '1h', 'tags' => ['category' => 'cat1']]]);
        // metric B: value 20, time NOW
        $this->usage->incrementBatch([['metric' => 'metric-B', 'value' => 20, 'period' => '1h', 'tags' => ['category' => 'cat2']]]);
        // metric C: value 30, time NOW - 2 hours
        $oldTime = (new \DateTime())->modify('-2 hours');
        // We can't easily force time in incrementBatch(), so we just rely on metrics created now being "newer" than this timestamp

        // 1. Array Equal (IN)
        $results = $this->usage->find([
            \Utopia\Usage\Query::equal('metric', ['metric-A', 'metric-B']),
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));

        // 2. Scalar Equal
        $results = $this->usage->find([
            \Utopia\Usage\Query::equal('value', [20]),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(20, $results[0]->getValue());

        // 3. Less Than
        $results = $this->usage->find([
            \Utopia\Usage\Query::lessThan('value', 20),
            \Utopia\Usage\Query::equal('metric', ['metric-A']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(10, $results[0]->getValue());

        // 4. Greater Than
        $results = $this->usage->find([
            \Utopia\Usage\Query::greaterThan('value', 10),
            \Utopia\Usage\Query::equal('metric', ['metric-B']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals(20, $results[0]->getValue());

        // 5. Between
        $results = $this->usage->find([
            \Utopia\Usage\Query::between('value', 5, 25),
            \Utopia\Usage\Query::equal('metric', ['metric-A', 'metric-B']),
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));

        // 6. Contains (IN alias for non-array input logic in Query class)
        $results = $this->usage->find([
            \Utopia\Usage\Query::contains('metric', ['metric-A']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));

        // 7. Order Desc
        $results = $this->usage->find([
            \Utopia\Usage\Query::equal('metric', ['metric-A', 'metric-B']),
            \Utopia\Usage\Query::orderDesc('value'),
            \Utopia\Usage\Query::limit(2),
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));
        // First should be B (20), Second A (10)
        $this->assertTrue($results[0]->getValue() >= $results[1]->getValue());

        // 8. Order Asc
        $results = $this->usage->find([
            \Utopia\Usage\Query::equal('metric', ['metric-A', 'metric-B']),
            \Utopia\Usage\Query::orderAsc('value'),
            \Utopia\Usage\Query::limit(2),
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));
        // First should be A (10), Second B (20)
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
        // Create a new adapter instance with compression enabled
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_compression_test');
        $adapter->setTenant(1);

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

        // Insert data using incrementBatch with compression enabled
        $batchResult = $usage->incrementBatch([
            ['metric' => 'compression.test.batch', 'value' => 50, 'period' => '1h', 'tags' => ['type' => 'batch']],
            ['metric' => 'compression.test.batch', 'value' => 75, 'period' => '1h', 'tags' => ['type' => 'batch']],
            ['metric' => 'compression.test.single', 'value' => 100, 'period' => '1h', 'tags' => ['type' => 'single']],
        ]);
        $this->assertTrue($batchResult);

        // Verify find query works with compression (returns array)
        $metrics = $usage->find([]);
        $this->assertIsArray($metrics);

        // Verify count query works with compression (returns int)
        $count = $usage->count([]);
        $this->assertIsInt($count);

        // Verify sum operation works with compression (returns int)
        $sum = $usage->sumByPeriod('compression.test.batch', '1h');
        $this->assertIsInt($sum);
    }

    /**
     * Test connection pooling functionality
     */
    public function testConnectionPooling(): void
    {
        // Create a new adapter instance
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $adapter->setNamespace('utopia_usage_pooling_test');
        $adapter->setTenant(1);

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
        $usage->incrementBatch([['metric' => 'pooling.test', 'value' => 100, 'period' => '1h', 'tags' => ['test' => 'value']]]);
        $usage->find([]);
        $usage->count([]);

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
        $adapter->setTenant(1);
        $adapter->setMaxRetries(2);
        $adapter->setRetryDelay(50);

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $adapter->setDatabase($database);
        }

        $usage = new Usage($adapter);
        $usage->setup();

        // These operations should succeed on first attempt (no retries needed)
        $result = $usage->incrementBatch([['metric' => 'retry.test', 'value' => 100, 'period' => '1h', 'tags' => ['test' => 'success']]]);
        $this->assertTrue($result);

        $count = $usage->count([]);
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
        $adapter->setTenant(1);
        $adapter->setMaxRetries(0); // Disable retries for faster test

        $usage = new Usage($adapter);

        try {
            // This should fail because database doesn't exist
            $usage->find([]);
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
        $adapter->setTenant(1);

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
        $usage->purge(\Utopia\Database\DateTime::now());

        $this->assertTrue($usage->increment('async-test', 42));

        $sum = $usage->sumByPeriod('async-test', '1h');
        $this->assertEquals(42, $sum);

        // Test fire-and-forget mode
        $adapter->setAsyncInserts(true, waitForConfirmation: false);
        $stats = $adapter->getConnectionStats();
        $this->assertFalse($stats['async_insert_wait']);

        // Disable async inserts
        $adapter->setAsyncInserts(false);
        $stats = $adapter->getConnectionStats();
        $this->assertFalse($stats['async_inserts']);

        $usage->purge(\Utopia\Database\DateTime::now());
    }
}
