<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Shared\Infrastructure\Application;
use Deployer\Shared\Infrastructure\Config\AppConfig;
use Deployer\Tests\Support\ApiTestCase;
use Deployer\Tests\Support\TinyPhar;

final class UpdatesApiTest extends ApiTestCase
{
    private const API = 'https://api.github.com/repos/yebt/php-cpnl-webhook-deployer';

    private string $releaseBytes = '';

    /** Rebuilds the app as an installed phar at $path running $version. */
    private function runAsPhar(string $path, string $version): void
    {
        $this->app = new Application(
            new AppConfig($this->makeTempDir(), PHP_BINARY, $path, true),
            $this->clock,
            $this->launcher,
            $this->http,
            version: $version,
        );
    }

    private function publish(string $tag, ?string $checksum = null, string $body = 'Bug fixes.'): void
    {
        if ($this->releaseBytes === '') {
            $release = $this->makeTempDir() . '/deployer.phar';
            TinyPhar::build($release, ltrim($tag, 'v'));
            $this->releaseBytes = (string) file_get_contents($release);
        }
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode([
            'tag_name' => $tag,
            'name' => 'Deployer ' . $tag,
            'html_url' => 'https://github.com/yebt/php-cpnl-webhook-deployer/releases/tag/' . $tag,
            'body' => $body,
            'published_at' => '2026-10-01T00:00:00Z',
            'draft' => false,
            'prerelease' => false,
            'assets' => [
                ['name' => 'deployer.phar', 'url' => self::API . '/releases/assets/1', 'size' => strlen($this->releaseBytes)],
                ['name' => 'deployer.phar.sha256', 'url' => self::API . '/releases/assets/2', 'size' => 80],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->http->onGet(self::API . '/releases/assets/1', 200, $this->releaseBytes);
        $this->http->onGet(self::API . '/releases/assets/2', 200, ($checksum ?? hash('sha256', $this->releaseBytes)) . "  deployer.phar\n");
    }

    public function testEndpointsRequireASession(): void
    {
        self::assertSame(401, $this->call('GET', '/api/updates')->status);
        self::assertSame(401, $this->call('POST', '/api/updates/apply')->status);
    }

    public function testApplyRequiresTheCsrfToken(): void
    {
        $this->setUpAdmin();

        $response = $this->call('POST', '/api/updates/apply', headers: ['x-csrf-token' => 'wrong']);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->http->gets, 'nothing is fetched without a valid token');
    }

    public function testReportsCurrentAndLatestVersions(): void
    {
        $installed = $this->makeTempDir() . '/index.php';
        TinyPhar::build($installed, '1.0.0');
        $this->runAsPhar($installed, '1.0.0');
        $this->setUpAdmin();
        $this->publish('v1.1.0', body: str_repeat('Long notes. ', 200));
        $project = $this->createProjectViaApi('site');
        $this->call('POST', '/api/projects/' . $project['id'] . '/runs');

        $data = $this->json($this->call('GET', '/api/updates'));

        self::assertSame('1.0.0', $data['current']);
        self::assertFalse($data['current_is_dev']);
        self::assertTrue($data['available']);
        self::assertTrue($data['can_apply']);
        self::assertSame(1, $data['active_runs']);
        self::assertSame('1.1.0', $data['latest']['version']);
        self::assertSame('https://github.com/yebt/php-cpnl-webhook-deployer/releases/tag/v1.1.0', $data['latest']['url']);
        self::assertSame('2026-10-01T00:00:00Z', $data['latest']['published_at']);
        self::assertLessThanOrEqual(601, mb_strlen($data['latest']['notes_excerpt']));
        self::assertStringEndsWith('…', $data['latest']['notes_excerpt']);
    }

    public function testSourceCheckoutCannotApply(): void
    {
        $this->setUpAdmin();
        $this->publish('v1.1.0');

        $data = $this->json($this->call('GET', '/api/updates'));
        self::assertSame('dev', $data['current']);
        self::assertTrue($data['current_is_dev']);
        self::assertFalse($data['can_apply']);

        $response = $this->call('POST', '/api/updates/apply');
        self::assertSame(409, $response->status);
        self::assertSame('not_running_from_phar', $this->json($response)['error']['code']);
    }

    public function testGitHubFailuresAreReportedAsBadGateway(): void
    {
        $this->setUpAdmin();
        $this->http->failGet(self::API . '/releases/latest', 'Could not resolve host');

        $response = $this->call('GET', '/api/updates');

        self::assertSame(502, $response->status);
        self::assertSame('update_check_failed', $this->json($response)['error']['code']);
    }

    public function testAppliesTheUpdate(): void
    {
        $installed = $this->makeTempDir() . '/index.php';
        TinyPhar::build($installed, '1.0.0');
        $this->runAsPhar($installed, '1.0.0');
        $this->setUpAdmin();
        $this->publish('v1.1.0');

        $response = $this->call('POST', '/api/updates/apply');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(['updated' => true, 'from' => '1.0.0', 'to' => '1.1.0', 'backup_file' => 'index.php.bak'], $this->json($response));
        self::assertSame($this->releaseBytes, file_get_contents($installed));
        self::assertFileExists($installed . '.bak');
    }

    public function testChecksumMismatchIsRefused(): void
    {
        $installed = $this->makeTempDir() . '/index.php';
        TinyPhar::build($installed, '1.0.0');
        $before = (string) file_get_contents($installed);
        $this->runAsPhar($installed, '1.0.0');
        $this->setUpAdmin();
        $this->publish('v1.1.0', str_repeat('f', 64));

        $response = $this->call('POST', '/api/updates/apply');

        self::assertSame(409, $response->status);
        self::assertSame('checksum_mismatch', $this->json($response)['error']['code']);
        self::assertSame($before, file_get_contents($installed));
    }

    public function testDevPharBuildsAreRefusedOverTheApi(): void
    {
        $installed = $this->makeTempDir() . '/index.php';
        TinyPhar::build($installed, 'dev');
        $this->runAsPhar($installed, 'dev');
        $this->setUpAdmin();
        $this->publish('v1.1.0');

        $response = $this->call('POST', '/api/updates/apply', ['force' => true]);

        self::assertSame(409, $response->status);
        self::assertSame('dev_build_requires_force', $this->json($response)['error']['code']);
    }
}
