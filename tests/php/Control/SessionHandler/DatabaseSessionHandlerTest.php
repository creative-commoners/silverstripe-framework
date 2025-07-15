<?php

namespace SilverStripe\Control\Tests\SessionHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SilverStripe\Control\Session;
use SilverStripe\Control\SessionHandler\DatabaseSessionHandler;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

class DatabaseSessionHandlerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = 'DatabaseSessionHandlerTest.yml';

    public function onBeforeLoadFixtures(): void
    {
        // Add the sessions table
        $handler = new DatabaseSessionHandler();
        DB::get_schema()->schemaUpdate(fn () => $handler->requireTable());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $expiry = DBDatetime::now()->getTimestamp() + 1000;
        DB::query('UPDATE "_sessions" SET "Expiry" = ' . $expiry . ' WHERE "ID" = \'valid\'');
    }

    public function testIdIsPrimaryKey(): void
    {
        $result = DB::query('SHOW KEYS FROM "_sessions" WHERE "Key_name" = \'PRIMARY\'');
        $this->assertSame('ID', $result->record()['Column_name']);
    }

    public static function provideRead(): array
    {
        return [
            'new session (aka no file)' => [
                'sessionID' => 'new-session',
                'expected' => '',
            ],
            'existing session' => [
                'sessionID' => 'valid',
                'expected' => 'this one is valid',
            ],
            'expired session' => [
                'sessionID' => 'expired',
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
            $result = DB::query('SELECT "ID" FROM "_sessions" WHERE "ID" = \'new-session\'');
            $this->assertSame(0, $result->numRecords());
        }
    }

    public static function provideWrite(): array
    {
        return [
            'overrides existing session' => [
                'sessionID' => 'valid',
                'gcLifetime' => 100,
                'configLifetime' => 500,
                'expectedLifetime' => 500,
            ],
            'overrides expired session' => [
                'sessionID' => 'expired',
                'gcLifetime' => 500,
                'configLifetime' => 100,
                'expectedLifetime' => 100,
            ],
            'creates new session' => [
                'sessionID' => 'new-session',
                'gcLifetime' => 0,
                'configLifetime' => 150,
                'expectedLifetime' => 150,
            ],
            'uses gc for lifetime fallback' => [
                'sessionID' => 'new-session',
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
        $result = DB::query('SELECT * FROM "_sessions" WHERE "ID" = \'' . $sessionID . '\'');
        $this->assertSame(1, $result->numRecords());
        $session = $result->record();
        $this->assertSame('New content now', $session['Data']);
        $this->assertSame($expiry, $session['Expiry']);
    }

    public static function provideDestroy(): array
    {
        return [
            'deletes existing session' => [
                'sessionID' => 'valid',
            ],
            'deletes expired session' => [
                'sessionID' => 'expired',
            ],
            'no action for missing session' => [
                'sessionID' => 'new-session'
            ],
        ];
    }

    #[DataProvider('provideDestroy')]
    public function testDestroy(string $sessionID): void
    {
        $handler = new DatabaseSessionHandler();

        $this->assertTrue($handler->destroy($sessionID));
        $result = DB::query('SELECT "ID" FROM "_sessions" WHERE "ID" = \'' . $sessionID . '\'');
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
                'INSERT INTO "_sessions" ("ID", "Data", "Expiry") VALUES (\'%s\', \'%s\', \'%s\')',
                $sessionID,
                'original content',
                $expiry
            ));
        }
        // Run gc
        $numDeleted = $handler->gc(1);
        // Check it deleted the right things
        foreach ($expectDeleted as $sessionID) {
            $result = DB::query('SELECT "ID" FROM "_sessions" WHERE "ID" = \'' . $sessionID . '\'');
            $this->assertSame(0, $result->numRecords());
        }
        $this->assertSame(count($expectDeleted), $numDeleted);
    }

    public static function provideValidateId(): array
    {
        return [
            'new session is invalid' => [
                'sessionID' => 'new-session',
                'expected' => false,
            ],
            'existing session is valid' => [
                'sessionID' => 'valid',
                'expected' => true,
            ],
            'expired existing session is invalid' => [
                'sessionID' => 'expired',
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
                'sessionID' => 'valid',
                'expectedContent' => 'new content',
            ],
            'session already expired' => [
                'sessionID' => 'expired',
                'expectedContent' => 'new content',
            ],
            'session doesnt exist (edge case)' => [
                'sessionID' => 'new-session',
                'expectedContent' => 'new content',
            ],
        ];
    }

    #[DataProvider('provideUpdateTimestamp')]
    public function testUpdateTimestamp(string $sessionID, string $expectedContent): void
    {
        $handler = new DatabaseSessionHandler();
        $now = DBDatetime::now();
        $reflectionGetLifetime = new ReflectionMethod($handler, 'getLifetime');
        $lifetime = $reflectionGetLifetime->invoke($handler);

        DBDatetime::withFixedNow($now, function () use ($handler, $sessionID) {
            $this->assertTrue($handler->updateTimestamp($sessionID, 'new content'));
        });

        $result = DB::query('SELECT * FROM "_sessions" WHERE "ID" = \'' . $sessionID . '\'');
        $this->assertSame(1, $result->numRecords());
        $session = $result->record();
        $this->assertSame($now->getTimestamp() + $lifetime, $session['Expiry']);
        $this->assertSame($expectedContent, $session['Data']);
    }
}
