<?php

namespace Utopia\Usage\Adapter;

use Exception;
use Utopia\Fetch\Client;
use Utopia\Usage\Adapter;
use Utopia\Usage\Metric;

/**
 * ClickHouse Adapter for Usage
 *
 * This adapter stores usage metrics in ClickHouse using HTTP interface.
 * ClickHouse is optimized for analytical queries and can handle massive amounts of metrics data.
 */
class ClickHouse extends Adapter
{
    private const DEFAULT_PORT = 8123;

    private const DEFAULT_TABLE = 'usage';

    private const DEFAULT_DATABASE = 'default';

    /** @var array<string,string> */
    public const PERIODS = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00',
    ];

    private string $host;

    private int $port;

    private string $database = self::DEFAULT_DATABASE;

    private string $table = self::DEFAULT_TABLE;

    private string $username;

    private string $password;

    /** @var bool Whether to use HTTPS for ClickHouse HTTP interface */
    private bool $secure = false;

    private Client $client;

    protected ?int $tenant = null;

    protected bool $sharedTables = false;

    protected string $namespace = '';

    /**
     * @param  string  $host  ClickHouse host
     * @param  string  $username  ClickHouse username (default: 'default')
     * @param  string  $password  ClickHouse password (default: '')
     * @param  int  $port  ClickHouse HTTP port (default: 8123)
     * @param  bool  $secure  Whether to use HTTPS (default: false)
     */
    public function __construct(
        string $host,
        string $username = 'default',
        string $password = '',
        int $port = self::DEFAULT_PORT,
        bool $secure = false
    ) {
        $this->validateHost($host);
        $this->validatePort($port);

        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->secure = $secure;

        $this->client = new Client();
        $this->client->addHeader('X-ClickHouse-User', $this->username);
        $this->client->addHeader('X-ClickHouse-Key', $this->password);
        $this->client->addHeader('X-ClickHouse-Database', $this->database);
    }

    /**
     * Validate host parameter.
     *
     * @throws Exception
     */
    private function validateHost(string $host): void
    {
        if (empty($host)) {
            throw new Exception('ClickHouse host cannot be empty');
        }

        // Allow hostnames, IP addresses, and localhost
        if (! preg_match('/^[a-zA-Z0-9._\-]+$/', $host)) {
            throw new Exception('ClickHouse host must be a valid hostname or IP address');
        }
    }

    /**
     * Validate port parameter.
     *
     * @throws Exception
     */
    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new Exception('ClickHouse port must be between 1 and 65535');
        }
    }

    /**
     * Validate identifier (database, table).
     *
     * @param  string  $type  Name of the identifier type for error messages
     *
     * @throws Exception
     */
    private function validateIdentifier(string $identifier, string $type = 'Identifier'): void
    {
        if (empty($identifier)) {
            throw new Exception("{$type} cannot be empty");
        }

        if (strlen($identifier) > 255) {
            throw new Exception("{$type} cannot exceed 255 characters");
        }

        // ClickHouse identifiers: alphanumeric, underscores, cannot start with number
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception("{$type} must start with a letter or underscore and contain only alphanumeric characters and underscores");
        }

        // Check against SQL keywords
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TABLE', 'DATABASE'];
        if (in_array(strtoupper($identifier), $keywords, true)) {
            throw new Exception("{$type} cannot be a reserved SQL keyword");
        }
    }

    /**
     * Escape an identifier for safe use in SQL.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Escape a string value for safe use in ClickHouse SQL queries.
     *
     * @return string The escaped value without surrounding quotes
     */
    private function escapeString(string $value): string
    {
        return str_replace(
            ['\\', "'"],
            ['\\\\', "''"],
            $value
        );
    }

    /**
     * Set the database name for subsequent operations.
     *
     * @throws Exception
     */
    public function setDatabase(string $database): self
    {
        $this->validateIdentifier($database, 'Database');
        $this->database = $database;
        $this->client->addHeader('X-ClickHouse-Database', $this->database);

        return $this;
    }

    /**
     * Set the table name for subsequent operations.
     *
     * @throws Exception
     */
    public function setTable(string $table): self
    {
        $this->validateIdentifier($table, 'Table');
        $this->table = $table;

        return $this;
    }

    /**
     * Set the tenant ID for multi-tenant support.
     * Tenant is used to isolate usage metrics by tenant.
     *
     * @param  int|null  $tenant
     * @return self
     */
    public function setTenant(?int $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * Get the tenant ID.
     *
     * @return int|null
     */
    public function getTenant(): ?int
    {
        return $this->tenant;
    }

    /**
     * Set whether tables are shared across tenants.
     * When enabled, a tenant column is added to the table for data isolation.
     *
     * @param  bool  $sharedTables
     * @return self
     */
    public function setSharedTables(bool $sharedTables): self
    {
        $this->sharedTables = $sharedTables;
        return $this;
    }

    /**
     * Get whether tables are shared across tenants.
     *
     * @return bool
     */
    public function isSharedTables(): bool
    {
        return $this->sharedTables;
    }

    /**
     * Set the namespace for multi-project support.
     * Namespace is used as a prefix for table names.
     *
     * @param  string  $namespace
     * @return self
     * @throws Exception
     */
    public function setNamespace(string $namespace): self
    {
        if (!empty($namespace)) {
            $this->validateIdentifier($namespace, 'Namespace');
        }
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Get the namespace.
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get the table name with namespace prefix.
     * Namespace is used to isolate tables for different projects/applications.
     *
     * @return string
     */
    private function getTableName(): string
    {
        $tableName = $this->table;

        if (!empty($this->namespace)) {
            $tableName = $this->namespace . '_' . $tableName;
        }

        return $tableName;
    }

    /**
     * Execute a ClickHouse query via HTTP interface.
     *
     * @param  string  $sql  SQL query to execute
     * @param  array<string,mixed>  $params  Query parameters for prepared statements
     * @return string Query result as string
     *
     * @throws Exception
     */
    private function query(string $sql, array $params = []): string
    {
        $protocol = $this->secure ? 'https' : 'http';
        $url = "{$protocol}://{$this->host}:{$this->port}/";

        // Replace parameters in SQL
        foreach ($params as $key => $value) {
            if (is_int($value) || is_float($value)) {
                // Numeric values should not be quoted
                $strValue = (string) $value;
            } elseif (is_string($value)) {
                $strValue = "'" . $this->escapeString($value) . "'";
            } elseif (is_null($value)) {
                $strValue = 'NULL';
            } elseif (is_bool($value)) {
                $strValue = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $encoded = json_encode($value);
                if (is_string($encoded)) {
                    $strValue = "'" . $this->escapeString($encoded) . "'";
                } else {
                    $strValue = 'NULL';
                }
            } else {
                /** @var scalar $value */
                $strValue = "'" . $this->escapeString((string) $value) . "'";
            }
            $sql = str_replace(":{$key}", $strValue, $sql);
        }

        try {
            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_POST,
                body: ['query' => $sql]
            );

            if ($response->getStatusCode() !== 200) {
                $body = $response->getBody();
                $bodyStr = is_string($body) ? $body : '';
                throw new Exception("ClickHouse query failed with HTTP {$response->getStatusCode()}: {$bodyStr}");
            }

            $body = $response->getBody();

            return is_string($body) ? $body : '';
        } catch (Exception $e) {
            throw new Exception(
                "ClickHouse query execution failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getName(): string
    {
        return 'ClickHouse';
    }

    /**
     * Setup ClickHouse table structure.
     *
     * Creates the database and table if they don't exist.
     * Uses the provided column definitions and adds internal fields (_id, _createdAt, _updatedAt, tenant).
     *
     * @param string $table Table name
     * @param array<int,array<string,mixed>> $columns Column definitions from the application
     * @param array<int,array<string,mixed>> $indexes Index definitions from the application
     * @throws Exception
     */
    public function setup(string $table, array $columns, array $indexes): void
    {
        $this->setTable($table);

        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $createDbSql = "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}";
        $this->query($createDbSql);

        // Track which internal fields are already present
        $hasId = false;
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasTenant = false;

        // Build column definitions from provided columns
        $columnDefs = [];
        foreach ($columns as $column) {
            $columnId = $column['$id'] ?? '';

            if ($columnId === '_id' || $columnId === '$id') {
                $hasId = true;
            } elseif ($columnId === '_createdAt' || $columnId === '$createdAt') {
                $hasCreatedAt = true;
            } elseif ($columnId === '_updatedAt' || $columnId === '$updatedAt') {
                $hasUpdatedAt = true;
            } elseif ($columnId === 'tenant' || $columnId === '$tenant') {
                $hasTenant = true;
            }

            $columnDefs[] = $this->getClickHouseColumnDefinition($column);
        }

        // Add internal fields if not present
        if (! $hasId) {
            array_unshift($columnDefs, '_id String');
        }
        if (! $hasCreatedAt) {
            $columnDefs[] = '_createdAt DateTime64(3) DEFAULT now64(3)';
        }
        if (! $hasUpdatedAt) {
            $columnDefs[] = '_updatedAt DateTime64(3) DEFAULT now64(3)';
        }

        // Add tenant column only if tables are shared across tenants and not already present
        if ($this->sharedTables && ! $hasTenant) {
            $columnDefs[] = 'tenant Nullable(UInt64)';
        }

        // Build indexes from provided index definitions
        $indexDefs = [];
        foreach ($indexes as $index) {
            $indexId = $index['$id'] ?? '';
            $attributes = $index['attributes'] ?? [];

            if (! empty($indexId) && is_string($indexId) && is_array($attributes) && ! empty($attributes)) {
                /** @var array<string> $attributes */
                $attributeList = implode(', ', $attributes);
                // ClickHouse doesn't allow hyphens in index names, replace with underscores
                $safeIndexId = str_replace('-', '_', $indexId);
                $indexDefs[] = 'INDEX ' . $safeIndexId . ' (' . $attributeList . ') TYPE bloom_filter GRANULARITY 1';
            }
        }

        // Add tenant index if tables are shared across tenants
        if ($this->sharedTables) {
            $indexDefs[] = 'INDEX idx_tenant tenant TYPE bloom_filter GRANULARITY 1';
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        // Determine ORDER BY clause - use first index or default
        $orderBy = '_createdAt';
        if (! empty($indexes) && isset($indexes[0]['attributes']) && is_array($indexes[0]['attributes'])) {
            /** @var array<string> $orderAttributes */
            $orderAttributes = $indexes[0]['attributes'];
            $orderBy = implode(', ', $orderAttributes);
        }

        // Create table with MergeTree engine for optimal performance
        // ClickHouse indexes must be defined inside the column list
        $indexClause = ! empty($indexDefs) ? ",\n                " . implode(",\n                ", $indexDefs) : '';
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDatabaseAndTable} (
                " . implode(",\n                ", $columnDefs) . $indexClause . "
            )
            ENGINE = MergeTree()
            ORDER BY ({$orderBy})
            PARTITION BY toYYYYMM(_createdAt)
            SETTINGS index_granularity = 8192
        ";

        $this->query($createTableSql);
    }

    /**
     * Convert a column definition to ClickHouse column syntax.
     *
     * @param array<string,mixed> $column Column definition
     * @return string ClickHouse column definition
     */
    private function getClickHouseColumnDefinition(array $column): string
    {
        $columnId = $column['$id'] ?? '';
        $type = $column['type'] ?? 'string';
        $required = $column['required'] ?? false;
        $size = $column['size'] ?? 0;

        // Map Utopia Database types to ClickHouse types
        $clickHouseType = match ($type) {
            'string' => $size > 0 && $size <= 255 ? 'String' : 'String',
            'integer' => 'Int64',
            'float' => 'Float64',
            'boolean' => 'UInt8',
            'datetime' => 'DateTime64(3)',
            'json' => 'String',  // Store JSON as string
            default => 'String',
        };

        // Add Nullable wrapper if not required
        if (! $required && $type !== 'boolean') {
            $clickHouseType = 'Nullable(' . $clickHouseType . ')';
        }

        return $columnId . ' ' . $clickHouseType;
    }

    /**
     * Log a usage metric.
     *
     * @param  array<string,mixed>  $tags
     *
     * @throws Exception
     */
    public function log(string $metric, int $value, string $period = '1h', array $tags = []): bool
    {
        if (! isset(self::PERIODS[$period])) {
            throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(self::PERIODS)));
        }

        $id = uniqid('', true);
        $now = new \DateTime();
        $time = $now->format(self::PERIODS[$period]);

        // Format timestamp for ClickHouse DateTime64(3)
        $microtime = microtime(true);
        $timestamp = date('Y-m-d H:i:s', (int) $microtime) . '.' . sprintf('%03d', ($microtime - floor($microtime)) * 1000);

        // Build column list and values based on sharedTables setting
        $columns = ['id', 'metric', 'value', 'period', 'time', 'tags'];
        $placeholders = [':id', ':metric', ':value', ':period', ':time', ':tags'];

        $params = [
            'id' => $id,
            'metric' => $metric,
            'value' => $value,
            'period' => $period,
            'time' => $timestamp,
            'tags' => json_encode($tags),
        ];

        if ($this->sharedTables) {
            $columns[] = 'tenant';
            $placeholders[] = ':tenant';
            $params['tenant'] = $this->tenant;
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $sql = "
            INSERT INTO {$escapedDatabaseAndTable}
            (" . implode(', ', $columns) . ")
            VALUES (
                " . implode(", ", $placeholders) . "
            )
        ";

        $this->query($sql, $params);

        return true;
    }

    /**
     * Log multiple usage metrics in batch.
     *
     * @param  array<int,array<string,mixed>>  $metrics
     *
     * @throws Exception
     */
    public function logBatch(array $metrics): bool
    {
        if (empty($metrics)) {
            return true;
        }

        $values = [];
        foreach ($metrics as $metricData) {
            $period = $metricData['period'] ?? '1h';

            if (! isset(self::PERIODS[$period])) {
                throw new \InvalidArgumentException('Invalid period. Allowed: ' . implode(', ', array_keys(self::PERIODS)));
            }

            $id = uniqid('', true);
            $microtime = microtime(true);
            $timestamp = date('Y-m-d H:i:s', (int) $microtime) . '.' . sprintf('%03d', ($microtime - floor($microtime)) * 1000);

            $metric = $metricData['metric'];
            $value = $metricData['value'];
            assert(is_string($metric));
            assert(is_int($value));

            if ($this->sharedTables) {
                $tenant = $this->tenant !== null ? (int) $this->tenant : 'NULL';
                $values[] = sprintf(
                    "('%s', '%s', %d, '%s', '%s', '%s', %s)",
                    $id,
                    $this->escapeString($metric),
                    $value,
                    $this->escapeString($period),
                    $timestamp,
                    $this->escapeString((string) json_encode($metricData['tags'] ?? [])),
                    $tenant
                );
            } else {
                $values[] = sprintf(
                    "('%s', '%s', %d, '%s', '%s', '%s')",
                    $id,
                    $this->escapeString($metric),
                    $value,
                    $this->escapeString($period),
                    $timestamp,
                    $this->escapeString((string) json_encode($metricData['tags'] ?? []))
                );
            }
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        // Build column list based on sharedTables setting
        $columns = 'id, metric, value, period, time, tags';
        if ($this->sharedTables) {
            $columns .= ', tenant';
        }

        $insertSql = "
            INSERT INTO {$escapedDatabaseAndTable}
            ({$columns})
            VALUES " . implode(', ', $values);

        $this->query($insertSql);

        return true;
    }

    /**
     * Parse ClickHouse TabSeparated results into Metric array.
     *
     * @return array<Metric>
     */
    private function parseResults(string $result): array
    {
        if (empty(trim($result))) {
            return [];
        }

        $lines = explode("\n", trim($result));
        $metrics = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $columns = explode("\t", $line);
            $expectedColumns = $this->sharedTables ? 7 : 6;
            if (count($columns) < $expectedColumns) {
                continue;
            }

            $data = [
                '$id' => (string) $columns[0],
                'metric' => (string) $columns[1],
                'value' => (int) $columns[2],
                'period' => (string) $columns[3],
                'time' => (string) $columns[4],
                'tags' => json_decode((string) $columns[5], true) ?? [],
            ];

            // Add tenant only if sharedTables is enabled
            if ($this->sharedTables && isset($columns[6])) {
                $data['tenant'] = $columns[6] === '\\\\N' ? null : (int) $columns[6];
            }

            $metrics[] = new Metric($data);
        }

        return $metrics;
    }

    /**
     * Get the SELECT column list for queries.
     * Returns 6 columns if not using shared tables, 7 if using shared tables.
     *
     * @return string
     */
    private function getSelectColumns(): string
    {
        if ($this->sharedTables) {
            return 'id, metric, value, period, time, tags, tenant';
        }
        return 'id, metric, value, period, time, tags';
    }

    /**
     * Build tenant filter clause based on current tenant context.
     *
     * @return string
     */
    private function getTenantFilter(): string
    {
        if (!$this->sharedTables || $this->tenant === null) {
            return '';
        }

        return " AND tenant = {$this->tenant}";
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<int,mixed>  $queries
     * @return array<Metric>
     *
     * @throws Exception
     */
    public function getByPeriod(string $metric, string $period, array $queries = []): array
    {
        $limit = 25;
        $offset = 0;

        foreach ($queries as $query) {
            if (is_object($query) && method_exists($query, 'getMethod') && method_exists($query, 'getValue')) {
                if ($query->getMethod() === 'limit') {
                    $limit = (int) $query->getValue();
                } elseif ($query->getMethod() === 'offset') {
                    $offset = (int) $query->getValue();
                }
            }
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $tenantFilter = $this->getTenantFilter();

        $sql = "
            SELECT " . $this->getSelectColumns() . "
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period{$tenantFilter}
            ORDER BY time DESC
            LIMIT :limit OFFSET :offset
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, [
            'metric' => $metric,
            'period' => $period,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return $this->parseResults($result);
    }

    /**
     * Get usage metrics between dates.
     *
     * @param  array<int,mixed>  $queries
     * @return array<Metric>
     *
     * @throws Exception
     */
    public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array
    {
        $limit = 25;
        $offset = 0;

        foreach ($queries as $query) {
            if (is_object($query) && method_exists($query, 'getMethod') && method_exists($query, 'getValue')) {
                if ($query->getMethod() === 'limit') {
                    $limit = (int) $query->getValue();
                } elseif ($query->getMethod() === 'offset') {
                    $offset = (int) $query->getValue();
                }
            }
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $tenantFilter = $this->getTenantFilter();

        $sql = "
            SELECT " . $this->getSelectColumns() . "
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND time >= :startDate AND time <= :endDate{$tenantFilter}
            ORDER BY time DESC
            LIMIT :limit OFFSET :offset
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, [
            'metric' => $metric,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return $this->parseResults($result);
    }

    /**
     * Count usage metrics by period.
     *
     * @param  array<int,mixed>  $queries
     *
     * @throws Exception
     */
    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $tenantFilter = $this->getTenantFilter();

        $sql = "
            SELECT count() as count
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period{$tenantFilter}
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, [
            'metric' => $metric,
            'period' => $period,
        ]);

        return (int) trim($result);
    }

    /**
     * Sum usage metric values by period.
     *
     * @param  array<int,mixed>  $queries
     *
     * @throws Exception
     */
    public function sumByPeriod(string $metric, string $period, array $queries = []): int
    {
        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $tenantFilter = $this->getTenantFilter();

        $sql = "
            SELECT sum(value) as total
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period{$tenantFilter}
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, [
            'metric' => $metric,
            'period' => $period,
        ]);

        $total = trim($result);

        return empty($total) ? 0 : (int) $total;
    }

    /**
     * Purge usage metrics older than the specified datetime.
     *
     * @throws Exception
     */
    public function purge(string $datetime): bool
    {
        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $tenantFilter = $this->getTenantFilter();

        $sql = "
            DELETE FROM {$escapedDatabaseAndTable}
            WHERE time < :datetime{$tenantFilter}
        ";

        $this->query($sql, ['datetime' => $datetime]);

        return true;
    }
}
