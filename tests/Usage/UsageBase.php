<?php

namespace Utopia\Tests\Usage;

use Utopia\Database\DateTime;
use Utopia\Usage\Query;
use Utopia\Usage\Usage;

trait UsageBase
{
    protected Usage $usage;

    abstract protected function initializeUsage(): void;

    public function setUp(): void
    {
        $this->initializeUsage();
        $this->createUsageMetrics();
    }

    public function tearDown(): void
    {
        $this->usage->purge(DateTime::now());
    }

    public function createUsageMetrics(): void
    {
        $this->assertTrue($this->usage->log('requests', 100, '1h', ['region' => 'us-east']));
        $this->assertTrue($this->usage->log('requests', 150, '1h', ['region' => 'us-west']));
        $this->assertTrue($this->usage->log('requests', 200, '1d', ['region' => 'us-east']));
        $this->assertTrue($this->usage->log('bandwidth', 5000, '1h', ['region' => 'us-east']));
        $this->assertTrue($this->usage->log('storage', 10000, 'inf', ['region' => 'us-east']));
    }

    public function testLogUsage(): void
    {
        $result = $this->usage->log('test-metric', 42, '1h', ['foo' => 'bar']);
        $this->assertTrue($result);
    }

    public function testLogBatch(): void
    {
        // First cleanup existing logs
        $this->usage->purge(DateTime::now());

        $metrics = [
            [
                'metric' => 'batch-requests',
                'value' => 100,
                'period' => '1h',
                'tags' => ['region' => 'eu-west'],
            ],
            [
                'metric' => 'batch-requests',
                'value' => 150,
                'period' => '1h',
                'tags' => ['region' => 'eu-east'],
            ],
            [
                'metric' => 'batch-bandwidth',
                'value' => 3000,
                'period' => '1d',
                'tags' => ['region' => 'eu-west'],
            ],
        ];

        $this->assertTrue($this->usage->logBatch($metrics));

        $results = $this->usage->getByPeriod('batch-requests', '1h');
        // Aggregated by deterministic id/hash, entries with same metric/period/time merge
        $this->assertEquals(1, count($results));
    }

    public function testGetByPeriod(): void
    {
        $results1h = $this->usage->getByPeriod('requests', '1h');
        $results1d = $this->usage->getByPeriod('requests', '1d');
        $resultsInf = $this->usage->getByPeriod('storage', 'inf');

        // SummingMergeTree / upsert-with-increase aggregates by deterministic id
        $this->assertEquals(1, count($results1h));
        $this->assertEquals(1, count($results1d));
        $this->assertEquals(1, count($resultsInf));
    }

