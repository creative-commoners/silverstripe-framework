<?php

namespace SilverStripe\Control\Tests\SessionHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SilverStripe\Control\Session;
use SilverStripe\Control\SessionHandler\DatabaseSessionHandler;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use RuntimeException;

class DatabaseSessionHandlerTest extends SapphireTest
{
    private const ID_NEW = 'bcaaaaaaaaaaaaaaaaaaaaaaaaaaaa01';

    private const ID_VALID = 'bcaaaaaaaaaaaaaaaaaaaaaaaaaaaa02';

    private const ID_EXPIRED = 'bcaaaaaaaaaaaaaaaaaaaaaaaaaaaa03';

    protected $usesDatabase = true;

    protected static $fixture_file = 'DatabaseSessionHandlerTest.yml';

    private string|false $gcLifeTime;

    public function onBeforeLoadFixtures(): void
    {
        // Add the sessions table
        $handler = new DatabaseSessionHandler();
        DB::get_schema()->schemaUpdate(fn() => $handler->requireTable());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->gcLifeTime = ini_get('session.gc_maxlifetime');
        $expiry = DBDatetime::now()->getTimestamp() + 1000;
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        $id = DatabaseSessionHandlerTest::ID_VALID;
        DB::query('UPDATE "' . $tableName . '" SET "Expiry" = ' . $expiry . ' WHERE "ID" = \'' . $id . '\'');
    }

    protected function tearDown(): void
    {
        ini_set('session.gc_maxlifetime', $this->gcLifeTime);
        parent::tearDown();
    }

    public function testIdIsPrimaryKey(): void
    {
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        $result = DB::query('SHOW KEYS FROM ' . $tableName . ' WHERE "Key_name" = \'PRIMARY\'');
        $this->assertSame('ID', $result->record()['Column_name']);
    }

