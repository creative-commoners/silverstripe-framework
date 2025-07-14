<?php

namespace SilverStripe\Control\Tests\SessionHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use SilverStripe\Control\SessionHandler\CacheSessionHandler;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CacheSessionHandlerTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function provideRead(): array
    {
        return [
            [
                'sessionID' => 'existing-session',
                'expected' => 'some-data',
            ],
            [
                'sessionID' => 'new-session',
                'expected' => '',
            ],
        ];
    }

    #[DataProvider('provideRead')]
    public function testRead(string $sessionID, string $expected): void
    {
        $cacheAdapter = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data');
        $this->assertSame($expected, $handler->read($sessionID));
    }

    public function testReadExpired(): void
    {
        $cacheAdapter = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data', -1);
        $this->assertSame('', $handler->read('existing-session'));
    }

    public static function provideWrite(): array
    {
        return [
            [
                'sessionID' => 'existing-session',
            ],
            [
                'sessionID' => 'new-session',
            ],
        ];
    }

    #[DataProvider('provideWrite')]
    public function testWrite(string $sessionID): void
    {
        $cacheAdapter = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data');
        $handler->write($sessionID, 'updated-data');
        $this->assertSame('updated-data', $cacheAdapter->get($sessionID));
    }

    public static function provideDestroy(): array
    {
        return [
            [
                'sessionID' => 'existing-session',
            ],
            [
                'sessionID' => 'new-session',
            ],
        ];
    }

    #[DataProvider('provideDestroy')]
    public function testDestroy(string $sessionID): void
    {
        $cacheAdapter = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data');
        $this->assertTrue($handler->destroy($sessionID));
        $this->assertNull($cacheAdapter->get($sessionID));
    }

    public static function provideValidateId(): array
    {
        return [
            'new session (no file) is invalid' => [
                'sessionID' => 'new-session',
                'isExpired' => false,
                'expected' => false,
            ],
            'existing session is valid' => [
                'sessionID' => 'existing-session',
                'isExpired' => false,
                'expected' => true,
            ],
            'expired existing session is invalid' => [
                'sessionID' => 'existing-session',
                'isExpired' => true,
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideValidateId')]
    public function testValidateId(string $sessionID, bool $isExpired, bool $expected): void
    {
        $cacheAdapter = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data', $isExpired ? -1 : 60);
        $this->assertSame($expected, $handler->validateId($sessionID));
    }

    public function testUpdateTimestamp(): void
    {
        $arrayAdapter = new ArrayAdapter();
        $cacheAdapter = new Psr16Cache($arrayAdapter);
        $handler = new CacheSessionHandler($cacheAdapter);
        $cacheAdapter->set('existing-session', 'some-data', 999999999);

        $this->assertTrue($handler->updateTimestamp('existing-session', 'new content'));
        $this->assertTrue($cacheAdapter->has('existing-session'));
        $reflectionExpiries = new ReflectionProperty($arrayAdapter, 'expiries');
        $reflectionExpiries->setAccessible(true);
        $expiry = $reflectionExpiries->getValue($arrayAdapter)['existing-session'];

        // 999999 is way more than the number of seconds the session should live for
        // so calling updateTimestamp should set it to less than that but more than right now
        // We can't do an exact time check because that would obviously introduce timing issues
        // and Symfony's cache stuff doesn't use our internal DateTime so we can't set a mock now.
        $this->assertLessThan(microtime(true) + 999999, $expiry);
        $this->assertGreaterThan(microtime(true), $expiry);
        $this->assertSame('new content', $cacheAdapter->get('existing-session'));
    }
}
