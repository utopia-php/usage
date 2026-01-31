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

        $this->assertTrue($usage->logBatch($metrics));

        // Switch adapter scope to the metric tenant to verify the row was stored under the override
        $adapter->setTenant(2);

        $results = $usage->getByPeriod('tenant-override', '1h');

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->getTenant());

        $usage->purge(DateTime::now());
    }

    /**
    * Test logBatch with explicit batch size parameter
    */
    public function testLogBatchWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'metric-1', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-2', 'value' => 20, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-3', 'value' => 30, 'period' => '1h', 'tags' => []],
            ['metric' => 'metric-4', 'value' => 40, 'period' => '1h', 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->logBatch($metrics, 2));

        // Verify all metrics were inserted
        $results = $this->usage->find();
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    /**
     * Test logBatchCounter with explicit batch size parameter
     */
    public function testLogBatchCounterWithBatchSize(): void
    {
        $metrics = [
            ['metric' => 'counter-1', 'value' => 100, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-2', 'value' => 200, 'period' => '1h', 'tags' => []],
            ['metric' => 'counter-3', 'value' => 300, 'period' => '1h', 'tags' => []],
        ];

        // Process with batch size of 2
        $this->assertTrue($this->usage->logBatchCounter($metrics, 2));

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

        $this->assertTrue($this->usage->logBatch($metrics, 10));

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

        $this->assertTrue($this->usage->logBatchCounter($metrics));

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

        $this->assertTrue($this->usage->logBatch($metrics));

        // Aggregated metrics should sum: 5 + 10 + 15 = 30
        $sum = $this->usage->sumByPeriod('agg-test', '1h');
        $this->assertEquals(30, $sum);
    }

    /**
     * Test empty batch
     */
    public function testEmptyBatch(): void
    {
        $this->assertTrue($this->usage->logBatch([]));
        $this->assertTrue($this->usage->logBatchCounter([]));
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

        $this->assertTrue($this->usage->logBatch($metrics));

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

        $this->assertTrue($this->usage->logBatch($metrics));

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

        $this->assertTrue($this->usage->logBatch($metrics, 1000));

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

        $this->assertTrue($this->usage->logBatch($metrics, 1));

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
        $this->assertTrue($this->usage->logBatch($metrics));

        $sum = $this->usage->sumByPeriod('default-batch-test', '1h');
        $this->assertEquals(50, $sum);
    }
    /**
     * Test metrics with special characters to ensure JSON encoding/decoding is correct
     */
    public function testMetricsWithSpecialCharacters(): void
    {
        $specialVal = "Text with \n newline, \t tab, \"quote\", and unicode \u{1F600}";
        $this->assertTrue($this->usage->log('special-metric', 1, '1h', ['s' => $specialVal]));

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
        $this->usage->log('metric-A', 10, '1h', ['category' => 'cat1']);
        // metric B: value 20, time NOW
        $this->usage->log('metric-B', 20, '1h', ['category' => 'cat2']);
        // metric C: value 30, time NOW - 2 hours
        $oldTime = (new \DateTime())->modify('-2 hours');
        // We can't easily force time in log(), so we just rely on metrics created now being "newer" than this timestamp

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
}
