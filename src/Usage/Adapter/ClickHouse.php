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
     * Get adapter name.
     */
    public function getName(): string
    {
        return 'ClickHouse';
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
                throw new Exception("ClickHouse query failed with HTTP {$response->getStatusCode()}: {$bodyStr}");
            }

            $body = $response->getBody();
            return is_string($body) ? $body : '';
        } catch (Exception $e) {
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

        // For any other type, try to convert to DateTime
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
     * Build insert value placeholders and query parameters for a metric.
     *
     * @param string $metric Metric name
     * @param int $value Metric value
     * @param string $period Period identifier
     * @param array<string,mixed> $tags Tags
     * @param int|null $tenant Tenant ID
     * @param int $paramCounter Parameter counter for batch operations
     * @return array{queryParams: array<string, mixed>, valuePlaceholders: array<string>}
     * @throws Exception
     */
    private function buildInsertValuesForMetric(
        string $metric,
        int $value,
        string $period,
        array $tags,
        ?int $tenant,
        int $paramCounter = 0
    ): array {
        $queryParams = [];
        $valuePlaceholders = [];

        // Normalize tags
        ksort($tags);

        // Period-aligned time
        $now = new \DateTime();
        $time = $period === Usage::PERIOD_INF
            ? null
            : $now->format(Usage::PERIODS[$period]);
        $timestamp = $time !== null ? $this->formatDateTime($time) : null;

        // Deterministic id
        $id = $this->buildDeterministicId($metric, $period, $timestamp, $tenant);

        // Build id
        $idKey = 'id' . ($paramCounter > 0 ? '_' . $paramCounter : '');
        $queryParams[$idKey] = $id;
        $valuePlaceholders[] = '{' . $idKey . ':String}';

        // Map attribute values
        $attributeMap = [
            'metric' => $metric,
            'value' => $value,
            'period' => $period,
            'time' => $timestamp,
            'tags' => json_encode($tags),
        ];

        // Add attributes dynamically - must include ALL attributes in schema order
        foreach ($this->getAttributes() as $attribute) {
            $attrId = $attribute['$id'];

            $attrKey = $attrId . ($paramCounter > 0 ? '_' . $paramCounter : '');
            $type = $this->getColumnType($attrId);

            // Use the value from map, or null if not present
            $queryParams[$attrKey] = $attributeMap[$attrId] ?? null;
            $valuePlaceholders[] = '{' . $attrKey . ':' . $type . '}';
        }

        // Add tenant if shared tables
        if ($this->sharedTables) {
            $tenantKey = 'tenant' . ($paramCounter > 0 ? '_' . $paramCounter : '');
            $queryParams[$tenantKey] = $tenant;
            $valuePlaceholders[] = '{' . $tenantKey . ':Nullable(UInt64)}';
        }

        return [
            'queryParams' => $queryParams,
            'valuePlaceholders' => $valuePlaceholders,
        ];
    }

    /**
     * Build the INSERT column list (same for all rows).
     *
     * @return array<string>
     */
    private function buildInsertColumns(): array
    {
        $insertColumns = ['id'];

        foreach ($this->getAttributes() as $attribute) {
            $insertColumns[] = $attribute['$id'];
        }

        if ($this->sharedTables) {
            $insertColumns[] = 'tenant';
        }

        return $insertColumns;
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
        // Validate
        $this->validateMetricData($metric, $value, $period, $tags);

        // Build query
        $tenant = $this->sharedTables ? $this->tenant : null;
        $result = $this->buildInsertValuesForMetric($metric, $value, $period, $tags, $tenant);

        $insertColumns = $this->buildInsertColumns();
        $tableName = $this->getTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $sql = "
            INSERT INTO {$escapedDatabaseAndTable}
            (" . implode(', ', $insertColumns) . ")
            VALUES (
                " . implode(", ", $result['valuePlaceholders']) . "
            )
        ";

        $this->query($sql, $result['queryParams']);

        return true;
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
        // Validate
        $this->validateMetricData($metric, $value, $period, $tags);

        // Build query
        $tenant = $this->sharedTables ? $this->tenant : null;
        $result = $this->buildInsertValuesForMetric($metric, $value, $period, $tags, $tenant);

        $insertColumns = $this->buildInsertColumns();
        $counterTableName = $this->getCounterTableName();
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);

        $sql = "
            INSERT INTO {$escapedDatabaseAndTable}
            (" . implode(', ', $insertColumns) . ")
            VALUES (
                " . implode(", ", $result['valuePlaceholders']) . "
            )
        ";

        $this->query($sql, $result['queryParams']);

        return true;
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
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);

        // Build column list (same for all rows)
        $insertColumns = $this->buildInsertColumns();

        // Process metrics in batches
        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $paramCounter = 0;
            $queryParams = [];
            $valueClauses = [];

            foreach ($metricsBatch as $metricData) {
                $period = $metricData['period'] ?? Usage::PERIOD_1H;
                $metric = $metricData['metric'];
                $value = $metricData['value'];
                $tags = (array) ($metricData['tags'] ?? []);

                // Build values for this metric
                $tenant = $this->sharedTables ? $this->resolveTenantFromMetric($metricData) : null;
                $result = $this->buildInsertValuesForMetric($metric, $value, $period, $tags, $tenant, $paramCounter);

                $queryParams = array_merge($queryParams, $result['queryParams']);
                $valueClauses[] = '(' . implode(', ', $result['valuePlaceholders']) . ')';
                $paramCounter++;
            }

            $insertSql = "
                INSERT INTO {$escapedDatabaseAndTable}
                (" . implode(', ', $insertColumns) . ")
                VALUES " . implode(', ', $valueClauses);

            $this->query($insertSql, $queryParams);
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
            try {
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
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
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
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        // Build column list (same for all rows)
        $insertColumns = $this->buildInsertColumns();

        // Process metrics in batches
        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $paramCounter = 0;
            $queryParams = [];
            $valueClauses = [];

            foreach ($metricsBatch as $metricData) {
                $period = $metricData['period'] ?? Usage::PERIOD_1H;
                $metric = $metricData['metric'];
                $value = $metricData['value'];
                $tags = (array) ($metricData['tags'] ?? []);

                // Build values for this metric
                $tenant = $this->sharedTables ? $this->resolveTenantFromMetric($metricData) : null;
                $result = $this->buildInsertValuesForMetric($metric, $value, $period, $tags, $tenant, $paramCounter);

                $queryParams = array_merge($queryParams, $result['queryParams']);
                $valueClauses[] = '(' . implode(', ', $result['valuePlaceholders']) . ')';
                $paramCounter++;
            }

            $insertSql = "
                INSERT INTO {$escapedDatabaseAndTable}
                (" . implode(', ', $insertColumns) . ")
                VALUES " . implode(', ', $valueClauses);

            $this->query($insertSql, $queryParams);
        }

        return true;
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
        $tableName = $this->getTableName();
        $counterTableName = $this->getCounterTableName();
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedCounterTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);
        // FINAL on both tables (SummingMergeTree and ReplacingMergeTree)
        $fromTable = $escapedTable . ($this->useFinal ? ' FINAL' : '');
        $fromCounterTable = $escapedCounterTable . ($this->useFinal ? ' FINAL' : '');

        // Parse queries
        $parsed = $this->parseQueries($queries);

        // Build SELECT clause
        $selectColumns = $this->getSelectColumns();

        // Build WHERE clause
        $whereClause = '';
        $tenantFilter = $this->getTenantFilter();
        if (!empty($parsed['filters']) || $tenantFilter) {
            $conditions = $parsed['filters'];
            if ($tenantFilter) {
                $conditions[] = ltrim($tenantFilter, ' AND');
                $parsed['params']['tenant'] = $this->tenant;
            }
            $whereClause = ' WHERE ' . implode(' AND ', $conditions);
        }

        // Build ORDER BY clause
        $orderClause = '';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }

        // Build LIMIT and OFFSET
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        // Query both tables with UNION ALL
        $sql = "
            SELECT {$selectColumns}
            FROM {$fromTable}{$whereClause}
            UNION ALL
            SELECT {$selectColumns}
            FROM {$fromCounterTable}{$whereClause}
            {$orderClause}{$limitClause}{$offsetClause}
            FORMAT TabSeparated
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
        $tableName = $this->getTableName();
        $counterTableName = $this->getCounterTableName();
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedCounterTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);
        // FINAL on both tables (SummingMergeTree and ReplacingMergeTree)
        $fromTable = $escapedTable . ($this->useFinal ? ' FINAL' : '');
        $fromCounterTable = $escapedCounterTable . ($this->useFinal ? ' FINAL' : '');

        // Parse queries - we only need filters and params
        $parsed = $this->parseQueries($queries);

        // Build WHERE clause
        $whereClause = '';
        $tenantFilter = $this->getTenantFilter();
        if (!empty($parsed['filters']) || $tenantFilter) {
            $conditions = $parsed['filters'];
            if ($tenantFilter) {
                $conditions[] = ltrim($tenantFilter, ' AND');
            }
            $whereClause = ' WHERE ' . implode(' AND ', $conditions);
        }

        // Remove limit and offset from params
        $params = $parsed['params'];
        unset($params['limit'], $params['offset']);

        // Add tenant param if filter is active
        if ($tenantFilter) {
            $params['tenant'] = $this->tenant;
        }

        // Count from both tables
        $sql = "
            SELECT SUM(cnt) as total
            FROM (
                SELECT COUNT(*) as cnt FROM {$fromTable}{$whereClause}
                UNION ALL
                SELECT COUNT(*) as cnt FROM {$fromCounterTable}{$whereClause}
            )
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, $params);
        $trimmed = trim($result);
        return $trimmed !== '' ? (int) $trimmed : 0;
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

        // Build select columns list matching getSelectColumns()
        $selectColumns = ['id'];
        foreach ($this->getAttributes() as $attribute) {
            $selectColumns[] = $attribute['$id'];
        }

        if ($this->sharedTables) {
            $selectColumns[] = 'tenant';
        }

        $expectedColumns = count($selectColumns);

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < $expectedColumns) {
                continue;
            }

            // Helper function to parse nullable string fields
            $parseNullableString = static function ($value): ?string {
                if ($value === '\\N' || $value === '') {
                    return null;
                }
                return $value;
            };

            // Build document dynamically by mapping columns to values
            $document = [];
            foreach ($selectColumns as $index => $columnName) {
                if (!isset($columns[$index])) {
                    continue;
                }

                $value = $columns[$index];

                if ($columnName === 'tenant') {
                    // Parse tenant as integer or null
                    $document[$columnName] = ($value === '\\N' || $value === '') ? null : (int) $value;
                } elseif ($columnName === 'time') {
                    // Convert ClickHouse timestamp format back to ISO 8601
                    $parsedTime = $value;
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document[$columnName] = $parsedTime;
                } elseif ($columnName === 'tags') {
                    // Decode JSON tags column
                    $document[$columnName] = json_decode($value, true) ?? [];
                } else {
                    // Get attribute metadata to check if nullable
                    $attribute = is_string($columnName) ? $this->getAttribute($columnName) : null;
                    if ($attribute && !$attribute['required']) {
                        // Nullable field - parse null values
                        $document[$columnName] = $parseNullableString($value);
                    } else {
                        // Required field - use value as-is
                        $document[$columnName] = $value;
                    }
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
        $tableName = $this->getTableName();
        $counterTableName = $this->getCounterTableName();
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedCounterTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($counterTableName);
        // FINAL on both tables (SummingMergeTree and ReplacingMergeTree)
        $fromTable = $escapedTable . ($this->useFinal ? ' FINAL' : '');
        $fromCounterTable = $escapedCounterTable . ($this->useFinal ? ' FINAL' : '');

        // Build query constraints
        $allQueries = [
            Query::equal('metric', [$metric]),
            Query::equal('period', [$period]),
        ];

        foreach ($queries as $query) {
            $allQueries[] = $query;
        }

        $parsed = $this->parseQueries($allQueries);

        // Build WHERE clause
        $whereClause = '';
        $tenantFilter = $this->getTenantFilter();
        if (!empty($parsed['filters']) || $tenantFilter) {
            $conditions = $parsed['filters'];
            if ($tenantFilter) {
                $conditions[] = ltrim($tenantFilter, ' AND');
                // Add tenant param
                $parsed['params']['tenant'] = $this->tenant;
            }
            $whereClause = ' WHERE ' . implode(' AND ', $conditions);
        }

        // Sum from both tables
        $sql = "
            SELECT SUM(total) as grand_total
            FROM (
                SELECT sum(value) as total FROM {$fromTable}{$whereClause}
                UNION ALL
                SELECT sum(value) as total FROM {$fromCounterTable}{$whereClause}
            )
            FORMAT TabSeparated
        ";

        $result = $this->query($sql, $parsed['params']);
        $total = trim($result);

        return empty($total) ? 0 : (int) $total;
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
