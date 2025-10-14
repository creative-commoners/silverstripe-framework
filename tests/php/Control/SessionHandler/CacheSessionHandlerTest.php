<?php

namespace SilverStripe\Control\Tests\SessionHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use SilverStripe\Control\SessionHandler\CacheSessionHandler;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use RuntimeException;

class CacheSessionHandlerTest extends SapphireTest
{
    private const ID_EXISTING = 'bcaaaaaaaaaaaaaaaaaaaaaaaaaaaa01';

    private const ID_NEW = 'bcaaaaaaaaaaaaaaaaaaaaaaaaaaaa02';

    protected $usesDatabase = false;

    public static function provideRead(): array
    {
        return [
            [
                'sessionID' => CacheSessionHandlerTest::ID_EXISTING,
                'expected' => 'some-data',
            ],
            [
                'sessionID' => CacheSessionHandlerTest::ID_NEW,
                'expected' => '',
            ],
        ];
    }

    #[DataProvider('provideRead')]
    public function testRead(string $sessionID, string $expected): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data');
        $this->assertSame($expected, $handler->read($sessionID));
    }

    public function testReadExpired(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data', -1);
        $this->assertSame('', $handler->read(CacheSessionHandlerTest::ID_EXISTING));
    }

    public static function provideWrite(): array
    {
        return [
            [
                'sessionID' => CacheSessionHandlerTest::ID_EXISTING,
            ],
            [
                'sessionID' => CacheSessionHandlerTest::ID_NEW,
            ],
        ];
    }

    #[DataProvider('provideWrite')]
    public function testWrite(string $sessionID): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data');
        $handler->write($sessionID, 'updated-data');
        $this->assertSame('updated-data', $cache->get($sessionID));
    }

    public static function provideDestroy(): array
    {
        return [
            [
                'sessionID' => CacheSessionHandlerTest::ID_EXISTING,
            ],
            [
                'sessionID' => CacheSessionHandlerTest::ID_NEW,
            ],
        ];
    }

    #[DataProvider('provideDestroy')]
    public function testDestroy(string $sessionID): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data');
        $this->assertTrue($handler->destroy($sessionID));
        $this->assertNull($cache->get($sessionID));
    }

    public static function provideValidateId(): array
    {
        return [
            'new session (no file) is invalid' => [
                'sessionID' => CacheSessionHandlerTest::ID_NEW,
                'isExpired' => false,
                'expected' => false,
            ],
            'existing session is valid' => [
                'sessionID' => CacheSessionHandlerTest::ID_EXISTING,
                'isExpired' => false,
                'expected' => true,
            ],
            'expired existing session is invalid' => [
                'sessionID' => CacheSessionHandlerTest::ID_EXISTING,
                'isExpired' => true,
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideValidateId')]
    public function testValidateId(string $sessionID, bool $isExpired, bool $expected): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data', $isExpired ? -1 : 60);
        $this->assertSame($expected, $handler->validateId($sessionID));
    }

    public function testUpdateTimestamp(): void
    {
        $arrayAdapter = new ArrayAdapter();
        $cache = new Psr16Cache($arrayAdapter);
        $handler = new CacheSessionHandler($cache);
        $cache->set(CacheSessionHandlerTest::ID_EXISTING, 'some-data', 999999999);

        $this->assertTrue($handler->updateTimestamp(CacheSessionHandlerTest::ID_EXISTING, 'new content'));
        $this->assertTrue($cache->has(CacheSessionHandlerTest::ID_EXISTING));
        $reflectionExpiries = new ReflectionProperty($arrayAdapter, 'expiries');
        $reflectionExpiries->setAccessible(true);
        $expiry = $reflectionExpiries->getValue($arrayAdapter)[CacheSessionHandlerTest::ID_EXISTING];

        // 999999 is way more than the number of seconds the session should live for
        // so calling updateTimestamp should set it to less than that but more than right now
        // We can't do an exact time check because that would obviously introduce timing issues
        // and Symfony's cache stuff doesn't use our internal DateTime so we can't set a mock now.
        $this->assertLessThan(microtime(true) + 999999, $expiry);
        $this->assertGreaterThan(microtime(true), $expiry);
        $this->assertSame('new content', $cache->get(CacheSessionHandlerTest::ID_EXISTING));
    }

    public function testInvalidSessionIDThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid session ID');
        $arrayAdapter = new ArrayAdapter();
        $cache = new Psr16Cache($arrayAdapter);
        $handler = new CacheSessionHandler($cache);
        $sessionID = 'spaces are not valid';
        $handler->read($sessionID);
    }
}
