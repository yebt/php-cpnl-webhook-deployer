<?php

declare(strict_types=1);

namespace Deployer\Tests\Updates;

use Deployer\Tests\Support\FakeHttpClient;
use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Domain\ReleaseAsset;
use Deployer\Updates\Infrastructure\GitHubReleaseSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GitHubReleaseSourceTest extends TestCase
{
    private const API = 'https://api.github.com/repos/acme/deployer';
    private const TOKEN = 'ghp_supersecrettoken123';

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function source(?string $token = null, string $apiBase = 'https://api.github.com'): GitHubReleaseSource
    {
        return new GitHubReleaseSource($this->http, 'acme/deployer', $token, 'deployer/1.0.0', $apiBase);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function release(string $tag, array $overrides = []): array
    {
        return [
            'tag_name' => $tag,
            'name' => 'Release ' . $tag,
            'html_url' => 'https://github.com/acme/deployer/releases/tag/' . $tag,
            'body' => "## Changes\n- things",
            'published_at' => '2026-10-01T12:00:00Z',
            'draft' => false,
            'prerelease' => str_contains($tag, '-'),
            'assets' => [
                ['name' => 'deployer.phar', 'url' => self::API . '/releases/assets/11', 'browser_download_url' => 'https://github.com/x', 'size' => 1200],
                ['name' => 'deployer.phar.sha256', 'url' => self::API . '/releases/assets/12', 'size' => 80],
                ['name' => 'deployer.htaccess', 'url' => self::API . '/releases/assets/13', 'size' => 300],
            ],
            ...$overrides,
        ];
    }

    public function testFetchesTheLatestReleaseWithItsAssets(): void
    {
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode(self::release('v1.4.0'), JSON_THROW_ON_ERROR));

        $release = $this->source()->latest(false);

        self::assertNotNull($release);
        self::assertSame('v1.4.0', $release->tag);
        self::assertSame('1.4.0', (string) $release->version);
        self::assertSame('https://github.com/acme/deployer/releases/tag/v1.4.0', $release->htmlUrl);
        self::assertStringContainsString('things', $release->notes);
        self::assertSame(self::API . '/releases/assets/11', $release->phar?->url);
        self::assertSame(self::API . '/releases/assets/12', $release->checksum?->url);

        $headers = $this->http->gets[0]['headers'];
        self::assertSame('deployer/1.0.0', $headers['User-Agent']);
        self::assertSame('application/vnd.github+json', $headers['Accept']);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testSendsTheTokenToTheApiWhenConfigured(): void
    {
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode(self::release('v1.4.0'), JSON_THROW_ON_ERROR));

        $this->source(self::TOKEN)->latest(false);

        self::assertSame('Bearer ' . self::TOKEN, $this->http->gets[0]['headers']['Authorization']);
    }

    public function testNoPublishedReleaseMeansNull(): void
    {
        self::assertNull($this->source()->latest(false));
    }

    public function testReleaseWithoutTheArtifactHasNoPharAsset(): void
    {
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode(self::release('v1.4.0', ['assets' => []]), JSON_THROW_ON_ERROR));

        $release = $this->source()->latest(false);

        self::assertNull($release?->phar);
        self::assertNull($release?->checksum);
    }

    public function testApiErrorsAreReportedWithoutLeakingTheToken(): void
    {
        $this->http->onGet(self::API . '/releases/latest', 403, '{"message":"API rate limit exceeded for ' . self::TOKEN . '"}');

        try {
            $this->source(self::TOKEN)->latest(false);
            self::fail('expected UpdateFailed');
        } catch (UpdateFailed $e) {
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringContainsString('rate limit', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testTransportErrorsDoNotLeakTheToken(): void
    {
        $this->http->failGet(self::API . '/releases/latest', 'Could not resolve host with ' . self::TOKEN);

        $this->expectException(UpdateFailed::class);
        $this->expectExceptionMessageMatches('/^(?!.*' . self::TOKEN . ').*Could not resolve host/');
        $this->source(self::TOKEN)->latest(false);
    }

    public function testNonSemverLatestTagIsAnError(): void
    {
        $this->http->onGet(self::API . '/releases/latest', 200, json_encode(self::release('nightly'), JSON_THROW_ON_ERROR));

        $this->expectException(UpdateFailed::class);
        $this->expectExceptionMessage('nightly');
        $this->source()->latest(false);
    }

    public function testWithPrereleasesPicksTheHighestPublishedVersion(): void
    {
        $this->http->onGet(self::API . '/releases?per_page=30', 200, json_encode([
            self::release('v1.4.0'),
            self::release('v2.0.0-rc.1'),
            self::release('v3.0.0', ['draft' => true]),
            self::release('nightly'),
            self::release('v1.5.0'),
        ], JSON_THROW_ON_ERROR));

        self::assertSame('2.0.0-rc.1', (string) $this->source()->latest(true)?->version);
    }

    public function testDownloadFollowsRedirectsToAssetStorageWithoutTheToken(): void
    {
        $storage = 'https://objects.githubusercontent.com/github-production-release-asset/1?sig=abc';
        $this->http->onGet(self::API . '/releases/assets/11', 302, '', ['Location' => $storage]);
        $this->http->onGet($storage, 200, 'PHAR-BYTES');

        $bytes = $this->source(self::TOKEN)->download(new ReleaseAsset('deployer.phar', self::API . '/releases/assets/11', 10), 1000);

        self::assertSame('PHAR-BYTES', $bytes);
        self::assertSame('application/octet-stream', $this->http->gets[0]['headers']['Accept']);
        self::assertSame('Bearer ' . self::TOKEN, $this->http->gets[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('Authorization', $this->http->gets[1]['headers'], 'the token never leaves api.github.com');
        self::assertSame(1000, $this->http->gets[1]['maxBytes']);
    }

    public function testDownloadRefusesRedirectsToUnexpectedHostsOrPlainHttp(): void
    {
        foreach (['https://evil.example.com/x', 'http://objects.githubusercontent.com/x'] as $target) {
            $this->http->onGet(self::API . '/releases/assets/11', 302, '', ['Location' => $target]);
            try {
                $this->source()->download(new ReleaseAsset('deployer.phar', self::API . '/releases/assets/11', 10), 1000);
                self::fail('expected UpdateFailed for ' . $target);
            } catch (UpdateFailed $e) {
                self::assertStringContainsString('not allowed', $e->getMessage());
            }
        }
    }

    public function testDownloadRefusesAssetUrlsOutsideTheApi(): void
    {
        $this->expectException(UpdateFailed::class);
        $this->source()->download(new ReleaseAsset('deployer.phar', 'https://evil.example.com/deployer.phar', 10), 1000);
    }

    public function testDownloadStopsAfterTooManyRedirects(): void
    {
        $this->http->onGet(self::API . '/releases/assets/11', 302, '', ['Location' => self::API . '/releases/assets/11']);

        $this->expectException(UpdateFailed::class);
        $this->expectExceptionMessage('redirects');
        $this->source()->download(new ReleaseAsset('deployer.phar', self::API . '/releases/assets/11', 10), 1000);
    }

    public function testApiBaseMustBeGitHubOverHttpsOrLoopback(): void
    {
        $this->source(null, 'https://127.0.0.1:8443');
        $this->source(null, 'http://localhost:8080');

        $this->expectException(InvalidArgumentException::class);
        $this->source(null, 'http://api.github.com');
    }

    public function testRepositoryMustBeOwnerSlashName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GitHubReleaseSource($this->http, 'acme/../../x', null, 'ua');
    }
}
