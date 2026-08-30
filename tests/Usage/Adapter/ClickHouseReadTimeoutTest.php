<?php

namespace Utopia\Tests\Adapter;

use Exception;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Read-path execution cap and cancellation.
 *
 * Reads carry `max_execution_time` plus a `query_id` so a read the client
 * gives up on is aborted server-side and, failing that, reaped by
 * `KILL QUERY`. Writes must stay uncapped.
 */
class ClickHouseReadTimeoutTest extends ClickHouseTestCase
{
    private const NAMESPACE = 'utopia_usage_read_timeout';

    /** Sleeps a row at a time so the cap and the KILL are both observable without burning CPU. */
    private const SLOW_QUERY = 'SELECT sum(x) AS total FROM (SELECT sleepEachRow(1) AS x FROM numbers(30) SETTINGS max_block_size = 1) FORMAT JSON';

    private ClickHouseAdapter $adapter;

    private Usage $usage;

    protected function setUp(): void
    {
        $this->adapter = $this->newAdapter();
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'read.timeout.metric', 'value' => 7],
        ], Usage::TYPE_EVENT);
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    private function newAdapter(?int $readTimeout = 25, ?float $clientTimeout = null): ClickHouseAdapter
    {
        $client = $clientTimeout === null
            ? null
            : new Client((new CurlAdapter())->withTimeout($clientTimeout));

        return new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            client: $client,
            namespace: self::NAMESPACE,
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
            readTimeout: $readTimeout,
        );
    }

    public function testReadCarriesExecutionCapAndGeneratedQueryId(): void
    {
        $this->find($this->adapter);

        $row = $this->lastQueryLogRow("query_kind = 'Select' AND query LIKE '%" . self::NAMESPACE . "_usage_events%'");

        $this->assertSame('25', $row['max_execution_time'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($row['query_id'] ?? ''));
    }

    public function testCallerSuppliedQueryIdSurvivesTheExecutionCap(): void
    {
        $queryId = 'read-timeout-' . bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->find($this->adapter);

        $row = $this->lastQueryLogRow("query_id = '{$queryId}'");

        $this->assertSame('25', $row['max_execution_time'] ?? null);
    }

    public function testNullReadTimeoutSendsNoExecutionCap(): void
    {
        $adapter = $this->newAdapter(readTimeout: null);
        $queryId = 'read-timeout-off-' . bin2hex(random_bytes(8));
        $adapter->setNextQueryId($queryId);
        $this->find($adapter);

        $row = $this->lastQueryLogRow("query_id = '{$queryId}'");

        $this->assertSame('', $row['max_execution_time'] ?? null);
    }

    public function testWritePathIsNotCapped(): void
    {
        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'read.timeout.metric', 'value' => 11],
        ], Usage::TYPE_EVENT);

        $row = $this->lastQueryLogRow("query_kind = 'Insert' AND query LIKE '%" . self::NAMESPACE . "_usage_events%'");

        $this->assertSame('', $row['max_execution_time'] ?? null);
    }

    public function testSlowReadIsAbortedByTheExecutionCap(): void
    {
        $adapter = $this->newAdapter(readTimeout: 1);

        $start = microtime(true);
        try {
            $this->queryReadRaw($adapter, self::SLOW_QUERY);
            $this->fail('Expected the execution cap to abort the read');
        } catch (Exception $e) {
            $this->assertStringContainsString('TIMEOUT_EXCEEDED', $e->getMessage());
        }

        // The query sleeps for 30s; anything close to that means ClickHouse ran
        // it to completion instead of aborting.
        $this->assertLessThan(10.0, microtime(true) - $start);
        $this->assertSame(0, $this->runningSleepQueries());
    }

    public function testReadAbandonedByTheClientIsKilled(): void
    {
        // Cap above the client timeout so the socket dies first — the production
        // shape, where the query would otherwise outlive the request.
        $adapter = $this->newAdapter(readTimeout: 25, clientTimeout: 1.0);
        $queryId = 'read-timeout-kill-' . bin2hex(random_bytes(8));
        $adapter->setNextQueryId($queryId);

        try {
            $this->queryReadRaw($adapter, self::SLOW_QUERY);
            $this->fail('Expected the client to give up on the read');
        } catch (Exception $e) {
            $this->assertStringContainsString('ClickHouse query failed', $e->getMessage());
        }

        $this->assertSame(0, $this->runningQueries("query_id = '{$queryId}'"));
    }

    public function testRejectsNonPositiveReadTimeout(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Read timeout must be a positive number of seconds');

        $this->newAdapter(readTimeout: 0);
    }

    /**
     * @return array<\Utopia\Usage\Metric>
     */
    private function find(ClickHouseAdapter $adapter): array
    {
        return $adapter->find('1', [
            Query::equal('metric', ['read.timeout.metric']),
            Query::limit(10),
        ], Usage::TYPE_EVENT);
    }

    /**
     * Most recent finished query matching $where, with the settings we care about.
     *
     * @return array<string, string>
     */
    private function lastQueryLogRow(string $where): array
    {
        $this->queryRaw($this->adapter, 'SYSTEM FLUSH LOGS');

        $sql = "SELECT query_id, Settings['max_execution_time'] AS max_execution_time "
            . "FROM system.query_log WHERE type = 'QueryFinish' AND {$where} "
            . 'ORDER BY event_time_microseconds DESC LIMIT 1 FORMAT JSON';

        $json = json_decode($this->queryRaw($this->adapter, $sql), true);
        $row = is_array($json) && is_array($json['data'] ?? null) ? ($json['data'][0] ?? null) : null;
        $this->assertIsArray($row, "no query_log row for: {$where}");

        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    private function runningSleepQueries(): int
    {
        return $this->runningQueries("query LIKE '%sleepEachRow%' AND query NOT LIKE '%system.processes%'");
    }

    /**
     * Queries still running server-side. Cancellation is asynchronous, so poll
     * briefly before declaring one abandoned.
     */
    private function runningQueries(string $where): int
    {
        $sql = "SELECT count() AS running FROM system.processes WHERE {$where} FORMAT JSON";

        $running = 0;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $json = json_decode($this->queryRaw($this->adapter, $sql), true);
            $row = is_array($json) && is_array($json['data'] ?? null) ? ($json['data'][0] ?? null) : null;
            $running = is_array($row) && is_numeric($row['running'] ?? null) ? (int) $row['running'] : 0;
            if ($running === 0) {
                return 0;
            }
            usleep(250_000);
        }

        return $running;
    }
}
