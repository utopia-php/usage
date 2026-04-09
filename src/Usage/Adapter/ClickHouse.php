<?php

namespace Utopia\Usage\Adapter;

use Exception;
use Utopia\Query\Query;
use Utopia\Fetch\Client;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;
use Utopia\Validator\Hostname;

/**
 * ClickHouse Adapter for Usage
 *
 * This adapter stores usage metrics in ClickHouse using HTTP interface.
 * Uses two separate tables:
 * - Events table (MergeTree): raw request events with metadata columns
 *   (path, method, status, resource, resourceId)
 * - Gauges table (MergeTree): simple resource snapshots (metric, value, time, tags)
 *
 * A SummingMergeTree materialized view pre-aggregates events by day for fast
 * billing/analytics queries.
 *
 * Features:
 * - Two-table architecture (events + gauges)
 * - Event-specific columns extracted from tags
 * - SUM aggregation for events, argMax for gauges
 * - Safe SQL injection prevention using ClickHouse parameter binding
 * - Multi-tenant support with optional shared tables
 * - Namespace support for table name prefixes
 * - Bloom filter indexes for efficient filtering
 * - Monthly partitioning by time
 */
class ClickHouse extends SQL
{
    private const DEFAULT_PORT = 8123;

    private const DEFAULT_DATABASE = 'default';

    private const DEFAULT_TABLE = self::COLLECTION;

    private const INSERT_BATCH_SIZE = 1_000;

    /** @var array<string, string> Maps interval strings to ClickHouse time functions */
    private const INTERVAL_FUNCTIONS = [
        '1h' => 'toStartOfHour',
        '1d' => 'toStartOfDay',
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

    protected ?string $tenant = null;

    protected bool $sharedTables = false;

    protected string $namespace = '';

    /** @var bool Whether to log queries for debugging */
    private bool $enableQueryLogging = false;

    /** @var array<array{sql: string, params: array<string, mixed>, duration: float, timestamp: float, success: bool, error?: string}> Query execution log */
    private array $queryLog = [];

    /** @var bool Whether to enable gzip compression for HTTP requests/responses */
    private bool $enableCompression = false;

    /** @var bool Whether to enable HTTP keep-alive for connection pooling */
    private bool $enableKeepAlive = true;

    /** @var int Number of requests made using this adapter instance */
    private int $requestCount = 0;

    /** @var int Maximum number of retry attempts for failed requests (0 = no retries) */
    private int $maxRetries = 3;

    /** @var int Initial retry delay in milliseconds (doubles with each retry) */
    private int $retryDelay = 100;

    /** @var string|null Current operation context for better error messages */
    private ?string $operationContext = null;

    /** @var bool Whether to enable ClickHouse async inserts (server-side batching) */
    private bool $asyncInserts = false;

    /** @var bool Whether to wait for async insert confirmation before returning */
    private bool $asyncInsertWait = true;

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
     * Enable or disable gzip compression for HTTP requests/responses.
     *
     * @param bool $enable Whether to enable compression
     * @return self
     */
    public function setCompression(bool $enable): self
    {
        $this->enableCompression = $enable;
        return $this;
    }

    /**
     * Enable or disable HTTP keep-alive for connection pooling.
     *
     * @param bool $enable Whether to enable keep-alive (default: true)
     * @return self
     */
    public function setKeepAlive(bool $enable): self
    {
        $this->enableKeepAlive = $enable;
        return $this;
    }

    /**
     * Set maximum number of retry attempts for failed requests.
     *
     * @param int $maxRetries Maximum retry attempts (0-10, 0 = no retries)
     * @return self
     * @throws Exception If maxRetries is out of valid range
     */
    public function setMaxRetries(int $maxRetries): self
    {
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new Exception('Max retries must be between 0 and 10');
        }
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Set initial retry delay in milliseconds.
     * Delay doubles with each retry attempt (exponential backoff).
     *
     * @param int $milliseconds Initial delay in milliseconds (10-5000ms)
     * @return self
     * @throws Exception If delay is out of valid range
     */
    public function setRetryDelay(int $milliseconds): self
    {
        if ($milliseconds < 10 || $milliseconds > 5000) {
            throw new Exception('Retry delay must be between 10 and 5000 milliseconds');
        }
        $this->retryDelay = $milliseconds;
        return $this;
    }

    /**
     * Enable or disable ClickHouse async inserts (server-side batching).
     *
     * @param bool $enable Whether to enable async inserts
     * @param bool $waitForConfirmation Whether to wait for server-side flush before returning
     * @return self
     */
    public function setAsyncInserts(bool $enable, bool $waitForConfirmation = true): self
    {
        $this->asyncInserts = $enable;
        $this->asyncInsertWait = $waitForConfirmation;
        return $this;
    }

    /**
     * Get connection statistics for monitoring.
     *
     * @return array{request_count: int, keep_alive_enabled: bool, compression_enabled: bool, query_logging_enabled: bool, max_retries: int, retry_delay: int, async_inserts: bool, async_insert_wait: bool}
     */
    public function getConnectionStats(): array
    {
        return [
            'request_count' => $this->requestCount,
            'keep_alive_enabled' => $this->enableKeepAlive,
            'compression_enabled' => $this->enableCompression,
            'query_logging_enabled' => $this->enableQueryLogging,
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
            'async_inserts' => $this->asyncInserts,
            'async_insert_wait' => $this->asyncInsertWait,
        ];
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
        $this->setOperationContext('healthCheck()');

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

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception("{$type} must start with a letter or underscore and contain only alphanumeric characters and underscores");
        }

        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TABLE', 'DATABASE'];
        if (in_array(strtoupper($identifier), $keywords, true)) {
            throw new Exception("{$type} cannot be a reserved SQL keyword");
        }
    }

    /**
     * Escape an identifier for safe use in SQL.
     *
     * @param string $identifier
     * @return string
     */
    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Set the namespace for multi-project support.
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
     *
     * @param string|null $tenant
     * @return self
     */
    public function setTenant(?string $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    /**
     * Get the tenant ID.
     *
     * @return string|null
     */
    public function getTenant(): ?string
    {
        return $this->tenant;
    }

    /**
     * Set whether tables are shared across tenants.
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
     * Get the base table name with namespace prefix.
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
     * Get the events table name.
     *
     * @return string
     */
    private function getEventsTableName(): string
    {
        return $this->getTableName() . '_events';
    }

    /**
     * Get the gauges table name.
     *
     * @return string
     */
    private function getGaugesTableName(): string
    {
        return $this->getTableName() . '_gauges';
    }

    /**
     * Get the events daily table name.
     *
     * @return string
     */
    private function getEventsDailyTableName(): string
    {
        return $this->getTableName() . '_events_daily';
    }

    /**
     * Get the appropriate table name for a given type.
     *
     * @param string $type 'event' or 'gauge'
     * @return string
     */
    private function getTableForType(string $type): string
    {
        return $type === Usage::TYPE_GAUGE ? $this->getGaugesTableName() : $this->getEventsTableName();
    }

    /**
     * Build a fully qualified table reference with database and escaping.
     *
     * @param string $tableName The table name (with namespace already applied)
     * @return string Fully qualified table reference
     */
    private function buildTableReference(string $tableName): string
    {
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        return $escapedTable;
    }

    /**
     * Log a query execution for debugging purposes.
     *
     * @param string $sql SQL query executed
     * @param array<string, mixed> $params Query parameters
     * @param float $duration Execution duration in seconds
     * @param bool $success Whether the query succeeded
     * @param string|null $error Error message if query failed
     * @param int $retryAttempt Current retry attempt number
     */
    private function logQuery(string $sql, array $params, float $duration, bool $success, ?string $error = null, int $retryAttempt = 0): void
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

        if ($retryAttempt > 0) {
            $logEntry['retry_attempt'] = $retryAttempt;
        }

        if ($error !== null) {
            $logEntry['error'] = $error;
        }

        $this->queryLog[] = $logEntry;
    }

