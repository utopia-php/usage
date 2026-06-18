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

        $this->assertStringContainsString('`time` DateTime64(3) CODEC(Delta(4), LZ4)', $ddl);
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

    public function testDailyTableCarriesCodecs(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getEventsDailyTableName'));

        $this->assertStringContainsString('`time` DateTime64(3) CODEC(Delta(4), LZ4)', $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamInternalId` Nullable(String) CODEC(ZSTD(3))', $ddl);
    }

    public function testGaugesTableCarriesServiceAndResource(): void
    {
        $ddl = $this->showCreate($this->resolveTableName('getGaugesTableName'));

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resource` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`time` DateTime64(3) CODEC(Delta(4), LZ4)', $ddl);
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
