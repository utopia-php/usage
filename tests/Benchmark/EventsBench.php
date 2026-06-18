<?php

namespace Utopia\Tests\Benchmark;

use Utopia\Query\Query;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class EventsBench extends BenchmarkBase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeAll();
        $this->seedEventRows($this->defaultRows);
    }

    public function testBenchmarks(): void
    {
        $start = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');
        $end = (new \DateTime('+1 day'))->format('Y-m-d H:i:s');

        $this->runBench('bench_events_sum_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->sum([
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ], 'value', Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_timeseries_30d_1h', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupByInterval('time', '1h'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(5000),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_path_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_method_status_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('method'),
                UsageQuery::groupBy('status'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(200),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_count_max_5k', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->count([
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ], Usage::TYPE_EVENT, 5000);
        });

        $this->runBench('bench_insert_10k', function (string $queryId): void {
            $batch = [];
            for ($i = 0; $i < 10000; $i++) {
                $batch[] = [
                    'metric' => 'bench.insert.10k',
                    'value' => $i,
                    'tags' => [
                        'path' => '/v1/insert/' . ($i % 100),
                        'method' => 'POST',
                        'status' => '201',
                        'service' => 'storage',
                    ],
                ];
            }
            // No query_id propagation for INSERT — kept for parity with other
            // scenarios; ClickHouse ignores the param on INSERT regardless.
            $this->adapter->setNextQueryId($queryId);
            $this->usage->addBatch($batch, Usage::TYPE_EVENT);
        }, 3);

        // Multi-dim MV target scenarios — until P3.3 routes through the MVs
        // these still scan raw events, providing the baseline numbers that
        // commit 5 will compare against.
        $this->runBench('bench_events_topN_country_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('country'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(200),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_service_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('service'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(200),
            ], Usage::TYPE_EVENT);
        });

        $this->assertNotEmpty($this->results, 'Benchmark scenarios must record results');
    }
}