    /**
     * Determine if an error is retryable.
     *
     * @param int|null $httpCode HTTP status code if available
     * @param string $errorMessage Error message
     * @return bool True if the error is retryable
     */
    private function isRetryableError(?int $httpCode, string $errorMessage): bool
    {
        if ($httpCode !== null) {
            if (in_array($httpCode, [408, 429, 500, 502, 503, 504], true)) {
                return true;
            }
            if ($httpCode >= 400 && $httpCode < 500) {
                return false;
            }
        }

        $retryablePatterns = [
            'connection', 'timeout', 'timed out', 'refused', 'reset',
            'broken pipe', 'network', 'temporary', 'unavailable',
        ];

        $lowerMessage = strtolower($errorMessage);
        foreach ($retryablePatterns as $pattern) {
            if (strpos($lowerMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the current operation context for better error messages.
     *
     * @param string|null $context
     * @return void
     */
    private function setOperationContext(?string $context): void
    {
        $this->operationContext = $context;
    }

    /**
     * Execute an operation with automatic retry logic and exponential backoff.
     *
     * @template T
     * @param callable(int): T $operation
     * @param callable(Exception, int|null): bool $shouldRetry
     * @param callable(Exception, int): Exception $buildException
     * @return T
     * @throws Exception
     */
    private function executeWithRetry(callable $operation, callable $shouldRetry, callable $buildException): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                return $operation($attempt);
            } catch (Exception $e) {
                $lastException = $e;

                if ($attempt < $this->maxRetries && $shouldRetry($e, $attempt)) {
                    $attempt++;
                    $delay = $this->retryDelay * (2 ** ($attempt - 1));
                    usleep($delay * 1000);
                    continue;
                }

                throw $buildException($e, $attempt);
            }
        }

        throw $buildException(
            $lastException ?? new Exception('Unknown error occurred'),
            $this->maxRetries
        );
    }

    /**
     * Build a contextual error message.
     *
     * @param string $baseMessage
     * @param string|null $table
     * @param string|null $sql
     * @return string
     */
    private function buildErrorMessage(string $baseMessage, ?string $table = null, ?string $sql = null): string
    {
        $parts = [];

        if ($this->operationContext !== null) {
            $parts[] = "Operation: {$this->operationContext}";
        }

        if ($table !== null) {
            $parts[] = "Table: {$table}";
        }

        if ($sql !== null) {
            $truncatedSql = strlen($sql) > 200 ? substr($sql, 0, 200) . '...' : $sql;
            $truncatedSql = preg_replace('/\s+/', ' ', $truncatedSql);
            $parts[] = "Query: {$truncatedSql}";
        }

        $context = !empty($parts) ? ' [' . implode(', ', $parts) . ']' : '';
        return $baseMessage . $context;
    }

    /**
     * Execute a ClickHouse query via HTTP interface.
     *
     * @param string $sql
     * @param array<string, mixed> $params
     * @return string
     * @throws Exception
     */
    private function query(string $sql, array $params = []): string
    {
        return $this->executeWithRetry(
            function (int $attempt) use ($sql, $params): string {
                $startTime = microtime(true);
                $scheme = $this->secure ? 'https' : 'http';
                $url = "{$scheme}://{$this->host}:{$this->port}/";

                $this->client->addHeader('X-ClickHouse-Database', $this->database);

                if ($this->enableKeepAlive) {
                    $this->client->addHeader('Connection', 'keep-alive');
                } else {
                    $this->client->addHeader('Connection', 'close');
                }

                if ($this->enableCompression) {
                    $this->client->addHeader('Accept-Encoding', 'gzip');
                }

                if ($attempt === 0) {
                    $this->requestCount++;
                }

                $body = ['query' => $sql];
                foreach ($params as $key => $value) {
                    $body['param_' . $key] = $this->formatParamValue($value);
                }

                $response = $this->client->fetch(
                    url: $url,
                    method: Client::METHOD_POST,
                    body: $body
                );
                $httpCode = $response->getStatusCode();

                if ($httpCode !== 200) {
                    $bodyStr = $response->getBody();
                    $bodyStr = is_string($bodyStr) ? $bodyStr : '';
                    $duration = microtime(true) - $startTime;
                    $baseError = "ClickHouse query failed with HTTP {$httpCode}: {$bodyStr}";
                    $errorMsg = $this->buildErrorMessage($baseError, null, $sql);
                    $this->logQuery($sql, $params, $duration, false, $errorMsg, $attempt);

                    throw new Exception($errorMsg . '|HTTP_CODE:' . $httpCode);
                }

                $body = $response->getBody();
                $result = is_string($body) ? $body : '';
                $duration = microtime(true) - $startTime;
                $this->logQuery($sql, $params, $duration, true, null, $attempt);
                return $result;
            },
            function (Exception $e, ?int $httpCode): bool {
                $exceptionHttpCode = null;
                if (preg_match('/\|HTTP_CODE:(\d+)$/', $e->getMessage(), $matches)) {
                    $exceptionHttpCode = (int) $matches[1];
                }
                return $this->isRetryableError($exceptionHttpCode, $e->getMessage());
            },
            function (Exception $e, int $attempt) use ($sql): Exception {
                $cleanMessage = preg_replace('/\|HTTP_CODE:\d+$/', '', $e->getMessage());
                $cleanMessage = is_string($cleanMessage) ? $cleanMessage : $e->getMessage();

                if (strpos($cleanMessage, '[Operation:') !== false) {
                    return new Exception($cleanMessage, 0, $e);
                }

                $baseError = "ClickHouse query execution failed after " . ($attempt + 1) . " attempt(s): {$cleanMessage}";
                $errorMsg = $this->buildErrorMessage($baseError, null, $sql);
                return new Exception($errorMsg, 0, $e);
            }
        );
    }

    /**
     * Execute a ClickHouse INSERT using JSONEachRow format.
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

        $this->executeWithRetry(
            function (int $attempt) use ($table, $data): void {
                $startTime = microtime(true);
                $scheme = $this->secure ? 'https' : 'http';
                $escapedTable = $this->escapeIdentifier($table);

                $queryParams = ['query' => "INSERT INTO {$escapedTable} FORMAT JSONEachRow"];
                if ($this->asyncInserts) {
                    $queryParams['async_insert'] = '1';
                    $queryParams['wait_for_async_insert'] = $this->asyncInsertWait ? '1' : '0';
                }
                $url = "{$scheme}://{$this->host}:{$this->port}/?" . http_build_query($queryParams);

                $this->client->addHeader('X-ClickHouse-Database', $this->database);
                $this->client->addHeader('Content-Type', 'application/x-ndjson');

                if ($this->enableKeepAlive) {
                    $this->client->addHeader('Connection', 'keep-alive');
                } else {
                    $this->client->addHeader('Connection', 'close');
                }

                if ($this->enableCompression) {
                    $this->client->addHeader('Accept-Encoding', 'gzip');
                }

                if ($attempt === 0) {
                    $this->requestCount++;
                }

                $body = implode("\n", $data);

                $sql = "INSERT INTO {$escapedTable} FORMAT JSONEachRow";
                $params = ['rows' => count($data), 'bytes' => strlen($body)];

                try {
                    $response = $this->client->fetch(
                        url: $url,
                        method: Client::METHOD_POST,
                        body: $body
                    );

                    $httpCode = $response->getStatusCode();

                    if ($httpCode !== 200) {
                        $bodyStr = $response->getBody();
                        $bodyStr = is_string($bodyStr) ? $bodyStr : '';
                        $duration = microtime(true) - $startTime;
                        $rowCount = count($data);
                        $baseError = "ClickHouse insert failed with HTTP {$httpCode}: {$bodyStr}";
                        $errorMsg = $this->buildErrorMessage($baseError, $table, "INSERT INTO {$table} ({$rowCount} rows)");
                        $this->logQuery($sql, $params, $duration, false, $errorMsg, $attempt);

                        throw new Exception($errorMsg . '|HTTP_CODE:' . $httpCode);
                    }

                    $duration = microtime(true) - $startTime;
                    $this->logQuery($sql, $params, $duration, true, null, $attempt);
                } finally {
                    $this->client->removeHeader('Content-Type');
                }
            },
            function (Exception $e, ?int $httpCode): bool {
                $exceptionHttpCode = null;
                if (preg_match('/\|HTTP_CODE:(\d+)$/', $e->getMessage(), $matches)) {
                    $exceptionHttpCode = (int) $matches[1];
                }
                return $this->isRetryableError($exceptionHttpCode, $e->getMessage());
            },
            function (Exception $e, int $attempt) use ($table, $data): Exception {
                $cleanMessage = preg_replace('/\|HTTP_CODE:\d+$/', '', $e->getMessage());
                $cleanMessage = is_string($cleanMessage) ? $cleanMessage : $e->getMessage();

                if (strpos($cleanMessage, '[Operation:') !== false) {
                    return new Exception($cleanMessage, 0, $e);
                }

                $rowCount = count($data);
                $baseError = "ClickHouse insert execution failed after " . ($attempt + 1) . " attempt(s): {$cleanMessage}";
                $errorMsg = $this->buildErrorMessage($baseError, $table, "INSERT INTO {$table} ({$rowCount} rows)");
                return new Exception($errorMsg, 0, $e);
            }
        );
    }

    /**
     * Format a parameter value for safe transmission to ClickHouse.
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

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Setup ClickHouse table structure.
     *
     * Creates:
     * 1. Events table (MergeTree) with event-specific columns
     * 2. Events daily table (SummingMergeTree) for pre-aggregation
     * 3. Events daily materialized view
     * 4. Gauges table (MergeTree) with simple schema
     *
     * @throws Exception
     */
    public function setup(): void
    {
        $this->setOperationContext('setup()');

        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $createDbSql = "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}";
        $this->query($createDbSql);

        // --- Events table ---
        $this->createTable(
            $this->getEventsTableName(),
            'event',
            $this->getEventIndexes()
        );

        // --- Events daily table (SummingMergeTree) ---
        $this->createDailyTable();

        // --- Events daily materialized view ---
        $this->createDailyMaterializedView();

        // --- Gauges table ---
        $this->createTable(
            $this->getGaugesTableName(),
            'gauge',
            $this->getGaugeIndexes()
        );
    }

    /**
     * Create a MergeTree table for the given type.
     *
     * @param string $tableName
     * @param string $type 'event' or 'gauge'
     * @param array<int, array<string, mixed>> $indexes
     * @throws Exception
     */
    private function createTable(string $tableName, string $type, array $indexes): void
    {
        $columns = ['id String'];

        foreach ($this->getAttributes($type) as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];

            if ($id === 'time') {
                $columns[] = 'time DateTime64(3)';
            } else {
                $columns[] = $this->getColumnDefinition($id, $type);
            }
        }

        // Add tenant column only if tables are shared across tenants
        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        // Build indexes
        $indexDefs = [];
        foreach ($indexes as $index) {
            /** @var string $indexName */
            $indexName = $index['$id'];
            /** @var array<string> $attributes */
            $attributes = $index['attributes'];
            $escapedIndexName = $this->escapeIdentifier($indexName);
            $escapedAttributes = array_map(fn ($attr) => $this->escapeIdentifier($attr), $attributes);
            $attributeList = implode(', ', $escapedAttributes);
            $indexDefs[] = "INDEX {$escapedIndexName} ({$attributeList}) TYPE bloom_filter GRANULARITY 1";
        }

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $columnDefs = implode(",\n                ", $columns);
        $indexDefsStr = !empty($indexDefs) ? ",\n                " . implode(",\n                ", $indexDefs) : '';

        $orderByExpr = $this->sharedTables ? '(tenant, id)' : '(id)';

        $createTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDatabaseAndTable} (
                {$columnDefs}{$indexDefsStr}
            )
            ENGINE = MergeTree()
            ORDER BY {$orderByExpr}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($createTableSql);
    }

    /**
     * Create the events daily SummingMergeTree table.
     *
     * Minimal schema: metric, value, time, tenant.
     * Resource-level breakdown uses the raw events table.
     *
     * @throws Exception
     */
    private function createDailyTable(): void
    {
        $dailyTableName = $this->getEventsDailyTableName();
        $escapedDailyTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($dailyTableName);

        $columns = [
            'metric String',
            'value Int64',
            'time DateTime64(3)',
        ];

        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        $indexes = [
            'INDEX index_metric (metric) TYPE bloom_filter GRANULARITY 1',
            'INDEX index_time (time) TYPE bloom_filter GRANULARITY 1',
        ];

        $columnDefs = implode(",\n                ", $columns);
        $indexDefsStr = ",\n                " . implode(",\n                ", $indexes);

        $dailyOrderBy = $this->sharedTables ? '(tenant, metric, time)' : '(metric, time)';

        $createDailyTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDailyTable} (
                {$columnDefs}{$indexDefsStr}
            )
            ENGINE = SummingMergeTree()
            ORDER BY {$dailyOrderBy}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($createDailyTableSql);
    }

    /**
     * Create the materialized view for daily event aggregation.
     *
     * @throws Exception
     */
    private function createDailyMaterializedView(): void
    {
        $eventsTable = $this->getEventsTableName();
        $dailyTableName = $this->getEventsDailyTableName();
        $dailyMvName = $this->getTableName() . '_events_daily_mv';

        $escapedEventsTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($eventsTable);
        $escapedDailyTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($dailyTableName);
        $escapedDailyMv = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($dailyMvName);

        if ($this->sharedTables) {
            $innerSelect = "metric, tenant, sum(value) as value, toStartOfDay(time) as d";
            $innerGroupBy = "metric, tenant, d";
            $outerSelect = "metric, value, d as time, tenant";
        } else {
            $innerSelect = "metric, sum(value) as value, toStartOfDay(time) as d";
            $innerGroupBy = "metric, d";
            $outerSelect = "metric, value, d as time";
        }

        $createDailyMvSql = "
            CREATE MATERIALIZED VIEW IF NOT EXISTS {$escapedDailyMv}
            TO {$escapedDailyTable}
            AS SELECT {$outerSelect}
            FROM (
                SELECT {$innerSelect}
                FROM {$escapedEventsTable}
                GROUP BY {$innerGroupBy}
            )
        ";

        $this->query($createDailyMvSql);
    }

    /**
     * Validate that an attribute name exists in the schema for a given type.
     *
     * @param string $attributeName
     * @param string $type 'event' or 'gauge'
     * @return bool
     * @throws Exception
     */
    private function validateAttributeName(string $attributeName, string $type = 'event'): bool
    {
        if ($attributeName === 'id') {
            return true;
        }

        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        foreach ($this->getAttributes($type) as $attribute) {
            if ($attribute['$id'] === $attributeName) {
                return true;
            }
        }

        // Also check the other type's attributes for cross-table queries
        $otherType = $type === 'event' ? 'gauge' : 'event';
        foreach ($this->getAttributes($otherType) as $attribute) {
            if ($attribute['$id'] === $attributeName) {
                return true;
            }
        }

        throw new Exception("Invalid attribute name: {$attributeName}");
    }

    /**
     * Format datetime for ClickHouse compatibility.
     *
     * @param \DateTime|string|null $dateTime
     * @return string
     * @throws Exception
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
     * Get ClickHouse type for an attribute.
     *
     * @param string $id Attribute identifier
     * @param string $type 'event' or 'gauge'
     * @return string ClickHouse type
     * @throws Exception
     */
    private function getColumnType(string $id, string $type = 'event'): string
    {
        $attribute = $this->getAttribute($id, $type);
        if (!$attribute) {
            throw new Exception("Attribute {$id} not found in {$type} schema");
        }

        // Country uses LowCardinality for efficient storage of low-cardinality values
        if ($id === 'country') {
            return 'LowCardinality(Nullable(String))';
        }

        $attributeType = is_string($attribute['type'] ?? null) ? $attribute['type'] : 'string';
        $baseType = match ($attributeType) {
            'integer' => 'Int64',
            'float' => 'Float64',
            'boolean' => 'UInt8',
            'datetime' => 'DateTime64(3)',
            default => 'String',
        };

        return !$attribute['required'] ? 'Nullable(' . $baseType . ')' : $baseType;
    }

    protected function getColumnDefinition(string $id, string $type = 'event'): string
    {
        $chType = $this->getColumnType($id, $type);
        $escapedId = $this->escapeIdentifier($id);
        return "{$escapedId} {$chType}";
    }

    /**
     * Validate metric data for batch operations.
     *
     * @param string $metric Metric name
     * @param int $value Metric value
     * @param string $type Metric type ('event' or 'gauge')
     * @param array<string,mixed> $tags Tags
     * @param int|null $metricIndex Index for batch error messages
     * @throws Exception
     */
    private function validateMetricData(string $metric, int $value, string $type, array $tags, ?int $metricIndex = null): void
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

        if ($type !== Usage::TYPE_EVENT && $type !== Usage::TYPE_GAUGE) {
            throw new \InvalidArgumentException($prefix . "Invalid type '{$type}'. Allowed: " . Usage::TYPE_EVENT . ', ' . Usage::TYPE_GAUGE);
        }

        if (!is_array($tags)) {
            throw new Exception($prefix . 'Tags must be an array');
        }
    }

    /**
     * Validate all metrics in a batch before processing.
     *
     * @param array<int,array<string,mixed>> $metrics
     * @param string $type The target table type
     * @throws Exception
     */
    private function validateMetricsBatch(array $metrics, string $type): void
    {
        foreach ($metrics as $index => $metricData) {
            if (!isset($metricData['metric'])) {
                throw new Exception("Metric #{$index}: 'metric' is required");
            }
            if (!isset($metricData['value'])) {
                throw new Exception("Metric #{$index}: 'value' is required");
            }

            $metric = $metricData['metric'];
            $value = $metricData['value'];

            if (!is_string($metric)) {
                throw new Exception("Metric #{$index}: 'metric' must be a string, got " . gettype($metric));
            }
            if (!is_int($value)) {
                throw new Exception("Metric #{$index}: 'value' must be an integer, got " . gettype($value));
            }

            /** @var array<string, mixed> */
            $tags = $metricData['tags'] ?? [];
            $this->validateMetricData($metric, $value, $type, $tags, $index);

            if (array_key_exists('$tenant', $metricData)) {
                $tenantValue = $metricData['$tenant'];

                if ($tenantValue !== null && !is_string($tenantValue)) {
                    throw new Exception("Metric #{$index}: '\$tenant' must be a string or null, got " . gettype($tenantValue));
                }
            }
        }
    }

    /**
     * Add metrics in batch (raw append to appropriate table).
     *
     * For events: extracts path/method/status/resource/resourceId from tags into
     * dedicated columns; remaining tags stay in the tags JSON column.
     * For gauges: simple metric/value/time/tags insert.
     *
     * @param  array<int,array<string,mixed>>  $metrics
     * @param  string  $type  Metric type: 'event' or 'gauge'
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     * @throws Exception
     */
    public function addBatch(array $metrics, string $type = Usage::TYPE_EVENT, int $batchSize = self::INSERT_BATCH_SIZE): bool
    {
        if (empty($metrics)) {
            return true;
        }

        $this->setOperationContext('addBatch()');

        // Validate all metrics before processing
        $this->validateMetricsBatch($metrics, $type);

        $batchSize = \min(self::INSERT_BATCH_SIZE, \max(1, $batchSize));

        $tableName = $this->getTableForType($type);

        foreach (\array_chunk($metrics, $batchSize) as $metricsBatch) {
            $rows = [];

            foreach ($metricsBatch as $metricData) {
                /** @var string $metric */
                $metric = $metricData['metric'];
                /** @var int $value */
                $value = $metricData['value'];
                /** @var array<string, mixed> $tags */
                $tags = $metricData['tags'] ?? [];

                $tenant = $this->sharedTables ? $this->resolveTenantFromMetric($metricData) : null;

                if ($type === Usage::TYPE_EVENT) {
                    // Extract event-specific columns from tags into dedicated columns
                    $eventColumns = [];
                    foreach (Metric::EVENT_COLUMNS as $col) {
                        if (isset($tags[$col])) {
                            $tagValue = $tags[$col];
                            $eventColumns[$col] = is_string($tagValue) ? $tagValue : (is_scalar($tagValue) ? (string) $tagValue : null);
                            unset($tags[$col]);
                        } else {
                            $eventColumns[$col] = null;
                        }
                    }

                    ksort($tags);

                    $row = array_merge([
                        'id' => $this->generateId(),
                        'metric' => $metric,
                        'value' => $value,
                        'time' => $this->formatDateTime(null),
                    ], $eventColumns, [
                        'tags' => $tags,
                    ]);
                } else {
                    // Gauge: simple schema
                    ksort($tags);

                    $row = [
                        'id' => $this->generateId(),
                        'metric' => $metric,
                        'value' => $value,
                        'time' => $this->formatDateTime(null),
                        'tags' => $tags,
                    ];
                }

                if ($this->sharedTables) {
                    $row['tenant'] = $tenant;
                }

                $encoded = json_encode($row);
                if ($encoded === false) {
                    throw new Exception("Failed to JSON encode metric row: " . json_last_error_msg());
                }
                $rows[] = $encoded;
            }

            $this->insert($tableName, $rows);
        }

        return true;
    }

    /**
     * Resolve tenant for a single metric entry.
     *
     * @param array<string, mixed> $metricData
     */
    private function resolveTenantFromMetric(array $metricData): ?string
    {
        $tenant = array_key_exists('$tenant', $metricData) ? $metricData['$tenant'] : $this->tenant;

        if ($tenant === null) {
            return null;
        }

        if (is_string($tenant)) {
            return $tenant;
        }

        if (is_int($tenant) || is_float($tenant)) {
            return (string) $tenant;
        }

        return null;
    }

    /**
     * Find metrics using Query objects.
     * When $type is null, queries both tables with UNION ALL.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (both)
     * @return array<Metric>
     * @throws Exception
     */
    public function find(array $queries = [], ?string $type = null): array
    {
        $this->setOperationContext('find()');

        if ($type !== null) {
            return $this->findFromTable($queries, $type);
        }

        // Query both tables with UNION ALL
        $events = $this->findFromTable($queries, Usage::TYPE_EVENT);
        $gauges = $this->findFromTable($queries, Usage::TYPE_GAUGE);

        return array_merge($events, $gauges);
    }

    /**
     * Find metrics from a specific table.
     *
     * When a `groupByInterval` query is present, switches to aggregated mode:
     * - Events: SELECT metric, SUM(value) as value, toStartOfInterval(time, INTERVAL ...) as time
     * - Gauges: SELECT metric, argMax(value, time) as value, toStartOfInterval(time, INTERVAL ...) as time
     * Results are grouped by metric and time bucket, ordered by time ASC.
     *
     * @param array<Query> $queries
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     * @throws Exception
     */
    private function findFromTable(array $queries, string $type): array
    {
        $tableName = $this->getTableForType($type);
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, $type);

        // Check if groupByInterval is requested
        if (isset($parsed['groupByInterval'])) {
            return $this->findAggregatedFromTable($parsed, $fromTable, $type);
        }

        $selectColumns = $this->getSelectColumns($type);

        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $parsed['params'] = $whereData['params'];

        $orderClause = '';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }

        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT {$selectColumns}
            FROM {$fromTable}{$whereClause}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $parsed['params']);

        return $this->parseResults($result, $type);
    }

    /**
     * Find aggregated metrics from a table using time-bucketed grouping.
     *
     * Produces SQL like:
     *   SELECT metric, SUM(value) as value,
     *          toStartOfInterval(time, INTERVAL 1 HOUR) as time
     *   FROM table WHERE ... GROUP BY metric, time ORDER BY time ASC
     *
     * @param array<string, mixed> $parsed Parsed query data from parseQueries()
     * @param string $fromTable Fully qualified table reference
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     * @throws Exception
     */
    private function findAggregatedFromTable(array $parsed, string $fromTable, string $type): array
    {
        /** @var string $interval */
        $interval = $parsed['groupByInterval'];
        $intervalSql = UsageQuery::VALID_INTERVALS[$interval];

        // Choose aggregation function based on metric type
        $valueExpr = $type === Usage::TYPE_GAUGE
            ? 'argMax(value, time) as value'
            : 'SUM(value) as value';

        // Use 'bucket' alias to avoid collision with the raw 'time' column,
        // then alias back to 'time' in outer context for consistent Metric parsing.
        $timeBucketExpr = "toStartOfInterval(time, {$intervalSql})";

        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Use custom ORDER BY if specified, otherwise default to bucket ASC
        $orderClause = ' ORDER BY bucket ASC';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }

        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, {$valueExpr}, {$timeBucketExpr} as bucket
            FROM {$fromTable}{$whereClause}
            GROUP BY metric, bucket{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);

        return $this->parseAggregatedResults($result, $type);
    }

    /**
     * Parse ClickHouse JSON results from an aggregated (groupByInterval) query into Metric array.
     *
     * Maps the 'bucket' column back to 'time' for consistent Metric objects.
     *
     * @param string $result Raw JSON response from ClickHouse
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     */
    private function parseAggregatedResults(string $result, string $type = 'event'): array
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
                if ($key === 'bucket') {
                    // Map 'bucket' back to 'time' for consistent Metric objects
                    $parsedTime = (string) $value;
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document['time'] = $parsedTime;
                } elseif ($key === 'value') {
                    $document[$key] = $value !== null ? (int) $value : null;
                } else {
                    $document[$key] = $value;
                }
            }

            // Set the type based on which table we queried
            $document['type'] = $type;

            $metrics[] = new Metric($document);
        }

        return $metrics;
    }

    /**
     * Count metrics using Query objects.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (both)
     * @return int
     * @throws Exception
     */
    public function count(array $queries = [], ?string $type = null): int
    {
        $this->setOperationContext('count()');

        if ($type !== null) {
            return $this->countFromTable($queries, $type);
        }

        // Count from both tables
        return $this->countFromTable($queries, Usage::TYPE_EVENT)
             + $this->countFromTable($queries, Usage::TYPE_GAUGE);
    }

    /**
     * Count metrics from a specific table.
     *
     * @param array<Query> $queries
     * @param string $type
     * @return int
     * @throws Exception
     */
    private function countFromTable(array $queries, string $type): int
    {
        $tableName = $this->getTableForType($type);
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, $type);

        $params = $parsed['params'];
        unset($params['limit'], $params['offset']);

        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        $sql = "
            SELECT COUNT(*) as total FROM {$fromTable}{$whereClause}
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
     * Sum metric values using Query objects.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string|null $type 'event', 'gauge', or null (both)
     * @return int
     * @throws Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', ?string $type = null): int
    {
        $this->setOperationContext('sum()');

        if ($type !== null) {
            return $this->sumFromTable($queries, $attribute, $type);
        }

        // Sum from both tables
        return $this->sumFromTable($queries, $attribute, Usage::TYPE_EVENT)
             + $this->sumFromTable($queries, $attribute, Usage::TYPE_GAUGE);
    }

    /**
     * Sum metric values from a specific table.
     *
     * @param array<Query> $queries
     * @param string $attribute
     * @param string $type
     * @return int
     * @throws Exception
     */
    private function sumFromTable(array $queries, string $attribute, string $type): int
    {
        $tableName = $this->getTableForType($type);
        $fromTable = $this->buildTableReference($tableName);

        $this->validateAttributeName($attribute, $type);
        $escapedAttribute = $this->escapeIdentifier($attribute);

        $parsed = $this->parseQueries($queries, $type);

        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        $sql = "
            SELECT sum({$escapedAttribute}) as total FROM {$fromTable}{$whereClause}
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
     * Find event metrics from the pre-aggregated daily table.
     *
     * @param array<Query> $queries
     * @return array<Metric>
     * @throws Exception
     */
    public function findDaily(array $queries = []): array
    {
        $this->setOperationContext('findDaily()');

        $fromTable = $this->buildTableReference($this->getEventsDailyTableName());

        // Daily table has limited columns — only allow metric, value, time, resource, resourceId, tenant
        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $dailyColumns = ['metric', 'value', 'time'];
        if ($this->sharedTables) {
            $dailyColumns[] = 'tenant';
        }
        $selectColumns = implode(', ', array_map(fn ($c) => $this->escapeIdentifier($c), $dailyColumns));

        $orderClause = !empty($parsed['orderBy']) ? ' ORDER BY ' . implode(', ', $parsed['orderBy']) : '';
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "SELECT {$selectColumns} FROM {$fromTable}{$whereData['clause']}{$orderClause}{$limitClause}{$offsetClause} FORMAT JSON";

        return $this->parseResults($this->query($sql, $whereData['params']), Usage::TYPE_EVENT);
    }

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     * @throws Exception
     */
    public function sumDaily(array $queries = [], string $attribute = 'value'): int
    {
        $this->setOperationContext('sumDaily()');

        $fromTable = $this->buildTableReference($this->getEventsDailyTableName());
        $this->validateAttributeName($attribute, Usage::TYPE_EVENT);
        $escapedAttribute = $this->escapeIdentifier($attribute);

        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $sql = "SELECT sum({$escapedAttribute}) as total FROM {$fromTable}{$whereData['clause']} FORMAT JSON";

        $result = $this->query($sql, $whereData['params']);
        $json = json_decode($result, true);

        return (is_array($json) && isset($json['data'][0]['total'])) ? (int) $json['data'][0]['total'] : 0;
    }

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * @param array<string> $metrics
     * @param array<Query> $queries
     * @return array<string, int>
     * @throws Exception
     */
    public function sumDailyBatch(array $metrics, array $queries = []): array
    {
        if (empty($metrics)) {
            return [];
        }

        $this->setOperationContext('sumDailyBatch()');

        $totals = \array_fill_keys($metrics, 0);

        $fromTable = $this->buildTableReference($this->getEventsDailyTableName());

        // Build metric IN params
        $metricParams = [];
        $metricPlaceholders = [];
        foreach ($metrics as $i => $metric) {
            $paramName = 'metric_' . $i;
            $metricParams[$paramName] = $metric;
            $metricPlaceholders[] = "{{$paramName}:String}";
        }
        $metricInClause = implode(', ', $metricPlaceholders);

        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $params = array_merge($metricParams, $parsed['params']);

        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        $metricFilter = $this->escapeIdentifier('metric') . " IN ({$metricInClause})";
        $whereClause = !empty($whereClause)
            ? $whereClause . ' AND ' . $metricFilter
            : ' WHERE ' . $metricFilter;

        $sql = "
            SELECT metric, SUM(value) as total
            FROM {$fromTable}{$whereClause}
            GROUP BY metric
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);
        $json = json_decode($result, true);

        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $row) {
                $metricName = $row['metric'] ?? '';
                if (isset($totals[$metricName])) {
                    $totals[$metricName] = (int) ($row['total'] ?? 0);
                }
            }
        }

        return $totals;
    }

    /**
     * Get time series data for metrics with query-time aggregation.
     *
     * Uses SUM for event metrics and argMax for gauge metrics.
     * When $type is null, queries both tables and merges results.
     *
     * @param  array<string>  $metrics
     * @param  string  $interval  '1h' or '1d'
     * @param  string  $startDate
     * @param  string  $endDate
     * @param  array<Query>  $queries
     * @param  bool  $zeroFill
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return array<string, array{total: int, data: array<array{value: int, date: string}>}>
     * @throws Exception
     */
    public function getTimeSeries(array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        if (!isset(self::INTERVAL_FUNCTIONS[$interval])) {
            throw new \InvalidArgumentException("Invalid interval '{$interval}'. Allowed: " . implode(', ', array_keys(self::INTERVAL_FUNCTIONS)));
        }

        $this->setOperationContext('getTimeSeries()');

        // Initialize result structure
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0, 'data' => []];
        }

        $typesToQuery = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToQuery[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToQuery[] = Usage::TYPE_GAUGE;
        }

        foreach ($typesToQuery as $queryType) {
            $typeResult = $this->getTimeSeriesFromTable($metrics, $interval, $startDate, $endDate, $queries, $queryType);

            // Merge results
            foreach ($typeResult as $metricName => $metricData) {
                if (!isset($output[$metricName])) {
                    continue;
                }

                $output[$metricName]['total'] += $metricData['total'];
                $output[$metricName]['data'] = array_merge(
                    $output[$metricName]['data'],
                    $metricData['data']
                );
            }
        }

        // Zero-fill gaps if requested
        if ($zeroFill) {
            foreach ($output as $metricName => &$metricData) {
                $metricData['data'] = $this->zeroFillTimeSeries(
                    $metricData['data'],
                    $interval,
                    $startDate,
                    $endDate
                );
            }
            unset($metricData);
        }

        return $output;
    }

    /**
     * Get time series data from a specific table.
     *
     * @param  array<string>  $metrics
     * @param  string  $interval
     * @param  string  $startDate
     * @param  string  $endDate
     * @param  array<Query>  $queries
     * @param  string  $type
     * @return array<string, array{total: int, data: array<array{value: int, date: string}>}>
     * @throws Exception
     */
    private function getTimeSeriesFromTable(array $metrics, string $interval, string $startDate, string $endDate, array $queries, string $type): array
    {
        $timeFunction = self::INTERVAL_FUNCTIONS[$interval];
        $tableName = $this->getTableForType($type);
        $fromTable = $this->buildTableReference($tableName);

        // Build metric IN params
        $metricParams = [];
        $metricPlaceholders = [];
        foreach ($metrics as $i => $metric) {
            $paramName = 'metric_' . $i;
            $metricParams[$paramName] = $metric;
            $metricPlaceholders[] = "{{$paramName}:String}";
        }

        $metricInClause = implode(', ', $metricPlaceholders);

        // Build additional WHERE conditions from queries
        $parsed = $this->parseQueries($queries, $type);
        $additionalFilters = $parsed['filters'];
        $params = array_merge($metricParams, $parsed['params']);

        $params['start_date'] = $this->formatDateTime($startDate);
        $params['end_date'] = $this->formatDateTime($endDate);

        // Build tenant filter
        $tenantFilter = '';
        if ($this->sharedTables && $this->tenant !== null) {
            $tenantFilter = ' AND tenant = {tenant:Nullable(String)}';
            $params['tenant'] = $this->tenant;
        }

        $additionalWhere = '';
        if (!empty($additionalFilters)) {
            $additionalWhere = ' AND ' . implode(' AND ', $additionalFilters);
        }

        // Use appropriate aggregation based on type
        if ($type === Usage::TYPE_EVENT) {
            $valueExpr = 'SUM(value) as agg_value';
        } else {
            $valueExpr = 'argMax(value, time) as agg_value';
        }

        $sql = "
            SELECT
                metric,
                {$timeFunction}(time) as bucket,
                {$valueExpr}
            FROM {$fromTable}
            WHERE metric IN ({$metricInClause})
                AND time BETWEEN {start_date:DateTime64(3)} AND {end_date:DateTime64(3)}
                {$tenantFilter}{$additionalWhere}
            GROUP BY metric, bucket
            ORDER BY bucket ASC
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);
        $json = json_decode($result, true);

        // Initialize result structure
        $output = [];
        foreach ($metrics as $metric) {
            $output[$metric] = ['total' => 0, 'data' => []];
        }

        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $row) {
                $metricName = $row['metric'] ?? '';
                $bucketTime = $row['bucket'] ?? '';
                $value = (int) ($row['agg_value'] ?? 0);

                if (!isset($output[$metricName])) {
                    continue;
                }

                // Format bucket time
                $formattedDate = $bucketTime;
                if (is_string($bucketTime) && strpos($bucketTime, 'T') === false) {
                    $formattedDate = str_replace(' ', 'T', $bucketTime) . '+00:00';
                }

                $output[$metricName]['total'] += $value;
                $output[$metricName]['data'][] = [
                    'value' => $value,
                    'date' => $formattedDate,
                ];
            }
        }

        return $output;
    }

    /**
     * Fill gaps in time series data with zero-value entries.
     *
     * @param array<array{value: int, date: string}> $data Existing data points
     * @param string $interval '1h' or '1d'
     * @param string $startDate Start datetime
     * @param string $endDate End datetime
     * @return array<array{value: int, date: string}>
     */
    private function zeroFillTimeSeries(array $data, string $interval, string $startDate, string $endDate): array
    {
        $format = $interval === '1h' ? 'Y-m-d\TH:00:00+00:00' : 'Y-m-d\T00:00:00+00:00';
        $step = $interval === '1h' ? '+1 hour' : '+1 day';

        // Build lookup of existing data points by formatted date
        $existing = [];
        foreach ($data as $point) {
            $dt = new \DateTime($point['date']);
            $key = $dt->format($format);
            // If multiple points in the same bucket, sum them
            $existing[$key] = ($existing[$key] ?? 0) + $point['value'];
        }

        // Generate all time buckets in range
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        $result = [];
        $current = clone $start;

        while ($current <= $end) {
            $key = $current->format($format);
            $result[] = [
                'value' => $existing[$key] ?? 0,
                'date' => $key,
            ];
            $current->modify($step);
        }

        return $result;
    }

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * When $type is null, queries both tables.
     *
     * @param  string  $metric
     * @param  array<Query>  $queries
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return int
     * @throws Exception
     */
    public function getTotal(string $metric, array $queries = [], ?string $type = null): int
    {
        $this->setOperationContext('getTotal()');

        if ($type === Usage::TYPE_EVENT) {
            return $this->getTotalFromEvents($metric, $queries);
        }

        if ($type === Usage::TYPE_GAUGE) {
            return $this->getTotalFromGauges($metric, $queries);
        }

        // Query both tables — event uses SUM, gauge uses argMax
        $eventTotal = $this->getTotalFromEvents($metric, $queries);
        $gaugeTotal = $this->getTotalFromGauges($metric, $queries);

        // If we got data from both, prioritize event (they don't overlap in practice)
        // If only one has data, return that
        if ($eventTotal > 0 && $gaugeTotal > 0) {
            // A metric shouldn't be in both tables; return whichever is nonzero
            // In practice, callers specify type for ambiguous cases
            return $eventTotal + $gaugeTotal;
        }

        return $eventTotal > 0 ? $eventTotal : $gaugeTotal;
    }

    /**
     * Get total from events table (SUM).
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromEvents(string $metric, array $queries): int
    {
        $tableName = $this->getEventsTableName();
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $params = $parsed['params'];
        $params['metric_name'] = $metric;

        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Add metric filter
        $metricFilter = $this->escapeIdentifier('metric') . ' = {metric_name:String}';
        if (!empty($whereClause)) {
            $whereClause .= ' AND ' . $metricFilter;
        } else {
            $whereClause = ' WHERE ' . $metricFilter;
        }

        $sql = "
            SELECT SUM(value) as total
            FROM {$fromTable}{$whereClause}
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
     * Get total from gauges table (argMax).
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromGauges(string $metric, array $queries): int
    {
        $tableName = $this->getGaugesTableName();
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, Usage::TYPE_GAUGE);
        $params = $parsed['params'];
        $params['metric_name'] = $metric;

        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Add metric filter
        $metricFilter = $this->escapeIdentifier('metric') . ' = {metric_name:String}';
        if (!empty($whereClause)) {
            $whereClause .= ' AND ' . $metricFilter;
        } else {
            $whereClause = ' WHERE ' . $metricFilter;
        }

        $sql = "
            SELECT argMax(value, time) as total
            FROM {$fromTable}{$whereClause}
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
     * Get totals for multiple metrics in a single query.
     *
     * @param  array<string>  $metrics
     * @param  array<Query>  $queries
     * @param  string|null  $type  'event', 'gauge', or null (both)
     * @return array<string, int>
     * @throws Exception
     */
    public function getTotalBatch(array $metrics, array $queries = [], ?string $type = null): array
    {
        if (empty($metrics)) {
            return [];
        }

        $this->setOperationContext('getTotalBatch()');

        // Initialize all metrics to 0
        $totals = \array_fill_keys($metrics, 0);

        $typesToQuery = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToQuery[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToQuery[] = Usage::TYPE_GAUGE;
        }

        foreach ($typesToQuery as $queryType) {
            $tableName = $this->getTableForType($queryType);
            $fromTable = $this->buildTableReference($tableName);

            // Build metric IN params
            $metricParams = [];
            $metricPlaceholders = [];
            foreach ($metrics as $i => $metric) {
                $paramName = 'metric_' . $i;
                $metricParams[$paramName] = $metric;
                $metricPlaceholders[] = "{{$paramName}:String}";
            }
            $metricInClause = implode(', ', $metricPlaceholders);

            $parsed = $this->parseQueries($queries, $queryType);
            $params = array_merge($metricParams, $parsed['params']);

            $whereData = $this->buildWhereClause($parsed['filters'], $params);
            $whereClause = $whereData['clause'];
            $params = $whereData['params'];

            $escapedMetric = $this->escapeIdentifier('metric');
            $metricFilter = "{$escapedMetric} IN ({$metricInClause})";
            if (!empty($whereClause)) {
                $whereClause .= ' AND ' . $metricFilter;
            } else {
                $whereClause = ' WHERE ' . $metricFilter;
            }

            // Use appropriate aggregation
            if ($queryType === Usage::TYPE_EVENT) {
                $valueExpr = 'SUM(value) as agg_val';
            } else {
                $valueExpr = 'argMax(value, time) as agg_val';
            }

            $sql = "
                SELECT
                    metric,
                    {$valueExpr}
                FROM {$fromTable}{$whereClause}
                GROUP BY metric
                FORMAT JSON
            ";

            $result = $this->query($sql, $params);
            $json = json_decode($result, true);

            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                foreach ($json['data'] as $row) {
                    $metricName = $row['metric'] ?? '';

                    if (!isset($totals[$metricName])) {
                        continue;
                    }

                    $totals[$metricName] += (int) ($row['agg_val'] ?? 0);
                }
            }
        }

        return $totals;
    }

    /**
     * Build WHERE clause from filters with optional tenant filtering.
     *
     * @param array<string> $filters
     * @param array<string, mixed> $params
     * @param bool $includeTenant
     * @return array{clause: string, params: array<string, mixed>}
     */
    private function buildWhereClause(array $filters, array $params = [], bool $includeTenant = true): array
    {
        $conditions = $filters;
        $whereParams = $params;

        if ($includeTenant) {
            $tenantFilter = $this->getTenantFilter();
            if ($tenantFilter) {
                $conditions[] = $tenantFilter;
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
     * Get the ClickHouse parameter type string for a given attribute.
     *
     * Returns 'Int64' for the value column, 'String' for everything else.
     *
     * @param string $attribute
     * @return string
     */
    private function getParamType(string $attribute): string
    {
        return $attribute === 'value' ? 'Int64' : 'String';
    }

    /**
     * Parse Query objects into SQL clauses.
     *
     * @param array<Query> $queries
     * @param string $type 'event' or 'gauge' — used for attribute validation
     * @return array{filters: array<string>, params: array<string, mixed>, orderBy?: array<string>, limit?: int, offset?: int, groupByInterval?: string}
     * @throws Exception
     */
    private function parseQueries(array $queries, string $type = 'event'): array
    {
        $filters = [];
        $params = [];
        $orderBy = [];
        $limit = null;
        $offset = null;
        $groupByInterval = null;
        $paramCounter = 0;

        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            switch ($method) {
                case Query::TYPE_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);

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
                                $inParams[] = "{{$paramName}:{$chType}}";
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
                            $filters[] = "{$escapedAttr} = {{$paramName}:{$chType}}";
                        }
                        $params[$paramName] = $formattedValue;
                    }
                    break;

                case Query::TYPE_LESSER:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $value = is_array($values) && !empty($values) ? $values[0] : $values;
                    if ($attribute === 'time') {
                        $filters[] = "{$escapedAttr} < {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($value);
                    } else {
                        $filters[] = "{$escapedAttr} < {{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatParamValue($value);
                    }
                    break;

                case Query::TYPE_GREATER:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $value = is_array($values) && !empty($values) ? $values[0] : $values;
                    if ($attribute === 'time') {
                        $filters[] = "{$escapedAttr} > {{$paramName}:DateTime64(3)}";
                        $params[$paramName] = $this->formatDateTime($value);
                    } else {
                        $filters[] = "{$escapedAttr} > {{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatParamValue($value);
                    }
                    break;

                case Query::TYPE_BETWEEN:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName1 = 'param_' . $paramCounter++;
                    $paramName2 = 'param_' . $paramCounter++;
                    $value1 = is_array($values) && isset($values[0]) ? $values[0] : $values;
                    $value2 = is_array($values) && isset($values[1]) ? $values[1] : $values;
                    if ($attribute === 'time') {
                        $paramType = 'DateTime64(3)';
                        $filters[] = "{$escapedAttr} BETWEEN {{$paramName1}:{$paramType}} AND {{$paramName2}:{$paramType}}";
                        $params[$paramName1] = $this->formatDateTime($value1);
                        $params[$paramName2] = $this->formatDateTime($value2);
                    } else {
                        $filters[] = "{$escapedAttr} BETWEEN {{$paramName1}:{$chType}} AND {{$paramName2}:{$chType}}";
                        $params[$paramName1] = $this->formatParamValue($value1);
                        $params[$paramName2] = $this->formatParamValue($value2);
                    }
                    break;

                case Query::TYPE_ORDER_DESC:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} DESC";
                    break;

                case Query::TYPE_ORDER_ASC:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} ASC";
                    break;

                case Query::TYPE_CONTAINS:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $inParams = [];
                    foreach ($values as $value) {
                        $paramName = 'param_' . $paramCounter++;
                        if ($attribute === 'time') {
                            $inParams[] = "{{$paramName}:DateTime64(3)}";
                            /** @var \DateTime|string|null $singleValue */
                            $singleValue = $value;
                            $params[$paramName] = $this->formatDateTime($singleValue);
                        } else {
                            $inParams[] = "{{$paramName}:{$chType}}";
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
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $singleValue = null;
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
                        $filters[] = "{$escapedAttr} <= {{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatParamValue($singleValue);
                    }
                    break;

                case Query::TYPE_GREATER_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $singleValue = null;
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
                        $filters[] = "{$escapedAttr} >= {{$paramName}:{$chType}}";
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

                case UsageQuery::TYPE_GROUP_BY_INTERVAL:
                    $this->validateAttributeName($attribute, $type);
                    $interval = $values[0] ?? '1h';
                    if (!is_string($interval) || !isset(UsageQuery::VALID_INTERVALS[$interval])) {
                        throw new \Exception(
                            "Invalid groupByInterval interval '{$interval}'. Allowed: "
                            . implode(', ', array_keys(UsageQuery::VALID_INTERVALS))
                        );
                    }
                    $groupByInterval = $interval;
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

        if ($groupByInterval !== null) {
            $result['groupByInterval'] = $groupByInterval;
        }

        return $result;
    }

    /**
     * Parse ClickHouse JSON results into Metric array.
     *
     * @param string $result
     * @param string $type 'event' or 'gauge' — used to set the type attribute on parsed metrics
     * @return array<Metric>
     */
    private function parseResults(string $result, string $type = 'event'): array
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
                    $document[$key] = $value !== null ? (string) $value : null;
                } elseif ($key === 'value') {
                    $document[$key] = $value !== null ? (int) $value : null;
                } elseif ($key === 'time') {
                    $parsedTime = (string)$value;
                    if (strpos($parsedTime, 'T') === false) {
                        $parsedTime = str_replace(' ', 'T', $parsedTime) . '+00:00';
                    }
                    $document[$key] = $parsedTime;
                } elseif ($key === 'tags') {
                    if (is_string($value)) {
                        $document[$key] = json_decode($value, true) ?? [];
                    } else {
                        $document[$key] = $value;
                    }
                } else {
                    $document[$key] = $value;
                }
            }

            if (isset($document['id'])) {
                $document['$id'] = $document['id'];
                unset($document['id']);
            }

            // Set the type based on which table we queried
            $document['type'] = $type;

            $metrics[] = new Metric($document);
        }

        return $metrics;
    }

    /**
     * Get the SELECT column list for queries.
     *
     * @param string $type 'event' or 'gauge'
     * @return string
     */
    private function getSelectColumns(string $type = 'event'): string
    {
        $columns = [];

        $columns[] = $this->escapeIdentifier('id');

        foreach ($this->getAttributes($type) as $attribute) {
            $id = $attribute['$id'];
            if (is_string($id)) {
                $columns[] = $this->escapeIdentifier($id);
            }
        }

        if ($this->sharedTables) {
            $columns[] = $this->escapeIdentifier('tenant');
        }

        return implode(', ', $columns);
    }

    /**
     * Build tenant filter clause.
     *
     * @return string
     */
    private function getTenantFilter(): string
    {
        if (!$this->sharedTables || $this->tenant === null) {
            return '';
        }

        return "tenant = {tenant:Nullable(String)}";
    }

    /**
     * Purge usage metrics matching the given queries.
     * Deletes from the specified table(s).
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (purge both)
     * @throws Exception
     */
    public function purge(array $queries = [], ?string $type = null): bool
    {
        $this->setOperationContext('purge()');

        $typesToPurge = [];
        if ($type === Usage::TYPE_EVENT || $type === null) {
            $typesToPurge[] = Usage::TYPE_EVENT;
        }
        if ($type === Usage::TYPE_GAUGE || $type === null) {
            $typesToPurge[] = Usage::TYPE_GAUGE;
        }

        foreach ($typesToPurge as $purgeType) {
            $tableName = $this->getTableForType($purgeType);
            $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

            $parsed = $this->parseQueries($queries, $purgeType);
            $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
            $whereClause = $whereData['clause'];
            $params = $whereData['params'];

            if (empty($whereClause)) {
                $whereClause = ' WHERE 1=1';
            }

            $sql = "DELETE FROM {$escapedTable}{$whereClause}";
            $this->query($sql, $params);
        }

        return true;
    }
}
