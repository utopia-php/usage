# Utopia Usage

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/usage.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework usage library is a simple and lite library for managing application usage statistics. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Features

- **Two Table Architecture**: Separate events and gauges tables optimized for their access patterns
- **Events Table**: Request-level metrics with dedicated columns for every dimension we filter on (path/method/status, service/resource ids, team ids, country/region, hostname, parsed UA fields)
- **Gauges Table**: Simple resource snapshots (storage size, user count, etc.)
- **Query-Time Aggregation**: No write-time period fan-out — aggregate by any interval at query time
- **Daily Materialized View**: Pre-aggregated daily SummingMergeTree for fast billing queries
- **Pluggable Adapters**: ClickHouse (production) and Database (development/testing)
- **In-Memory Buffering**: Collect metrics and flush in batch for high-throughput scenarios
- **Rich Queries**: Filter, sort, paginate using `Utopia\Query\Query` objects
- **Multi-Tenant**: Shared tables with tenant isolation via string tenant IDs
- **LowCardinality Columns**: Country uses `LowCardinality(String)` for efficient storage
- **Bloom Filter Indexes**: Fast filtering on all event columns

## Getting Started

Install using composer:
```bash
composer require utopia-php/usage
```

### Using ClickHouse Adapter

```php
<?php

use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\ClickHouse;

$adapter = new ClickHouse(
    host: 'clickhouse-server',
    username: 'default',
    password: '',
    port: 8123,
    secure: false
);

// Multi-tenant setup
$adapter->setNamespace('my_app');
$adapter->setSharedTables(true);
$adapter->setTenant('project_123');

$usage = new Usage($adapter);
$usage->setup(); // Creates events, gauges, and daily MV tables
```

### Using Database Adapter

```php
<?php

use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\Database as DatabaseAdapter;

$adapter = new DatabaseAdapter($database); // Utopia\Database\Database instance
$usage = new Usage($adapter);
$usage->setup();
```

## Metric Types

### Events (Additive)

Events are request-level metrics like bandwidth, executions, API calls. They are summed when aggregated.

Event-specific columns (see `Metric::EVENT_COLUMNS`): `path`, `method`, `status`,
`service`, `resource`, `resourceId`, `resourceInternalId`, `teamId`,
`teamInternalId`, `country`, `region`, `hostname`, `osCode`, `osName`,
`osVersion`, `clientType`, `clientCode`, `clientName`, `clientVersion`,
`clientEngine`, `clientEngineVersion`, `deviceName`, `deviceBrand`,
`deviceModel`.

```php
// Collect events — values accumulate in-memory buffer (summed per metric)
$usage->collect('bandwidth', 5000, Usage::TYPE_EVENT, [
    'path' => '/v1/storage/files',
    'method' => 'POST',
    'status' => '201',
    'service' => 'storage',
    'resource' => 'bucket',
    'resourceId' => 'abc123',
    'resourceInternalId' => '42',
    'teamId' => 'team_x',
    'teamInternalId' => '7',
    'country' => 'US',
    'region' => 'us-east',
    'hostname' => 'app.example.com',
    'osName' => 'iOS',
    'clientName' => 'Appwrite SDK',
    'deviceName' => 'smartphone',
]);
```

### Strict tag keys

All keys passed in the `tags` array must map to a known event or gauge
column (see `Metric::EVENT_COLUMNS` and `Metric::GAUGE_COLUMNS`). Unknown
keys throw at write time — there is no JSON catch-all. To add a new
dimension, widen the schema and bump the library version.

### Gauges (Point-in-Time)

Gauges are resource snapshots like storage size, user count, file count. Last-write-wins semantics.

Gauge-specific columns (see `Metric::GAUGE_COLUMNS`): `teamId`,
`teamInternalId`, `resourceId`, `resourceInternalId`.

```php
// Collect gauges — last value wins per metric in buffer
$usage->collect('users', 1500, Usage::TYPE_GAUGE);
$usage->collect('storage.size', 1048576, Usage::TYPE_GAUGE, [
    'teamId' => 'team_x',
    'teamInternalId' => '7',
    'resourceId' => 'abc123',
    'resourceInternalId' => '42',
]);
```

### Flushing

```php
// Check if flush is recommended (threshold or interval reached)
if ($usage->shouldFlush()) {
    $usage->flush(); // Writes events to events table, gauges to gauges table
}

// Configure thresholds
$usage->setFlushThreshold(5000);  // Flush after 5000 collect() calls (default: 10,000)
$usage->setFlushInterval(10);     // Flush after 10 seconds (default: 20)
```

### Batch Writes

```php
// Write directly without buffering
$usage->addBatch([
    ['metric' => 'requests', 'value' => 100, 'tags' => ['path' => '/v1/users', 'method' => 'GET']],
    ['metric' => 'bandwidth', 'value' => 50000, 'tags' => ['country' => 'DE', 'region' => 'fra']],
], Usage::TYPE_EVENT);

$usage->addBatch([
    ['metric' => 'users', 'value' => 42, 'tags' => ['teamId' => 'team_x']],
], Usage::TYPE_GAUGE);
```

## Querying Metrics

### Find with Query Objects

```php
use Utopia\Query\Query;

// Find events filtered by metric and time range
$metrics = $usage->find([
    Query::equal('metric', ['bandwidth']),
    Query::equal('country', ['US']),
    Query::greaterThanEqual('time', '2026-01-01'),
    Query::orderDesc('time'),
    Query::limit(100),
], Usage::TYPE_EVENT);

// Find gauges
$gauges = $usage->find([
    Query::equal('metric', ['users', 'storage.size']),
], Usage::TYPE_GAUGE);

// Query both tables (type = null)
$all = $usage->find([
    Query::equal('metric', ['bandwidth']),
]);
```

