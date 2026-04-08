<?php

namespace Utopia\Tests\Usage;

use Utopia\Query\Query;
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
        $this->usage->purge();
    }

    public function createUsageMetrics(): void
    {
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'requests', 'value' => 100, 'type' => 'event', 'tags' => ['region' => 'us-east']],
            ['metric' => 'requests', 'value' => 150, 'type' => 'event', 'tags' => ['region' => 'us-west']],
            ['metric' => 'bandwidth', 'value' => 5000, 'type' => 'event', 'tags' => ['region' => 'us-east']],
            ['metric' => 'storage', 'value' => 10000, 'type' => 'gauge', 'tags' => ['region' => 'us-east']],
        ]));
    }

    public function testAddBatchEvent(): void
    {
        $this->usage->purge();

        // addBatch with event type — values should sum
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'add-metric', 'value' => 10, 'type' => 'event', 'tags' => []],
            ['metric' => 'add-metric', 'value' => 5, 'type' => 'event', 'tags' => []],
        ]));

        $sum = $this->usage->sum([
            Query::equal('metric', ['add-metric']),
        ]);
        $this->assertEquals(15, $sum);
    }

    public function testAddBatchGauge(): void
    {
        $this->usage->purge();

        // addBatch with gauge type
        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'gauge-metric', 'value' => 100, 'type' => 'gauge', 'tags' => []],
            ['metric' => 'gauge-metric', 'value' => 200, 'type' => 'gauge', 'tags' => []],
        ]));

        // getTotal for gauge returns latest value (argMax)
        $total = $this->usage->getTotal('gauge-metric');
        $this->assertGreaterThanOrEqual(100, $total);
    }

    public function testAddBatchWithBatchSize(): void
    {
        $this->usage->purge();

        $metrics = [
            ['metric' => 'batch-requests', 'value' => 100, 'type' => 'event', 'tags' => ['region' => 'eu-west']],
            ['metric' => 'batch-requests', 'value' => 150, 'type' => 'event', 'tags' => ['region' => 'eu-east']],
            ['metric' => 'batch-bandwidth', 'value' => 3000, 'type' => 'event', 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, 2));

        $results = $this->usage->find([
            Query::equal('metric', ['batch-requests']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testFind(): void
    {
        $results = $this->usage->find([
            Query::equal('metric', ['requests']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testFindWithTimeRange(): void
    {
        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->format('Y-m-d\TH:i:s');

        $results = $this->usage->find([
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testCount(): void
    {
        $count = $this->usage->count([
            Query::equal('metric', ['requests']),
        ]);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testSum(): void
    {
        $sum = $this->usage->sum([
            Query::equal('metric', ['requests']),
        ]);
        $this->assertEquals(250, $sum); // 100 + 150
    }

    public function testGetTotal(): void
    {
        $total = $this->usage->getTotal('requests');
        $this->assertEquals(250, $total); // event: SUM

        $total = $this->usage->getTotal('storage');
        $this->assertEquals(10000, $total); // gauge: argMax (latest)
    }

    public function testGetTotalBatch(): void
    {
        $totals = $this->usage->getTotalBatch(['requests', 'bandwidth', 'storage']);

        $this->assertIsArray($totals);
        $this->assertArrayHasKey('requests', $totals);
        $this->assertArrayHasKey('bandwidth', $totals);
        $this->assertArrayHasKey('storage', $totals);

        $this->assertEquals(250, $totals['requests']);
        $this->assertEquals(5000, $totals['bandwidth']);
        $this->assertEquals(10000, $totals['storage']);
    }

    public function testGetTotalBatchWithMissingMetric(): void
    {
        $totals = $this->usage->getTotalBatch(['requests', 'nonexistent-metric']);

        $this->assertEquals(250, $totals['requests']);
        $this->assertEquals(0, $totals['nonexistent-metric']);
    }

    public function testGetTotalBatchEmpty(): void
    {
        $totals = $this->usage->getTotalBatch([]);
        $this->assertIsArray($totals);
        $this->assertEmpty($totals);
    }

    public function testGetTimeSeries(): void
    {
        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        $results = $this->usage->getTimeSeries(
            ['requests'],
            '1h',
            $start,
            $end,
        );

        $this->assertIsArray($results);
        $this->assertArrayHasKey('requests', $results);
        $this->assertArrayHasKey('total', $results['requests']);
        $this->assertArrayHasKey('data', $results['requests']);
        $this->assertGreaterThanOrEqual(0, $results['requests']['total']);
    }

    public function testGetTimeSeriesMultipleMetrics(): void
    {
        $start = (new \DateTime())->modify('-1 day')->format('Y-m-d H:i:s');
        $end = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i:s');

        $results = $this->usage->getTimeSeries(
            ['requests', 'bandwidth'],
            '1d',
            $start,
            $end,
        );

        $this->assertArrayHasKey('requests', $results);
        $this->assertArrayHasKey('bandwidth', $results);
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
        $now = (new \DateTime())->format('Y-m-d\TH:i:s');
        $results = $this->usage->find([
            Query::lessThanEqual('time', $now),
        ]);

        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testGreaterThanEqualQuery(): void
    {
        $past = (new \DateTime())->modify('-24 hours')->format('Y-m-d\TH:i:s');
        $results = $this->usage->find([
            Query::greaterThanEqual('time', $past),
        ]);

        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testPurge(): void
    {
        sleep(2);

        $this->usage->addBatch([
            ['metric' => 'purge-test', 'value' => 999, 'type' => 'event', 'tags' => []],
        ]);

        sleep(2);

        $status = $this->usage->purge();
        $this->assertTrue($status);

        $results = $this->usage->find([
            Query::equal('metric', ['purge-test']),
        ]);
        $this->assertEquals(0, count($results));
    }

    public function testPurgeWithQueries(): void
    {
        $this->usage->purge();

        $this->assertTrue($this->usage->addBatch([
            ['metric' => 'purge-keep', 'value' => 10, 'type' => 'event', 'tags' => []],
            ['metric' => 'purge-remove', 'value' => 20, 'type' => 'event', 'tags' => []],
        ]));

        // Purge only the 'purge-remove' metric
        $status = $this->usage->purge([
            Query::equal('metric', ['purge-remove']),
        ]);
        $this->assertTrue($status);

        // 'purge-remove' should be gone
        $sum = $this->usage->sum([
            Query::equal('metric', ['purge-remove']),
        ]);
        $this->assertEquals(0, $sum);

        // 'purge-keep' should still exist
        $sum = $this->usage->sum([
            Query::equal('metric', ['purge-keep']),
        ]);
        $this->assertEquals(10, $sum);
    }

    public function testCollectAndFlush(): void
    {
        $this->usage->purge();

        // collect() accumulates in memory, nothing written yet
        $this->usage->collect('collect-metric', 10, Usage::TYPE_EVENT);
        $this->usage->collect('collect-metric', 20, Usage::TYPE_EVENT);
        $this->usage->collect('collect-metric', 30, Usage::TYPE_EVENT);

        // Buffer should have accumulated values
        $this->assertEquals(3, $this->usage->getBufferCount());
        // 1 unique metric:type key = 1 buffer entry (events sum)
        $this->assertEquals(1, $this->usage->getBufferSize());

        // Nothing in storage yet
        $sum = $this->usage->sum([
            Query::equal('metric', ['collect-metric']),
        ]);
        $this->assertEquals(0, $sum);

        // Flush writes to storage
        $this->assertTrue($this->usage->flush());

        // Buffer should be empty after flush
        $this->assertEquals(0, $this->usage->getBufferCount());
        $this->assertEquals(0, $this->usage->getBufferSize());

        // Storage should have accumulated value (10 + 20 + 30 = 60)
        $sum = $this->usage->sum([
            Query::equal('metric', ['collect-metric']),
        ]);
        $this->assertEquals(60, $sum);
    }

    public function testCollectMultipleMetrics(): void
    {
        $this->usage->purge();

        $this->usage->collect('metric-a', 10, Usage::TYPE_EVENT);
        $this->usage->collect('metric-b', 20, Usage::TYPE_EVENT);
        $this->usage->collect('metric-a', 5, Usage::TYPE_EVENT);

        // 2 unique metric:type keys = 2 buffer entries
        $this->assertEquals(2, $this->usage->getBufferSize());
        $this->assertEquals(3, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        $sumA = $this->usage->sum([
            Query::equal('metric', ['metric-a']),
        ]);
        $sumB = $this->usage->sum([
            Query::equal('metric', ['metric-b']),
        ]);

        $this->assertEquals(15, $sumA);
        $this->assertEquals(20, $sumB);
    }

    public function testCollectGaugeAndFlush(): void
    {
        $this->usage->purge();

        // collect with gauge type uses last-write-wins semantics
        $this->usage->collect('gauge-collect', 100, Usage::TYPE_GAUGE);
        $this->usage->collect('gauge-collect', 200, Usage::TYPE_GAUGE);
        $this->usage->collect('gauge-collect', 300, Usage::TYPE_GAUGE);

        // 1 unique metric:type key = 1 buffer entry (gauge: last-write-wins)
        $this->assertEquals(1, $this->usage->getBufferSize());
        $this->assertEquals(3, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        // Should have last value (300), not summed
        $total = $this->usage->getTotal('gauge-collect');
        $this->assertEquals(300, $total);
    }

    public function testMixedCollectEventAndGauge(): void
    {
        $this->usage->purge();

        // Mix both types in the same buffer
        $this->usage->collect('inc-mixed', 10, Usage::TYPE_EVENT);
        $this->usage->collect('inc-mixed', 20, Usage::TYPE_EVENT);
        $this->usage->collect('set-mixed', 100, Usage::TYPE_GAUGE);
        $this->usage->collect('set-mixed', 200, Usage::TYPE_GAUGE);

        // inc: 1 metric:event key = 1, gauge: 1 metric:gauge key = 1
        $this->assertEquals(2, $this->usage->getBufferSize());
        $this->assertEquals(4, $this->usage->getBufferCount());

        $this->assertTrue($this->usage->flush());

        // Event: summed (10 + 20 = 30)
        $this->assertEquals(30, $this->usage->getTotal('inc-mixed'));

        // Gauge: last value (200)
        $this->assertEquals(200, $this->usage->getTotal('set-mixed'));
    }

    public function testShouldFlushByThreshold(): void
    {
        $this->usage->setFlushThreshold(3);

        $this->assertFalse($this->usage->shouldFlush());

        $this->usage->collect('threshold-test', 1, Usage::TYPE_EVENT);
        $this->usage->collect('threshold-test', 1, Usage::TYPE_EVENT);

        $this->assertFalse($this->usage->shouldFlush());

        $this->usage->collect('threshold-test', 1, Usage::TYPE_EVENT);

        $this->assertTrue($this->usage->shouldFlush());

        // Clean up
        $this->usage->flush();
        $this->usage->setFlushThreshold(10_000); // reset
    }

    public function testShouldFlushByInterval(): void
    {
        $this->usage->setFlushInterval(1);

        $this->usage->collect('interval-test', 1, Usage::TYPE_EVENT);

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

    public function testCollectValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metric name cannot be empty');
        $this->usage->collect('', 10, Usage::TYPE_EVENT);
    }

    public function testCollectNegativeValueValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value cannot be negative');
        $this->usage->collect('test', -1, Usage::TYPE_EVENT);
    }

    public function testCollectInvalidTypeValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->usage->collect('test', 10, 'invalid');
    }

    public function testWithQueries(): void
    {
        $results = $this->usage->find([
            Query::equal('metric', ['requests']),
            Query::limit(1),
        ]);

        $this->assertEquals(1, count($results));

        $results2 = $this->usage->find([
            Query::equal('metric', ['requests']),
            Query::limit(1),
            Query::offset(1),
        ]);

        $this->assertLessThanOrEqual(1, count($results2));
    }

    public function testEmptyBatch(): void
    {
        $this->assertTrue($this->usage->addBatch([]));
    }

    public function testAddBatchWithTags(): void
    {
        $metrics = [
            ['metric' => 'tagged', 'value' => 10, 'type' => 'event', 'tags' => ['region' => 'us-east']],
            ['metric' => 'tagged', 'value' => 20, 'type' => 'event', 'tags' => ['region' => 'us-west']],
            ['metric' => 'tagged', 'value' => 15, 'type' => 'event', 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->addBatch($metrics));

        $results = $this->usage->find([
            Query::equal('metric', ['tagged']),
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
    }
}