    public static function provideRead(): array
    {
        return [
            'new session (aka no file)' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
                'expected' => '',
            ],
            'existing session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_VALID,
                'expected' => 'this one is valid',
            ],
            'expired session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_EXPIRED,
                'expected' => '',
            ],
        ];
    }

    #[DataProvider('provideRead')]
    public function testRead(string $sessionID, string $expected): void
    {
        $handler = new DatabaseSessionHandler();

        $this->assertSame($expected, $handler->read($sessionID));

        if ($sessionID === 'new-session') {
            // Make sure no new file is created for new sessions
            $tableName = DatabaseSessionHandler::config()->get('table_name');
            $result = DB::query('SELECT "ID" FROM ' . $tableName . ' WHERE "ID" = \'new-session\'');
            $this->assertSame(0, $result->numRecords());
        }
    }

    public static function provideWrite(): array
    {
        return [
            'overrides existing session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_VALID,
                'gcLifetime' => 100,
                'configLifetime' => 500,
                'expectedLifetime' => 500,
            ],
            'overrides expired session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_EXPIRED,
                'gcLifetime' => 500,
                'configLifetime' => 100,
                'expectedLifetime' => 100,
            ],
            'creates new session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
                'gcLifetime' => 0,
                'configLifetime' => 150,
                'expectedLifetime' => 150,
            ],
            'uses gc for lifetime fallback' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
                'gcLifetime' => 200,
                'configLifetime' => 0,
                'expectedLifetime' => 200,
            ],
        ];
    }

    #[DataProvider('provideWrite')]
    public function testWrite(string $sessionID, int $gcLifetime, int $configLifetime, int $expectedLifetime): void
    {
        ini_set('session.gc_maxlifetime', $gcLifetime);
        Session::config()->set('timeout', $configLifetime);
        $handler = new DatabaseSessionHandler();
        $now = DBDatetime::now();
        DBDatetime::set_mock_now($now);
        $expiry = $now->getTimestamp() + $expectedLifetime;

        $this->assertTrue($handler->write($sessionID, 'New content now'));
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        $result = DB::query('SELECT * FROM ' . $tableName . ' WHERE "ID" = \'' . $sessionID . '\'');
        $this->assertSame(1, $result->numRecords());
        $session = $result->record();
        $this->assertSame('New content now', $session['Data']);
        $this->assertSame($expiry, $session['Expiry']);
    }

    public static function provideDestroy(): array
    {
        return [
            'deletes existing session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_VALID,
            ],
            'deletes expired session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_EXPIRED,
            ],
            'no action for missing session' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
            ],
        ];
    }

    #[DataProvider('provideDestroy')]
    public function testDestroy(string $sessionID): void
    {
        $handler = new DatabaseSessionHandler();

        $this->assertTrue($handler->destroy($sessionID));
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        $result = DB::query('SELECT "ID" FROM ' . $tableName . ' WHERE "ID" = \'' . $sessionID . '\'');
        $this->assertSame(0, $result->numRecords());
    }

    public static function provideGc(): array
    {
        return [
            'respect config' => [
                'gcLifetime' => 100,
                'configLifetime' => 500,
                'sessionLifetimeMap' => [
                    'session1' => 50,
                    'session2' => 550,
                    'session3' => 150,
                    'session4' => 1000,
                ],
                'expectDeleted' => [
                    'session2',
                    'session4',
                    'expired',
                ],
            ],
            'fall back on gc' => [
                'gcLifetime' => 100,
                'configLifetime' => 0,
                'sessionLifetimeMap' => [
                    'session1' => 50,
                    'session2' => 550,
                    'session3' => 150,
                    'session4' => 1000,
                ],
                'expectDeleted' => [
                    'session2',
                    'session3',
                    'session4',
                    'expired',
                ],
            ],
        ];
    }

    #[DataProvider('provideGc')]
    public function testGc(int $gcLifetime, int $configLifetime, array $sessionLifetimeMap, array $expectDeleted): void
    {
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        ini_set('session.gc_maxlifetime', $gcLifetime);
        Session::config()->set('timeout', $configLifetime);
        $lifetime = ($configLifetime > 0) ? $configLifetime : $gcLifetime;
        $handler = new DatabaseSessionHandler();

        // Create the dummy files and set their modified time as appropriate
        // Use symfony filesystem so that any subdirs are automatically created
        $now = DBDatetime::now();
        DBDatetime::set_mock_now($now);
        // $lifeToDate represents how long ago the session was last written to
        // so we need to calculate its expiry date based on how much lifetime is left.
        foreach ($sessionLifetimeMap as $sessionID => $lifeToDate) {
            $expiry = $now->getTimestamp() + ($lifetime - $lifeToDate);
            DB::query(sprintf(
                'INSERT INTO ' . $tableName . ' ("ID", "Data", "Expiry") VALUES (\'%s\', \'%s\', \'%s\')',
                $sessionID,
                'original content',
                $expiry
            ));
        }
        // Run gc
        $numDeleted = $handler->gc(1);
        // Check it deleted the right things
        foreach ($expectDeleted as $sessionID) {
            $result = DB::query('SELECT "ID" FROM ' . $tableName . ' WHERE "ID" = \'' . $sessionID . '\'');
            $this->assertSame(0, $result->numRecords());
        }
        $this->assertSame(count($expectDeleted), $numDeleted);
    }

    public static function provideValidateId(): array
    {
        return [
            'new session is invalid' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
                'expected' => false,
            ],
            'existing session is valid' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_VALID,
                'expected' => true,
            ],
            'expired existing session is invalid' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_EXPIRED,
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideValidateId')]
    public function testValidateId(string $sessionID, bool $expected): void
    {
        $handler = new DatabaseSessionHandler();
        $this->assertSame($expected, $handler->validateId($sessionID));
    }

    public static function provideUpdateTimestamp(): array
    {
        return [
            'session already exists' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_VALID,
                'expectedContent' => 'new content',
            ],
            'session already expired' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_EXPIRED,
                'expectedContent' => 'new content',
            ],
            'session doesnt exist (edge case)' => [
                'sessionID' => DatabaseSessionHandlerTest::ID_NEW,
                'expectedContent' => 'new content',
            ],
        ];
    }

    #[DataProvider('provideUpdateTimestamp')]
    public function testUpdateTimestamp(string $sessionID, string $expectedContent): void
    {
        $tableName = DatabaseSessionHandler::config()->get('table_name');
        $handler = new DatabaseSessionHandler();
        $now = DBDatetime::now();
        $reflectionGetLifetime = new ReflectionMethod($handler, 'getLifetime');
        $lifetime = $reflectionGetLifetime->invoke($handler);

        DBDatetime::withFixedNow($now, function () use ($handler, $sessionID) {
            $this->assertTrue($handler->updateTimestamp($sessionID, 'new content'));
        });

        $result = DB::query('SELECT * FROM ' . $tableName . ' WHERE "ID" = \'' . $sessionID . '\'');
        $this->assertSame(1, $result->numRecords());
        $session = $result->record();
        $this->assertSame($now->getTimestamp() + $lifetime, $session['Expiry']);
        $this->assertSame($expectedContent, $session['Data']);
    }

    public function testInvalidSessionIDThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid session ID');
        $handler = new DatabaseSessionHandler();
        $sessionID = 'spaces are not valid';
        $handler->read($sessionID);
    }
}
