<?php

declare(strict_types=1);

namespace Deployer\Tests\Auth;

use Deployer\Auth\Infrastructure\Cli\UserPasswordCommand;
use Deployer\Shared\Infrastructure\Cli\Console;
use Deployer\Tests\Support\ApiTestCase;

final class AuthApiTest extends ApiTestCase
{
    public function testSetupCreatesTheSingleAdminOnlyOnce(): void
    {
        self::assertSame(['needs_setup' => true], $this->json($this->call('GET', '/api/setup/status')));

        $this->setUpAdmin();

        self::assertSame(['needs_setup' => false], $this->json($this->call('GET', '/api/setup/status')));
        $again = $this->call('POST', '/api/setup', ['username' => 'evil', 'password' => 'another long password']);
        self::assertSame(409, $again->status);
        self::assertSame('already_set_up', $this->json($again)['error']['code']);
    }

    public function testSetupLogsTheAdminIn(): void
    {
        $this->setUpAdmin('root');

        $me = $this->call('GET', '/api/me');
        self::assertSame(200, $me->status);
        self::assertSame('root', $this->json($me)['user']['username']);
        self::assertSame($this->csrf, $this->json($me)['csrf_token']);
    }

    public function testSetupValidatesInput(): void
    {
        $response = $this->call('POST', '/api/setup', ['username' => 'admin', 'password' => 'short']);

        self::assertSame(422, $response->status);
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
        self::assertArrayHasKey('password', $this->json($response)['error']['details']);
        self::assertSame(['needs_setup' => true], $this->json($this->call('GET', '/api/setup/status')));
    }

    public function testMalformedJsonIsRejected(): void
    {
        $response = $this->call('POST', '/api/setup', rawBody: '{nope');

        self::assertSame(400, $response->status);
        self::assertSame('invalid_json', $this->json($response)['error']['code']);
    }

    public function testProtectedRoutesRequireASession(): void
    {
        $response = $this->call('GET', '/api/me');

        self::assertSame(401, $response->status);
        self::assertSame('unauthenticated', $this->json($response)['error']['code']);
        self::assertSame(401, $this->call('GET', '/api/does-not-exist')->status, 'no route enumeration without a session');
        self::assertSame(200, $this->call('GET', '/api/health')->status);
    }

    public function testLoginSetsAHardenedSessionCookie(): void
    {
        $this->setUpAdmin();
        $this->cookies = [];

        $response = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery']);

        self::assertSame(200, $response->status);
        self::assertCount(1, $response->cookies);
        $cookie = $response->cookies[0];
        self::assertStringStartsWith('deployer_session=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringNotContainsString('Secure', $cookie);
        self::assertNotEmpty($this->json($response)['csrf_token']);
        self::assertSame(200, $this->call('GET', '/api/me')->status);
    }

    public function testCookieIsSecureBehindAnHttpsProxyOrOnHttps(): void
    {
        $this->setUpAdmin();

        $proxied = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'], ['X-Forwarded-Proto' => 'https']);
        $direct = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'], https: true);

        self::assertStringContainsString('; Secure', $proxied->cookies[0]);
        self::assertStringContainsString('; Secure', $direct->cookies[0]);
    }

    public function testLoginRejectsWrongCredentialsWithoutRevealingWhichPartFailed(): void
    {
        $this->setUpAdmin();
        $this->cookies = [];

        $badPassword = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'wrong password!']);
        $badUser = $this->call('POST', '/api/login', ['username' => 'nobody', 'password' => 'correct horse battery']);

