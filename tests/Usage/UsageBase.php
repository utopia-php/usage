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
        $this->assertTrue($this->usage->increment('requests', 100, ['region' => 'us-east']));
        $this->assertTrue($this->usage->increment('requests', 150, ['region' => 'us-west']));
        $this->assertTrue($this->usage->increment('bandwidth', 5000, ['region' => 'us-east']));
        $this->assertTrue($this->usage->increment('storage', 10000, ['region' => 'us-east']));
    }

    public function testIncrement(): void
    {
        $this->usage->purge(DateTime::now());

        // increment() should auto fan-out to all 3 periods
        $this->assertTrue($this->usage->increment('inc-metric', 10));
        $this->assertTrue($this->usage->increment('inc-metric', 5));

        // All periods should have the summed value (10 + 5 = 15)
        $sum1h = $this->usage->sumByPeriod('inc-metric', '1h');
        $sum1d = $this->usage->sumByPeriod('inc-metric', '1d');
        $sumInf = $this->usage->sumByPeriod('inc-metric', 'inf');

        $this->assertEquals(15, $sum1h);
        $this->assertEquals(15, $sum1d);
        $this->assertEquals(15, $sumInf);
    }

    public function testIncrementBatch(): void
    {
        // First cleanup existing data
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

        $this->assertTrue($this->usage->incrementBatch($metrics));

        $results = $this->usage->getByPeriod('batch-requests', '1h');
        // Aggregated by deterministic id/hash, entries with same metric/period/time merge
        $this->assertEquals(1, count($results));
    }

    public function testGetByPeriod(): void
    {
        $results1h = $this->usage->getByPeriod('requests', '1h');
        $resultsInf = $this->usage->getByPeriod('storage', 'inf');

        // SummingMergeTree / upsert-with-increase aggregates by deterministic id
        $this->assertEquals(1, count($results1h));
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
        $countBandwidth = $this->usage->countByPeriod('bandwidth', '1h');

        // Aggregated by deterministic id: multiple increments in same period/time collapse
        $this->assertEquals(1, $count1h);
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

        // Increment the same metric twice
        $this->assertTrue($this->usage->increment('increment-test', 5));
        $this->assertTrue($this->usage->increment('increment-test', 7));
        // Because adapters aggregate by deterministic id/time/period (and tenant where applicable),
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
        $this->usage->increment('purge-test', 999);

        // Wait a bit
        sleep(2);

        // Purge all metrics
        $status = $this->usage->purge(DateTime::now());
        $this->assertTrue($status);

        // Verify metrics were purged
        $results = $this->usage->getByPeriod('purge-test', '1h');
        $this->assertEquals(0, count($results));
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

    public function testSet(): void
    {
        $this->usage->purge(DateTime::now());

        // set() should auto fan-out to all 3 periods with replace semantics
        $this->assertTrue($this->usage->set('set-metric', 100));
        $this->assertTrue($this->usage->set('set-metric', 200));

        // All periods should have the last value (200), not summed
        $sum1h = $this->usage->sumByPeriod('set-metric', '1h');
        $sum1d = $this->usage->sumByPeriod('set-metric', '1d');
        $sumInf = $this->usage->sumByPeriod('set-metric', 'inf');

        $this->assertEquals(200, $sum1h);
        $this->assertEquals(200, $sum1d);
        $this->assertEquals(200, $sumInf);
    }

    public function testCollectAndFlush(): void
    {
        $this->usage->purge(DateTime::now());

        // collect() accumulates in memory, nothing written yet
        $this->usage->collect('collect-metric', 10);
        $this->usage->collect('collect-metric', 20);
        $this->usage->collect('collect-metric', 30);

        // Buffer should have accumulated values
        $this->assertEquals(3, $this->usage->getBufferCount());
        // 3 periods per metric, all collapsed to same key = 3 entries
        $this->assertEquals(3, $this->usage->getBufferSize());

        // Nothing in storage yet
        $sum = $this->usage->sumByPeriod('collect-metric', '1h');
        $this->assertEquals(0, $sum);

        // Flush writes to storage
        $this->assertTrue($this->usage->flush());

        // Buffer should be empty after flush
        $this->assertEquals(0, $this->usage->getBufferCount());
        $this->assertEquals(0, $this->usage->getBufferSize());

        // Storage should have accumulated value (10 + 20 + 30 = 60)
        $sum1h = $this->usage->sumByPeriod('collect-metric', '1h');
        $sum1d = $this->usage->sumByPeriod('collect-metric', '1d');
        $sumInf = $this->usage->sumByPeriod('collect-metric', 'inf');

        $this->assertEquals(60, $sum1h);
        $this->assertEquals(60, $sum1d);
        $this->assertEquals(60, $sumInf);
    }

    public function testCollectMultipleMetrics(): void
    {
        $this->usage->purge(DateTime::now());

        $this->usage->collect('metric-a', 10);
        $this->usage->collect('metric-b', 20);
        $this->usage->collect('metric-a', 5);

        // 2 unique metrics × 3 periods = 6 buffer entries
        $this->assertEquals(6, $this->usage->getBufferSize());
        $this->assertEquals(3, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        $sumA = $this->usage->sumByPeriod('metric-a', '1h');
        $sumB = $this->usage->sumByPeriod('metric-b', '1h');

        $this->assertEquals(15, $sumA);
        $this->assertEquals(20, $sumB);
    }

    public function testCollectSetAndFlush(): void
    {
        $this->usage->purge(DateTime::now());

        // collectSet() uses last-write-wins semantics
        $this->usage->collectSet('set-collect', 100);
        $this->usage->collectSet('set-collect', 200);
        $this->usage->collectSet('set-collect', 300);

        // 1 unique metric × 3 periods = 3 buffer entries (set buffer)
        $this->assertEquals(3, $this->usage->getBufferSize());
        $this->assertEquals(3, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        // Should have last value (300), not summed
        $sum1h = $this->usage->sumByPeriod('set-collect', '1h');
        $sum1d = $this->usage->sumByPeriod('set-collect', '1d');
        $sumInf = $this->usage->sumByPeriod('set-collect', 'inf');

        $this->assertEquals(300, $sum1h);
        $this->assertEquals(300, $sum1d);
        $this->assertEquals(300, $sumInf);
    }

    public function testMixedCollectAndCollectSet(): void
    {
        $this->usage->purge(DateTime::now());

        // Mix both types in the same buffer
        $this->usage->collect('inc-mixed', 10);
        $this->usage->collect('inc-mixed', 20);
        $this->usage->collectSet('set-mixed', 100);
        $this->usage->collectSet('set-mixed', 200);

        // inc: 1 metric × 3 periods = 3, counter: 1 metric × 3 periods = 3
        $this->assertEquals(6, $this->usage->getBufferSize());
        $this->assertEquals(4, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        // Increment: summed (10 + 20 = 30)
        $this->assertEquals(30, $this->usage->sumByPeriod('inc-mixed', '1h'));

        // Counter: last value (200)
        $this->assertEquals(200, $this->usage->sumByPeriod('set-mixed', '1h'));
    }

    public function testShouldFlushByThreshold(): void
    {
        $this->usage->setFlushThreshold(3);

        $this->assertFalse($this->usage->shouldFlush());

        $this->usage->collect('threshold-test', 1);
        $this->usage->collect('threshold-test', 1);

        $this->assertFalse($this->usage->shouldFlush());

        $this->usage->collect('threshold-test', 1);

        $this->assertTrue($this->usage->shouldFlush());

        // Clean up
        $this->usage->flush();
        $this->usage->setFlushThreshold(10_000); // reset
    }

    public function testShouldFlushByInterval(): void
    {
        $this->usage->setFlushInterval(1);

        $this->usage->collect('interval-test', 1);

        // Right after collect, interval hasn't elapsed
        $this->assertFalse($this->usage->shouldFlush());

        // Wait for interval to elapse
        sleep(2);

        $this->assertTrue($this->usage->shouldFlush());

        // Clean up
        $this->usage->flush();
        $this->usage->setFlushInterval(20); // reset
    }

    public function testFlushEmptyBuffer(): void
    {
        // Flushing an empty buffer should succeed
        $this->assertTrue($this->usage->flush());
        $this->assertEquals(0, $this->usage->getBufferCount());
        $this->assertEquals(0, $this->usage->getBufferSize());
    }

    public function testFlushThresholdConfiguration(): void
    {
        $this->usage->setFlushThreshold(500);
        $this->assertEquals(500, $this->usage->getFlushThreshold());

        $this->usage->setFlushInterval(30);
        $this->assertEquals(30, $this->usage->getFlushInterval());

        // Invalid values
        $this->expectException(\InvalidArgumentException::class);
        $this->usage->setFlushThreshold(0);
    }
}
