<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Verifies the Plausible-style schema hygiene applied in P2:
 *  - explicit CODEC(Delta(4), LZ4) on time
 *  - CODEC(ZSTD(3)) on high-cardinality string columns
 *  - LowCardinality on path / hostname / version+model columns
 *  - set(0) skipping index on low-cardinality enums
 */
class ClickHouseSchemaTest extends TestCase
{
    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $this->adapter->setNamespace('utopia_usage_schema');
        $this->adapter->setSharedTables(true);
        $this->adapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $this->adapter->setDatabase($database);
        }

        $usage = new Usage($this->adapter);
        $usage->setup();
    }

    public function testEventsTableCarriesCodecsAndLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getEventsTableName'));

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`id` String CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`path` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`hostname` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`osVersion` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`deviceModel` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
    }

    public function testEventsTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getEventsTableName'));

        // status / method / country / service / clientType / osName must be set(0)
        $this->assertStringContainsString('`index-status` status TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-method` method TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-country` country TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-clientType` clientType TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-osName` osName TYPE set(0)', $ddl);

        // path / hostname / resourceId / teamId stay bloom_filter
        $this->assertStringContainsString('`index-path` path TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-hostname` hostname TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);
    }

    public function testDailyTableMatchesPrePrSchema(): void
    {
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbValue = $dbProp->getValue($this->adapter);
        $database = is_string($dbValue) ? $dbValue : '';
        $dailyTable = $this->resolveTableName('getEventsDailyTableName');
        $fullName = "`{$database}`.`{$dailyTable}`";
        $mvName = "`{$database}`.`{$this->namespacedDailyMv()}`";

        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, "DROP TABLE IF EXISTS {$mvName}", []);
        $query->invoke($this->adapter, "DROP TABLE IF EXISTS {$fullName}", []);

        $usage = new Usage($this->adapter);
        $usage->setup();

        $ddl = $this->showCreate($dailyTable);

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC')", $ddl);
        $this->assertStringNotContainsString("`time` DateTime64(3, 'UTC') CODEC", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String)', $ddl);
        $this->assertStringNotContainsString('`resourceId` Nullable(String) CODEC', $ddl);
    }

    private function namespacedDailyMv(): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $getTableName = $reflection->getMethod('getTableName');
        $getTableName->setAccessible(true);
        $raw = $getTableName->invoke($this->adapter);
        $base = is_string($raw) ? $raw : '';
        return $base . '_events_daily_mv';
    }

    public function testGaugesTableCarriesServiceAndResource(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getGaugesTableName'));

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resource` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
    }

    public function testGaugesTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getGaugesTableName'));

        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resource` resource TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);
    }

    public function testSetupBackfillsServiceResourceOnLegacyGaugesTable(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $legacyAdapter = new ClickHouseAdapter($host, $username, $password, $port, $secure);
        $legacyAdapter->setNamespace('utopia_usage_schema_legacy_gauge');
        $legacyAdapter->setSharedTables(true);
        $legacyAdapter->setTenant('1');

        if ($database = getenv('CLICKHOUSE_DATABASE')) {
            $legacyAdapter->setDatabase($database);
        }

        $reflection = new ReflectionClass($legacyAdapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbValue = $dbProp->getValue($legacyAdapter);
        $database = is_string($dbValue) ? $dbValue : '';

        $getGauges = $reflection->getMethod('getGaugesTableName');
        $getGauges->setAccessible(true);
        $gaugesRaw = $getGauges->invoke($legacyAdapter);
        $gaugesTable = is_string($gaugesRaw) ? $gaugesRaw : '';
        $fullName = "`{$database}`.`{$gaugesTable}`";

        $query = $reflection->getMethod('query');
        $query->setAccessible(true);

        $query->invoke($legacyAdapter, "DROP TABLE IF EXISTS {$fullName}", []);
        $query->invoke($legacyAdapter, "
            CREATE TABLE {$fullName} (
                id String,
                metric String,
                value Int64,
                time DateTime64(3),
                tenant Nullable(String)
            )
            ENGINE = MergeTree()
            ORDER BY (tenant, metric, time, id)
            PARTITION BY toYYYYMM(time)
            SETTINGS allow_nullable_key = 1
        ", []);

        $usage = new Usage($legacyAdapter);
        $usage->setup();

        $sql = "SHOW CREATE TABLE {$fullName} FORMAT JSON";
        $raw = $query->invoke($legacyAdapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        $ddl = '';
        if (is_array($json) && isset($json['data'][0]['statement']) && is_string($json['data'][0]['statement'])) {
            $ddl = $json['data'][0]['statement'];
        }

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resource` LowCardinality(Nullable(String))', $ddl);
    }

    private function resolveTableName(string $accessor): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $method = $reflection->getMethod($accessor);
        $method->setAccessible(true);
        $raw = $method->invoke($this->adapter);
        return is_string($raw) ? $raw : '';
    }

    private function showCreate(string $table): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $dbProp = $reflection->getProperty('database');
        $dbProp->setAccessible(true);
        $dbValue = $dbProp->getValue($this->adapter);
        $database = is_string($dbValue) ? $dbValue : '';

        $sql = "SHOW CREATE TABLE `{$database}`.`{$table}` FORMAT JSON";
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $raw = $query->invoke($this->adapter, $sql, []);
        $rawString = is_string($raw) ? $raw : '';
        $json = json_decode($rawString, true);
        if (is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $row = $json['data'][0];
            $statement = $row['statement'] ?? '';
            if (is_string($statement)) {
                return $statement;
            }
        }
        return '';
    }
}
