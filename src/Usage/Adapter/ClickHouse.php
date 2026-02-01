<?php

namespace Utopia\Usage\Adapter;

use Exception;
use Utopia\Usage\Query;
use Utopia\Fetch\Client;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;
use Utopia\Validator\Hostname;

/**
 * ClickHouse Adapter for Usage
 *
 * This adapter stores usage metrics in ClickHouse using HTTP interface.
 * ClickHouse is optimized for analytical queries and can handle massive amounts of metrics data.
 *
 * Features:
 * - Dynamic schema based on SQL adapter attributes (no hardcoded columns)
 * - Safe SQL injection prevention using ClickHouse parameter binding
 * - Support for find() and count() operations with Query objects
 * - Multi-tenant support with optional shared tables
 * - Namespace support for table name prefixes
 * - Proper index creation for optimized analytical queries
 * - Bloom filter indexes for efficient filtering
 * - MergeTree engine with monthly partitioning by time
 */
class ClickHouse extends SQL
{
    private const DEFAULT_PORT = 8123;

    private const DEFAULT_DATABASE = 'default';

    private const DEFAULT_TABLE = self::COLLECTION;

    private const DEFAULT_COUNTER_TABLE = self::COLLECTION . '_counter';

    private const INSERT_BATCH_SIZE = 1_000;

    private string $host;

    private int $port;

    private string $database = self::DEFAULT_DATABASE;

    private string $table = self::DEFAULT_TABLE;

    private string $username;

    private string $password;

    /** @var bool Whether to use HTTPS for ClickHouse HTTP interface */
    private bool $secure = false;

    private Client $client;

    /** @var bool Whether to use FINAL in SELECT queries to force merge-on-read (tests) */
    private bool $useFinal = true;

    protected ?int $tenant = null;

    protected bool $sharedTables = false;

    protected string $namespace = '';

    /** @var bool Whether to log queries for debugging */
    private bool $enableQueryLogging = false;

    /** @var array<array{sql: string, params: array<string, mixed>, duration: float, timestamp: float, success: bool, error?: string}> Query execution log */
    private array $queryLog = [];

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

