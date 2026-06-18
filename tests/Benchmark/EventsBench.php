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

        $todayStart = (new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $todayEnd = (new \DateTime('+2 hour'))->format('Y-m-d H:i:s');
        $this->runBench('bench_events_topN_path_today_partial', function (string $queryId) use ($todayStart, $todayEnd): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $todayStart),
                Query::lessThanEqual('time', $todayEnd),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_path_30d_filtered_resource', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::equal('resource', ['function']),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_path_country', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('path'),
                UsageQuery::groupBy('country'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        // Write fan-out cost with all MVs attached. Compare against
        // bench_insert_10k for the multiplier (must stay ≤ 1.3×).
        $this->runBench('bench_insert_with_mvs', function (string $queryId): void {
            $batch = [];
            for ($i = 0; $i < 10000; $i++) {
                $batch[] = [
                    'metric' => 'bench.insert.with_mvs',
                    'value' => $i,
                    'tags' => [
                        'path' => '/v1/mv/' . ($i % 100),
                        'method' => 'POST',
                        'status' => '201',
                        'service' => 'storage',
                        'country' => 'us',
                    ],
                ];
            }
            $this->adapter->setNextQueryId($queryId);
            $this->usage->addBatch($batch, Usage::TYPE_EVENT);
        }, 3);

        // Catches buffered/async insert lag: write then read the same key.
        $this->runBench('bench_mv_lag', function (string $queryId): void {
            $this->usage->addBatch([
                ['metric' => 'bench.mv.lag', 'value' => 1, 'tags' => ['path' => '/v1/mv-lag']],
            ], Usage::TYPE_EVENT);
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find([
                UsageQuery::groupBy('path'),
                Query::equal('metric', ['bench.mv.lag']),
                Query::limit(10),
            ], Usage::TYPE_EVENT);
        }, 3);

        // Storage footprint per busy project — reads system.parts.
        $this->runBench('bench_mv_storage_per_busy_project', function (string $queryId): void {
            $reflection = new \ReflectionClass($this->adapter);
            $getDatabase = $reflection->getProperty('database');
            $getDatabase->setAccessible(true);
            $databaseValue = $getDatabase->getValue($this->adapter);
            $database = is_string($databaseValue) ? $databaseValue : '';
            $namespace = $this->namespace;
            $this->adapter->setNextQueryId($queryId);
            $this->runRawSql(
                "SELECT sum(bytes_on_disk) AS total_bytes "
                . "FROM system.parts "
                . "WHERE database = '" . addslashes($database) . "' "
                . "AND table LIKE '" . addslashes($namespace) . "_usage_events_daily_by_%' "
                . "AND active = 1 FORMAT JSON"
            );
        }, 3);

        $this->assertNotEmpty($this->results, 'Benchmark scenarios must record results');
    }
}
