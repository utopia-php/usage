<?php

namespace Utopia\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Base class for ClickHouse benchmarks.
 *
 * Each scenario seeds a synthetic dataset via `INSERT … SELECT FROM
 * numbers(…)`, then runs the target query N times with a fresh query_id and
 * records both wall-clock (PHP-side) and ClickHouse-side stats (`rows_read`,
 * `read_bytes`, `query_duration_ms` from `system.query_log`).
 *
 * Output is a JSON report with p50/p95 per scenario, written to
 * `tests/Benchmark/output/<class>.json`.
 */
abstract class BenchmarkBase extends TestCase
{
    protected Usage $usage;

    protected ClickHouseAdapter $adapter;

    /** @var array<string, array<int, array{wall_ms: float, rows_read: int, read_bytes: int, query_duration_ms: float}>> */
    protected array $results = [];

    /** @var int Default number of synthetic rows; override via BENCH_ROWS env */
    protected int $defaultRows = 1_000_000;

    protected string $tenant = '1';

    protected string $namespace = 'utopia_usage_bench';

    protected string $metric = 'network.requests';

    /** Number of measured iterations (after one warmup). */
    protected int $iterations = 5;

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace($this->namespace);
        // Benchmarks mirror the cloud workload (sharedTables=true) so the
        // synthetic seed and the routing paths exercise the same schema.
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant($this->tenant);

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();

