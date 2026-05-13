<?php

namespace Utopia\Tests\Adapter;

use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Exception\Duplicate;
use Utopia\Tests\Usage\UsageBase;
use Utopia\Usage\Adapter\Database as AdapterDatabase;
use Utopia\Usage\Usage;

class DatabaseTest extends TestCase
{
    use UsageBase;

    protected Database $database;

    protected function initializeUsage(): void
    {
        $dbHost = 'mariadb';
        $dbPort = '3306';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $cache = new Cache(new NoCache());
        $this->database = new Database(new MariaDB($pdo), $cache);
        $this->database->setDatabase('utopiaTests');
        $this->database->setNamespace('utopia_usage');

        $this->usage = new Usage(new AdapterDatabase($this->database));

        // Create database if missing
        try {
            $this->database->create();
        } catch (Duplicate $ex) {
            // ignore duplicate exception
        }

        // Always run setup to ensure collection exists
        try {

            $this->usage->setup();
        } catch (Duplicate $ex) {
            // ignore duplicate exception
        }
    }

    /**
     * Test healthCheck() method
     */
    public function testHealthCheck(): void
    {
        $adapter = $this->usage->getAdapter();

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertIsArray($health);
        $this->assertArrayHasKey('healthy', $health);

        // Assert connection is healthy
        $this->assertTrue($health['healthy'], 'Database should be healthy');

        // Assert additional fields are present when healthy
        $this->assertArrayHasKey('database', $health);
        $this->assertArrayHasKey('collection', $health);
        $this->assertIsString($health['database']);
        $this->assertIsString($health['collection']);
    }

    /**
     * Test healthCheck() with database that doesn't exist
     */
    public function testHealthCheckWithNonExistentDatabase(): void
    {
        // Create a new database instance pointing to a non-existent database
        $dbHost = 'mariadb';
        $dbPort = '3306';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        $cache = new Cache(new NoCache());
        $database = new Database(new MariaDB($pdo), $cache);
        $database->setDatabase('nonexistent_database_xyz');
        $database->setNamespace('test');

        $adapter = new AdapterDatabase($database);

        $health = $adapter->healthCheck();

        // Assert basic structure
        $this->assertIsArray($health);
        $this->assertArrayHasKey('healthy', $health);

        // Assert connection failed
        $this->assertFalse($health['healthy'], 'Database should be unhealthy with non-existent database');

        // Assert error message is present
        $this->assertArrayHasKey('error', $health);
        if (isset($health['error'])) {
            $this->assertIsString($health['error']);
            $this->assertNotEmpty($health['error']);
        }
    }
}
