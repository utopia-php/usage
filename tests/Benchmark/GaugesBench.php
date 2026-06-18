<?php

namespace Utopia\Tests\Benchmark;

use DateTime;
use Utopia\Query\Query;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class GaugesBench extends BenchmarkBase
{
    protected string $metric = 'storage';

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeAll();
        $this->seedGaugeRows((int) max(1000, $this->defaultRows / 100), $this->metric);
    }

    public function testBenchmarks(): void
    {
        $start30d = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
        $endClosed = (new DateTime('-2 days'))->format('Y-m-d H:i:s');
        $endPartial = (new DateTime('+1 day'))->format('Y-m-d H:i:s');

        $this->runBench('bench_gauges_latest_in_window', function (string $queryId) use ($start30d, $endPartial): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->getTotal(
                $this->metric,
                [
                    Query::greaterThanEqual('time', $start30d),
                    Query::lessThanEqual('time', $endPartial),
                ],
                Usage::TYPE_GAUGE
            );
        });

        $this->runBench('bench_gauges_topN_service_30d', function (string $queryId) use ($start30d, $endClosed): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('service'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start30d),
                Query::lessThanEqual('time', $endClosed),
                Query::limit(10),
            ], Usage::TYPE_GAUGE);
        });

        $this->runBench('bench_gauges_topN_resource_30d', function (string $queryId) use ($start30d, $endClosed): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('resource'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start30d),
                Query::lessThanEqual('time', $endClosed),
                Query::limit(10),
            ], Usage::TYPE_GAUGE);
        });

        $this->runBench('bench_gauges_topN_service_today_partial', function (string $queryId) use ($start30d, $endPartial): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('service'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start30d),
                Query::lessThanEqual('time', $endPartial),
                Query::limit(10),
            ], Usage::TYPE_GAUGE);
        });

        $this->assertNotEmpty($this->results, 'Benchmark scenarios must record results');

        $expected = [
            'bench_gauges_latest_in_window' => 'raw',
            'bench_gauges_topN_service_30d' => 'gauges_daily_by_service',
            'bench_gauges_topN_resource_30d' => 'gauges_daily_by_resource',
            'bench_gauges_topN_service_today_partial' => 'gauges_hybrid_by_service',
        ];
        foreach ($expected as $scenario => $route) {
            $this->assertArrayHasKey($scenario, $this->routes, "missing routing log for {$scenario}");
            $this->assertContains($route, $this->routes[$scenario], "{$scenario} expected to route to {$route}, got " . implode(',', $this->routes[$scenario]));
        }
    }
}
