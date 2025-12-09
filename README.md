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
- **Batch Operations**: Log multiple metrics efficiently
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
**Log Usage**

A simple example for logging a usage metric.

```php
$metric = 'requests';
$value = 100;
$period = '1h'; // Supported periods: '1h', '1d', 'inf'
$tags = ['region' => 'us-east', 'method' => 'GET'];

$usage->log($metric, $value, $period, $tags);
```

**Log Batch Usage**

Log multiple metrics in batch for better performance.

```php
$metrics = [
    [
        'metric' => 'requests',
        'value' => 100,
        'period' => '1h',
        'tags' => ['region' => 'us-east'],
    ],
    [
        'metric' => 'bandwidth',
        'value' => 50000,
        'period' => '1h',
        'tags' => ['region' => 'us-east'],
    ],
];

$usage->logBatch($metrics);
```

**Get Usage By Period**

Fetch all usage metrics by period.

```php
$metrics = $usage->getByPeriod('requests', '1h');
// Returns an array of all usage metrics for specific period
```

**Get Usage Between Dates**

Fetch all usage metrics between two dates.

```php
$start = '2024-01-01 00:00:00';
$end = '2024-01-31 23:59:59';

$metrics = $usage->getBetweenDates('requests', $start, $end);
// Returns an array of usage metrics within the date range
```

**Count and Sum Usage**

Get counts and sums of usage metrics.

```php
// Count total records
$count = $usage->countByPeriod('requests', '1h');

// Sum all values
$sum = $usage->sumByPeriod('requests', '1h');
```

**Purge Old Usage**

Delete old usage metrics.

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
- Easy migration from existing databases

**Example**:
```php
$usage = Usage::withDatabase($database);
```

### ClickHouse Adapter

The ClickHouse adapter uses the HTTP interface to store metrics in ClickHouse for high-performance analytics.

**Features**:
- Optimized for analytical queries
- Handles millions of metrics per second
- Automatic partitioning by month
- Efficient compression and storage
- Bloom filter indexes for fast lookups

**Example**:
```php
$usage = Usage::withClickHouse(
    host: 'clickhouse.example.com',
    username: 'metrics_user',
    password: 'secure_password',
    port: 8123,
    secure: true  // Use HTTPS
);

// Configure database and table (optional)
$adapter = $usage->getAdapter();
$adapter->setDatabase('analytics');
$adapter->setTable('metrics');

$usage->setup();
```

### Creating Custom Adapters

Extend the `Utopia\Usage\Adapter` abstract class and implement these methods:

- `getName(): string` - Return adapter name
- `setup(): void` - Initialize storage structure
- `log(string $metric, int $value, string $period, array $tags): bool` - Log single metric
- `logBatch(array $metrics): bool` - Log multiple metrics
- `getByPeriod(string $metric, string $period, array $queries): array` - Get metrics by period
- `getBetweenDates(string $metric, string $startDate, string $endDate, array $queries): array` - Get metrics in date range
- `countByPeriod(string $metric, string $period, array $queries): int` - Count metrics
- `sumByPeriod(string $metric, string $period, array $queries): int` - Sum metric values
- `purge(string $datetime): bool` - Delete old metrics

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