        $rowsEnv = getenv('BENCH_ROWS');
        if (is_string($rowsEnv) && ctype_digit($rowsEnv)) {
            $this->defaultRows = (int) $rowsEnv;
        }
    }

    protected function tearDown(): void
    {
        $this->writeReport();
    }

    /**
     * Seed `$n` synthetic rows into the events table via
     * `INSERT … SELECT FROM numbers(n)`. Bypasses the library's batching path.
     *
     * @param int $rows Number of rows to insert
     * @param string|null $metric Metric name (defaults to $this->metric)
     */
    protected function seedEventRows(int $rows, ?string $metric = null): void
    {
        $metric ??= $this->metric;

        $reflection = new \ReflectionClass($this->adapter);

        $getEvents = $reflection->getMethod('getEventsTableName');
        $getEvents->setAccessible(true);
        $eventsTableRaw = $getEvents->invoke($this->adapter);
        $eventsTable = is_string($eventsTableRaw) ? $eventsTableRaw : '';

        $getDatabase = $reflection->getProperty('database');
        $getDatabase->setAccessible(true);
        $databaseRaw = $getDatabase->getValue($this->adapter);
        $database = is_string($databaseRaw) ? $databaseRaw : '';

        $tableRef = "`{$database}`.`{$eventsTable}`";

        $template = file_get_contents(__DIR__ . '/fixtures/seed.sql');
        if (!is_string($template)) {
            throw new \RuntimeException('Unable to read seed.sql fixture');
        }

        $sql = strtr($template, [
            '{TABLE}' => $tableRef,
            '{ROWS}' => (string) $rows,
            '{METRIC}' => addslashes($metric),
            '{TENANT}' => addslashes($this->tenant),
        ]);

        $this->runRawSql($sql);
    }

    /**
     * Seed `$n` synthetic rows into the gauges table.
     */
    protected function seedGaugeRows(int $rows, string $metric = 'storage'): void
    {
        $reflection = new \ReflectionClass($this->adapter);

        $getGauges = $reflection->getMethod('getGaugesTableName');
        $getGauges->setAccessible(true);
        $gaugesTableRaw = $getGauges->invoke($this->adapter);
        $gaugesTable = is_string($gaugesTableRaw) ? $gaugesTableRaw : '';

        $getDatabase = $reflection->getProperty('database');
        $getDatabase->setAccessible(true);
        $databaseRaw = $getDatabase->getValue($this->adapter);
        $database = is_string($databaseRaw) ? $databaseRaw : '';

        $tableRef = "`{$database}`.`{$gaugesTable}`";

        $sql = "INSERT INTO {$tableRef} (id, metric, value, time, tenant) "
            . "SELECT lower(hex(randomString(16))), '" . addslashes($metric) . "', "
            . "number AS value, now() - toIntervalSecond(number % 86400) AS time, "
            . "'" . addslashes($this->tenant) . "' AS tenant "
            . "FROM numbers({$rows})";

        $this->runRawSql($sql);
    }

    /**
     * Execute a raw SQL string against ClickHouse (bypassing parameter binding).
     */
    protected function runRawSql(string $sql): void
    {
        $reflection = new \ReflectionClass($this->adapter);
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $sql, []);
    }

    /**
     * Truncate the events / gauges tables and recreate the rollup tables.
     */
    protected function purgeAll(): void
    {
        $this->usage->purge();
    }

    /**
     * Run a benchmark scenario `$iterations + 1` times (1 warmup) and record
     * per-iteration stats into $this->results[$name].
     *
     * @param string $name Scenario name
     * @param callable(string): void $callable Receives a unique query_id; must
     *                                          forward it to the adapter via
     *                                          `setNextQueryId()` before its
     *                                          ClickHouse call.
     */
    protected function runBench(string $name, callable $callable, ?int $iterations = null): void
    {
        $iterations ??= $this->iterations;

        // Warmup pass — discarded to dodge cold-start variance.
        $warmupId = $this->generateQueryId($name, -1);
        $callable($warmupId);

        $records = [];
        for ($i = 0; $i < $iterations; $i++) {
            $queryId = $this->generateQueryId($name, $i);

            $start = microtime(true);
            $callable($queryId);
            $wallMs = (microtime(true) - $start) * 1000.0;

            $chStats = $this->captureCHStats($queryId);
            $chStats['wall_ms'] = round($wallMs, 3);

            $records[] = $chStats;
        }

        $this->results[$name] = $records;
    }

    /**
     * Query system.query_log for the given query_id and return rows_read,
     * read_bytes, query_duration_ms (best-effort — returns zeros if the log
     * hasn't flushed yet).
     *
     * @return array{wall_ms: float, rows_read: int, read_bytes: int, query_duration_ms: float}
     */
    protected function captureCHStats(string $queryId): array
    {
        $stats = [
            'wall_ms' => 0.0,
            'rows_read' => 0,
            'read_bytes' => 0,
            'query_duration_ms' => 0.0,
        ];

        // system.query_log is buffered — flush before we read it.
        try {
            $this->runRawSql('SYSTEM FLUSH LOGS');
        } catch (\Throwable $e) {
            // Some environments deny SYSTEM access; fall through with zeros.
            return $stats;
        }

        $escapedId = addslashes($queryId);
        $sql = "SELECT sum(read_rows) AS rows_read, sum(read_bytes) AS read_bytes, "
            . "max(query_duration_ms) AS query_duration_ms "
            . "FROM system.query_log "
            . "WHERE query_id = '{$escapedId}' AND type >= 2 "
            . "FORMAT JSON";

        try {
            $reflection = new \ReflectionClass($this->adapter);
            $query = $reflection->getMethod('query');
            $query->setAccessible(true);
            $raw = $query->invoke($this->adapter, $sql, []);
            $rawString = is_string($raw) ? $raw : '';
            $json = json_decode($rawString, true);
            if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
                $row = $json['data'][0];
                $stats['rows_read'] = (int) ($row['rows_read'] ?? 0);
                $stats['read_bytes'] = (int) ($row['read_bytes'] ?? 0);
                $stats['query_duration_ms'] = (float) ($row['query_duration_ms'] ?? 0);
            }
        } catch (\Throwable $e) {
            // Best-effort.
        }

        return $stats;
    }

    /**
     * Compute percentile for a sorted numeric series.
     *
     * @param array<int|float> $values
     */
    private function percentile(array $values, float $p): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $idx = (int) floor($p * (count($values) - 1));
        return (float) $values[$idx];
    }

    /**
     * Write the per-scenario JSON report under tests/Benchmark/output/.
     */
    protected function writeReport(): void
    {
        if (empty($this->results)) {
            return;
        }

        $summary = [];
        foreach ($this->results as $name => $records) {
            $wall = array_map(static fn (array $r) => $r['wall_ms'], $records);
            $rows = array_map(static fn (array $r) => $r['rows_read'], $records);
            $bytes = array_map(static fn (array $r) => $r['read_bytes'], $records);
            $chDur = array_map(static fn (array $r) => $r['query_duration_ms'], $records);

            $summary[$name] = [
                'iterations' => count($records),
                'rows_dataset' => $this->defaultRows,
                'wall_p50_ms' => $this->percentile($wall, 0.50),
                'wall_p95_ms' => $this->percentile($wall, 0.95),
                'ch_p50_ms' => $this->percentile($chDur, 0.50),
                'ch_p95_ms' => $this->percentile($chDur, 0.95),
                'rows_read_p50' => $this->percentile($rows, 0.50),
                'rows_read_p95' => $this->percentile($rows, 0.95),
                'read_bytes_p50' => $this->percentile($bytes, 0.50),
                'read_bytes_p95' => $this->percentile($bytes, 0.95),
                'samples' => $records,
            ];
        }

        $outDir = __DIR__ . '/output';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0775, true);
        }

        $short = (new \ReflectionClass($this))->getShortName();
        $file = $outDir . '/' . $short . '.json';
        file_put_contents($file, (string) json_encode([
            'class' => static::class,
            'tenant' => $this->tenant,
            'rows_dataset' => $this->defaultRows,
            'generated_at' => date(DATE_ATOM),
            'scenarios' => $summary,
        ], JSON_PRETTY_PRINT));
    }

    protected function generateQueryId(string $scenario, int $iter): string
    {
        return 'bench-' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^a-z0-9_]+/i', '_', $scenario) . '-' . $iter;
    }
}
