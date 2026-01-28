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

        // After aggregation there may be only a single row; offset 1 yields zero rows
        $this->assertEquals(0, count($results2));
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
}
