<?php

namespace SilverStripe\Security\Tests\MemberAuthenticator;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\ChangePasswordForm;
use SilverStripe\Security\MemberAuthenticator\ChangePasswordHandler;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Security\Security;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\RandomGenerator;

class ChangePasswordHandlerTest extends SapphireTest
{
    protected static $fixture_file = 'ChangePasswordHandlerTest.yml';

    private ChangePasswordHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()
            ->set(Security::class, 'login_url', 'Security/login')
            ->set(Security::class, 'lost_password_url', 'Security/lostpassword');

        $this->logOut();
        $this->handler = new ChangePasswordHandler('/Security/changepassword', new MemberAuthenticator());
        $generator = new class() extends RandomGenerator {
            public function randomToken($algorithm = 'whirlpool')
            {
                return 'temporary-hash';
            }
        };
        Injector::inst()->registerService($generator, RandomGenerator::class);
        // Hash members AutoLoginHash and set AutoLoginExpired
        $member = $this->objFromFixture(Member::class, 'sarah');
        $member->AutoLoginHash = $member->encryptWithUserSettings($member->AutoLoginHash);
        $member->AutoLoginExpired = date('Y-m-d H:i:s', strtotime('+7 days'));
        $member->write();
    }

    public function testValidUrlParamsRedirectsWithTemporaryHash()
    {
        $request = $this->createRequest([
            'm' => $this->idFromFixture(Member::class, 'sarah'),
            't' => 'foobar',
        ]);
        $result = $this->handler->setRequest($request)->changepassword();
        $this->assertTrue(is_a($result, HTTPResponse::class));
        $this->assertSame(302, $result->getStatusCode());
        $location = '/Security/changepassword?th=temporary-hash';
        $this->assertSame(Director::absoluteURL($location), $result->getHeader('Location'));
    }

    public function testExpiredOrInvalidTokenProvidesLostPasswordAndLoginLink()
    {
        $request = $this->createRequest([
            'm' => $this->idFromFixture(Member::class, 'sarah'),
            't' => 'an-old-or-expired-hash',
        ]);
        $result = $this->handler->setRequest($request)->changepassword();
        $this->assertIsArray($result);
        $this->assertSame(['Content'], array_keys($result));
        $this->assertStringContainsString('Security/lostpassword', $result['Content']);
        $this->assertStringContainsString('Security/login', $result['Content']);
    }

    public function testTemporaryHashInUrlRendersForm()
    {
        $this->setMemberAutoLoginTempHash();
        $request = $this->createRequest([
            'th' => 'temporary-hash'
        ]);
        $result = $this->handler->setRequest($request)->changepassword();
        $this->assertIsArray($result);
        $this->assertSame(['Content', 'Form'], array_keys($result));
        $this->assertTrue(is_a($result['Form'], ChangePasswordForm::class));
    }

    public function testSubsequentTemporaryHashRedirectsToLoginForm()
    {
        $this->setMemberAutoLoginTempHash();
        $request = $this->createRequest([
            'th' => 'temporary-hash'
        ]);
        $this->handler->setRequest($request)->changepassword();
        $request = $this->createRequest([
            'th' => 'temporary-hash'
        ]);
        $result = $this->handler->setRequest($request)->changepassword();
        $this->assertTrue(is_a($result, HTTPResponse::class));
        $this->assertSame(302, $result->getStatusCode());
        $location = '/Security/login';
        $this->assertStringContainsString($location, $result->getHeader('Location'));
    }

    public function testIncorrectTemporaryHashInUrlRendersLoginForm()
    {
        $this->setMemberAutoLoginTempHash();
        $request = $this->createRequest([
            'th' => 'wrong-hash'
        ]);
        $result = $this->handler->setRequest($request)->changepassword();
        $this->assertTrue(is_a($result, HTTPResponse::class));
        $this->assertSame(302, $result->getStatusCode());
        $location = '/Security/login';
        $this->assertStringContainsString($location, $result->getHeader('Location'));
    }

    private function createRequest(array $params = []): HTTPRequest
    {
        $request = new HTTPRequest('POST', '/Security/changepassword', $params);
        $request->setSession(new Session([]));
        return $request;
    }

    /**
     * Set the AutoLoginTempHash to 'temporary-hash for the member
     * This is used to simulate the second stage of the change password process
     */
    private function setMemberAutoLoginTempHash(): void
    {
        $member = $this->objFromFixture(Member::class, 'sarah');
        $member->AutoLoginTempHash = 'temporary-hash';
        $member->write();
    }
}
