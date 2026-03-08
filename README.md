# Utopia Usage

[![Build Status](https://travis-ci.org/utopia-php/usage.svg?branch=master)](https://travis-ci.com/utopia-php/usage)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/usage.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia framework usage library is a simple and lite library for managing application usage statistics. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Features

- **Pluggable Adapters**: Use different storage backends (Database, ClickHouse)
- **Database Adapter**: Store metrics in any SQL database via utopia-php/database
- **ClickHouse Adapter**: High-performance analytics storage for massive scale
- **Flexible Periods**: Hourly (1h), Daily (1d), and Infinite (inf) periods
- **Dual Upsert Semantics**: Additive (`increment`) and replace (`set`) upserts
- **In-Memory Buffering**: Collect metrics and flush in batch for high-throughput scenarios
- **Auto Period Fan-Out**: `increment()`, `set()`, `collect()`, `collectSet()` automatically write to all periods
- **Batch Operations**: `incrementBatch()` and `setBatch()` for efficient bulk writes
- **Async Inserts**: ClickHouse adapter supports server-side async inserts
- **Rich Queries**: Filter, limit, offset, and aggregate metrics
- **Tag Support**: Add custom tags for multi-dimensional analytics

## Getting Started

Install using composer:
```bash
composer require utopia-php/usage
```

### Using Database Adapter

The Database adapter stores metrics using utopia-php/database, supporting MySQL, MariaDB, PostgreSQL, and more.

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PDO;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Usage\Usage;

$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'password';
$dbPort = '3306';

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_TIMEOUT => 3, // Seconds
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);

$cache = new Cache(new NoCache());
$database = new Database(new MySQL($pdo), $cache);
$database->setNamespace('namespace');

// Create Usage instance with Database adapter
$usage = Usage::withDatabase($database);
$usage->setup();
```

### Using ClickHouse Adapter

The ClickHouse adapter provides high-performance analytics storage for massive scale metrics.

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Usage\Usage;

// Create Usage instance with ClickHouse adapter
$usage = Usage::withClickHouse(
    host: 'clickhouse-server',
    username: 'default',
    password: '',
    port: 8123,
    secure: false
);

$usage->setup();
```

### Using Custom Adapter

You can create custom adapters by extending the `Utopia\Usage\Adapter` abstract class.

```php
<?php

use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\Database;

// Create custom adapter instance
$adapter = new Database($database);

// Use with Usage
$usage = Usage::withAdapter($adapter);
$usage->setup();
```

## Metric Types

The library supports two types of metrics with different upsert semantics:

### Increment (Additive Upsert)

Values are **summed** when the same metric/period/time bucket already exists. Use for event-driven counters like request counts, bandwidth, etc.

```php
// Single metric, auto fan-out to all periods (1h, 1d, inf)
$usage->increment('requests', 1);
$usage->increment('bandwidth', 5000, ['region' => 'us-east']);

// Batch with explicit periods
$usage->incrementBatch([
    ['metric' => 'requests', 'value' => 100, 'period' => '1h', 'tags' => ['method' => 'GET']],
    ['metric' => 'bandwidth', 'value' => 50000, 'period' => '1h', 'tags' => ['region' => 'us-east']],
]);
```

### Set (Replace Upsert)

Values **replace** the existing value when the same metric/period/time bucket already exists. Use for periodic recounts or resource gauges (e.g., current storage size, active user count).

```php
// Single metric, auto fan-out to all periods (1h, 1d, inf)
$usage->set('storage.size', 1048576);
$usage->set('users.active', 42, ['plan' => 'pro']);

// Batch with explicit periods
$usage->setBatch([
    ['metric' => 'storage.size', 'value' => 1048576, 'period' => '1h', 'tags' => []],
    ['metric' => 'users.active', 'value' => 42, 'period' => '1d', 'tags' => []],
]);
```

## In-Memory Buffering

For high-throughput scenarios (e.g., inside a request loop or worker), use `collect()` / `collectSet()` to accumulate metrics in memory and `flush()` to write them in batch.

```php
// Accumulate increment metrics (values are summed in-memory)
$usage->collect('requests', 1);
$usage->collect('requests', 1);
$usage->collect('bandwidth', 5000);

// Accumulate set metrics (last-write-wins in-memory)
$usage->collectSet('storage.size', 1048576);

// Check if flush is recommended (threshold or interval reached)
if ($usage->shouldFlush()) {
    $usage->flush();
}

// Or flush explicitly
$usage->flush();
```

### Flush Configuration

```php
// Flush when 5000 collect() calls have been made (default: 10,000)
$usage->setFlushThreshold(5000);

// Flush when 10 seconds have elapsed since last flush (default: 20)
$usage->setFlushInterval(10);
```

## Querying Metrics

**Get Usage By Period**

```php
$metrics = $usage->getByPeriod('requests', '1h');
// Returns an array of Metric objects
```

**Get Usage Between Dates**

```php
$start = '2024-01-01 00:00:00';
$end = '2024-01-31 23:59:59';

$metrics = $usage->getBetweenDates('requests', $start, $end);
```

**Count and Sum Usage**

```php
// Count total records
$count = $usage->countByPeriod('requests', '1h');

// Sum all values
$sum = $usage->sumByPeriod('requests', '1h');
```

**Find with Query Objects**

```php
use Utopia\Usage\Query;

$metrics = $usage->find([
    Query::equal('metric', ['requests', 'bandwidth']),
    Query::greaterThan('value', 100),
    Query::orderDesc('time'),
    Query::limit(10),
]);

$count = $usage->count([
    Query::equal('period', ['1h']),
]);
```

**Purge Old Usage**

```php
use Utopia\Database\DateTime;

$datetime = DateTime::addSeconds(new \DateTime(), -86400); // Delete metrics older than 24 hours
$usage->purge($datetime);
```

## Periods

The library supports three types of periods:

- `1h` - Hourly periods (`Y-m-d H:00`)
- `1d` - Daily periods (`Y-m-d 00:00`)
- `inf` - Infinite/lifetime periods (`0000-00-00 00:00`)

## Adapters

### Database Adapter

The Database adapter uses [utopia-php/database](https://github.com/utopia-php/database) to store metrics in SQL databases.

**Features**:
- Works with MySQL, MariaDB, PostgreSQL, SQLite
- Full query support (filters, sorting, pagination)
- ACID compliance for data consistency
- Additive upsert via `upsertDocumentsWithIncrease`
- Replace upsert via `upsertDocuments`

### ClickHouse Adapter

The ClickHouse adapter uses the HTTP interface to store metrics in ClickHouse for high-performance analytics.

**Features**:
- SummingMergeTree for additive upserts (`usage` table)
- ReplacingMergeTree for replace upserts (`usage_snapshot` table)
- Automatic partitioning by month
- Efficient compression and storage
- Bloom filter indexes for fast lookups
- Async insert support for server-side batching
- Deterministic IDs for correct merge behavior

**Example**:
```php
$usage = Usage::withClickHouse(
    host: 'clickhouse.example.com',
    username: 'metrics_user',
    password: 'secure_password',
    port: 8123,
    secure: true  // Use HTTPS
);

// Enable async inserts (server-side batching)
$adapter = $usage->getAdapter();
$adapter->setAsyncInserts(true, waitForConfirmation: true);

$usage->setup();
```

### Creating Custom Adapters

Extend the `Utopia\Usage\Adapter` abstract class and implement these methods:

- `getName(): string` - Return adapter name
- `setup(): void` - Initialize storage structure
- `healthCheck(): array` - Check adapter health
- `incrementBatch(array $metrics, int $batchSize): bool` - Additive upsert batch
- `setBatch(array $metrics, int $batchSize): bool` - Replace upsert batch
- `getByPeriod(string $metric, string $period, array $queries): array` - Get metrics by period
- `getBetweenDates(string $metric, string $startDate, string $endDate, array $queries): array` - Get metrics in date range
- `countByPeriod(string $metric, string $period, array $queries): int` - Count metrics
- `sumByPeriod(string $metric, string $period, array $queries): int` - Sum metric values
- `purge(string $datetime): bool` - Delete old metrics
- `find(array $queries): array` - Find metrics with query objects
- `count(array $queries): int` - Count metrics with query objects

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
