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
        if (! $this->database->exists($this->database->getDatabase())) {
            $this->database->create();
        }

        // Always run setup to ensure collection exists
        try {

            $this->usage->setup();
        } catch (Duplicate $ex) {
            // ignore duplicate exception
        }
    }
}
