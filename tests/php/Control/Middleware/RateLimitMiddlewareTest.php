<?php

namespace SilverStripe\Control\Tests\Middleware;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Control\Middleware\RateLimitMiddleware;
use SilverStripe\Control\Middleware\RequestHandlerMiddlewareAdapter;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Tests\Middleware\Control\TestController;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class RateLimitMiddlewareTest extends FunctionalTest
{

    protected static $extra_controllers = [
        TestController::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2017-09-27 00:00:00');
        Config::modify()->set(Injector::class, 'TestRateLimitMiddleware', [
            'class' => RateLimitMiddleware::class,
            'properties' => [
                'ExtraKey' => 'test',
                'MaxAttempts' => 2,
                'Decay' => 1,
            ],
        ]);
        Config::modify()->set(Injector::class, 'RateLimitTestController', [
            'class' => RequestHandlerMiddlewareAdapter::class,
            'properties' => [
                'RequestHandler' => '%$' . TestController::class,
                'Middlewares' => [
                    '%$TestRateLimitMiddleware'
                ],
            ],
        ]);
    }

    protected function getExtraRoutes()
    {
        $rules = parent::getExtraRoutes();
        $rules['TestController//$Action/$ID/$OtherID'] = '%$RateLimitTestController';
        return $rules;
    }

    public function testRequest()
    {
        $response = $this->get('TestController');
        $this->assertFalse($response->isError());
        $this->assertEquals(2, $response->getHeader('X-RateLimit-Limit'));
        $this->assertEquals(1, $response->getHeader('X-RateLimit-Remaining'));
        $this->assertEquals(DBDatetime::now()->getTimestamp() + 60, $response->getHeader('X-RateLimit-Reset'));
        $this->assertEquals('Success', $response->getBody());
        $response = $this->get('TestController');
        $this->assertFalse($response->isError());
        $this->assertEquals(0, $response->getHeader('X-RateLimit-Remaining'));
        $response = $this->get('TestController');
        $this->assertTrue($response->isError());
        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals(60, $response->getHeader('retry-after'));
        $this->assertNotEquals('Success', $response->getBody());
    }

    public function testSecurityRateLimitMiddlewareUsesConfiguredExclusion(): void
    {
        $securityMiddleware = Injector::inst()->get('SecurityRateLimitMiddleware');
        $this->assertInstanceOf(RateLimitMiddleware::class, $securityMiddleware);
        $this->assertSame(
            ['#^Security/changepassword/ChangePasswordForm/field/Password/strength$#'],
            $securityMiddleware->getExcludedURLPatterns()
        );
    }

    public static function provideShouldBypassRateLimit(): array
    {
        return [
            'matching POST URL bypasses rate limit' => [
                'httpMethod' => 'POST',
                'url' => 'Security/changepassword/ChangePasswordForm/field/Password/strength',
                'patterns' => ['#^Security/changepassword/ChangePasswordForm/field/Password/strength$#'],
                'expectedBypass' => true,
            ],
            'custom patterns, custom URL bypasses' => [
                'httpMethod' => 'POST',
                'url' => 'custom/endpoint',
                'patterns' => ['#^custom/endpoint$#'],
                'expectedBypass' => true,
            ],
            'non-matching POST URL is rate limited' => [
                'httpMethod' => 'POST',
                'url' => 'custom/strength',
                'patterns' => ['#^custom/strength-extra$#'],
                'expectedBypass' => false,
            ],
            'matching GET URL bypasses rate limit' => [
                'httpMethod' => 'GET',
                'url' => 'custom/strength',
                'patterns' => ['#^custom/strength$#'],
                'expectedBypass' => true,
            ],
        ];
    }

    #[DataProvider('provideShouldBypassRateLimit')]
    public function testShouldBypassRateLimit(
        string $httpMethod,
        string $url,
        array $patterns,
        bool $expectedBypass
    ): void {
        $middleware = $this->createMiddleware($httpMethod . '-' . $url);
        $middleware->setExcludedURLPatterns($patterns);
        $request = new HTTPRequest($httpMethod, $url);
        $response = $this->processRequest($middleware, $request);
        $this->assertFalse($response->isError());
        $response = $this->processRequest($middleware, $request);
        if ($expectedBypass) {
            $this->assertFalse($response->isError());
        } else {
            $this->assertTrue($response->isError());
            $this->assertEquals(429, $response->getStatusCode());
        }
    }

    private function createMiddleware(string $extraKey): RateLimitMiddleware
    {
        $middleware = new RateLimitMiddleware();
        $middleware->setExtraKey($extraKey);
        $middleware->setMaxAttempts(1);
        $middleware->setDecay(1);
        return $middleware;
    }

    private function processRequest(RateLimitMiddleware $middleware, HTTPRequest $request): HTTPResponse
    {
        return $middleware->process($request, function (HTTPRequest $request): HTTPResponse {
            return HTTPResponse::create('Success', 200);
        });
    }

    public function testSetExcludedURLPatternsThrowsExceptionForInvalidRegex(): void
    {
        $middleware = new RateLimitMiddleware();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RateLimitMiddleware.ExcludedURLPatterns contains an invalid regex pattern: #[invalid(#');
        $middleware->setExcludedURLPatterns(['#[invalid(#']);
    }
}