        self::assertSame(401, $badPassword->status);
        self::assertSame(401, $badUser->status);
        self::assertSame($this->json($badPassword), $this->json($badUser));
        self::assertSame([], $badPassword->cookies);
    }

    public function testLoginRegeneratesTheSessionId(): void
    {
        $this->setUpAdmin();
        $before = $this->cookies['deployer_session'];

        $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery']);
        $after = $this->cookies['deployer_session'];

        self::assertNotSame($before, $after);
        $this->cookies['deployer_session'] = $before;
        self::assertSame(401, $this->call('GET', '/api/me')->status, 'the pre-login session is destroyed');
    }

    public function testStateChangingCallsRequireTheCsrfToken(): void
    {
        $this->setUpAdmin();
        $token = $this->csrf;
        $this->csrf = null;

        self::assertSame(403, $this->call('POST', '/api/logout')->status);
        $wrong = $this->call('POST', '/api/logout', headers: ['X-CSRF-Token' => 'nope']);
        self::assertSame('csrf_mismatch', $this->json($wrong)['error']['code']);
        self::assertSame(200, $this->call('GET', '/api/me')->status, 'safe methods need no token');

        self::assertSame(204, $this->call('POST', '/api/logout', headers: ['X-CSRF-Token' => (string) $token])->status);
        self::assertSame(401, $this->call('GET', '/api/me')->status);
    }

    public function testLogoutClearsTheCookie(): void
    {
        $this->setUpAdmin();

        $response = $this->call('POST', '/api/logout');

        self::assertStringContainsString('Max-Age=0', $response->cookies[0]);
        self::assertArrayNotHasKey('deployer_session', $this->cookies);
    }

    public function testSessionsExpireAfterIdleTimeout(): void
    {
        $this->setUpAdmin();

        $this->clock->advance('+11 hours');
        self::assertSame(200, $this->call('GET', '/api/me')->status, 'activity keeps the session alive');
        $this->clock->advance('+11 hours');
        self::assertSame(200, $this->call('GET', '/api/me')->status);
        $this->clock->advance('+13 hours');
        self::assertSame(401, $this->call('GET', '/api/me')->status);
    }

    public function testRepeatedFailuresThrottleTheIp(): void
    {
        $this->setUpAdmin();
        $this->cookies = [];
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'bad password ' . $i])->status);
        }

        $blocked = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery']);
        self::assertSame(429, $blocked->status);
        self::assertSame('too_many_attempts', $this->json($blocked)['error']['code']);
        self::assertArrayHasKey('Retry-After', $blocked->headers);

        $otherIp = $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'], ip: '10.0.0.2');
        self::assertSame(200, $otherIp->status);

        $this->clock->advance('+16 minutes');
        self::assertSame(200, $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'])->status);
    }

    public function testApiResponsesAreNotCached(): void
    {
        $response = $this->call('GET', '/api/setup/status');

        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
    }

    public function testCliResetsThePasswordAndRevokesSessions(): void
    {
        $this->setUpAdmin();

        [$code, $out] = $this->runPasswordCommand([], "a brand new password\na brand new password\n");

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Password updated for "admin"', $out);
        self::assertSame(401, $this->call('GET', '/api/me')->status, 'existing sessions are revoked');
        $this->cookies = [];
        self::assertSame(401, $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'])->status);
        self::assertSame(200, $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'a brand new password'])->status);
    }

    public function testCliCreatesTheAdminWhenNoneExists(): void
    {
        [$code] = $this->runPasswordCommand(['ops'], "a brand new password\na brand new password\n");

        self::assertSame(0, $code);
        self::assertSame(['needs_setup' => false], $this->json($this->call('GET', '/api/setup/status')));
        self::assertSame(200, $this->call('POST', '/api/login', ['username' => 'ops', 'password' => 'a brand new password'])->status);
    }

    public function testCliRejectsMismatchedOrShortPasswords(): void
    {
        $this->setUpAdmin();

        self::assertSame(1, $this->runPasswordCommand([], "a brand new password\nsomething else here\n")[0]);
        self::assertSame(1, $this->runPasswordCommand([], "short\nshort\n")[0]);
        $this->cookies = [];
        self::assertSame(200, $this->call('POST', '/api/login', ['username' => 'admin', 'password' => 'correct horse battery'])->status);
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function runPasswordCommand(array $args, string $stdin): array
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, $stdin);
        rewind($in);
        $out = fopen('php://memory', 'w+');
        $command = new UserPasswordCommand($this->app, $in);
        $code = $command->run($args, new Console($out, $out));
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }
}
