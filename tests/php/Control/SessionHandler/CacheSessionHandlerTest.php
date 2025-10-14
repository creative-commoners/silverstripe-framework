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
                'sessionID' => md5('existing-session'),
                'expected' => 'some-data',
            ],
            [
                'sessionID' => md5('new-session'),
                'expected' => '',
            ],
        ];
    }

    #[DataProvider('provideRead')]
    public function testRead(string $sessionID, string $expected): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data');
        $this->assertSame($expected, $handler->read($sessionID));
    }

    public function testReadExpired(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data', -1);
        $this->assertSame('', $handler->read(md5('existing-session')));
    }

    public static function provideWrite(): array
    {
        return [
            [
                'sessionID' => md5('existing-session'),
            ],
            [
                'sessionID' => md5('new-session'),
            ],
        ];
    }

    #[DataProvider('provideWrite')]
    public function testWrite(string $sessionID): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data');
        $handler->write($sessionID, 'updated-data');
        $this->assertSame('updated-data', $cache->get($sessionID));
    }

    public static function provideDestroy(): array
    {
        return [
            [
                'sessionID' => md5('existing-session'),
            ],
            [
                'sessionID' => md5('new-session'),
            ],
        ];
    }

    #[DataProvider('provideDestroy')]
    public function testDestroy(string $sessionID): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data');
        $this->assertTrue($handler->destroy($sessionID));
        $this->assertNull($cache->get($sessionID));
    }

    public static function provideValidateId(): array
    {
        return [
            'new session (no file) is invalid' => [
                'sessionID' => md5('new-session'),
                'isExpired' => false,
                'expected' => false,
            ],
            'existing session is valid' => [
                'sessionID' => md5('existing-session'),
                'isExpired' => false,
                'expected' => true,
            ],
            'expired existing session is invalid' => [
                'sessionID' => md5('existing-session'),
                'isExpired' => true,
                'expected' => false,
            ],
            'invalid phpsessid format is invalid' => [
                'sessionID' => 'spaces are not valid',
                'isExpired' => false,
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideValidateId')]
    public function testValidateId(string $sessionID, bool $isExpired, bool $expected): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data', $isExpired ? -1 : 60);
        $this->assertSame($expected, $handler->validateId($sessionID));
    }

    public function testUpdateTimestamp(): void
    {
        $arrayAdapter = new ArrayAdapter();
        $cache = new Psr16Cache($arrayAdapter);
        $handler = new CacheSessionHandler($cache);
        $cache->set(md5('existing-session'), 'some-data', 999999999);

        $this->assertTrue($handler->updateTimestamp(md5('existing-session'), 'new content'));
        $this->assertTrue($cache->has(md5('existing-session')));
        $reflectionExpiries = new ReflectionProperty($arrayAdapter, 'expiries');
        $reflectionExpiries->setAccessible(true);
        $expiry = $reflectionExpiries->getValue($arrayAdapter)[md5('existing-session')];

        // 999999 is way more than the number of seconds the session should live for
        // so calling updateTimestamp should set it to less than that but more than right now
        // We can't do an exact time check because that would obviously introduce timing issues
        // and Symfony's cache stuff doesn't use our internal DateTime so we can't set a mock now.
        $this->assertLessThan(microtime(true) + 999999, $expiry);
        $this->assertGreaterThan(microtime(true), $expiry);
        $this->assertSame('new content', $cache->get(md5('existing-session')));
    }
}