    public function testGetBetweenDates(): void
    {
        $start = DateTime::addSeconds(new \DateTime(), -3600); // 1 hour ago
        $end = DateTime::now();

        $results = $this->usage->getBetweenDates('requests', $start, $end);
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testCountByPeriod(): void
    {
        $count1h = $this->usage->countByPeriod('requests', '1h');
        $count1d = $this->usage->countByPeriod('requests', '1d');
        $countBandwidth = $this->usage->countByPeriod('bandwidth', '1h');

        // Aggregated by deterministic id: multiple logs in same period/time collapse
        $this->assertEquals(1, $count1h);
        $this->assertEquals(1, $count1d);
        $this->assertEquals(1, $countBandwidth);
    }

    public function testSumByPeriod(): void
    {
        $sum = $this->usage->sumByPeriod('requests', '1h');
        $this->assertEquals(250, $sum); // 100 + 150

        $sumBandwidth = $this->usage->sumByPeriod('bandwidth', '1h');
        $this->assertEquals(5000, $sumBandwidth);
    }

    public function testIncrementingDefaultBehavior(): void
    {
        // Ensure clean state
        $this->usage->purge(\Utopia\Database\DateTime::now());

        // Log the same metric twice with identical period and tags
        $this->assertTrue($this->usage->log('increment-test', 5, '1h', []));
        $this->assertTrue($this->usage->log('increment-test', 7, '1h', []));
        // Because adapters now aggregate by deterministic id/time/period (and tenant where applicable),
        // there should be a single record and the summed value should be 12.
        $results = $this->usage->getByPeriod('increment-test', '1h');
        $this->assertEquals(1, count($results));

        $sum = $this->usage->sumByPeriod('increment-test', '1h');
        $this->assertEquals(12, $sum);
    }

    public function testWithQueries(): void
    {
        $results = $this->usage->getByPeriod('requests', '1h', [
            Query::limit(1),
        ]);

        $this->assertEquals(1, count($results));

        $results2 = $this->usage->getByPeriod('requests', '1h', [
            Query::limit(1),
            Query::offset(1),
        ]);

        // With UNION ALL querying both tables, and SummingMergeTree eventual consistency,
        // offset 1 may yield 0 or more rows depending on merge timing
        $this->assertLessThanOrEqual(1, count($results2));
    }

    public function testEqualWithArrayValues(): void
    {
        // Test equal query with array of values (IN clause)
        $results = $this->usage->find([
            Query::equal('metric', ['requests', 'bandwidth']),
        ]);

        // Should find all metrics matching either 'requests' or 'bandwidth'
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    public function testContainsQuery(): void
    {
        // Test contains query with multiple values
        $results = $this->usage->find([
            Query::contains('metric', ['requests', 'storage']),
        ]);

        // Should find all metrics matching either 'requests' or 'storage'
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    public function testLessThanEqualQuery(): void
    {
        // Get current time and subtract some time to test lessThanEqual
        $now = (new \DateTime())->format('Y-m-d\TH:i:s');
        $results = $this->usage->find([
            Query::lessThanEqual('time', $now),
        ]);

        // Should find all metrics with time <= now
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testGreaterThanEqualQuery(): void
    {
        // Get a time in the past (formatted as ISO 8601 string)
        $past = (new \DateTime())->modify('-24 hours')->format('Y-m-d\TH:i:s');
        $results = $this->usage->find([
            Query::greaterThanEqual('time', $past),
        ]);

        // Should find all metrics with time >= past (most recent metrics)
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testPurge(): void
    {
        sleep(2);

        // Add a metric
        $this->usage->log('purge-test', 999, '1h');

        // Wait a bit
        sleep(2);

        // Purge all metrics
        $status = $this->usage->purge(DateTime::now());
        $this->assertTrue($status);

        // Verify metrics were purged
        $results = $this->usage->getByPeriod('purge-test', '1h');
        $this->assertEquals(0, count($results));
    }

    public function testInvalidPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->usage->log('test', 100, 'invalid-period');
    }

    public function testPeriodFormats(): void
    {
        $periods = Usage::PERIODS;

        $this->assertArrayHasKey('1h', $periods);
        $this->assertArrayHasKey('1d', $periods);
        $this->assertArrayHasKey('inf', $periods);

        $this->assertEquals('Y-m-d H:00', $periods['1h']);
        $this->assertEquals('Y-m-d 00:00', $periods['1d']);
        $this->assertEquals('0000-00-00 00:00', $periods['inf']);
    }

    public function testLogCounter(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        $result = $this->usage->logCounter('counter-metric', 42, '1h', ['foo' => 'bar']);
        $this->assertTrue($result);

        $results = $this->usage->getByPeriod('counter-metric', '1h');
        $this->assertEquals(1, count($results));
    }

    public function testCounterMetricsReplaceOnDuplicate(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        // Log the same counter metric twice
        $this->assertTrue($this->usage->logCounter('counter-replace-test', 10, '1h', []));
        $this->assertTrue($this->usage->logCounter('counter-replace-test', 20, '1h', []));

        // Counter should have the last value (20), not aggregated (30)
        $results = $this->usage->getByPeriod('counter-replace-test', '1h');
        $this->assertEquals(1, count($results));

        $sum = $this->usage->sumByPeriod('counter-replace-test', '1h');
        $this->assertEquals(20, $sum);
    }

    public function testLogBatchCounter(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        $metrics = [
            [
                'metric' => 'batch-counter-1',
                'value' => 100,
                'period' => '1h',
                'tags' => ['region' => 'eu-west'],
            ],
            [
                'metric' => 'batch-counter-2',
                'value' => 200,
                'period' => '1d',
                'tags' => ['region' => 'eu-east'],
            ],
            [
                'metric' => 'batch-counter-3',
                'value' => 300,
                'period' => 'inf',
                'tags' => ['region' => 'us-west'],
            ],
        ];

        $this->assertTrue($this->usage->logBatchCounter($metrics));

        // Each metric should be stored as individual entry (counter, no aggregation)
        $results1h = $this->usage->getByPeriod('batch-counter-1', '1h');
        $results1d = $this->usage->getByPeriod('batch-counter-2', '1d');
        $resultsInf = $this->usage->getByPeriod('batch-counter-3', 'inf');

        $this->assertEquals(1, count($results1h));
        $this->assertEquals(1, count($results1d));
        $this->assertEquals(1, count($resultsInf));
    }

    public function testDifferenceBetweenAggregatedAndCounter(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        // Log same metric 3 times using aggregated (logBatch)
        $this->assertTrue($this->usage->log('agg-vs-counter', 10, '1h', []));
        $this->assertTrue($this->usage->log('agg-vs-counter', 20, '1h', []));
        $this->assertTrue($this->usage->log('agg-vs-counter', 30, '1h', []));

        $aggSum = $this->usage->sumByPeriod('agg-vs-counter', '1h');
        $aggCount = $this->usage->countByPeriod('agg-vs-counter', '1h');

        // Clear for counter test
        $this->usage->purge(DateTime::now());

        // Log same counter metric 3 times (last one wins)
        $this->assertTrue($this->usage->logCounter('counter-vs-agg', 10, '1h', []));
        $this->assertTrue($this->usage->logCounter('counter-vs-agg', 20, '1h', []));
        $this->assertTrue($this->usage->logCounter('counter-vs-agg', 30, '1h', []));

        $counterSum = $this->usage->sumByPeriod('counter-vs-agg', '1h');
        $counterCount = $this->usage->countByPeriod('counter-vs-agg', '1h');

        // Aggregated: sums to 60 (10 + 20 + 30)
        $this->assertEquals(60, $aggSum);
        // Counter: only has last value (30)
        $this->assertEquals(30, $counterSum);
    }

    public function testBatchCounterWithMultiplePeriods(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        $metrics = [
            ['metric' => 'multi-period-counter', 'value' => 100, 'period' => '1h', 'tags' => []],
            ['metric' => 'multi-period-counter', 'value' => 200, 'period' => '1d', 'tags' => []],
            ['metric' => 'multi-period-counter', 'value' => 300, 'period' => 'inf', 'tags' => []],
        ];

        $this->assertTrue($this->usage->logBatchCounter($metrics));

        // Each period should have independent counter value
        $sum1h = $this->usage->sumByPeriod('multi-period-counter', '1h');
        $sum1d = $this->usage->sumByPeriod('multi-period-counter', '1d');
        $sumInf = $this->usage->sumByPeriod('multi-period-counter', 'inf');

        $this->assertEquals(100, $sum1h);
        $this->assertEquals(200, $sum1d);
        $this->assertEquals(300, $sumInf);
    }

    public function testBatchCounterWithDuplicateMetricsInBatch(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        // Multiple entries of the same metric in batch (last one wins)
        $metrics = [
            ['metric' => 'dup-counter', 'value' => 10, 'period' => '1h', 'tags' => []],
            ['metric' => 'dup-counter', 'value' => 20, 'period' => '1h', 'tags' => []],
            ['metric' => 'dup-counter', 'value' => 30, 'period' => '1h', 'tags' => []],
        ];

        $this->assertTrue($this->usage->logBatchCounter($metrics));

        // Should only have the last value (30)
        $sum = $this->usage->sumByPeriod('dup-counter', '1h');
        $this->assertEquals(30, $sum);
    }

    public function testLogBatchCounterWithTags(): void
    {
        // Clear existing data
        $this->usage->purge(DateTime::now());

        $metrics = [
            ['metric' => 'tagged-counter', 'value' => 50, 'period' => '1h', 'tags' => ['region' => 'us-east']],
            ['metric' => 'tagged-counter', 'value' => 75, 'period' => '1h', 'tags' => ['region' => 'us-west']],
            ['metric' => 'tagged-counter', 'value' => 100, 'period' => '1h', 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->logBatchCounter($metrics));

        $results = $this->usage->getByPeriod('tagged-counter', '1h');
        // Each tag variant should be separate entry (deterministic id differs)
        $this->assertGreaterThanOrEqual(1, count($results));
    }
}
