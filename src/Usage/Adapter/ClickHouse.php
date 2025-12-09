<?php

namespace Utopia\Usage\Adapter;

use Exception;
use Utopia\Database\Document;
use Utopia\Fetch\Client;
use Utopia\Usage\Adapter;

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

        // Set authentication headers
        $this->client->addHeader('X-ClickHouse-User', $this->username);
        $this->client->addHeader('X-ClickHouse-Key', $this->password);
        $this->client->addHeader('X-ClickHouse-Database', $this->database);

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
     *
     * @throws Exception
     */
    public function setup(): void
    {
        // Create database if not exists
        $escapedDatabase = $this->escapeIdentifier($this->database);
        $createDbSql = "CREATE DATABASE IF NOT EXISTS {$escapedDatabase}";
        $this->query($createDbSql);

        // Build column definitions
        $columns = [
            'id String',
            'metric String',
            'value Int64',
            'period String',
            'time DateTime64(3)',
            'tags String',  // JSON string
        ];

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        // Create table with MergeTree engine for optimal performance
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS {$escapedDatabaseAndTable} (
                " . implode(",\n                ", $columns) . ',
                INDEX idx_metric metric TYPE bloom_filter GRANULARITY 1,
                INDEX idx_period period TYPE bloom_filter GRANULARITY 1
            )
            ENGINE = MergeTree()
            ORDER BY (metric, period, time)
            PARTITION BY toYYYYMM(time)
            SETTINGS index_granularity = 8192
        ';

        $this->query($createTableSql);
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

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            INSERT INTO {$escapedDatabaseAndTable}
            (id, metric, value, period, time, tags)
            VALUES (
                :id,
                :metric,
                :value,
                :period,
                :time,
                :tags
            )
        ";

        $this->query($sql, [
            'id' => $id,
            'metric' => $metric,
            'value' => $value,
            'period' => $period,
            'time' => $timestamp,
            'tags' => json_encode($tags),
        ]);

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

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $insertSql = "
            INSERT INTO {$escapedDatabaseAndTable}
            (id, metric, value, period, time, tags)
            VALUES " . implode(', ', $values);

        $this->query($insertSql);

        return true;
    }

    /**
     * Parse ClickHouse TabSeparated results into Document array.
     *
     * @return array<Document>
     */
    private function parseResults(string $result): array
    {
        if (empty(trim($result))) {
            return [];
        }

        $lines = explode("\n", trim($result));
        $documents = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 6) {
                continue;
            }

            $documents[] = new Document([
                '$id' => (string) $columns[0],
                'metric' => (string) $columns[1],
                'value' => (int) $columns[2],
                'period' => (string) $columns[3],
                'time' => (string) $columns[4],
                'tags' => json_decode((string) $columns[5], true) ?? [],
            ]);
        }

        return $documents;
    }

    /**
     * Get usage metrics by period.
     *
     * @param  array<int,mixed>  $queries
     * @return array<Document>
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

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            SELECT id, metric, value, period, time, tags
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period
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
     * @return array<Document>
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

        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            SELECT id, metric, value, period, time, tags
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND time >= :startDate AND time <= :endDate
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
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            SELECT count() as count
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period
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
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            SELECT sum(value) as total
            FROM {$escapedDatabaseAndTable}
            WHERE metric = :metric AND period = :period
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
        $escapedDatabaseAndTable = $this->escapeIdentifier($this->database) . '.' . $this->escapeIdentifier($this->table);

        $sql = "
            DELETE FROM {$escapedDatabaseAndTable}
            WHERE time < :datetime
        ";

        $this->query($sql, ['datetime' => $datetime]);

        return true;
    }
}
