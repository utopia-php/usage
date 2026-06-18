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

    /**
     * Filter methods that must be supplied at least one value. Empty `values`
     * arrays for these methods are rejected up front so they can't silently
     * compile into a "no filter applied" WHERE clause.
     *
     * @var list<string>
     */
    private const VALUE_REQUIRED_METHODS = [
        Query::TYPE_EQUAL,
        Query::TYPE_NOT_EQUAL,
        Query::TYPE_LESSER,
        Query::TYPE_LESSER_EQUAL,
        Query::TYPE_GREATER,
        Query::TYPE_GREATER_EQUAL,
        Query::TYPE_BETWEEN,
        Query::TYPE_NOT_BETWEEN,
        Query::TYPE_CONTAINS,
        Query::TYPE_NOT_CONTAINS,
        Query::TYPE_STARTS_WITH,
        Query::TYPE_ENDS_WITH,
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
     * Opt-in query_id forwarded to ClickHouse on the next query() call only.
     * Cleared after a single use so callers must set it explicitly per query.
     */
    private ?string $nextQueryId = null;

    /**
     * Structured log entries recorded for each routing decision. Ops
     * dashboards read these to confirm rollup hit-rate.
     *
     * @var array<array{operation: string, metric: ?string, route_decision: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string}>
     */
    private array $routeLog = [];

    /**
     * Probability (0.0 - 1.0) that the next eligible read also runs against
     * the raw events table and logs the delta. Recommended 0.01 for
     * progressive rollout; default 0.0 (off).
     */
    private float $dualReadSampleRate = 0.0;

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
     * Forward an opt-in `query_id` to ClickHouse on the next `query()` call.
     *
     * Single-use: cleared automatically after one query is dispatched. Pass
     * null to clear without dispatching. Benchmarks use this to look up the
     * matching row in `system.query_log` (rows_read / read_bytes /
     * query_duration_ms).
     */
    public function setNextQueryId(?string $queryId): self
    {
        $this->nextQueryId = $queryId;
        return $this;
    }

    /**
     * Return the in-memory route-decision log (operation, metric,
     * route_decision, window, dimensions, interval). Cleared by
     * clearRouteLog().
     *
     * @return array<array{operation: string, metric: ?string, route_decision: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string}>
     */
    public function getRouteLog(): array
    {
        return $this->routeLog;
    }

    public function clearRouteLog(): self
    {
        $this->routeLog = [];
        return $this;
    }

    /**
     * Sample rate for dual-read parity checks. When > 0, eligible
     * routed reads also execute against the raw events table and log a
     * `warning` route_decision entry if the totals diverge by >1%.
     *
     * @param float $rate 0.0 (off) … 1.0 (every read)
     */
    public function setDualReadSampleRate(float $rate): self
    {
        if ($rate < 0.0) {
            $rate = 0.0;
        }
        if ($rate > 1.0) {
            $rate = 1.0;
        }
        $this->dualReadSampleRate = $rate;
        return $this;
    }

    public function getDualReadSampleRate(): float
    {
        return $this->dualReadSampleRate;
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
        $queryId = $this->nextQueryId;
        $this->nextQueryId = null;

        return $this->executeWithRetry(
            function (int $attempt) use ($sql, $params, $queryId): string {
                $startTime = microtime(true);
                $scheme = $this->secure ? 'https' : 'http';
                $url = "{$scheme}://{$this->host}:{$this->port}/";
                if ($queryId !== null && $queryId !== '') {
                    $url .= '?' . http_build_query(['query_id' => $queryId]);
                }

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
                // Never retry inserts. The underlying MergeTree engine has
                // no row-level deduplication, so a retried insert that hits
                // the server twice (network blip + first request actually
                // succeeded) leaves duplicate rows behind. Surface the
                // failure to the caller instead — they can replay the
                // batch from durable storage if they choose.
                return false;
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
     * Per-dim rollup MV slate. Each entry creates a SummingMergeTree table
     * + materialized view that fans out off the raw events INSERT.
     *
     * The (method, status) cross-product is bounded (~180 keys) so the two
     * dims share a single MV; everything else is single-dim.
     *
     * @var array<array{name: string, dims: array<int, string>}>
     */
    private const DIM_ROLLUPS = [
        ['name' => 'by_path', 'dims' => ['path']],
        ['name' => 'by_country', 'dims' => ['country']],
        ['name' => 'by_service', 'dims' => ['service']],
        ['name' => 'by_method_status', 'dims' => ['method', 'status']],
    ];

    /**
     * Per-dim rollup MV slate for gauges. argMax(value, time) is non-additive,
     * so each entry creates an AggregatingMergeTree table + materialized view
     * that emits argMaxState on write and the read path collapses with
     * argMaxMerge.
     *
     * @var array<array{name: string, dims: array<int, string>}>
     */
    private const GAUGE_DIM_ROLLUPS = [
        ['name' => 'by_service', 'dims' => ['service']],
        ['name' => 'by_resource', 'dims' => ['resource']],
    ];

    /**
     * Setup ClickHouse table structure.
     *
     * Creates:
     * 1. Events table (MergeTree) with event-specific columns
     * 2. Events daily table (SummingMergeTree) for pre-aggregation
     * 3. Events daily materialized view
     * 4. Gauges table (MergeTree) with simple schema
     * 5. Per-dim rollup tables + MVs (by_path, by_country, by_service, by_method_status)
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

        // --- Per-dim rollup tables + MVs ---
        foreach (self::DIM_ROLLUPS as $rollup) {
            $this->createDimRollupTable($rollup['name'], $rollup['dims']);
            $this->createDimRollupMaterializedView($rollup['name'], $rollup['dims']);
        }

        // --- Gauges table ---
        $this->createTable(
            $this->getGaugesTableName(),
            'gauge',
            $this->getGaugeIndexes()
        );

        $this->ensureGaugeDimColumns();

        // --- Per-dim gauge rollup tables + MVs (AggregatingMergeTree) ---
        foreach (self::GAUGE_DIM_ROLLUPS as $rollup) {
            $this->createGaugeDimRollupTable($rollup['name'], $rollup['dims']);
            $this->createGaugeDimRollupMaterializedView($rollup['name'], $rollup['dims']);
        }
    }

    /**
     * Backfill the service / resource columns on an existing gauges table.
     * setup() uses CREATE TABLE IF NOT EXISTS, so deployments that came up
     * before these columns were added never receive them — the gauge AMT
     * MVs would then fail at insert time because their SELECT references
     * columns the source table lacks.
     */
    private function ensureGaugeDimColumns(): void
    {
        $gaugesTable = $this->escapeIdentifier($this->database)
            . '.' . $this->escapeIdentifier($this->getGaugesTableName());

        $sql = "ALTER TABLE {$gaugesTable} "
            . 'ADD COLUMN IF NOT EXISTS service LowCardinality(Nullable(String)), '
            . 'ADD COLUMN IF NOT EXISTS resource LowCardinality(Nullable(String))';

        $this->query($sql);
    }

    /**
     * @return string e.g. utopia_usage_events_daily_by_path
     */
    private function getDimRollupTableName(string $name): string
    {
        return $this->getTableName() . '_events_daily_' . $name;
    }

    private function getDimRollupMvName(string $name): string
    {
        return $this->getDimRollupTableName($name) . '_mv';
    }

    /**
     * @param array<int, string> $dims
     */
    private function createDimRollupTable(string $name, array $dims): void
    {
        $tableName = $this->getDimRollupTableName($name);
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $columns = [
            'metric String',
            'value Int64',
            "time DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)",
        ];

        foreach ($dims as $dim) {
            $columns[] = $this->escapeIdentifier($dim) . ' ' . $this->getDimRollupColumnType($dim);
        }

        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        $orderByParts = $this->sharedTables ? ['tenant', 'metric', 'time'] : ['metric', 'time'];
        foreach ($dims as $dim) {
            $orderByParts[] = $dim;
        }
        $orderBy = '(' . implode(', ', array_map(fn ($c) => $this->escapeIdentifier($c), $orderByParts)) . ')';

        $columnDefs = implode(",\n                ", $columns);

        $sql = "
            CREATE TABLE IF NOT EXISTS {$escapedTable} (
                {$columnDefs}
            )
            ENGINE = SummingMergeTree(value)
            ORDER BY {$orderBy}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($sql);
    }

    /**
     * Pick the storage layout for a dim rollup column. Bounded-cardinality
     * dimensions stay LowCardinality; unbounded ones (paths interpolate IDs,
     * so cardinality scales with row count) get plain Nullable(String) with
     * ZSTD to avoid LowCardinality overhead past its useful range.
     */
    private function getDimRollupColumnType(string $dim): string
    {
        if ($dim === 'path') {
            return 'Nullable(String) CODEC(ZSTD(3))';
        }
        return 'LowCardinality(Nullable(String))';
    }

    /**
     * @param array<int, string> $dims
     */
    private function createDimRollupMaterializedView(string $name, array $dims): void
    {
        $tableName = $this->getDimRollupTableName($name);
        $mvName = $this->getDimRollupMvName($name);

        $escapedEvents = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->getEventsTableName());
        $escapedTarget = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedMv = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($mvName);

        $dimList = implode(', ', array_map(fn ($d) => $this->escapeIdentifier($d), $dims));

        $selectColumns = ['metric', "toStartOfDay(time, 'UTC') AS time"];
        foreach ($dims as $dim) {
            $selectColumns[] = $this->escapeIdentifier($dim);
        }
        if ($this->sharedTables) {
            $selectColumns[] = 'tenant';
        }
        $selectColumns[] = 'sum(value) AS value';

        $groupByColumns = ['metric', 'time'];
        foreach ($dims as $dim) {
            $groupByColumns[] = $this->escapeIdentifier($dim);
        }
        if ($this->sharedTables) {
            $groupByColumns[] = 'tenant';
        }

        $selectSql = implode(', ', $selectColumns);
        $groupSql = implode(', ', $groupByColumns);

        $sql = "
            CREATE MATERIALIZED VIEW IF NOT EXISTS {$escapedMv}
            TO {$escapedTarget}
            AS SELECT {$selectSql}
            FROM {$escapedEvents}
            GROUP BY {$groupSql}
        ";

        $this->query($sql);
    }

    /**
     * @return string e.g. utopia_usage_gauges_daily_by_service
     */
    private function getGaugeDimRollupTableName(string $name): string
    {
        return $this->getTableName() . '_gauges_daily_' . $name;
    }

    private function getGaugeDimRollupMvName(string $name): string
    {
        return $this->getGaugeDimRollupTableName($name) . '_mv';
    }

    /**
     * @param array<int, string> $dims
     */
    private function createGaugeDimRollupTable(string $name, array $dims): void
    {
        $tableName = $this->getGaugeDimRollupTableName($name);
        $escapedTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $columns = [
            'metric String',
            "time DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)",
        ];

        foreach ($dims as $dim) {
            $columns[] = $this->escapeIdentifier($dim) . ' LowCardinality(Nullable(String))';
        }

        $columns[] = "value AggregateFunction(argMax, Int64, DateTime64(3, 'UTC'))";

        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        $orderByParts = $this->sharedTables ? ['tenant', 'metric', 'time'] : ['metric', 'time'];
        foreach ($dims as $dim) {
            $orderByParts[] = $dim;
        }
        $orderBy = '(' . implode(', ', array_map(fn ($c) => $this->escapeIdentifier($c), $orderByParts)) . ')';

        $columnDefs = implode(",\n                ", $columns);

        $sql = "
            CREATE TABLE IF NOT EXISTS {$escapedTable} (
                {$columnDefs}
            )
            ENGINE = AggregatingMergeTree()
            ORDER BY {$orderBy}
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192, allow_nullable_key = 1
        ";

        $this->query($sql);
    }

    /**
     * @param array<int, string> $dims
     */
    private function createGaugeDimRollupMaterializedView(string $name, array $dims): void
    {
        $tableName = $this->getGaugeDimRollupTableName($name);
        $mvName = $this->getGaugeDimRollupMvName($name);

        $escapedGauges = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->getGaugesTableName());
        $escapedTarget = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);
        $escapedMv = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($mvName);

        $innerSelect = ['metric', "toStartOfDay(time, 'UTC') AS d", 'time AS t'];
        foreach ($dims as $dim) {
            $innerSelect[] = $this->escapeIdentifier($dim);
        }
        if ($this->sharedTables) {
            $innerSelect[] = 'tenant';
        }
        $innerSelect[] = 'value';

        $outerSelect = ['metric', 'd AS time'];
        foreach ($dims as $dim) {
            $outerSelect[] = $this->escapeIdentifier($dim);
        }
        if ($this->sharedTables) {
            $outerSelect[] = 'tenant';
        }
        $outerSelect[] = 'argMaxState(value, t) AS value';

        $groupBy = ['metric', 'd'];
        foreach ($dims as $dim) {
            $groupBy[] = $this->escapeIdentifier($dim);
        }
        if ($this->sharedTables) {
            $groupBy[] = 'tenant';
        }

        $innerSelectSql = implode(', ', $innerSelect);
        $outerSelectSql = implode(', ', $outerSelect);
        $groupBySql = implode(', ', $groupBy);

        $sql = "
            CREATE MATERIALIZED VIEW IF NOT EXISTS {$escapedMv}
            TO {$escapedTarget}
            AS SELECT {$outerSelectSql}
            FROM (
                SELECT {$innerSelectSql}
                FROM {$escapedGauges}
            )
            GROUP BY {$groupBySql}
        ";

        $this->query($sql);
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
        $columns = ['id String ' . $this->getColumnCodec('id')];

        foreach ($this->getAttributes($type) as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];

            if ($id === 'time') {
                $columns[] = "time DateTime64(3, 'UTC') " . $this->getColumnCodec('time');
            } else {
                $columns[] = $this->getColumnDefinition($id, $type);
            }
        }

        // Add tenant column only if tables are shared across tenants
        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        // Build indexes. `indexType` selects per-column between bloom_filter
        // (high-cardinality strings) and set(0) (low-cardinality enums that
        // are too dense for blooms to ever skip — see plausible-research.md).
        $indexDefs = [];
        foreach ($indexes as $index) {
            /** @var string $indexName */
            $indexName = $index['$id'];
            /** @var array<string> $attributes */
            $attributes = $index['attributes'];
            $indexType = is_string($index['indexType'] ?? null) ? $index['indexType'] : 'bloom_filter';
            $escapedIndexName = $this->escapeIdentifier($indexName);
            $escapedAttributes = array_map(fn ($attr) => $this->escapeIdentifier($attr), $attributes);
            $attributeList = implode(', ', $escapedAttributes);
            $indexDefs[] = "INDEX {$escapedIndexName} ({$attributeList}) TYPE {$indexType} GRANULARITY 1";
        }

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($tableName);

        $columnDefs = implode(",\n                ", $columns);
        $indexDefsStr = !empty($indexDefs) ? ",\n                " . implode(",\n                ", $indexDefs) : '';

        // Primary key matches the most common filter pattern:
        // tenant (multi-tenant isolation) → metric (per-metric series) →
        // time (range scans). id is the tiebreaker for stable physical
        // ordering. This shape lets ClickHouse skip whole granules on
        // metric+time predicates instead of doing a full-table scan.
        $orderByExpr = $this->sharedTables ? '(tenant, metric, time, id)' : '(metric, time, id)';

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
            "time DateTime64(3, 'UTC')",
            'resource LowCardinality(Nullable(String))',
            'resourceId Nullable(String)',
            'resourceInternalId Nullable(String)',
            'teamId Nullable(String)',
            'teamInternalId Nullable(String)',
        ];

        if ($this->sharedTables) {
            $columns[] = 'tenant Nullable(String)';
        }

        $columnDefs = implode(",\n                ", $columns);

        $dailyOrderBy = $this->sharedTables
            ? '(tenant, metric, time, resource, resourceId, resourceInternalId, teamId, teamInternalId)'
            : '(metric, time, resource, resourceId, resourceInternalId, teamId, teamInternalId)';

        $createDailyTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDailyTable} (
                {$columnDefs}
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
    // NOTE: setup() uses CREATE IF NOT EXISTS for idempotency. If sharedTables
    // is toggled between calls, the original MV definition is kept (DROP+CREATE
    // would lose buffered data). This is acceptable for v1 since setup() is
    // expected to run once per environment lifecycle.
    private function createDailyMaterializedView(): void
    {
        $eventsTable = $this->getEventsTableName();
        $dailyTableName = $this->getEventsDailyTableName();
        $dailyMvName = $this->getTableName() . '_events_daily_mv';

        $escapedEventsTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($eventsTable);
        $escapedDailyTable  = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($dailyTableName);
        $escapedDailyMv     = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($dailyMvName);

        $dimensions = 'resource, resourceId, resourceInternalId, teamId, teamInternalId';

        if ($this->sharedTables) {
            $innerSelect  = "metric, tenant, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, tenant, {$dimensions}, d";
            $outerSelect  = "metric, value, d as time, tenant, {$dimensions}";
        } else {
            $innerSelect  = "metric, {$dimensions}, sum(value) as value, toStartOfDay(time, 'UTC') as d";
            $innerGroupBy = "metric, {$dimensions}, d";
            $outerSelect  = "metric, value, d as time, {$dimensions}";
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

        // Reject attributes that don't exist on the target type's schema.
        // Falling back to the other type's columns (e.g. allowing `path` on
        // a gauge query because it exists on the event schema) compiles to
        // SQL that references columns the gauge table doesn't have, which
        // ClickHouse rejects with "Unknown identifier".
        throw new Exception("Invalid attribute name: {$attributeName}");
    }

    /**
     * Validate that a groupBy attribute is an aggregable dimension column.
     *
     * Restricted to the indexed dimension columns for the table type — `metric`,
     * `value` and `time` are excluded since they are already part of the
     * aggregation (metric is in the SELECT, time is bucketed via
     * groupByInterval, value is the measured quantity).
     *
     * @throws Exception
     */
    private function validateGroupByAttribute(string $attribute, string $type): bool
    {
        $allowed = $type === Usage::TYPE_GAUGE ? Metric::GAUGE_COLUMNS : Metric::EVENT_COLUMNS;

        if (in_array($attribute, $allowed, true)) {
            return true;
        }

        throw new Exception("Invalid groupBy attribute '{$attribute}' for {$type}. Allowed: " . implode(', ', $allowed));
    }

    /**
     * Columns available in the events daily (pre-aggregated) table.
     */
    private const DAILY_COLUMNS = [
        'metric', 'value', 'time',
        'resource', 'resourceId', 'resourceInternalId',
        'teamId', 'teamInternalId',
    ];

    /**
     * Validate that a query attribute exists in the daily table schema.
     * The daily table only has metric, value, time (+ tenant if shared).
     *
     * @throws Exception
     */
    private function validateDailyAttributeName(string $attributeName): bool
    {
        if ($attributeName === 'id') {
            return true;
        }

        if ($attributeName === 'tenant' && $this->sharedTables) {
            return true;
        }

        if (in_array($attributeName, self::DAILY_COLUMNS, true)) {
            return true;
        }

        $allowed = implode(', ', self::DAILY_COLUMNS) . ($this->sharedTables ? ', tenant' : '');
        throw new Exception(
            "Invalid attribute '{$attributeName}' for daily table. "
            . "Allowed: {$allowed}."
        );
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

        $lowCardinality = [
            'country', 'region', 'service', 'resource',
            'osCode', 'osName', 'osVersion',
            'clientType', 'clientCode', 'clientName', 'clientVersion',
            'clientEngine', 'clientEngineVersion',
            'deviceName', 'deviceBrand', 'deviceModel',
            'hostname',
        ];

        if (in_array($id, $lowCardinality, true)) {
            return 'LowCardinality(Nullable(String))';
        }

        $attributeType = is_string($attribute['type'] ?? null) ? $attribute['type'] : 'string';
        $baseType = match ($attributeType) {
            'integer' => 'Int64',
            'float' => 'Float64',
            'boolean' => 'UInt8',
            'datetime' => "DateTime64(3, 'UTC')",
            default => 'String',
        };

        return !$attribute['required'] ? 'Nullable(' . $baseType . ')' : $baseType;
    }

    protected function getColumnDefinition(string $id, string $type = 'event'): string
    {
        $chType = $this->getColumnType($id, $type);
        $escapedId = $this->escapeIdentifier($id);
        $codec = $this->getColumnCodec($id);
        if ($codec !== '') {
            return "{$escapedId} {$chType} {$codec}";
        }
        return "{$escapedId} {$chType}";
    }

    /**
     * Return the per-column ClickHouse CODEC clause (Plausible-style
     * compression hygiene) for the events/gauges tables. Empty string when
     * no codec is overridden for this column.
     */
    private function getColumnCodec(string $id): string
    {
        if ($id === 'time') {
            return 'CODEC(Delta(4), LZ4)';
        }

        $zstdColumns = [
            'id', 'path', 'hostname',
            'resourceId', 'resourceInternalId',
            'teamId', 'teamInternalId',
            'osVersion', 'clientVersion', 'clientEngineVersion', 'deviceModel',
        ];

        if (in_array($id, $zstdColumns, true)) {
            return 'CODEC(ZSTD(3))';
        }

        return '';
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
    public function addBatch(array $metrics, string $type, int $batchSize = self::INSERT_BATCH_SIZE): bool
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

                $columns = Metric::extractColumns($tags, $type);

                $row = array_merge([
                    'id'     => $this->generateId(),
                    'metric' => $metric,
                    'value'  => $value,
                    'time'   => $this->formatDateTime(null),
                ], $columns);

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
            if ($type === Usage::TYPE_EVENT) {
                $plan = $this->extractRoutingPlan($queries);
                $route = $this->selectAggregateSource($plan, Usage::TYPE_EVENT);
                $this->recordRoute('find', $plan, $route);

                if (str_starts_with($route, 'daily_by_')) {
                    $name = substr($route, strlen('daily_'));
                    $rollup = $this->getRollupByName($name);
                    if ($rollup !== null) {
                        $result = $this->findFromDailyByDim($rollup, $queries);
                        $this->maybeDualRead($queries, $type, $route, $plan, $result);
                        return $result;
                    }
                }
                if (str_starts_with($route, 'hybrid_by_')) {
                    $name = substr($route, strlen('hybrid_'));
                    $rollup = $this->getRollupByName($name);
                    if ($rollup !== null) {
                        $result = $this->findHybridFromDailyByDim($rollup, $queries);
                        $this->maybeDualRead($queries, $type, $route, $plan, $result);
                        return $result;
                    }
                }
            }

            if ($type === Usage::TYPE_GAUGE) {
                $plan = $this->extractRoutingPlan($queries);
                $route = $this->selectAggregateSource($plan, Usage::TYPE_GAUGE);
                $this->recordRoute('find', $plan, $route);

                if (str_starts_with($route, 'gauges_daily_by_')) {
                    $name = substr($route, strlen('gauges_daily_'));
                    $rollup = $this->getGaugeRollupByName($name);
                    if ($rollup !== null) {
                        $result = $this->findFromGaugeDailyByDim($rollup, $queries);
                        $this->maybeDualRead($queries, $type, $route, $plan, $result);
                        return $result;
                    }
                }
                if (str_starts_with($route, 'gauges_hybrid_by_')) {
                    $name = substr($route, strlen('gauges_hybrid_'));
                    $rollup = $this->getGaugeRollupByName($name);
                    if ($rollup !== null) {
                        $result = $this->findHybridFromGaugeDailyByDim($rollup, $queries);
                        $this->maybeDualRead($queries, $type, $route, $plan, $result);
                        return $result;
                    }
                }
            }

            return $this->findFromTable($queries, $type);
        }

        // Cursor pagination is per-table — paginating across both events and
        // gauges has no coherent ordering, so reject this combination upfront.
        $userLimit = null;
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if ($method === Query::TYPE_CURSOR_AFTER || $method === Query::TYPE_CURSOR_BEFORE) {
                throw new Exception('Cursor pagination requires an explicit $type (event or gauge)');
            }
            if ($method === Query::TYPE_LIMIT) {
                $values = $query->getValues();
                if (!empty($values) && is_numeric($values[0])) {
                    $userLimit = (int) $values[0];
                }
            }
        }

        // Query both tables and merge. Each side already applied LIMIT, so
        // without a final cap callers asking for `limit(N)` could receive
        // up to 2N rows. Slice the merged result back down to the user's
        // requested limit. Tables whose schema doesn't support every filter
        // attribute (e.g. `path` on a gauge query) are skipped.
        $events = $this->queriesMatchType($queries, Usage::TYPE_EVENT)
            ? $this->findFromTable($queries, Usage::TYPE_EVENT)
            : [];
        $gauges = $this->queriesMatchType($queries, Usage::TYPE_GAUGE)
            ? $this->findFromTable($queries, Usage::TYPE_GAUGE)
            : [];

        $merged = array_merge($events, $gauges);

        if ($userLimit !== null && count($merged) > $userLimit) {
            $merged = array_slice($merged, 0, $userLimit);
        }

        return $merged;
    }

    /**
     * Check whether every filter attribute in $queries exists on the schema
     * for the given type. Used by the null-$type code paths in find/count/sum
     * so a query with event-only attributes (path/method/status/etc.) silently
     * skips the gauges table instead of throwing "Invalid attribute name".
     *
     * @param array<Query> $queries
     */
    private function queriesMatchType(array $queries, string $type): bool
    {
        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            if ($attribute === '' || $attribute === 'id') {
                continue;
            }
            if ($attribute === 'tenant' && $this->sharedTables) {
                continue;
            }
            $matched = false;
            foreach ($this->getAttributes($type) as $schemaAttribute) {
                if ($schemaAttribute['$id'] === $attribute) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
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

        // Cursor pagination is incompatible with time-bucketed aggregation —
        // aggregated rows have no stable identity to anchor a keyset cursor on.
        if (isset($parsed['cursor']) && isset($parsed['groupByInterval'])) {
            throw new Exception('Cursor pagination cannot be combined with groupByInterval');
        }

        // Route through the aggregated path whenever any aggregation
        // hint is present — time bucketing, dimension breakdown, or both.
        if (isset($parsed['groupByInterval']) || !empty($parsed['groupBy'])) {
            return $this->findAggregatedFromTable($parsed, $fromTable, $type);
        }

        $selectColumns = $this->getSelectColumns($type);

        $filters = $parsed['filters'];
        $params = $parsed['params'];
        $orderAttributes = $parsed['orderAttributes'] ?? [];
        $cursorDirection = $parsed['cursorDirection'] ?? null;

        if (isset($parsed['cursor'])) {
            $resolvedOrder = $this->resolveCursorOrder($orderAttributes);
            $cursorWhere = $this->buildCursorWhere($resolvedOrder, $parsed['cursor'], $cursorDirection ?? 'after', $params);
            $filters[] = $cursorWhere['clause'];
            $params = $cursorWhere['params'];
            $orderAttributes = $resolvedOrder;
        }

        $whereData = $this->buildWhereClause($filters, $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        $orderClause = '';
        if (isset($parsed['cursor'])) {
            // $orderAttributes is always non-empty here — resolveCursorOrder
            // appends an `id` tiebreaker when no order is specified.
            $orderSql = $this->buildOrderBySql($orderAttributes, flip: $cursorDirection === 'before');
            $orderClause = ' ORDER BY ' . implode(', ', $orderSql);
        } elseif (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }

        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT {$selectColumns}
            FROM {$fromTable}{$whereClause}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $params);

        $rows = $this->parseResults($result, $type);

        if ($cursorDirection === 'before') {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Find aggregated metrics from a table using time-bucketed grouping.
     *
     * Produces SQL like:
     *   SELECT metric, SUM(value) as value,
     *          toStartOfInterval(time, INTERVAL 1 HOUR) as time
     *   FROM table WHERE ... GROUP BY metric, time ORDER BY time ASC
     *
     * @param array{filters: array<string>, params: array<string, mixed>, orderBy?: array<string>, limit?: int, offset?: int, groupByInterval?: string, groupBy?: array<int, string>} $parsed Parsed query data from parseQueries()
     * @param string $fromTable Fully qualified table reference
     * @param string $type 'event' or 'gauge'
     * @return array<Metric>
     * @throws Exception
     */
    private function findAggregatedFromTable(array $parsed, string $fromTable, string $type): array
    {
        $hasInterval = isset($parsed['groupByInterval']);

        // Choose aggregation function based on metric type
        $valueExpr = $type === Usage::TYPE_GAUGE
            ? 'argMax(value, time) as value'
            : 'SUM(value) as value';

        // Bucket column is only emitted when time bucketing is requested.
        // Without it the result is a flat aggregate per (metric, …dims).
        $bucketSelect = '';
        $bucketGroup = '';
        if ($hasInterval) {
            $interval = $parsed['groupByInterval'];
            $intervalSql = UsageQuery::VALID_INTERVALS[$interval];
            $bucketSelect = ", toStartOfInterval(time, {$intervalSql}) as bucket";
            $bucketGroup = ', bucket';
        }

        $groupByDims = $parsed['groupBy'] ?? [];
        $dimSelect = '';
        $dimGroup = '';
        if (!empty($groupByDims)) {
            $escapedDims = array_map(
                fn (string $dim): string => $this->escapeIdentifier($dim),
                $groupByDims
            );
            $dimSelect = ', ' . implode(', ', $escapedDims);
            $dimGroup = ', ' . implode(', ', $escapedDims);
        }

        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        // Default ORDER BY:
        // - With time bucketing: bucket ASC (chronological time series).
        // - Dim-only: value DESC (top-N table semantics).
        // For caller-supplied ORDER BY, `time` is rewritten to `bucket`
        // only when bucket is present; otherwise sorting by time is
        // invalid (the column is no longer in the SELECT after GROUP BY).
        if ($hasInterval) {
            $orderClause = ' ORDER BY bucket ASC';
            if (!empty($parsed['orderBy'])) {
                $rewrittenOrderBy = array_map(
                    fn (string $clause): string => preg_replace(
                        '/^`time`(\s+(?:ASC|DESC))?$/',
                        '`bucket`$1',
                        $clause
                    ) ?? $clause,
                    $parsed['orderBy']
                );
                $orderClause = ' ORDER BY ' . implode(', ', $rewrittenOrderBy);
            }
        } else {
            $orderClause = ' ORDER BY value DESC';
            if (!empty($parsed['orderBy'])) {
                foreach ($parsed['orderBy'] as $clause) {
                    if (preg_match('/^`time`/', $clause)) {
                        throw new Exception(
                            'orderBy("time") requires groupByInterval — without time bucketing the result has no time column'
                        );
                    }
                }
                $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
            }
        }

        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, {$valueExpr}{$bucketSelect}{$dimSelect}
            FROM {$fromTable}{$whereClause}
            GROUP BY metric{$bucketGroup}{$dimGroup}{$orderClause}{$limitClause}{$offsetClause}
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
                    // Preserve numeric precision: SUM(value) over many rows
                    // can exceed PHP_INT_MAX, and gauge averages are floats.
                    // Casting to int truncates both cases — keep numeric
                    // strings as int|float depending on shape.
                    if ($value === null) {
                        $document[$key] = null;
                    } elseif (is_int($value) || is_float($value)) {
                        $document[$key] = $value;
                    } elseif (is_numeric($value)) {
                        $document[$key] = (str_contains((string) $value, '.') || str_contains((string) $value, 'e') || str_contains((string) $value, 'E'))
                            ? (float) $value
                            : (int) $value;
                    } else {
                        $document[$key] = $value;
                    }
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
     * When $max is non-null the count is bounded at the database level via
     * a `LIMIT {max}` inside a subquery — ClickHouse stops scanning once
     * that many rows have been matched, keeping large counts cheap.
     *
     * @param array<Query> $queries
     * @param string|null $type 'event', 'gauge', or null (both)
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     * @throws Exception
     */
    public function count(array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $this->setOperationContext('count()');

        if ($type !== null) {
            return $this->countFromTable($queries, $type, $max);
        }

        // Count from both tables. Each per-table count is independently
        // capped at $max, so naively summing them could yield up to 2*$max.
        // Cap the combined total at $max in PHP to honour the contract.
        // Skip a table when its schema can't satisfy every filter attribute.
        $events = $this->queriesMatchType($queries, Usage::TYPE_EVENT)
            ? $this->countFromTable($queries, Usage::TYPE_EVENT, $max)
            : 0;
        $gauges = $this->queriesMatchType($queries, Usage::TYPE_GAUGE)
            ? $this->countFromTable($queries, Usage::TYPE_GAUGE, $max)
            : 0;

        $total = $events + $gauges;

        if ($max !== null && $total > $max) {
            $total = $max;
        }

        return $total;
    }

    /**
     * Count metrics from a specific table.
     *
     * @param array<Query> $queries
     * @param string $type
     * @param int|null $max Optional upper bound (inclusive) for the count
     * @return int
     * @throws Exception
     */
    private function countFromTable(array $queries, string $type, ?int $max = null): int
    {
        $tableName = $this->getTableForType($type);
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, $type);

        $params = $parsed['params'];
        unset($params['limit'], $params['offset']);

        $whereData = $this->buildWhereClause($parsed['filters'], $params);
        $whereClause = $whereData['clause'];
        $params = $whereData['params'];

        if ($max !== null) {
            $params['max'] = $max;
            $sql = "
                SELECT COUNT(*) as total FROM (
                    SELECT 1 FROM {$fromTable}{$whereClause} LIMIT {max:UInt64}
                ) sub
                FORMAT JSON
            ";
        } else {
            $sql = "
                SELECT COUNT(*) as total FROM {$fromTable}{$whereClause}
                FORMAT JSON
            ";
        }

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
     * Events-only by default — summing gauges is semantically meaningless.
     *
     * @param array<Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string $type 'event' or 'gauge'
     * @return int
     * @throws Exception
     */
    public function sum(array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        $this->setOperationContext('sum()');

        if ($type === Usage::TYPE_EVENT && $attribute === 'value') {
            return $this->routedSum($queries, 'sum');
        }

        return $this->sumFromTable($queries, $attribute, $type);
    }

    /**
     * Routed event flat-sum: pick the cheapest source (closed-day MV, hybrid
     * MV+raw, or raw) for a `SELECT sum(value)` over the events table and
     * record the decision in the route log under `$operation`.
     *
     * @param array<Query> $queries
     */
    private function routedSum(array $queries, string $operation): int
    {
        $plan = $this->extractRoutingPlan($queries);
        $route = $this->selectAggregateSource($plan);
        $this->recordRoute($operation, $plan, $route);

        if ($route === 'daily') {
            return $this->sumFromDaily($queries);
        }
        if ($route === 'hybrid') {
            return $this->sumHybridDailyAndRaw($queries, $plan);
        }

        return $this->sumFromTable($queries, 'value', Usage::TYPE_EVENT);
    }

    /**
     * Snapshot of the parsed query shape relevant for routing.
     *
     * @param array<Query> $queries
     * @return array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string}
     */
    private function extractRoutingPlan(array $queries): array
    {
        $metric = null;
        $start = null;
        $end = null;
        $filterColumns = [];
        $dimensions = [];
        $interval = null;

        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            if ($method === UsageQuery::TYPE_GROUP_BY) {
                if (!in_array($attribute, $dimensions, true)) {
                    $dimensions[] = $attribute;
                }
                continue;
            }
            if ($method === UsageQuery::TYPE_GROUP_BY_INTERVAL) {
                $intervalValue = $values[0] ?? null;
                $interval = is_string($intervalValue) ? $intervalValue : null;
                continue;
            }
            if (in_array($method, [Query::TYPE_LIMIT, Query::TYPE_OFFSET, Query::TYPE_ORDER_ASC, Query::TYPE_ORDER_DESC, Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE], true)) {
                continue;
            }

            if ($attribute === '' || $attribute === 'id') {
                continue;
            }

            if (!in_array($attribute, $filterColumns, true)) {
                $filterColumns[] = $attribute;
            }

            if ($attribute === 'metric' && $method === Query::TYPE_EQUAL) {
                $first = $values[0] ?? null;
                if (is_string($first) && count($values) === 1) {
                    $metric = $first;
                }
            }

            if ($attribute === 'time') {
                if ($method === Query::TYPE_GREATER_EQUAL || $method === Query::TYPE_GREATER) {
                    $start = $this->stringifyTime($values[0] ?? null);
                } elseif ($method === Query::TYPE_LESSER_EQUAL || $method === Query::TYPE_LESSER) {
                    $end = $this->stringifyTime($values[0] ?? null);
                } elseif ($method === Query::TYPE_BETWEEN) {
                    $start = $this->stringifyTime($values[0] ?? null);
                    $end = $this->stringifyTime($values[1] ?? null);
                }
            }
        }

        return [
            'metric' => $metric,
            'start' => $start,
            'end' => $end,
            'filterColumns' => $filterColumns,
            'dimensions' => $dimensions,
            'interval' => $interval,
        ];
    }

    /**
     * Pure routing decision: which physical table satisfies this read.
     *
     * Returns one of:
     *   - 'raw'    — scan the raw events / gauges table.
     *   - 'daily'  — read the existing events daily MV (no grouping, only
     *                daily-MV-compatible filters, window in closed days).
     *   - 'hybrid' — closed days from events daily MV, today's partial from raw.
     *   - 'daily_by_path' / 'daily_by_country' / 'daily_by_service' /
     *     'daily_by_method_status' — read an events per-dim rollup.
     *   - 'hybrid_by_<dim>' — events multi-dim MV closed days + raw today's
     *     partial.
     *   - 'gauges_daily_by_service' / 'gauges_daily_by_resource' — read a
     *     gauges per-dim AMT rollup.
     *   - 'gauges_hybrid_by_<dim>' — gauges multi-dim AMT MV closed days +
     *     raw today's partial.
     *
     * Any caller-requested sub-day interval forces 'raw' because the
     * rollups are day-grained.
     *
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string} $plan
     */
    private function selectAggregateSource(array $plan, string $type = Usage::TYPE_EVENT): string
    {
        if ($plan['interval'] !== null) {
            return 'raw';
        }

        if ($plan['end'] === null) {
            return 'raw';
        }

        try {
            $endDt = new \DateTime($plan['end']);
            $boundaryDt = new \DateTime('today', new \DateTimeZone('UTC'));
            $startDt = $plan['start'] !== null ? new \DateTime($plan['start']) : null;
        } catch (\Exception $e) {
            return 'raw';
        }
        $straddlesToday = $endDt >= $boundaryDt;

        if ($startDt !== null && $startDt >= $boundaryDt) {
            return 'raw';
        }

        if ($type === Usage::TYPE_GAUGE) {
            if (empty($plan['dimensions'])) {
                return 'raw';
            }

            $dimMatch = $this->matchGaugeDimRollup($plan['dimensions']);
            if ($dimMatch === null) {
                return 'raw';
            }

            $allowedFilters = array_merge(['metric', 'time', 'tenant'], $dimMatch['dims']);
            foreach ($plan['filterColumns'] as $column) {
                if (!in_array($column, $allowedFilters, true)) {
                    return 'raw';
                }
            }

            $base = 'gauges_daily_' . $dimMatch['name'];
            return $straddlesToday ? 'gauges_hybrid_' . $dimMatch['name'] : $base;
        }

        if (empty($plan['dimensions'])) {
            foreach ($plan['filterColumns'] as $column) {
                if (!in_array($column, self::DAILY_COLUMNS, true) && $column !== 'tenant') {
                    return 'raw';
                }
            }
            return $straddlesToday ? 'hybrid' : 'daily';
        }

        $dimMatch = $this->matchDimRollup($plan['dimensions']);
        if ($dimMatch === null) {
            return 'raw';
        }

        $allowedFilters = array_merge(['metric', 'time', 'tenant'], $dimMatch['dims']);
        foreach ($plan['filterColumns'] as $column) {
            if (!in_array($column, $allowedFilters, true)) {
                return 'raw';
            }
        }

        $base = 'daily_' . $dimMatch['name'];
        return $straddlesToday ? 'hybrid_' . $dimMatch['name'] : $base;
    }

    /**
     * Find the events per-dim rollup whose dim set matches the request
     * exactly (order-insensitive). Returns null when no MV covers this shape.
     *
     * @param array<int, string> $dimensions
     * @return array{name: string, dims: array<int, string>}|null
     */
    private function matchDimRollup(array $dimensions): ?array
    {
        $needle = $dimensions;
        sort($needle);

        foreach (self::DIM_ROLLUPS as $rollup) {
            $candidate = $rollup['dims'];
            sort($candidate);
            if ($candidate === $needle) {
                return $rollup;
            }
        }
        return null;
    }

    /**
     * Find the gauge per-dim AMT rollup whose dim set matches the request
     * exactly (order-insensitive). Returns null when no MV covers this shape.
     *
     * @param array<int, string> $dimensions
     * @return array{name: string, dims: array<int, string>}|null
     */
    private function matchGaugeDimRollup(array $dimensions): ?array
    {
        $needle = $dimensions;
        sort($needle);

        foreach (self::GAUGE_DIM_ROLLUPS as $rollup) {
            $candidate = $rollup['dims'];
            sort($candidate);
            if ($candidate === $needle) {
                return $rollup;
            }
        }
        return null;
    }

    /**
     * @return array{name: string, dims: array<int, string>}|null
     */
    private function getGaugeRollupByName(string $name): ?array
    {
        foreach (self::GAUGE_DIM_ROLLUPS as $rollup) {
            if ($rollup['name'] === $name) {
                return $rollup;
            }
        }
        return null;
    }

    private function stringifyTime(mixed $value): ?string
    {
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Partition a query list into non-time filters and time filters. Used by
     * the hybrid helpers so the daily branch can substitute a day-floored
     * lower bound while the raw branch keeps the caller's original literal.
     *
     * @param array<Query> $queries
     * @return array{nonTime: array<Query>, time: array<Query>}
     */
    private function splitTimeQueries(array $queries): array
    {
        $timeMethods = [
            Query::TYPE_GREATER,
            Query::TYPE_GREATER_EQUAL,
            Query::TYPE_LESSER,
            Query::TYPE_LESSER_EQUAL,
            Query::TYPE_BETWEEN,
            Query::TYPE_NOT_BETWEEN,
        ];

        $nonTime = [];
        $time = [];
        foreach ($queries as $query) {
            if ($query->getAttribute() === 'time' && in_array($query->getMethod(), $timeMethods, true)) {
                $time[] = $query;
            } else {
                $nonTime[] = $query;
            }
        }

        return ['nonTime' => $nonTime, 'time' => $time];
    }

    /**
     * Floor a stringified timestamp to its UTC start-of-day. Returns null if
     * the input can't be parsed.
     */
    private function floorToStartOfDay(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $dt = new \DateTime($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        $dt->setTime(0, 0, 0);
        return $dt->format('Y-m-d H:i:s.v');
    }

    /**
     * Rewrite the time bounds in a query list so a purge against a
     * day-bucketed rollup matches whole-day windows. Lower bounds are
     * floored to start-of-day; upper bounds are floored to the start of the
     * next day so a half-open mid-day end still includes that day's rollup
     * row. Other queries pass through unchanged.
     *
     * @param array<Query> $queries
     * @return array<Query>
     */
    private function translateTimeQueriesToDayBoundaries(array $queries): array
    {
        $output = [];
        foreach ($queries as $query) {
            if ($query->getAttribute() !== 'time') {
                $output[] = $query;
                continue;
            }

            $method = $query->getMethod();
            $values = $query->getValues();

            if ($method === Query::TYPE_GREATER_EQUAL || $method === Query::TYPE_GREATER) {
                $floored = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                if ($floored === null) {
                    $output[] = $query;
                    continue;
                }
                $output[] = Query::greaterThanEqual('time', $floored);
                continue;
            }

            if ($method === Query::TYPE_LESSER_EQUAL || $method === Query::TYPE_LESSER) {
                $ceiled = $this->floorToStartOfNextDay($this->stringifyTime($values[0] ?? null));
                if ($ceiled === null) {
                    $output[] = $query;
                    continue;
                }
                $output[] = Query::lessThan('time', $ceiled);
                continue;
            }

            if ($method === Query::TYPE_BETWEEN) {
                $lower = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                $upper = $this->floorToStartOfNextDay($this->stringifyTime($values[1] ?? null));
                if ($lower !== null) {
                    $output[] = Query::greaterThanEqual('time', $lower);
                }
                if ($upper !== null) {
                    $output[] = Query::lessThan('time', $upper);
                }
                continue;
            }

            $output[] = $query;
        }

        return $output;
    }

    /**
     * Floor a stringified timestamp to the UTC start of the next day. Used to
     * expand an inclusive upper bound into a half-open `< floor(next_day)`
     * window for day-bucketed rollups.
     */
    private function floorToStartOfNextDay(?string $value): ?string
    {
        $floored = $this->floorToStartOfDay($value);
        if ($floored === null) {
            return null;
        }
        try {
            $dt = new \DateTime($floored, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        $dt->modify('+1 day');
        return $dt->format('Y-m-d H:i:s.v');
    }

    /**
     * Translate the caller's time-bound queries into filter fragments suited
     * to a day-bucketed rollup. Lower bounds are floored to start-of-day so
     * a mid-day start still picks up that day's rollup row; upper bounds
     * pass through unchanged.
     *
     * @param array<int, string> $existing Pre-built non-time filter fragments.
     * @param array<int, Query> $timeQueries
     * @param array<string, mixed> $params Mutated with the new bind values.
     * @return array<int, string>
     */
    private function buildDailyTimeFilters(array $existing, array $timeQueries, array &$params): array
    {
        $filters = $existing;
        $counter = 0;
        foreach ($timeQueries as $query) {
            $method = $query->getMethod();
            $values = $query->getValues();

            if ($method === Query::TYPE_GREATER_EQUAL || $method === Query::TYPE_GREATER) {
                $floored = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                if ($floored === null) {
                    continue;
                }
                $name = 'daily_time_lower_' . $counter++;
                $params[$name] = $floored;
                $filters[] = '`time` >= {' . $name . ':DateTime64(3, \'UTC\')}';
                continue;
            }

            if ($method === Query::TYPE_LESSER_EQUAL || $method === Query::TYPE_LESSER) {
                $upper = $this->stringifyTime($values[0] ?? null);
                if ($upper === null) {
                    continue;
                }
                $name = 'daily_time_upper_' . $counter++;
                $params[$name] = $upper;
                $op = $method === Query::TYPE_LESSER ? '<' : '<=';
                $filters[] = '`time` ' . $op . ' {' . $name . ':DateTime64(3, \'UTC\')}';
                continue;
            }

            if ($method === Query::TYPE_BETWEEN) {
                $lower = $this->floorToStartOfDay($this->stringifyTime($values[0] ?? null));
                $upper = $this->stringifyTime($values[1] ?? null);
                if ($lower !== null) {
                    $name = 'daily_time_lower_' . $counter++;
                    $params[$name] = $lower;
                    $filters[] = '`time` >= {' . $name . ':DateTime64(3, \'UTC\')}';
                }
                if ($upper !== null) {
                    $name = 'daily_time_upper_' . $counter++;
                    $params[$name] = $upper;
                    $filters[] = '`time` <= {' . $name . ':DateTime64(3, \'UTC\')}';
                }
                continue;
            }
        }

        return $filters;
    }

    /**
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string} $plan
     */
    private function recordRoute(string $operation, array $plan, string $route): void
    {
        $this->routeLog[] = [
            'operation' => $operation,
            'metric' => $plan['metric'],
            'route_decision' => $route,
            'start' => $plan['start'],
            'end' => $plan['end'],
            'dimensions' => $plan['dimensions'],
            'interval' => $plan['interval'],
        ];
    }

    /**
     * sumDaily() with the caller's queries forwarded as-is. Used by the
     * 'daily' branch of selectAggregateSource(); the daily MV's
     * SummingMergeTree engine re-aggregates over the dim columns so a flat
     * `sum(value) WHERE metric=… AND time BETWEEN …` returns the same total
     * as scanning raw events.
     *
     * @param array<Query> $queries
     */
    private function sumFromDaily(array $queries): int
    {
        return $this->sumDaily($queries, 'value');
    }

    /**
     * @return array{name: string, dims: array<int, string>}|null
     */
    private function getRollupByName(string $name): ?array
    {
        foreach (self::DIM_ROLLUPS as $rollup) {
            if ($rollup['name'] === $name) {
                return $rollup;
            }
        }
        return null;
    }

    /**
     * Day-grained aggregated read from a per-dim rollup table. Same return
     * shape as findAggregatedFromTable() but without GROUP BY interval —
     * the rollup is already day-bucketed.
     *
     * @param array{name: string, dims: array<int, string>} $rollup
     * @param array<Query> $queries
     * @return array<Metric>
     */
    private function findFromDailyByDim(array $rollup, array $queries): array
    {
        $tableName = $this->getDimRollupTableName($rollup['name']);
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $dimSelect = '';
        $dimGroup = '';
        if (!empty($rollup['dims'])) {
            $escapedDims = array_map(fn (string $d): string => $this->escapeIdentifier($d), $rollup['dims']);
            $dimSelect = ', ' . implode(', ', $escapedDims);
            $dimGroup = ', ' . implode(', ', $escapedDims);
        }

        $orderClause = ' ORDER BY value DESC';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, sum(value) AS value{$dimSelect}
            FROM {$fromTable}{$whereData['clause']}
            GROUP BY metric{$dimGroup}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $whereData['params']);
        return $this->parseAggregatedResults($result, Usage::TYPE_EVENT);
    }

    /**
     * UNION ALL of the per-dim rollup (closed days) + raw events (today's
     * partial), summed back together by the outer GROUP BY.
     *
     * @param array{name: string, dims: array<int, string>} $rollup
     * @param array<Query> $queries
     * @return array<Metric>
     */
    private function findHybridFromDailyByDim(array $rollup, array $queries): array
    {
        $startOfToday = (new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $dailyName = $this->getDimRollupTableName($rollup['name']);
        $dailyTable = $this->buildTableReference($dailyName);
        $eventsTable = $this->buildTableReference($this->getEventsTableName());

        $split = $this->splitTimeQueries($queries);
        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $dailyParsed = $this->parseQueries($split['nonTime'], Usage::TYPE_EVENT);

        $params = array_merge($parsed['params'], $dailyParsed['params']);

        $rawFilters = $parsed['filters'];
        $dailyFilters = $this->buildDailyTimeFilters($dailyParsed['filters'], $split['time'], $params);

        $params['hybrid_boundary'] = $startOfToday;
        $dailyFilters[] = '`time` < {hybrid_boundary:DateTime64(3, \'UTC\')}';
        $rawFilters[] = '`time` >= {hybrid_boundary:DateTime64(3, \'UTC\')}';

        $dailyWhere = $this->buildWhereClause($dailyFilters, $params);
        $rawWhere = $this->buildWhereClause($rawFilters, $dailyWhere['params']);

        $dimSelect = '';
        $dimGroup = '';
        $dimCols = '';
        if (!empty($rollup['dims'])) {
            $escapedDims = array_map(fn (string $d): string => $this->escapeIdentifier($d), $rollup['dims']);
            $dimList = implode(', ', $escapedDims);
            $dimSelect = ', ' . $dimList;
            $dimGroup = ', ' . $dimList;
            $dimCols = ', ' . $dimList;
        }

        $orderClause = ' ORDER BY value DESC';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, sum(value) AS value{$dimSelect}
            FROM (
                SELECT metric{$dimCols}, sum(value) AS value
                FROM {$dailyTable}{$dailyWhere['clause']}
                GROUP BY metric{$dimGroup}
                UNION ALL
                SELECT metric{$dimCols}, sum(value) AS value
                FROM {$eventsTable}{$rawWhere['clause']}
                GROUP BY metric{$dimGroup}
            )
            GROUP BY metric{$dimGroup}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $rawWhere['params']);
        return $this->parseAggregatedResults($result, Usage::TYPE_EVENT);
    }

    /**
     * Day-grained aggregated read from a per-dim gauge AMT rollup table.
     * Same return shape as findFromDailyByDim but emits argMaxMerge(value)
     * instead of sum(value) — gauges store argMaxState partials.
     *
     * @param array{name: string, dims: array<int, string>} $rollup
     * @param array<Query> $queries
     * @return array<Metric>
     */
    private function findFromGaugeDailyByDim(array $rollup, array $queries): array
    {
        $tableName = $this->getGaugeDimRollupTableName($rollup['name']);
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, Usage::TYPE_GAUGE);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $dimSelect = '';
        $dimGroup = '';
        if (!empty($rollup['dims'])) {
            $escapedDims = array_map(fn (string $d): string => $this->escapeIdentifier($d), $rollup['dims']);
            $dimSelect = ', ' . implode(', ', $escapedDims);
            $dimGroup = ', ' . implode(', ', $escapedDims);
        }

        $orderClause = ' ORDER BY value DESC';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, argMaxMerge(value) AS value{$dimSelect}
            FROM {$fromTable}{$whereData['clause']}
            GROUP BY metric{$dimGroup}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $whereData['params']);
        return $this->parseAggregatedResults($result, Usage::TYPE_GAUGE);
    }

    /**
     * Hybrid gauge AMT read: closed-day rollup partials (argMaxState) UNION
     * raw gauges (argMaxState(value, time) over today's partial), with an
     * outer argMaxMerge that combines both sides. Same return shape as
     * findHybridFromDailyByDim but argMax-based.
     *
     * @param array{name: string, dims: array<int, string>} $rollup
     * @param array<Query> $queries
     * @return array<Metric>
     */
    private function findHybridFromGaugeDailyByDim(array $rollup, array $queries): array
    {
        $startOfToday = (new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $dailyName = $this->getGaugeDimRollupTableName($rollup['name']);
        $dailyTable = $this->buildTableReference($dailyName);
        $gaugesTable = $this->buildTableReference($this->getGaugesTableName());

        $split = $this->splitTimeQueries($queries);
        $parsed = $this->parseQueries($queries, Usage::TYPE_GAUGE);
        $dailyParsed = $this->parseQueries($split['nonTime'], Usage::TYPE_GAUGE);

        $params = array_merge($parsed['params'], $dailyParsed['params']);

        $rawFilters = $parsed['filters'];
        $dailyFilters = $this->buildDailyTimeFilters($dailyParsed['filters'], $split['time'], $params);

        $params['hybrid_boundary'] = $startOfToday;
        $dailyFilters[] = '`time` < {hybrid_boundary:DateTime64(3, \'UTC\')}';
        $rawFilters[] = '`time` >= {hybrid_boundary:DateTime64(3, \'UTC\')}';

        $dailyWhere = $this->buildWhereClause($dailyFilters, $params);
        $rawWhere = $this->buildWhereClause($rawFilters, $dailyWhere['params']);

        $dimSelect = '';
        $dimGroup = '';
        $dimCols = '';
        if (!empty($rollup['dims'])) {
            $escapedDims = array_map(fn (string $d): string => $this->escapeIdentifier($d), $rollup['dims']);
            $dimList = implode(', ', $escapedDims);
            $dimSelect = ', ' . $dimList;
            $dimGroup = ', ' . $dimList;
            $dimCols = ', ' . $dimList;
        }

        $orderClause = ' ORDER BY value DESC';
        if (!empty($parsed['orderBy'])) {
            $orderClause = ' ORDER BY ' . implode(', ', $parsed['orderBy']);
        }
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "
            SELECT metric, argMaxMerge(value) AS value{$dimSelect}
            FROM (
                SELECT metric{$dimCols}, value
                FROM {$dailyTable}{$dailyWhere['clause']}
                UNION ALL
                SELECT metric{$dimCols}, argMaxState(value, time) AS value
                FROM {$gaugesTable}{$rawWhere['clause']}
                GROUP BY metric{$dimGroup}
            )
            GROUP BY metric{$dimGroup}{$orderClause}{$limitClause}{$offsetClause}
            FORMAT JSON
        ";

        $result = $this->query($sql, $rawWhere['params']);
        return $this->parseAggregatedResults($result, Usage::TYPE_GAUGE);
    }

    /**
     * Dual-read sampler: with probability `$dualReadSampleRate`, re-run
     * the same logical query against raw events and log a warning if the
     * totals diverge by more than 1%.
     *
     * @param array<Query> $queries
     * @param string $type
     * @param string $route
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string} $plan
     * @param array<Metric> $rolledResult
     */
    private function maybeDualRead(array $queries, string $type, string $route, array $plan, array $rolledResult): void
    {
        if ($this->dualReadSampleRate <= 0.0) {
            return;
        }
        if (mt_rand() / mt_getrandmax() > $this->dualReadSampleRate) {
            return;
        }

        try {
            $rawResult = $this->findFromTable($queries, $type);
        } catch (\Throwable $e) {
            return;
        }

        $rolledTotal = $this->sumMetricValues($rolledResult);
        $rawTotal = $this->sumMetricValues($rawResult);
        if ($rawTotal === 0 && $rolledTotal === 0) {
            return;
        }

        $denominator = $rawTotal === 0 ? max(abs($rolledTotal), 1) : abs($rawTotal);
        $delta = abs($rolledTotal - $rawTotal) / $denominator;
        if ($delta > 0.01) {
            $this->routeLog[] = [
                'operation' => 'dual_read_warning',
                'metric' => $plan['metric'],
                'route_decision' => $route . ':delta=' . round($delta, 4),
                'start' => $plan['start'],
                'end' => $plan['end'],
                'dimensions' => $plan['dimensions'],
                'interval' => $plan['interval'],
            ];
        }
    }

    /**
     * @param array<Metric> $metrics
     */
    private function sumMetricValues(array $metrics): int
    {
        $sum = 0;
        foreach ($metrics as $metric) {
            $value = $metric->getValue(0);
            if (is_int($value)) {
                $sum += $value;
            } elseif (is_float($value)) {
                $sum += (int) $value;
            }
        }
        return $sum;
    }

    /**
     * Hybrid daily + raw read: closed days from the daily MV, today's
     * partial from the raw events table, combined via outer SUM over
     * UNION ALL.
     *
     * @param array<Query> $queries
     * @param array{metric: ?string, start: ?string, end: ?string, filterColumns: array<int, string>, dimensions: array<int, string>, interval: ?string} $plan
     */
    private function sumHybridDailyAndRaw(array $queries, array $plan): int
    {
        $startOfToday = (new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $dailyTable = $this->buildTableReference($this->getEventsDailyTableName());
        $eventsTable = $this->buildTableReference($this->getEventsTableName());

        $split = $this->splitTimeQueries($queries);
        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $dailyParsed = $this->parseQueries($split['nonTime'], Usage::TYPE_EVENT);

        $params = array_merge($parsed['params'], $dailyParsed['params']);

        $rawFilters = $parsed['filters'];
        $dailyFilters = $this->buildDailyTimeFilters($dailyParsed['filters'], $split['time'], $params);

        $params['hybrid_boundary'] = $startOfToday;
        $dailyFilters[] = '`time` < {hybrid_boundary:DateTime64(3, \'UTC\')}';
        $rawFilters[] = '`time` >= {hybrid_boundary:DateTime64(3, \'UTC\')}';

        $dailyWhere = $this->buildWhereClause($dailyFilters, $params);
        $rawWhere = $this->buildWhereClause($rawFilters, $dailyWhere['params']);

        $sql = "
            SELECT sum(total) AS total FROM (
                SELECT sum(value) AS total FROM {$dailyTable}{$dailyWhere['clause']}
                UNION ALL
                SELECT sum(value) AS total FROM {$eventsTable}{$rawWhere['clause']}
            )
            FORMAT JSON
        ";

        $result = $this->query($sql, $rawWhere['params']);
        $json = json_decode($result, true);

        if (!is_array($json) || !isset($json['data'][0]['total'])) {
            return 0;
        }

        return (int) $json['data'][0]['total'];
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

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }
        $parsed = $this->parseQueries($queries, Usage::TYPE_EVENT);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $groupByColumns = $this->sharedTables ? ['tenant'] : [];
        $groupByColumns[] = 'metric';
        $groupByColumns[] = 'time';
        foreach (['resource', 'resourceId', 'resourceInternalId', 'teamId', 'teamInternalId'] as $dim) {
            $groupByColumns[] = $dim;
        }

        $selectExpressions = [];
        foreach ($groupByColumns as $column) {
            $selectExpressions[] = $this->escapeIdentifier($column);
        }
        $selectExpressions[] = 'sum(`value`) AS `value`';

        $selectColumns = implode(', ', $selectExpressions);
        $groupBySql = implode(', ', array_map(fn ($c) => $this->escapeIdentifier($c), $groupByColumns));

        $orderClause = !empty($parsed['orderBy']) ? ' ORDER BY ' . implode(', ', $parsed['orderBy']) : '';
        $limitClause = isset($parsed['limit']) ? ' LIMIT {limit:UInt64}' : '';
        $offsetClause = isset($parsed['offset']) ? ' OFFSET {offset:UInt64}' : '';

        $sql = "SELECT {$selectColumns} FROM {$fromTable}{$whereData['clause']} GROUP BY {$groupBySql}{$orderClause}{$limitClause}{$offsetClause} FORMAT JSON";

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
        $this->validateDailyAttributeName($attribute);
        $escapedAttribute = $this->escapeIdentifier($attribute);

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }
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

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                $this->validateDailyAttributeName($attr);
            }
        }

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
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
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
            // Skip a table when its schema can't satisfy every filter attribute
            // (e.g. `path` on a gauge query); avoids "Invalid attribute name"
            // when the caller leaves $type null and only one side is applicable.
            if (!$this->queriesMatchType($queries, $queryType)) {
                continue;
            }

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
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
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
                {$timeFunction}(time, 'UTC') as bucket,
                {$valueExpr}
            FROM {$fromTable}
            WHERE metric IN ({$metricInClause})
                AND time BETWEEN {start_date:DateTime64(3, 'UTC')} AND {end_date:DateTime64(3, 'UTC')}
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
                $bucketTime = (string) ($row['bucket'] ?? '');
                $value = (float) ($row['agg_value'] ?? 0);

                if (!isset($output[$metricName])) {
                    continue;
                }

                // Format bucket time
                $formattedDate = $bucketTime;
                if (strpos($bucketTime, 'T') === false) {
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
     * @param array<array{value: float, date: string}> $data Existing data points
     * @param string $interval '1h' or '1d'
     * @param string $startDate Start datetime
     * @param string $endDate End datetime
     * @return array<array{value: float, date: string}>
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

        if ($eventTotal > 0 && $gaugeTotal > 0) {
            throw new Exception(
                "Metric '{$metric}' exists in both event and gauge tables. "
                . "Specify \$type explicitly to avoid ambiguous aggregation."
            );
        }

        return $eventTotal > 0 ? $eventTotal : $gaugeTotal;
    }

    /**
     * Get total from events table (SUM). Routes through routedSum() so
     * closed-day windows hit the daily MV.
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromEvents(string $metric, array $queries): int
    {
        $queries[] = Query::equal('metric', [$metric]);
        return $this->routedSum($queries, 'getTotal');
    }

    /**
     * Get total from gauges table (argMax). Records a route decision so
     * ops dashboards see gauge getTotal calls alongside the rest.
     *
     * @param string $metric
     * @param array<Query> $queries
     * @return int
     * @throws Exception
     */
    private function getTotalFromGauges(string $metric, array $queries): int
    {
        $queries[] = Query::equal('metric', [$metric]);

        $plan = $this->extractRoutingPlan($queries);
        $this->recordRoute('getTotal', $plan, 'raw');

        $tableName = $this->getGaugesTableName();
        $fromTable = $this->buildTableReference($tableName);

        $parsed = $this->parseQueries($queries, Usage::TYPE_GAUGE);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);

        $sql = "
            SELECT argMax(value, time) as total
            FROM {$fromTable}{$whereData['clause']}
            FORMAT JSON
        ";

        $result = $this->query($sql, $whereData['params']);
        $json = json_decode($result, true);

        if (!is_array($json) || !isset($json['data'][0]['total'])) {
            return 0;
        }

        return (int) $json['data'][0]['total'];
    }

    /**
     * Get totals for multiple metrics in a single query.
     *
     * When $type is null both tables are queried with their type-appropriate
     * aggregator (SUM for events, argMax for gauges). If a metric appears in
     * both tables the result of mixing those aggregators is meaningless, so
     * the second occurrence raises an exception — callers must specify $type
     * to disambiguate.
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

        // Track which type contributed a non-zero value to detect ambiguous mixing.
        $contributingType = [];

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

                    $rowValue = (int) ($row['agg_val'] ?? 0);
                    if ($rowValue === 0) {
                        continue;
                    }

                    if ($type === null
                        && isset($contributingType[$metricName])
                        && $contributingType[$metricName] !== $queryType) {
                        throw new Exception(
                            "Metric '{$metricName}' exists in both event and gauge tables. "
                            . "Specify \$type explicitly to avoid ambiguous aggregation."
                        );
                    }

                    $contributingType[$metricName] = $queryType;
                    $totals[$metricName] = $rowValue;
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
     * Resolve the ClickHouse parameter type for a column.
     *
     * Used by both filter binding and cursor keyset comparison so values are
     * bound with the column's actual SQL type — binding a numeric column as
     * `String` would compare values lexicographically (`"9" > "10"`) and
     * silently produce incorrect filter results or page boundaries. Add a
     * branch here when introducing a new typed column.
     *
     * @param string $attribute
     * @return string ClickHouse parameter type (e.g. 'String', 'DateTime64(3, \'UTC\')', 'Int64')
     */
    private function getParamType(string $attribute): string
    {
        return match ($attribute) {
            'time' => "DateTime64(3, 'UTC')",
            'value' => 'Int64',
            default => 'String',
        };
    }

    /**
     * Format a value for the given ClickHouse parameter type.
     *
     * Routes DateTime-typed columns through formatDateTime() and everything
     * else through formatParamValue(). Centralising this dispatch keeps
     * parseQueries and buildCursorWhere consistent across libraries.
     *
     * @param string $chType ClickHouse parameter type as returned by getParamType()
     * @param mixed $value
     * @return string
     * @throws Exception
     */
    private function formatTypedValue(string $chType, mixed $value): string
    {
        if ($chType === "DateTime64(3, 'UTC')") {
            if ($value === null) {
                throw new Exception('DateTime parameter value cannot be null');
            }
            /** @var \DateTime|string $value */
            return $this->formatDateTime($value);
        }

        return $this->formatParamValue($value);
    }

    /**
     * Normalize a user-supplied cursor row into a column-keyed array.
     *
     * Accepts a `Metric` (or any `ArrayObject`) or a plain associative array.
     * `Metric` stores its identifier under `$id` (Appwrite convention) while
     * the underlying column is `id` — this remaps `$id` → `id` so cursor
     * pagination can match the SQL column.
     *
     * @param mixed $rawCursor
     * @return array<string, mixed>
     * @throws Exception
     */
    private function normalizeCursorRow(mixed $rawCursor): array
    {
        if ($rawCursor instanceof \ArrayObject) {
            /** @var array<string, mixed> $row */
            $row = $rawCursor->getArrayCopy();
        } elseif (is_array($rawCursor)) {
            /** @var array<string, mixed> $rawCursor */
            $row = $rawCursor;
        } else {
            throw new Exception(
                'Invalid cursor value: expected ArrayObject (Metric) or associative array, got '
                . get_debug_type($rawCursor)
            );
        }

        if (!array_key_exists('id', $row) && array_key_exists('$id', $row)) {
            $row['id'] = $row['$id'];
            unset($row['$id']);
        }

        return $row;
    }

    /**
     * Resolve the effective order attributes for cursor pagination.
     *
     * Auto-appends `id` as a tiebreaker when not already present so keyset
     * pagination is deterministic on non-unique columns (e.g. time).
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @return array<int, array{attribute: string, direction: string}>
     */
    private function resolveCursorOrder(array $orderAttributes): array
    {
        foreach ($orderAttributes as $entry) {
            if ($entry['attribute'] === 'id') {
                return $orderAttributes;
            }
        }

        $defaultDirection = 'ASC';
        if (!empty($orderAttributes)) {
            $last = $orderAttributes[count($orderAttributes) - 1];
            $defaultDirection = $last['direction'];
        }

        $orderAttributes[] = ['attribute' => 'id', 'direction' => $defaultDirection];

        return $orderAttributes;
    }

    /**
     * Build keyset-pagination WHERE fragments for cursor support.
     *
     * Produces a tuple-compare clause across the order attributes:
     *   (a > A) OR (a = A AND b > B) OR ...
     *
     * For cursor `before`, the comparison directions are flipped relative to
     * the requested ORDER BY (the caller is responsible for also flipping the
     * actual ORDER BY at SQL build time so the page comes back from the right
     * side, then reversing the rows post-fetch).
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @param array<string, mixed> $cursor
     * @param string $cursorDirection 'after' or 'before'
     * @param array<string, mixed> $params Existing params (mutated by adding cursor binds)
     * @return array{clause: string, params: array<string, mixed>}
     * @throws Exception
     */
    private function buildCursorWhere(array $orderAttributes, array $cursor, string $cursorDirection, array $params): array
    {
        $orderAttributes = $this->resolveCursorOrder($orderAttributes);

        $tuples = [];
        foreach ($orderAttributes as $i => $entry) {
            $attr = $entry['attribute'];
            $direction = $entry['direction'];

            if (!array_key_exists($attr, $cursor)) {
                throw new \Exception("Cursor is missing required attribute '{$attr}'");
            }

            // Flip comparison direction for `before` so we paginate to the previous page.
            if ($cursorDirection === 'before') {
                $direction = $direction === 'DESC' ? 'ASC' : 'DESC';
            }

            $conditions = [];

            for ($j = 0; $j < $i; $j++) {
                $prev = $orderAttributes[$j];
                $prevAttr = $prev['attribute'];
                if (!array_key_exists($prevAttr, $cursor)) {
                    throw new Exception("Cursor is missing required attribute '{$prevAttr}'");
                }
                $prevValue = $cursor[$prevAttr];
                if ($prevValue === null) {
                    throw new Exception("Cursor value for '{$prevAttr}' cannot be null");
                }
                $prevEscaped = $this->escapeIdentifier($prevAttr);
                $prevType = $this->getParamType($prevAttr);
                $paramName = "cursor_eq_{$i}_{$j}";

                $conditions[] = "{$prevEscaped} = {{$paramName}:{$prevType}}";
                $params[$paramName] = $this->formatTypedValue($prevType, $prevValue);
            }

            $value = $cursor[$attr];
            if ($value === null) {
                throw new Exception("Cursor value for '{$attr}' cannot be null");
            }
            $escaped = $this->escapeIdentifier($attr);
            $chType = $this->getParamType($attr);
            $operator = $direction === 'DESC' ? '<' : '>';
            $paramName = "cursor_cmp_{$i}";

            $conditions[] = "{$escaped} {$operator} {{$paramName}:{$chType}}";
            $params[$paramName] = $this->formatTypedValue($chType, $value);

            $tuples[] = '(' . implode(' AND ', $conditions) . ')';
        }

        return [
            'clause' => '(' . implode(' OR ', $tuples) . ')',
            'params' => $params,
        ];
    }

    /**
     * Build the ORDER BY SQL fragment list, optionally flipping all directions.
     *
     * Used when cursor direction is `before` — we run the query in reverse to
     * grab the previous-page rows, then `array_reverse` the result.
     *
     * @param array<int, array{attribute: string, direction: string}> $orderAttributes
     * @param bool $flip Whether to flip ASC↔DESC
     * @return array<string>
     */
    private function buildOrderBySql(array $orderAttributes, bool $flip = false): array
    {
        $sql = [];
        foreach ($orderAttributes as $entry) {
            $direction = $entry['direction'];
            if ($flip) {
                $direction = $direction === 'DESC' ? 'ASC' : 'DESC';
            }
            $sql[] = $this->escapeIdentifier($entry['attribute']) . ' ' . $direction;
        }
        return $sql;
    }

    /**
     * Parse Query objects into SQL clauses.
     *
     * @param array<Query> $queries
     * @param string $type 'event' or 'gauge' — used for attribute validation
     * @return array{filters: array<string>, params: array<string, mixed>, orderBy?: array<string>, orderAttributes?: array<int, array{attribute: string, direction: string}>, limit?: int, offset?: int, groupByInterval?: string, groupBy?: array<int, string>, cursor?: array<string, mixed>, cursorDirection?: string}
     * @throws Exception
     */
    private function parseQueries(array $queries, string $type = 'event'): array
    {
        $filters = [];
        $params = [];
        $orderBy = [];
        $orderAttributes = [];
        $limit = null;
        $offset = null;
        $groupByInterval = null;
        $groupBy = [];
        $cursor = null;
        $cursorDirection = null;
        $paramCounter = 0;

        foreach ($queries as $query) {
            $method = $query->getMethod();
            $attribute = $query->getAttribute();
            $values = $query->getValues();

            // Reject empty values for filter methods that take values — mirrors
            // the validator in utopia-php/database (Validator/Query/Filter.php)
            // and prevents silently dropping the WHERE fragment, which would
            // otherwise turn `Query::contains('attr', [])` into a full-table
            // match instead of an empty result.
            if (\in_array($method, self::VALUE_REQUIRED_METHODS, true) && empty($values)) {
                throw new \Exception(\ucfirst($method) . ' queries require at least one value.');
            }

            switch ($method) {
                case Query::TYPE_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);

                    if (count($values) > 1) {
                        $inParams = [];
                        foreach ($values as $value) {
                            $paramName = 'param_' . $paramCounter++;
                            $inParams[] = "{{$paramName}:{$chType}}";
                            $params[$paramName] = $this->formatTypedValue($chType, $value);
                        }
                        $filters[] = "{$escapedAttr} IN (" . implode(', ', $inParams) . ")";
                    } else {
                        $paramName = 'param_' . $paramCounter++;
                        $filters[] = "{$escapedAttr} = {{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    }
                    break;

                case Query::TYPE_NOT_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} != {{$paramName}:{$chType}}";
                    $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    break;

                case Query::TYPE_LESSER:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} < {{$paramName}:{$chType}}";
                    $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    break;

                case Query::TYPE_GREATER:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} > {{$paramName}:{$chType}}";
                    $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    break;

                case Query::TYPE_BETWEEN:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName1 = 'param_' . $paramCounter++;
                    $paramName2 = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} BETWEEN {{$paramName1}:{$chType}} AND {{$paramName2}:{$chType}}";
                    $params[$paramName1] = $this->formatTypedValue($chType, $values[0] ?? null);
                    $params[$paramName2] = $this->formatTypedValue($chType, $values[1] ?? null);
                    break;

                case Query::TYPE_NOT_BETWEEN:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName1 = 'param_' . $paramCounter++;
                    $paramName2 = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} NOT BETWEEN {{$paramName1}:{$chType}} AND {{$paramName2}:{$chType}}";
                    $params[$paramName1] = $this->formatTypedValue($chType, $values[0] ?? null);
                    $params[$paramName2] = $this->formatTypedValue($chType, $values[1] ?? null);
                    break;

                case Query::TYPE_ORDER_DESC:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} DESC";
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'DESC'];
                    break;

                case Query::TYPE_ORDER_ASC:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $orderBy[] = "{$escapedAttr} ASC";
                    $orderAttributes[] = ['attribute' => $attribute, 'direction' => 'ASC'];
                    break;

                case Query::TYPE_CURSOR_AFTER:
                case Query::TYPE_CURSOR_BEFORE:
                    if ($cursor !== null) {
                        // Keep the first cursor encountered (matches base groupByType semantics)
                        break;
                    }
                    $rawCursor = $values[0] ?? null;
                    if ($rawCursor === null) {
                        break; // no-op cursor
                    }
                    $cursor = $this->normalizeCursorRow($rawCursor);
                    $cursorDirection = $method === Query::TYPE_CURSOR_AFTER ? 'after' : 'before';
                    break;

                case Query::TYPE_LESSER_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} <= {{$paramName}:{$chType}}";
                    $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    break;

                case Query::TYPE_GREATER_EQUAL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "{$escapedAttr} >= {{$paramName}:{$chType}}";
                    $params[$paramName] = $this->formatTypedValue($chType, $values[0] ?? null);
                    break;

                case Query::TYPE_CONTAINS:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $inParams = [];
                    foreach ($values as $value) {
                        $paramName = 'param_' . $paramCounter++;
                        $inParams[] = "{{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatTypedValue($chType, $value);
                    }
                    if (!empty($inParams)) {
                        $filters[] = "{$escapedAttr} IN (" . implode(', ', $inParams) . ")";
                    }
                    break;

                case Query::TYPE_NOT_CONTAINS:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $chType = $this->getParamType($attribute);
                    $inParams = [];
                    foreach ($values as $value) {
                        $paramName = 'param_' . $paramCounter++;
                        $inParams[] = "{{$paramName}:{$chType}}";
                        $params[$paramName] = $this->formatTypedValue($chType, $value);
                    }
                    if (!empty($inParams)) {
                        $filters[] = "{$escapedAttr} NOT IN (" . implode(', ', $inParams) . ")";
                    }
                    break;

                case Query::TYPE_IS_NULL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $filters[] = "{$escapedAttr} IS NULL";
                    break;

                case Query::TYPE_IS_NOT_NULL:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $filters[] = "{$escapedAttr} IS NOT NULL";
                    break;

                case Query::TYPE_STARTS_WITH:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $needle = $values[0] ?? null;
                    if (!is_string($needle)) {
                        throw new Exception("startsWith needle must be a string for attribute '{$attribute}'");
                    }
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "startsWith({$escapedAttr}, {{$paramName}:String})";
                    $params[$paramName] = $needle;
                    break;

                case Query::TYPE_ENDS_WITH:
                    $this->validateAttributeName($attribute, $type);
                    $escapedAttr = $this->escapeIdentifier($attribute);
                    $needle = $values[0] ?? null;
                    if (!is_string($needle)) {
                        throw new Exception("endsWith needle must be a string for attribute '{$attribute}'");
                    }
                    $paramName = 'param_' . $paramCounter++;
                    $filters[] = "endsWith({$escapedAttr}, {{$paramName}:String})";
                    $params[$paramName] = $needle;
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
                    if (!is_string($interval)) {
                        throw new \Exception(
                            'Invalid groupByInterval interval: expected string, got ' . get_debug_type($interval) . '. Allowed: '
                            . implode(', ', array_keys(UsageQuery::VALID_INTERVALS))
                        );
                    }
                    if (!isset(UsageQuery::VALID_INTERVALS[$interval])) {
                        throw new \Exception(
                            "Invalid groupByInterval interval '{$interval}'. Allowed: "
                            . implode(', ', array_keys(UsageQuery::VALID_INTERVALS))
                        );
                    }
                    $groupByInterval = $interval;
                    break;

                case UsageQuery::TYPE_GROUP_BY:
                    $this->validateGroupByAttribute($attribute, $type);
                    if (!in_array($attribute, $groupBy, true)) {
                        $groupBy[] = $attribute;
                    }
                    break;
            }
        }

        $result = [
            'filters' => $filters,
            'params' => $params,
        ];

        if (!empty($orderBy)) {
            $result['orderBy'] = $orderBy;
            $result['orderAttributes'] = $orderAttributes;
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

        if (!empty($groupBy)) {
            $result['groupBy'] = $groupBy;
        }

        if ($cursor !== null && $cursorDirection !== null) {
            $result['cursor'] = $cursor;
            $result['cursorDirection'] = $cursorDirection;
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
     * For event purges, also deletes matching rows from the pre-aggregated
     * daily table — materialized views are forward-only triggers, so deletes
     * on the source table do not propagate to the MV target. Only daily-table
     * compatible filters (metric, value, time, tenant) are forwarded; queries
     * with event-only attributes (path/method/status/etc.) leave existing
     * daily rows in place.
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

            if ($purgeType === Usage::TYPE_EVENT) {
                $this->purgeDaily($queries);
                $this->purgeDimRollups($queries);
            }

            if ($purgeType === Usage::TYPE_GAUGE) {
                $this->purgeGaugeDimRollups($queries);
            }
        }

        return true;
    }

    /**
     * Purge matching rows from the daily aggregated table.
     *
     * Only forwarded when every query attribute is daily-compatible
     * (metric, value, time, tenant). If any query references an
     * event-only column, the daily delete is skipped — silently
     * leaving the daily rows in place is safer than throwing here
     * because callers commonly purge by path/method/etc.
     *
     * @param array<Query> $queries
     * @throws Exception
     */
    /**
     * Forward the purge to each per-dim rollup table when every filter
     * attribute fits the rollup's columns.
     *
     * @param array<Query> $queries
     * @throws Exception
     */
    private function purgeDimRollups(array $queries): void
    {
        foreach (self::DIM_ROLLUPS as $rollup) {
            $allowed = array_merge(['metric', 'value', 'time'], $rollup['dims']);
            if ($this->sharedTables) {
                $allowed[] = 'tenant';
            }

            $compatible = true;
            foreach ($queries as $query) {
                $attr = $query->getAttribute();
                if (!empty($attr) && !in_array($attr, $allowed, true)) {
                    $compatible = false;
                    break;
                }
            }
            if (!$compatible) {
                continue;
            }

            $table = $this->buildTableReference($this->getDimRollupTableName($rollup['name']));
            $rollupQueries = $this->translateTimeQueriesToDayBoundaries($queries);
            $parsed = $this->parseQueries($rollupQueries, Usage::TYPE_EVENT);
            $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
            $whereClause = $whereData['clause'];

            if (empty($whereClause)) {
                $whereClause = ' WHERE 1=1';
            }

            $sql = "DELETE FROM {$table}{$whereClause}";
            $this->query($sql, $whereData['params']);
        }
    }

    /**
     * Forward the purge to each gauge per-dim rollup table when every filter
     * attribute fits the rollup's columns.
     *
     * @param array<Query> $queries
     * @throws Exception
     */
    private function purgeGaugeDimRollups(array $queries): void
    {
        foreach (self::GAUGE_DIM_ROLLUPS as $rollup) {
            $allowed = array_merge(['metric', 'time'], $rollup['dims']);
            if ($this->sharedTables) {
                $allowed[] = 'tenant';
            }

            $compatible = true;
            foreach ($queries as $query) {
                $attr = $query->getAttribute();
                if (!empty($attr) && !in_array($attr, $allowed, true)) {
                    $compatible = false;
                    break;
                }
            }
            if (!$compatible) {
                continue;
            }

            $table = $this->buildTableReference($this->getGaugeDimRollupTableName($rollup['name']));
            $rollupQueries = $this->translateTimeQueriesToDayBoundaries($queries);
            $parsed = $this->parseQueries($rollupQueries, Usage::TYPE_GAUGE);
            $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
            $whereClause = $whereData['clause'];

            if (empty($whereClause)) {
                $whereClause = ' WHERE 1=1';
            }

            $sql = "DELETE FROM {$table}{$whereClause}";
            $this->query($sql, $whereData['params']);
        }
    }

    /**
     * @param array<Query> $queries
     * @throws Exception
     */
    private function purgeDaily(array $queries): void
    {
        $dailyQueries = [];
        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (!empty($attr)) {
                if ($attr !== 'id'
                    && !in_array($attr, self::DAILY_COLUMNS, true)
                    && !($attr === 'tenant' && $this->sharedTables)) {
                    return;
                }
            }
            $dailyQueries[] = $query;
        }

        $dailyTable = $this->buildTableReference($this->getEventsDailyTableName());

        $parsed = $this->parseQueries($dailyQueries, Usage::TYPE_EVENT);
        $whereData = $this->buildWhereClause($parsed['filters'], $parsed['params']);
        $whereClause = $whereData['clause'];

        if (empty($whereClause)) {
            $whereClause = ' WHERE 1=1';
        }

        $sql = "DELETE FROM {$dailyTable}{$whereClause}";
        $this->query($sql, $whereData['params']);
    }
}
