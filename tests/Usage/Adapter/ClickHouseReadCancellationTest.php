<?php

namespace Utopia\Tests\Adapter;

use Exception;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Tests\Usage\Adapter\ScriptedClient;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;

/**
 * What the adapter does when a read fails, driven by a scripted transport:
 * the abandoned query is killed, a failed kill stays out of the way, and a
 * server that refuses the settings degrades to an uncapped read.
 */
class ClickHouseReadCancellationTest extends ClickHouseTestCase
{
    private const READ_SQL = 'SELECT count() FROM events FORMAT JSON';

    private function newAdapter(ScriptedClient $client, ?int $readTimeout = 25): ClickHouseAdapter
    {
        return new ClickHouseAdapter(
            '127.0.0.1',
            'default',
            '',
            8123,
            false,
            client: $client,
            readTimeout: $readTimeout,
        );
    }

    public function testAbandonedReadIsKilledByItsQueryId(): void
    {
        $client = new ScriptedClient([ScriptedClient::TIMEOUT]);
        $adapter = $this->newAdapter($client);
        $adapter->setNextQueryId('pinned-id');

        try {
            $this->queryReadRaw($adapter, self::READ_SQL);
            $this->fail('Expected the read to fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
        }

        $this->assertCount(2, $client->urls);
        $this->assertStringContainsString('max_execution_time=25', $client->urls[0]);
        $this->assertStringContainsString('query_id=pinned-id', $client->urls[0]);
        $this->assertStringContainsString('KILL QUERY WHERE query_id = {queryId:String} ASYNC', $client->bodies[1]);
        $this->assertStringContainsString('pinned-id', $client->bodies[1]);
    }

    public function testFailedCancellationDoesNotMaskTheReadError(): void
    {
        $client = new ScriptedClient([ScriptedClient::TIMEOUT, ScriptedClient::TIMEOUT]);
        $adapter = $this->newAdapter($client);

        try {
            $this->queryReadRaw($adapter, self::READ_SQL);
            $this->fail('Expected the read to fail');
        } catch (Exception $e) {
            $this->assertStringContainsString(self::READ_SQL, $e->getMessage());
        }

        $this->assertCount(2, $client->urls);
    }

    public function testUncappedReadIsNotCancelled(): void
    {
        $client = new ScriptedClient([ScriptedClient::TIMEOUT]);
        $adapter = $this->newAdapter($client, readTimeout: null);

        try {
            $this->queryReadRaw($adapter, self::READ_SQL);
            $this->fail('Expected the read to fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
        }

        $this->assertCount(1, $client->urls);
        $this->assertStringNotContainsString('max_execution_time', $client->urls[0]);
    }

    public function testSettingsRejectionFallsBackToAnUncappedRead(): void
    {
        $readonly = ScriptedClient::response(403, "Code: 164. DB::Exception: Cannot modify 'max_execution_time' setting in readonly mode. (READONLY)");
        $client = new ScriptedClient([$readonly]);
        $adapter = $this->newAdapter($client);

        $this->assertSame('{"data":[]}', $this->queryReadRaw($adapter, self::READ_SQL));
        $this->assertCount(2, $client->urls);
        $this->assertStringContainsString('max_execution_time=25', $client->urls[0]);
        $this->assertStringNotContainsString('max_execution_time', $client->urls[1]);
        $this->assertStringNotContainsString('KILL QUERY', $client->bodies[1]);

        // The instance stops trying once refused.
        $this->queryReadRaw($adapter, self::READ_SQL);
        $this->assertCount(3, $client->urls);
        $this->assertStringNotContainsString('max_execution_time', $client->urls[2]);
    }

    public function testUnreachableServerIsNotCancelled(): void
    {
        $client = new ScriptedClient([ScriptedClient::UNREACHABLE]);
        $adapter = $this->newAdapter($client);

        try {
            $this->queryReadRaw($adapter, self::READ_SQL);
            $this->fail('Expected the read to fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to connect', $e->getMessage());
        }

        // Nothing reached the server, so a KILL would only burn a second timeout.
        $this->assertCount(1, $client->urls);
    }

    public function testCancellationLeavesAnotherReadsPinnedIdAlone(): void
    {
        $client = new ScriptedClient();
        $adapter = $this->newAdapter($client);
        $client->script = [
            // A concurrent caller pins its id while this read is failing.
            function () use ($adapter): string {
                $adapter->setNextQueryId('other-read-id');

                return ScriptedClient::TIMEOUT;
            },
        ];
        $adapter->setNextQueryId('failing-read-id');

        try {
            $this->queryReadRaw($adapter, self::READ_SQL);
            $this->fail('Expected the read to fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
        }

        $this->assertStringContainsString('failing-read-id', $client->bodies[1]);
        $this->assertStringNotContainsString('query_id=other-read-id', $client->urls[1]);

        $this->queryReadRaw($adapter, self::READ_SQL);
        $this->assertStringContainsString('query_id=other-read-id', $client->urls[2]);
    }
}
