<?php

declare(strict_types=1);

namespace Deployer\Updates\Infrastructure;

use Deployer\Shared\Application\Port\HttpClient;
use Deployer\Shared\Application\Port\HttpResponse;
use Deployer\Updates\Application\Port\ReleaseSource;
use Deployer\Updates\Application\UpdateFailed;
use Deployer\Updates\Domain\Release;
use Deployer\Updates\Domain\ReleaseAsset;
use Deployer\Updates\Domain\SemVer;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * GitHub Releases adapter.
 *
 * Security rules:
 *  - every URL (API, asset, redirect target) must be https on an allow-listed
 *    GitHub host; redirects are followed manually so each hop is checked;
 *  - the optional token is only ever sent to the API host, never to the asset
 *    storage it redirects to, and is redacted from every error message.
 *
 * A loopback API base (http(s)://127.0.0.1|localhost|[::1]) is accepted for local
 * simulations only; then that origin is the only allowed host.
 */
final class GitHubReleaseSource implements ReleaseSource
{
    public const DEFAULT_API = 'https://api.github.com';
    /** @var list<string> */
    private const GITHUB_HOSTS = ['api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];
    private const API_TIMEOUT = 15;
    private const DOWNLOAD_TIMEOUT = 120;
    private const MAX_API_BYTES = 2 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;

    private readonly string $apiBase;
    /** @var list<string> allowed origins as "scheme://host[:port]" */
    private readonly array $allowedOrigins;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $repository,
        #[SensitiveParameter] private readonly ?string $token,
        private readonly string $userAgent,
        string $apiBase = self::DEFAULT_API,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1 || str_contains($repository, '..')) {
            throw new InvalidArgumentException(sprintf('Update repository must look like "owner/name", got "%s".', $repository));
        }
        $apiBase = rtrim($apiBase, '/');
        if ($apiBase === self::DEFAULT_API) {
            $this->allowedOrigins = array_map(static fn (string $h): string => 'https://' . $h, self::GITHUB_HOSTS);
        } elseif (($origin = self::loopbackOrigin($apiBase)) !== null && $origin === $apiBase) {
            $this->allowedOrigins = [$origin];
        } else {
            throw new InvalidArgumentException(sprintf('Update API must be %s (or a loopback origin for local tests), got "%s".', self::DEFAULT_API, $apiBase));
        }
        $this->apiBase = $apiBase;
    }

    public function latest(bool $includePrereleases): ?Release
    {
        $base = sprintf('%s/repos/%s', $this->apiBase, $this->repository);
        if (!$includePrereleases) {
            $response = $this->api($base . '/releases/latest');
            if ($response->status === 404) {
                return null;
            }

            return $this->toRelease($this->decode($response), true);
        }

        // /releases/latest never returns prereleases: pick the highest published version instead.
        $best = null;
        foreach ($this->decode($this->api($base . '/releases?per_page=30')) as $item) {
            if (!is_array($item) || ($item['draft'] ?? false) === true) {
                continue;
            }
            $release = $this->toRelease($item, false);
            if ($release !== null && ($best === null || $release->version->compare($best->version) > 0)) {
                $best = $release;
            }
        }

        return $best;
    }

    public function download(ReleaseAsset $asset, int $maxBytes): string
    {
        $url = $asset->url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $origin = $this->assertAllowed($url);
            $headers = ['Accept' => 'application/octet-stream', 'User-Agent' => $this->userAgent];
            if ($origin === $this->apiBase) {
                $headers += $this->authHeader();
            }
            $response = $this->send($url, $headers, self::DOWNLOAD_TIMEOUT, $maxBytes);
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $url = (string) $response->header('location');
                continue;
            }
            if ($response->status !== 200) {
                throw new UpdateFailed($this->redact(sprintf('Downloading %s failed with HTTP %d.', $asset->name, $response->status)));
            }

            return $response->body;
        }

        throw new UpdateFailed(sprintf('Downloading %s failed: too many redirects.', $asset->name));
    }

    private function api(string $url): HttpResponse
    {
        $response = $this->send($url, [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => $this->userAgent,
        ] + $this->authHeader(), self::API_TIMEOUT, self::MAX_API_BYTES);
        if ($response->status !== 200 && $response->status !== 404) {
            $data = json_decode($response->body, true);
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'unexpected response';

            throw new UpdateFailed($this->redact(sprintf('GitHub API error %d: %s', $response->status, $message)));
        }

        return $response;
    }

    /** @param array<string,string> $headers */
    private function send(string $url, array $headers, int $timeout, int $maxBytes): HttpResponse
    {
        try {
            return $this->http->get($url, $headers, $timeout, $maxBytes);
        } catch (Throwable $e) {
            throw new UpdateFailed($this->redact('Cannot reach GitHub: ' . $e->getMessage()));
        }
    }

    /** @return array<mixed> */
    private function decode(HttpResponse $response): array
    {
        $data = json_decode($response->body, true);

        return is_array($data) ? $data : throw new UpdateFailed('GitHub API returned malformed JSON.');
    }

    /** @param array<mixed> $data */
    private function toRelease(array $data, bool $strict): ?Release
    {
        $tag = is_string($data['tag_name'] ?? null) ? $data['tag_name'] : '';
        $version = SemVer::tryParse($tag);
        if ($version === null) {
            return $strict ? throw new UpdateFailed(sprintf('Latest release tag "%s" is not a semantic version.', $tag)) : null;
        }
        $assets = [];
        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['url'] ?? null)) {
                $assets[$asset['name']] = new ReleaseAsset($asset['name'], $asset['url'], (int) ($asset['size'] ?? 0));
            }
        }

        return new Release(
            tag: $tag,
            version: $version,
            name: is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : $tag,
            htmlUrl: is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
            notes: is_string($data['body'] ?? null) ? $data['body'] : '',
            publishedAt: is_string($data['published_at'] ?? null) ? $data['published_at'] : null,
            phar: $assets[Release::PHAR_ASSET] ?? null,
            checksum: $assets[Release::CHECKSUM_ASSET] ?? null,
        );
    }

    /** @return string the URL's origin, when allowed */
    private function assertAllowed(string $url): string
    {
        $parts = parse_url($url);
        $origin = is_array($parts) && isset($parts['scheme'], $parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            ? strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';
        if (!in_array($origin, $this->allowedOrigins, true)) {
            throw new UpdateFailed(sprintf('Download URL host is not allowed: %s', $origin !== '' ? $origin : 'invalid URL'));
        }

        return $origin;
    }

    private static function loopbackOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['path']) || isset($parts['query'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($host, self::LOOPBACK_HOSTS, true)
            ? $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;
    }

    /** @return array<string,string> */
    private function authHeader(): array
    {
        return $this->token !== null && $this->token !== '' ? ['Authorization' => 'Bearer ' . $this->token] : [];
    }

    private function redact(string $message): string
    {
        return $this->token !== null && strlen($this->token) >= 4 ? str_replace($this->token, '***', $message) : $message;
    }
}