### Totals

```php
// Get total for a single metric (SUM for events, latest for gauges)
$total = $usage->getTotal('bandwidth', [
    Query::greaterThanEqual('time', '2026-03-01'),
], Usage::TYPE_EVENT);

// Batch totals — single query with GROUP BY
$totals = $usage->getTotalBatch(
    ['bandwidth', 'executions', 'requests'],
    [Query::greaterThanEqual('time', '2026-03-01')],
    Usage::TYPE_EVENT
);
```

### Time Series

```php
// Get time series with query-time aggregation
// Events: SUM per bucket, Gauges: argMax per bucket
$series = $usage->getTimeSeries(
    metrics: ['bandwidth', 'requests'],
    interval: '1d',        // '1h' or '1d'
    startDate: '2026-03-01',
    endDate: '2026-04-01',
    zeroFill: true,        // Fill gaps with zeros
    type: Usage::TYPE_EVENT
);

// Returns: ['bandwidth' => ['total' => 5000000, 'data' => [['value' => 100, 'date' => '...'], ...]]]
```

### Billing Queries (Daily MV)

The daily materialized view pre-aggregates events by `metric + tenant + day` for fast billing:

```php
// Sum a single metric from the daily table
$total = $usage->sumDaily([
    Query::equal('metric', ['bandwidth']),
    Query::between('time', '2026-03-01', '2026-04-01'),
]);

// Sum multiple metrics in one query
$totals = $usage->sumDailyBatch(
    ['bandwidth', 'executions', 'storage.size'],
    [Query::between('time', '2026-03-01', '2026-04-01')]
);
// Returns: ['bandwidth' => 5000000, 'executions' => 12345, 'storage.size' => 0]

// Find daily aggregated rows
$rows = $usage->findDaily([
    Query::equal('metric', ['bandwidth']),
    Query::orderDesc('time'),
    Query::limit(30),
]);
```

### Purge

```php
// Purge all event metrics older than 90 days
$usage->purge([
    Query::lessThan('time', '2026-01-01'),
], Usage::TYPE_EVENT);

// Purge all gauge metrics
$usage->purge([], Usage::TYPE_GAUGE);
```

## Architecture

### ClickHouse Tables

| Table | Engine | Purpose |
|-------|--------|---------|
| `{ns}_usage_events` | MergeTree | Raw request events with full metadata |
| `{ns}_usage_gauges` | MergeTree | Resource snapshot gauges |
| `{ns}_usage_events_daily` | SummingMergeTree | Pre-aggregated daily event totals |
| `{ns}_usage_events_daily_mv` | Materialized View | Auto-populates daily table on insert |

### Events Table Schema

| Column | Type | Description |
|--------|------|-------------|
| id | String | UUID |
| metric | String | Metric name (e.g. bandwidth, requests) |
| value | Int64 | Metric value |
| time | DateTime64(3) | Event timestamp |
| path | Nullable(String) | API endpoint path |
| method | Nullable(String) | HTTP method |
| status | Nullable(String) | HTTP status code |
| service | LowCardinality(Nullable(String)) | API service (storage, databases, …) |
| resource | LowCardinality(Nullable(String)) | Resource type (bucket, file, …) |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| country | LowCardinality(Nullable(String)) | ISO country code (lowercased) |
| region | LowCardinality(Nullable(String)) | Region code (lowercased) |
| hostname | Nullable(String) | Caller origin host |
| osCode, osName | LowCardinality(Nullable(String)) | Parsed OS short code / name |
| osVersion | Nullable(String) | Parsed OS version |
| clientType, clientCode, clientName, clientEngine | LowCardinality(Nullable(String)) | Parsed client identity |
| clientVersion, clientEngineVersion | Nullable(String) | Parsed client versions |
| deviceName, deviceBrand | LowCardinality(Nullable(String)) | Parsed device identity |
| deviceModel | Nullable(String) | Parsed device model |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Gauges Table Schema

| Column | Type | Description |
|--------|------|-------------|
| id | String | UUID |
| metric | String | Metric name |
| value | Int64 | Current value |
| time | DateTime64(3) | Snapshot timestamp |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Daily Table Schema

| Column | Type | Description |
|--------|------|-------------|
| metric | String | Metric name |
| value | Int64 | Aggregated daily sum |
| time | DateTime64(3) | Day start timestamp |
| resource | LowCardinality(Nullable(String)) | Resource type |
| resourceId | Nullable(String) | External resource id |
| resourceInternalId | Nullable(String) | Internal resource sequence |
| teamId | Nullable(String) | External team id |
| teamInternalId | Nullable(String) | Internal team sequence |
| tenant | Nullable(String) | Tenant ID (shared tables) |

### Creating Custom Adapters

Extend `Utopia\Usage\Adapter` and implement:

- `getName()`, `setup()`, `healthCheck()`
- `addBatch(array $metrics, string $type, int $batchSize): bool`
- `find(array $queries, ?string $type): array`
- `count(array $queries, ?string $type): int`
- `sum(array $queries, string $attribute, ?string $type): int`
- `getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries, bool $zeroFill, ?string $type): array`
- `getTotal(string $metric, array $queries, ?string $type): int`
- `getTotalBatch(array $metrics, array $queries, ?string $type): array`
- `findDaily(array $queries): array`
- `sumDaily(array $queries, string $attribute): int`
- `sumDailyBatch(array $metrics, array $queries): array`
- `purge(array $queries, ?string $type): bool`
- `setNamespace(string $namespace): self`
- `setTenant(?string $tenant): self`
- `setSharedTables(bool $sharedTables): self`

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
