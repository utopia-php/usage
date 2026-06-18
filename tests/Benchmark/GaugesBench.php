<?php

namespace Utopia\Tests\Benchmark;

use Utopia\Query\Query;
use Utopia\Usage\Usage;

class GaugesBench extends BenchmarkBase
{
    protected string $metric = 'storage';

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeAll();
        // Gauges are sparser than events — scale by 1/100 vs the default rows.
        $this->seedGaugeRows((int) max(1000, $this->defaultRows / 100), $this->metric);
    }

    public function testBenchmarks(): void
    {
        $start = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('+1 day'))->format('Y-m-d H:i:s');

        $this->runBench('bench_gauges_latest_in_window', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->getTotal(
                $this->metric,
                [
                    Query::greaterThanEqual('time', $start),
                    Query::lessThanEqual('time', $end),
                ],
                Usage::TYPE_GAUGE
            );
        });

        $this->assertNotEmpty($this->results, 'Benchmark scenarios must record results');
    }
}