        // Initialize the HTTP client for connection reuse
        $this->client = new Client();
        $this->client->addHeader('X-ClickHouse-User', $this->username);
        $this->client->addHeader('X-ClickHouse-Key', $this->password);
        $this->client->setTimeout(30_000); // 30 seconds
    }

    /**
     * Enable or disable using FINAL in SELECT queries.
     */
    public function setUseFinal(bool $useFinal): self
    {
        $this->useFinal = $useFinal;
        return $this;
    }

    /**
     * Set the HTTP request timeout in milliseconds.
     *
     * @param int $milliseconds Timeout in milliseconds (min: 1000ms, max: 600000ms)
     * @return self
     * @throws Exception If timeout is out of valid range
     */
    public function setTimeout(int $milliseconds): self
    {
        if ($milliseconds < 1000) {
            throw new Exception('Timeout must be at least 1000 milliseconds (1 second)');
        }
        if ($milliseconds > 600000) {
            throw new Exception('Timeout cannot exceed 600000 milliseconds (10 minutes)');
        }
        $this->client->setTimeout($milliseconds);
        return $this;
    }

    /**
     * Enable or disable query logging for debugging.
     *
     * @param bool $enable Whether to enable query logging
     * @return self
     */
    public function enableQueryLogging(bool $enable = true): self
    {
        $this->enableQueryLogging = $enable;
        return $this;
    }

    /**
     * Get the query execution log.
     *
     * @return array<array{sql: string, params: array<string, mixed>, duration: float, timestamp: float, success: bool, error?: string}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Clear the query execution log.
     *
     * @return self
     */
    public function clearQueryLog(): self
    {
        $this->queryLog = [];
        return $this;
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return 'ClickHouse';
    }

    /**
     * Check ClickHouse connection health and get server information.
     *
     * @return array{healthy: bool, host: string, port: int, database: string, secure: bool, version?: string, uptime?: int, error?: string, response_time?: float}
     */
    public function healthCheck(): array
    {
        $startTime = microtime(true);
        $result = [
            'healthy' => false,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'secure' => $this->secure,
        ];

        try {
            // Simple connectivity test
            $response = $this->query('SELECT 1 as ping FORMAT JSON');
            $json = json_decode($response, true);

            if (!is_array($json) || !isset($json['data'][0]['ping'])) {
                $result['error'] = 'Invalid response format';
                return $result;
            }

            // Get server version and uptime
            try {
                $versionResponse = $this->query('SELECT version() as version, uptime() as uptime FORMAT JSON');
                $versionJson = json_decode($versionResponse, true);

                if (is_array($versionJson) && isset($versionJson['data'][0])) {
                    $result['version'] = (string) $versionJson['data'][0]['version'];
                    $result['uptime'] = (int) $versionJson['data'][0]['uptime'];
                }
            } catch (Exception $e) {
                // Version info is optional, don't fail health check
            }

            $result['healthy'] = true;
            $result['response_time'] = round(microtime(true) - $startTime, 3);

            return $result;
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $result['response_time'] = round(microtime(true) - $startTime, 3);
            return $result;
        }
    }

    /**
     * Validate host parameter.
     *
     * @param string $host
     * @throws Exception
     */
    private function validateHost(string $host): void
    {
        $validator = new Hostname();
        if (!$validator->isValid($host)) {
            throw new Exception('ClickHouse host is not a valid hostname or IP address');
        }
    }

    /**
     * Validate port parameter.
     *
     * @param int $port
     * @throws Exception
     */
    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new Exception('ClickHouse port must be between 1 and 65535');
        }
    }

    /**
     * Validate identifier (database, table, namespace).
     * ClickHouse identifiers follow SQL standard rules.
     *
     * @param string $identifier
     * @param string $type Name of the identifier type for error messages
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
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception("{$type} must start with a letter or underscore and contain only alphanumeric characters and underscores");
        }

        // Check against SQL keywords (common ones)
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TABLE', 'DATABASE'];
        if (in_array(strtoupper($identifier), $keywords, true)) {
            throw new Exception("{$type} cannot be a reserved SQL keyword");
        }
    }

    /**
     * Escape an identifier (database name, table name, column name) for safe use in SQL.
     * Uses backticks as per SQL standard for identifier quoting.
     *
     * @param string $identifier
     * @return string
     */
    private function escapeIdentifier(string $identifier): string
    {
        // Backtick escaping: replace any backticks in the identifier with double backticks
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Set the namespace for multi-project support.
     * Namespace is used as a prefix for table names.
     *
     * @param string $namespace
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
     * Set the database name for subsequent operations.
     *
     * @param string $database
     * @return self
     * @throws Exception
     */
    public function setDatabase(string $database): self
    {
        $this->validateIdentifier($database, 'Database');
        $this->database = $database;
        return $this;
    }

    /**
     * Enable or disable HTTPS for ClickHouse HTTP interface.
     */
    public function setSecure(bool $secure): self
    {
        $this->secure = $secure;
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
     * Set the tenant ID for multi-tenant support.
     * Tenant is used to isolate metrics by tenant.
     *
     * @param int|null $tenant
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
     * @param bool $sharedTables
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
     * Get the counter table name with namespace prefix.
     * Counter table stores logs as individual entries without aggregation.
     *
     * @return string
     */
    private function getCounterTableName(): string
    {
        $tableName = self::DEFAULT_COUNTER_TABLE;

        if (!empty($this->namespace)) {
            $tableName = $this->namespace . '_' . $tableName;
        }

        return $tableName;
    }

    /**
     * Build a fully qualified table reference with database, escaping, and optional FINAL clause.
     *
     * @param string $tableName The table name (with namespace already applied)
     * @param bool $useFinal Whether to append FINAL clause (defaults to adapter's useFinal setting)
     * @return string Fully qualified table reference
     */
    private function buildTableReference(string $tableName, ?bool $useFinal = null): string
    {
        $useFinal = $useFinal ?? $this->useFinal;
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        return $escapedTable . ($useFinal ? ' FINAL' : '');
    }

    /**
     * Log a query execution for debugging purposes.
     *
     * @param string $sql SQL query executed
     * @param array<string, mixed> $params Query parameters
     * @param float $duration Execution duration in seconds
     * @param bool $success Whether the query succeeded
     * @param string|null $error Error message if query failed
     */
    private function logQuery(string $sql, array $params, float $duration, bool $success, ?string $error = null): void
    {
        if (!$this->enableQueryLogging) {
            return;
        }

        $logEntry = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'timestamp' => microtime(true),
            'success' => $success,
        ];

        if ($error !== null) {
            $logEntry['error'] = $error;
        }

        $this->queryLog[] = $logEntry;
    }

    /**
     * Execute a ClickHouse query via HTTP interface using Fetch Client.
     *
     * Uses ClickHouse query parameters (sent as POST multipart form data) to prevent SQL injection.
     * This is ClickHouse's native parameter mechanism - parameters are safely
     * transmitted separately from the query structure.
     *
     * Parameters are referenced in the SQL using the syntax: {paramName:Type}.
     * For example: SELECT * WHERE id = {id:String}
     *
     * ClickHouse handles all parameter escaping and type conversion internally,
     * making this approach fully injection-safe without needing manual escaping.
     *
     * Using POST body avoids URL length limits for batch operations with many parameters.
     * Equivalent to: curl -X POST -F 'query=...' -F 'param_key=value' http://host/
     *
     * @param array<string, mixed> $params Key-value pairs for query parameters
     * @throws Exception
     */
    private function query(string $sql, array $params = []): string
    {
        $startTime = microtime(true);
        $scheme = $this->secure ? 'https' : 'http';
        $url = "{$scheme}://{$this->host}:{$this->port}/";

        // Update the database header for each query (in case setDatabase was called)
        $this->client->addHeader('X-ClickHouse-Database', $this->database);

        // Build multipart form data body with query and parameters
        // The Fetch client will automatically encode arrays as multipart/form-data
        $body = ['query' => $sql];
        foreach ($params as $key => $value) {
            $body['param_' . $key] = $this->formatParamValue($value);
        }

        try {
            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_POST,
                body: $body
            );
            if ($response->getStatusCode() !== 200) {
                $bodyStr = $response->getBody();
                $bodyStr = is_string($bodyStr) ? $bodyStr : '';
                $duration = microtime(true) - $startTime;
                $errorMsg = "ClickHouse query failed with HTTP {$response->getStatusCode()}: {$bodyStr}";
                $this->logQuery($sql, $params, $duration, false, $errorMsg);
                throw new Exception($errorMsg);
            }

            $body = $response->getBody();
            $result = is_string($body) ? $body : '';
            $duration = microtime(true) - $startTime;
            $this->logQuery($sql, $params, $duration, true);
            return $result;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->logQuery($sql, $params, $duration, false, $e->getMessage());
            // Preserve the original exception context for better debugging
            // Re-throw with additional context while maintaining the original exception chain
            throw new Exception(
                "ClickHouse query execution failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Execute a ClickHouse INSERT using JSONEachRow format.
     *
     * This is significantly more efficient than SQL parameter binding for batch inserts.
     *
     * @param string $table Table name
     * @param array<string> $data Array of JSON strings (one per row)
     * @throws Exception
     */
    private function insert(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $startTime = microtime(true);
        $scheme = $this->secure ? 'https' : 'http';
        $escapedTable = $this->escapeIdentifier($table);
        $url = "{$scheme}://{$this->host}:{$this->port}/?query=INSERT+INTO+{$escapedTable}+FORMAT+JSONEachRow";

        // Update the database header
        $this->client->addHeader('X-ClickHouse-Database', $this->database);
        $this->client->addHeader('Content-Type', 'application/x-ndjson');

        // Join JSON strings with newlines
        $body = implode("\n", $data);

        $sql = "INSERT INTO {$escapedTable} FORMAT JSONEachRow";
        $params = ['rows' => count($data), 'bytes' => strlen($body)];

        try {
            $response = $this->client->fetch(
                url: $url,
                method: Client::METHOD_POST,
                body: $body
            );

            if ($response->getStatusCode() !== 200) {
                $bodyStr = $response->getBody();
                $bodyStr = is_string($bodyStr) ? $bodyStr : '';
                $duration = microtime(true) - $startTime;
                $errorMsg = "ClickHouse insert failed with HTTP {$response->getStatusCode()}: {$bodyStr}";
                $this->logQuery($sql, $params, $duration, false, $errorMsg);
                throw new Exception($errorMsg);
            }

            $duration = microtime(true) - $startTime;
            $this->logQuery($sql, $params, $duration, true);
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->logQuery($sql, $params, $duration, false, $e->getMessage());
            throw new Exception(
                "ClickHouse insert execution failed: {$e->getMessage()}",
                0,
                $e
            );
        } finally {
            // Clean up Content-Type to avoid affecting other queries
            $this->client->removeHeader('Content-Type');
        }
    }

    /**
     * Format a parameter value for safe transmission to ClickHouse.
     *
     * Converts PHP values to their string representation without SQL quoting.
     * ClickHouse's query parameter mechanism handles type conversion and escaping.
     *
     * @param mixed $value
     * @return string
     */
    private function formatParamValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $encoded = json_encode($value);
            return is_string($encoded) ? $encoded : '';
        }

        if (is_string($value)) {
            return $value;
        }

        // For objects or other types, attempt to convert to string
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Setup ClickHouse table structure.
     *
     * Creates the database and table if they don't exist.
     * Uses schema definitions from the base SQL adapter.
     *
     * @throws Exception
     */
    public function setup(): void
    {
        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $createDbSql = "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}";
        $this->query($createDbSql);

        // Build column definitions from base adapter schema
        $columns = [
            'id String',
        ];

        foreach ($this->getAttributes() as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];

            // Special handling for time column - must be NOT NULL for partition key
            if ($id === 'time') {
                // Use DateTime64(3) without Nullable wrapper for time since it's used as partition key
                $columns[] = 'time DateTime64(3)';
            } else {
                $columns[] = $this->getColumnDefinition($id);
            }
        }

        // Add tenant column only if tables are shared across tenants
        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(UInt64)';  // Supports 11-digit MySQL auto-increment IDs
        }

        // Build indexes from base adapter schema
        $indexes = [];
        foreach ($this->getIndexes() as $index) {
            /** @var string $indexName */
            $indexName = $index['$id'];
            /** @var array<string> $attributes */
            $attributes = $index['attributes'];
            // Escape index name and attribute names to prevent SQL injection
            $escapedIndexName = $this->escapeIdentifier($indexName);
            $escapedAttributes = array_map(fn ($attr) => $this->escapeIdentifier($attr), $attributes);
            $attributeList = implode(', ', $escapedAttributes);
            $indexes[] = "INDEX {$escapedIndexName} ({$attributeList}) TYPE bloom_filter GRANULARITY 1";
        }

        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        // Create aggregated table with SummingMergeTree engine so inserts act as increments for matching keys
        $columnDefs = implode(",\n                ", $columns);
        $indexDefs = !empty($indexes) ? ",\n                " . implode(",\n                ", $indexes) : '';

        $orderByExpr = $this->sharedTables ? '(tenant, id)' : '(id)';

        $createTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDatabaseAndTable} (
                {$columnDefs}{$indexDefs}
            )
            ENGINE = SummingMergeTree()
            ORDER BY {$orderByExpr}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($createTableSql);

        // Create counter table with ReplacingMergeTree engine (replaces on duplicate ORDER BY key)
        $counterTableName = $this->getCounterTableName();
        $escapedCounterDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);

        $createCounterTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedCounterDatabaseAndTable} (
                {$columnDefs}{$indexDefs}
            )
            ENGINE = ReplacingMergeTree()
            ORDER BY {$orderByExpr}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($createCounterTableSql);
    }

    /**
     * Validate that an attribute name exists in the schema.
     * Prevents SQL injection by ensuring only valid column names are used.
     *
     * @param string $attributeName The attribute name to validate
     * @return bool True if valid
     * @throws Exception If attribute name is invalid
     */
    private function validateAttributeName(string $attributeName): bool
    {

        // Special case: 'id' is always valid
        if ($attributeName === 'id') {
            return true;
        }

        // Check if tenant is valid (only when sharedTables is enabled)
        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        // Check against defined attributes
        foreach ($this->getAttributes() as $attribute) {
            if ($attribute['$id'] === $attributeName) {
                return true;
            }
        }

        throw new Exception("Invalid attribute name: {$attributeName}");
    }

    /**
     * Format datetime for ClickHouse compatibility.
     * Converts datetime to 'YYYY-MM-DD HH:MM:SS.mmm' format without timezone suffix.
     * ClickHouse DateTime64(3) type expects this format as timezone is handled by column metadata.
     * Works with DateTime objects, strings, and other datetime representations.
     *
     * @param \DateTime|string|null $dateTime The datetime value to format
     * @return string The formatted datetime string in ClickHouse compatible format
     * @throws Exception If the datetime string cannot be parsed
     */
    private function formatDateTime($dateTime): string
    {
        if ($dateTime === null) {
            return (new \DateTime())->format('Y-m-d H:i:s.v');
        }

        if ($dateTime instanceof \DateTime) {
            return $dateTime->format('Y-m-d H:i:s.v');
        }

        if (is_string($dateTime)) {
            try {
                // Parse the datetime string, handling ISO 8601 format with timezone
                $dt = new \DateTime($dateTime);
                return $dt->format('Y-m-d H:i:s.v');
            } catch (\Exception $e) {
                throw new Exception("Invalid datetime string: {$dateTime}");
            }
        }

        /** @phpstan-ignore-next-line */
        throw new Exception("Invalid datetime value type: " . gettype($dateTime));
    }
    /**
     * Get ClickHouse-specific SQL column definition for a given attribute ID.
     *
     * Dynamically determines the ClickHouse type based on attribute metadata and nullability
     *
     * @param string $id The attribute ID
     * @return string ClickHouse column definition
     * @throws Exception
     */
    /**
     * Get ClickHouse type for an attribute.
     *
     * Maps PHP attribute types to ClickHouse types and applies Nullable wrapper.
     *
     * @param string $id Attribute identifier
     * @return string ClickHouse type (e.g., "String", "Nullable(Int64)", "DateTime64(3)")
     * @throws Exception
     */
    private function getColumnType(string $id): string
    {
        $attribute = $this->getAttribute($id);
        if (!$attribute) {
            throw new Exception("Attribute {$id} not found");
        }

        // Map attribute type to ClickHouse type
        $attributeType = is_string($attribute['type'] ?? null) ? $attribute['type'] : 'string';
        $baseType = match ($attributeType) {
            'integer' => 'Int64',
            'float' => 'Float64',
            'boolean' => 'UInt8',
            'datetime' => 'DateTime64(3)',
            default => 'String',
        };

        // Add Nullable wrapper if not required
        return !$attribute['required'] ? 'Nullable(' . $baseType . ')' : $baseType;
    }

    protected function getColumnDefinition(string $id): string
    {
        $type = $this->getColumnType($id);
        $escapedId = $this->escapeIdentifier($id);
        return "{$escapedId} {$type}";
    }

    /**
     * Validate a metric's basic structure and constraints.
     *
     * @param string $metric Metric name
     * @param int $value Metric value
     * @param string $period Period identifier
     * @param array<string,mixed> $tags Tags
     * @param int|null $metricIndex Index for batch error messages
     * @throws Exception
     */
    private function validateMetricData(string $metric, int $value, string $period, array $tags, ?int $metricIndex = null): void
    {
        $prefix = $metricIndex !== null ? "Metric #{$metricIndex}: " : '';

        if (empty($metric)) {
            throw new Exception($prefix . 'Metric cannot be empty');
        }

        if (strlen($metric) > 255) {
            throw new Exception($prefix . 'Metric exceeds maximum size of 255 characters');
        }

        if ($value < 0) {
            throw new Exception($prefix . 'Value cannot be negative');
        }

        if (!isset(Usage::PERIODS[$period])) {
            throw new \InvalidArgumentException($prefix . 'Invalid period. Allowed: ' . implode(', ', array_keys(Usage::PERIODS)));
        }

        if (!is_array($tags)) {
            throw new Exception($prefix . 'Tags must be an array');
        }

        // Validate complete data structure using Metric class
        $data = [
            'metric' => $metric,
            'value' => $value,
            'period' => $period,
            'tags' => $tags,
        ];
        Metric::validate($data);
    }


    /**
     * Log a usage metric.
     *
     * @param  array<string,mixed>  $tags
     *
     * @throws Exception
     */
    public function log(string $metric, int $value, string $period = Usage::PERIOD_1H, array $tags = []): bool
    {
        return $this->logBatch([
            [
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'tags' => $tags,
            ]
        ]);
    }

    /**
     * Log a usage counter metric (uses deterministic ID, replaces if ID matches).
     *
     * @param  array<string,mixed>  $tags
     *
     * @throws Exception
     */
    public function logCounter(string $metric, int $value, string $period = Usage::PERIOD_1H, array $tags = []): bool
    {
        return $this->logBatchCounter([
            [
                'metric' => $metric,
                'value' => $value,
                'period' => $period,
                'tags' => $tags,
            ]
        ]);
    }

    /**
     * Log multiple usage counter metrics in batch (individual entries without aggregation).
     *
     * @param  array<int,array<string,mixed>>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     *
     * @throws Exception
     */
    public function logBatchCounter(array $metrics, int $batchSize = self::INSERT_BATCH_SIZE): bool
    {
        if (empty($metrics)) {
            return true;
        }

        // Validate all metrics before processing
        $this->validateMetricsBatch($metrics);

        // Ensure batch size is within acceptable range
        $batchSize = \min(self::INSERT_BATCH_SIZE, \max(1, $batchSize));

        $counterTableName = $this->getCounterTableName();

        // Process metrics in batches
        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $rows = [];

            foreach ($metricsBatch as $metricData) {
                // Prepare row data
                $row = $this->prepareMetricRow($metricData);
                if ($row) {
                    $encoded = json_encode($row);
                    if ($encoded === false) {
                        throw new Exception("Failed to JSON encode metric row: " . json_last_error_msg());
                    }
                    $rows[] = $encoded;
                }
            }

            if (!empty($rows)) {
                $this->insert($counterTableName, $rows);
            }
        }

        return true;
    }

    /**
     * Validate all metrics in a batch before processing.
     *
     * @param array<int,array<string,mixed>> $metrics
     * @throws Exception
     */
    private function validateMetricsBatch(array $metrics): void
    {
        foreach ($metrics as $index => $metricData) {
            // Validate required fields exist
            if (!isset($metricData['metric'])) {
                throw new Exception("Metric #{$index}: 'metric' is required");
            }
            if (!isset($metricData['value'])) {
                throw new Exception("Metric #{$index}: 'value' is required");
            }

            $metric = $metricData['metric'];
            $value = $metricData['value'];
            $period = $metricData['period'] ?? Usage::PERIOD_1H;

            // Validate types
            if (!is_string($metric)) {
                throw new Exception("Metric #{$index}: 'metric' must be a string, got " . gettype($metric));
            }
            if (!is_int($value)) {
                throw new Exception("Metric #{$index}: 'value' must be an integer, got " . gettype($value));
            }
            if (!is_string($period)) {
                throw new Exception("Metric #{$index}: 'period' must be a string, got " . gettype($period));
            }

            /** @var array<string, mixed> */
            $tags = $metricData['tags'] ?? [];
            $this->validateMetricData($metric, $value, $period, $tags, $index);

            // Validate tenant when provided (metric-level tenant overrides adapter tenant)
            if (array_key_exists('tenant', $metricData)) {
                $tenantValue = $metricData['$tenant'];

                if ($tenantValue !== null) {
                    if (is_int($tenantValue)) {
                        if ($tenantValue < 0) {
                            throw new Exception("Metric #{$index}: 'tenant' cannot be negative");
                        }
                    } elseif (is_string($tenantValue) && ctype_digit($tenantValue)) {
                        // ok numeric string
                    } else {
                        throw new Exception("Metric #{$index}: 'tenant' must be a non-negative integer, got " . gettype($tenantValue));
                    }
                }
            }
        }
    }

    /**
     * Log multiple usage metrics in batch.
     *
     * @param  array<int,array<string,mixed>>  $metrics
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     *
     * @throws Exception
     */
    public function logBatch(array $metrics, int $batchSize = self::INSERT_BATCH_SIZE): bool
    {
        if (empty($metrics)) {
            return true;
        }

        // Validate all metrics before processing
        $this->validateMetricsBatch($metrics);

        // Ensure batch size is within acceptable range
        $batchSize = \min(self::INSERT_BATCH_SIZE, \max(1, $batchSize));

        $tableName = $this->getTableName();

        // Process metrics in batches
        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $rows = [];

            foreach ($metricsBatch as $metricData) {
                // Prepare row data
                $row = $this->prepareMetricRow($metricData);
                if ($row) {
                    $encoded = json_encode($row);
                    if ($encoded === false) {
                        throw new Exception("Failed to JSON encode metric row: " . json_last_error_msg());
                    }
                    $rows[] = $encoded;
                }
            }

            if (!empty($rows)) {
                $this->insert($tableName, $rows);
            }
        }

        return true;
    }

    /**
     * Prepare a row for JSONEachRow insert.
     *
     * @param array<string, mixed> $metricData
     * @return array<string, mixed>
     */
    private function prepareMetricRow(array $metricData): array
    {
        /** @var string $period */
        $period = $metricData['period'] ?? Usage::PERIOD_1H;
        /** @var string $metric */
        $metric = $metricData['metric'];
        /** @var int $value */
        $value = $metricData['value'];
        /** @var array<string, mixed> $tags */
        $tags = $metricData['tags'] ?? [];

        // Normalize tags
        ksort($tags);

        // Period-aligned time
        $now = new \DateTime();
        $time = $period === Usage::PERIOD_INF
            ? null
            : $now->format(Usage::PERIODS[$period]);
        $timestamp = $time !== null ? $this->formatDateTime($time) : null;

        // Resolve tenant
        $tenant = $this->sharedTables ? $this->resolveTenantFromMetric($metricData) : null;

        // Deterministic id
        $id = $this->buildDeterministicId($metric, $period, $timestamp, $tenant);

        // Build row compatible with JSONEachRow (keys match column names)
        $row = [
            'id' => $id,
            'metric' => $metric,
            'value' => $value,
            'period' => $period,
            'time' => $timestamp, // DateTime64(3) accepts string format
            'tags' => $tags, // Will be JSON encoded automatically by json_encode($row)
        ];

        if ($this->sharedTables) {
            $row['tenant'] = $tenant;
        }

        return $row;
    }

    /**
     * Resolve tenant for a single metric entry, giving precedence to metric-level tenant.
     *
     * @param array<string, mixed> $metricData
     */
    private function resolveTenantFromMetric(array $metricData): ?int
    {
        $tenant = array_key_exists('$tenant', $metricData) ? $metricData['$tenant'] : $this->tenant;

        if ($tenant === null) {
            return null;
        }

        if (is_int($tenant)) {
            return $tenant;
        }

        if (is_string($tenant) && ctype_digit($tenant)) {
            return (int) $tenant;
        }

        // Validation should prevent reaching here, but return null defensively
        return null;
    }

    /**
     * Find metrics using Query objects.
     * Queries both aggregated and counter tables and combines results.
     *
     * @param array<Query> $queries
     * @return array<Metric>
     * @throws Exception
     */
    public function find(array $queries = []): array
    {
        // Get table references with FINAL clause
        $fromTable = $this->buildTableReference($this->getTableName());
        $fromCounterTable = $this->buildTableReference($this->getCounterTableName());

        // Parse queries
        $parsed = $this->parseQueries($queries);

        // Build SELECT clause
        $selectColumns = $this->getSelectColumns();

        // Build WHERE clause
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $parsed['params'] = $whereData['params'];

        // Build ORDER BY clause
        $orderClause = '';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }

        // Build LIMIT and OFFSET
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        // Query both tables with UNION ALL
        // Wrap in subquery to ensure ORDER BY, LIMIT, OFFSET apply to the entire UNION result
        $sql = "
            SELECT *
            FROM (
                SELECT {$selectColumns}
                FROM {$fromTable}{$whereClause}
                UNION ALL
                SELECT {$selectColumns}
                FROM {$fromCounterTable}{$whereClause}
            ){$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $parsed['params']);

        return $this->parseResults($result);
    }

    /**
     * Count metrics using Query objects.
     * Counts from both aggregated and counter tables.
     *
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    public function count(array $queries = []): int
    {
        // Get table references with FINAL clause
        $fromTable = $this->buildTableReference($this->getTableName());
        $fromCounterTable = $this->buildTableReference($this->getCounterTableName());

        // Parse queries - we only need filters and params
        $parsed = $this->parseQueries($queries);

        // Remove limit and offset from params (not needed for count)
        $params = $parsed['params'];
        unset($params['limit'], $params['offset']);

        // Build WHERE clause
        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Count from both tables
        $sql = "
            SELECT SUM(cnt) as total
            FROM (
                SELECT COUNT(*) as cnt FROM {$fromTable}{$whereClause}
                UNION ALL
                SELECT COUNT(*) as cnt FROM {$fromCounterTable}{$whereClause}
            )
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);
        $json = json_decode($result, true);

        if (!is_array($json) || !isset($json['data'][0]['total'])) {
            return 0;
        }

        return (int) $json['data'][0]['total'];
    }

    /**
     * Build WHERE clause from filters with optional tenant filtering.
     *
     * @param array<string> $filters SQL filter conditions
     * @param array<string, mixed> $params Existing query parameters
     * @param bool $includeTenant Whether to include tenant filter
     * @return array{clause: string, params: array<string, mixed>}
     */
    private function buildWhereClause(array $filters, array $params = [], bool $includeTenant = true): array
    {
        $conditions = $filters;
        $whereParams = $params;

        if ($includeTenant) {
            $tenantFilter = $this->getTenantFilter();
            if ($tenantFilter) {
                $conditions[] = ltrim($tenantFilter, ' AND');
                $whereParams['tenant'] = $this->tenant;
            }
        }

        $clause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

        return [
            'clause' => $clause,
            'params' => $whereParams
        ];
    }

    /**
     * Parse Query objects into SQL clauses.
     *
     * @param array<Query> $queries
     * @return array{filters: array<string>, params: array<string, mixed>, orderBy?: array<string>, limit?: int, offset?: int}
     * @throws Exception
     */
    private function parseQueries(array $queries): array
    {
        $filters = [];
        $params = [];
        $orderBy = [];
        $limit = null;
        $offset = null;
        $paramCounter = 0;

        foreach ($queries as $query) {


            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            switch ($method) {
                case Query::TYPE_EQUAL:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);

                    // Support arrays of values (produce IN (...) ) or single value equality
                    if (count($values) > 1) {
                        /** @var array<mixed> $arrayValues */
                        $arrayValues = $values;
                        $inParams = [];
                        foreach ($arrayValues as $value) {
                            $paramName = 'param_' . $paramCounter++;
                            if ($attribute === 'time') {
                                $inParams[] = "{{$paramName}:DateTime64(3)}";
                                /** @var \DateTime|string|null $timeValue */
                                $timeValue = $value;
                                $params[$paramName] = $this->formatDateTime($timeValue);
                            } else {
                                $inParams[] = "{{$paramName}:String}";
                                /** @var bool|float|int|string $scalarValue */
                                $scalarValue = $value;
                                $params[$paramName] = $this->formatParamValue($scalarValue);
                            }
                        }

                        /** @var int $inParamCount */
                        $inParamCount = count($inParams);
                        if ($inParamCount === 1) {
                            $filters[] = "{$escapedAttr} = " . $inParams[0];
                        } else {
                            $filters[] = "{$escapedAttr} IN (" . implode(', ', $inParams) . ")";
                        }
                    } else {
                        $paramName = 'param_' . $paramCounter++;
                        if ($attribute === 'time') {
                            /** @var array<\DateTime|string|null> $values */
                            $formattedValue = $this->formatDateTime($values[0]);
                            $filters[] = "{$escapedAttr} = {{$paramName}:DateTime64(3)}";
                        } else {
                            /** @var bool|float|int|string $formattedValue */
                            $formattedValue = $this->formatParamValue($values[0]);
                            $filters[] = "{$escapedAttr} = {{$paramName}:String}";
                        }
                        $params[$paramName] = $formattedValue;
                    }
                    break;

                case Query::TYPE_LESSER:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $value = is_array($values) && !empty($values) ? $values[0] : $values;
                    if ($attribute === 'time') {
                        $filters[] = "{$escapedAttr} < {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($value);
                    } else {
                        $filters[] = "{$escapedAttr} < {{$paramName}:String}";
                        $params[$paramName] = $this->formatParamValue($value);
                    }
                    break;

                case Query::TYPE_GREATER:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $value = is_array($values) && !empty($values) ? $values[0] : $values;
                    if ($attribute === 'time') {
                        $filters[] = "{$escapedAttr} > {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($value);
                    } else {
                        $filters[] = "{$escapedAttr} > {{$paramName}:String}";
                        $params[$paramName] = $this->formatParamValue($value);
                    }
                    break;

                case Query::TYPE_BETWEEN:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $paramName1 = 'param_' . $paramCounter++;
                    $paramName2 = 'param_' . $paramCounter++;
                    // Between has two values
                    $value1 = is_array($values) && isset($values[0]) ? $values[0] : $values;
                    $value2 = is_array($values) && isset($values[1]) ? $values[1] : $values;
                    if ($attribute === 'time') {
                        $paramType = 'DateTime64(3)';
                        $filters[] = "{$escapedAttr} BETWEEN {{$paramName1}:{$paramType}} AND {{$paramName2}:{$paramType}}";
                        $params[$paramName1] = $this->formatDateTime($value1);
                        $params[$paramName2] = $this->formatDateTime($value2);
                    } else {
                        $filters[] = "{$escapedAttr} BETWEEN {{$paramName1}:String} AND {{$paramName2}:String}";
                        $params[$paramName1] = $this->formatParamValue($value1);
                        $params[$paramName2] = $this->formatParamValue($value2);
                    }
                    break;



                case Query::TYPE_ORDER_DESC:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} DESC";
                    break;

                case Query::TYPE_ORDER_ASC:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} ASC";
                    break;

                case Query::TYPE_CONTAINS:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $inParams = [];
                    foreach ($values as $value) {
                        $paramName = 'param_' . $paramCounter++;
                        if ($attribute === 'time') {
                            $inParams[] = "{{$paramName}:DateTime64(3)}";
                            /** @var \DateTime|string|null $singleValue */
                            $singleValue = $value;
                            $params[$paramName] = $this->formatDateTime($singleValue);
                        } else {
                            $inParams[] = "{{$paramName}:String}";
                            /** @var bool|float|int|string $singleValue */
                            $singleValue = $value;
                            $params[$paramName] = $this->formatParamValue($singleValue);
                        }
                    }
                    if (!empty($inParams)) {
                        $filters[] = "{$escapedAttr} IN (" . implode(', ', $inParams) . ")";
                    }
                    break;

                case Query::TYPE_LESSER_EQUAL:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    if ($attribute === 'time') {
                        if (is_array($values)) {
                            /** @var \DateTime|string|null $singleValue */
                            $singleValue = $values[0] ?? null;
                        }
                        $filters[] = "{$escapedAttr} <= {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($singleValue);
                    } else {
                        if (is_array($values)) {
                            /** @var bool|float|int|string $singleValue */
                            $singleValue = $values[0] ?? null;
                        }
                        $filters[] = "{$escapedAttr} <= {{$paramName}:String}";
                        $params[$paramName] = $this->formatParamValue($singleValue);
                    }
                    break;

                case Query::TYPE_GREATER_EQUAL:
                    $this->validateAttributeName($attribute);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    if ($attribute === 'time') {
                        if (is_array($values)) {
                            /** @var \DateTime|string|null $singleValue */
                            $singleValue = $values[0] ?? null;
                        }
                        $filters[] = "{$escapedAttr} >= {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($singleValue);
                    } else {
                        if (is_array($values)) {
                            /** @var bool|float|int|string $singleValue */
                            $singleValue = $values[0] ?? null;
                        }
                        $filters[] = "{$escapedAttr} >= {{$paramName}:String}";
                        $params[$paramName] = $this->formatParamValue($singleValue);
                    }
                    break;

                case Query::TYPE_LIMIT:
                    $limitVal = is_array($values) && !empty($values) ? $values[0] : $values;
                    if (!\is_int($limitVal)) {
                        throw new \Exception('Invalid limit value. Expected int');
                    }
                    $limit = $limitVal;
                    $params['limit'] = $limit;
                    break;

                case Query::TYPE_OFFSET:
                    $offsetVal = is_array($values) && !empty($values) ? $values[0] : $values;
                    if (!\is_int($offsetVal)) {
                        throw new \Exception('Invalid offset value. Expected int');
                    }
                    $offset = $offsetVal;
                    $params['offset'] = $offset;
                    break;
            }
        }

        $result = [
            'filters' => $filters,
            'params' => $params,
        ];

        if (!empty($orderBy)) {
            $result['orderBy'] = $orderBy;
        }

        if ($limit !== null) {
            $result['limit'] = $limit;
        }

        if ($offset !== null) {
            $result['offset'] = $offset;
        }

        return $result;
    }

    /**
     * Parse ClickHouse JSON results into Metric array.
     *
     * @return array<Metric>
     */
    private function parseResults(string $result): array
    {
        if (empty(trim($result))) {
            return [];
        }

        $json = json_decode($result, true);

        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return [];
        }

        $rows = $json['data'];
        $metrics = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $document = [];

            foreach ($row as $key => $value) {
                if ($key === 'tenant') {
                    // Parse tenant
                    $document[$key] = $value !== null ? (int) $value : null;
                } elseif ($key === 'value') {
                    // Parse value as integer
                    $document[$key] = $value !== null ? (int) $value : null;
                } elseif ($key === 'time') {
                    // Time comes as string in JSON format, convert to ISO 8601 if needed
                    $parsedTime = (string)$value;
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document[$key] = $parsedTime;
                } elseif ($key === 'tags') {
                    // Tags in JSON output are already mixed (array or object), no need to json_decode
                    // ClickHouse JSON output for Map/Array might vary, but for String it's a string
                    // If we store tags as String (serialized JSON), we need to decode it.
                    // The schema says tags is String? Let's check getColumnType.
                    // Ah, tags is usually String in ClickHouse adapter (checked log/logBatch).
                    // So it comes as a string, we need to decode it.
                    if (is_string($value)) {
                        $document[$key] = json_decode($value, true) ?? [];
                    } else {
                        $document[$key] = $value;
                    }
                } else {
                    $document[$key] = $value;
                }
            }

            // Add special $id field if present
            if (isset($document['id'])) {
                $document['$id'] = $document['id'];
                unset($document['id']);
            }

            $metrics[] = new Metric($document);
        }

        return $metrics;
    }

    /**
     * Get the SELECT column list for queries.
     * Dynamically builds the column list from attributes.
     *
     * @return string
     */
    private function getSelectColumns(): string
    {
        $columns = [];

        // Add id column first
        $columns[] = $this->escapeIdentifier('id');

        // Dynamically add all attribute columns
        foreach ($this->getAttributes() as $attribute) {
            $id = $attribute['$id'];
            if (is_string($id)) {
                $columns[] = $this->escapeIdentifier($id);
            }
        }

        // Add tenant column if shared tables are enabled
        if ($this->sharedTables) {
            $columns[] = $this->escapeIdentifier('tenant');
        }

        return implode(', ', $columns);
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

        return " AND tenant = {tenant:Nullable(UInt64)}";
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<int,Query>  $queries
     * @return array<Metric>
     *
     * @throws Exception
     */
    public function getByPeriod(string $metric, string $period, array $queries = []): array
    {
        $allQueries = [
            Query::equal('metric', [$metric]),
            Query::equal('period', [$period]),
        ];

        // Add custom queries
        foreach ($queries as $query) {
            $allQueries[] = $query;
        }

        // Add default ordering
        $allQueries[] = Query::orderDesc('time');

        return $this->find($allQueries);
    }

    /**
     * Get usage metrics between dates.
     *
     * @param  array<int,Query>  $queries
     * @return array<Metric>
     *
     * @throws Exception
     */
    public function getBetweenDates(string $metric, string $startDate, string $endDate, array $queries = []): array
    {
        $allQueries = [
            Query::equal('metric', [$metric]),
            Query::between('time', $startDate, $endDate)
        ];

        // Add custom queries
        foreach ($queries as $query) {
            $allQueries[] = $query;
        }

        // Add default ordering
        $allQueries[] = Query::orderDesc('time');

        return $this->find($allQueries);
    }

    /**
     * Sum metric values using Query objects.
     * Sums from both aggregated and counter tables.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     * @throws Exception
     */
    public function sum(array $queries = [], string $attribute = 'value'): int
    {
        // Get table references with FINAL clause
        $fromTable = $this->buildTableReference($this->getTableName());
        $fromCounterTable = $this->buildTableReference($this->getCounterTableName());

        // Validate attribute name
        $this->validateAttributeName($attribute);
        $escapedAttribute = $this->escapeIdentifier($attribute);

        // Parse queries
        $parsed = $this->parseQueries($queries);

        // Build WHERE clause
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Sum from both tables
        $sql = "
            SELECT SUM(total) as grand_total
            FROM (
                SELECT sum({$escapedAttribute}) as total FROM {$fromTable}{$whereClause}
                UNION ALL
                SELECT sum({$escapedAttribute}) as total FROM {$fromCounterTable}{$whereClause}
            )
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);

        $json = json_decode($result, true);

        if (!is_array($json) || !isset($json['data'][0]['grand_total'])) {
            return 0;
        }

        return (int) $json['data'][0]['grand_total'];
    }

    /**
     * Count usage metrics by period.
     *
     * @param  array<int,Query>  $queries
     *
     * @throws Exception
     */
    public function countByPeriod(string $metric, string $period, array $queries = []): int
    {
        $allQueries = [
            Query::equal('metric', [$metric]),
            Query::equal('period', [$period]),
        ];

        // Add custom queries
        foreach ($queries as $query) {
            $allQueries[] = $query;
        }

        return $this->count($allQueries);
    }

    /**
     * Sum usage metric values by period.
     * Sums from both aggregated and counter tables.
     *
     * @param  array<int,Query>  $queries
     *
     * @throws Exception
     */
    public function sumByPeriod(string $metric, string $period, array $queries = []): int
    {
        $allQueries = [
            Query::equal('metric', [$metric]),
            Query::equal('period', [$period]),
        ];

        // Add custom queries
        foreach ($queries as $query) {
            $allQueries[] = $query;
        }

        return $this->sum($allQueries);
    }

    /**
     * Purge usage metrics older than the specified datetime.
     * Purges from both aggregated and counter tables.
     *
     * @throws Exception
     */
    public function purge(string $datetime): bool
    {
        $tableName = $this->getTableName();
        $counterTableName = $this->getCounterTableName();
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedCounterTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);
        $tenantFilter = $this->getTenantFilter();

        $params = ['datetime' => $datetime];
        if ($this->sharedTables) {
            $params['tenant'] = $this->tenant;
        }

        // Purge from aggregated table
        $sql = "
            DELETE FROM {$escapedTable}
            WHERE time < {datetime:DateTime64(3)}{$tenantFilter}
        ";
        $this->query($sql, $params);

        // Purge from counter table
        $sql = "
            DELETE FROM {$escapedCounterTable}
            WHERE time < {datetime:DateTime64(3)}{$tenantFilter}
        ";
        $this->query($sql, $params);

        return true;
    }
}
