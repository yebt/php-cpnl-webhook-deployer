<?php

declare(strict_types=1);

namespace Deployer\Tests\Updates;

use Deployer\Updates\Application\ApplyUpdate;
use Deployer\Updates\Application\CheckForUpdate;
use Deployer\Updates\Application\Port\PharInstaller;
use Deployer\Updates\Application\Port\ReleaseSource;
use Deployer\Updates\Application\UpdateRefused;
use Deployer\Updates\Domain\Release;
use Deployer\Updates\Domain\ReleaseAsset;
use Deployer\Updates\Domain\SemVer;
use PHPUnit\Framework\TestCase;

final class ApplyUpdateTest extends TestCase
{
    private const PHAR = 'NEW-PHAR-BYTES';

    private FakeSource $source;
    private FakeInstaller $installer;

    protected function setUp(): void
    {
        $this->source = new FakeSource();
        $this->installer = new FakeInstaller();
    }

    private function check(string $current, bool $prereleases = false): CheckForUpdate
    {
        return new CheckForUpdate($this->source, $current, $prereleases);
    }

    private function apply(string $current, string $runningPhar = '/srv/public_html/index.php'): ApplyUpdate
    {
        return new ApplyUpdate($this->check($current), $this->source, $this->installer, $runningPhar);
    }

    public function testReportsAnAvailableUpdate(): void
    {
        $this->source->release = FakeSource::release('1.1.0');

        $check = ($this->check('1.0.0'))();

        self::assertTrue($check->available);
        self::assertFalse($check->currentIsDev);
        self::assertSame('1.1.0', (string) $check->latest?->version);
    }

    public function testSameOrOlderReleaseIsNotAnUpdate(): void
    {
        $this->source->release = FakeSource::release('1.0.0');
        self::assertFalse(($this->check('1.0.0'))()->available);

        $this->source->release = FakeSource::release('0.9.0');
        self::assertFalse(($this->check('1.0.0'))()->available);
    }

    public function testNoReleaseMeansNoUpdate(): void
    {
        $check = ($this->check('1.0.0'))();

        self::assertNull($check->latest);
        self::assertFalse($check->available);
    }

    public function testDevBuildsAlwaysSeeTheLatestReleaseAsAvailable(): void
    {
        $this->source->release = FakeSource::release('0.1.0');

        $check = ($this->check('dev'))();

        self::assertTrue($check->available);
        self::assertTrue($check->currentIsDev);
    }

    public function testPassesThePrereleasePreferenceToTheSource(): void
    {
        ($this->check('1.0.0', true))();

        self::assertTrue($this->source->askedForPrereleases);
    }

    public function testInstallsAVerifiedRelease(): void
    {
        $this->source->release = FakeSource::release('1.1.0');

        $result = ($this->apply('1.0.0'))(false);

        self::assertTrue($result->updated);
        self::assertSame('1.0.0', $result->from);
        self::assertSame('1.1.0', $result->to);
        self::assertSame('/srv/public_html/index.php.bak', $result->backupPath);
        self::assertSame([['/srv/public_html/index.php', self::PHAR, '1.1.0']], $this->installer->installs);
    }

    public function testAlreadyUpToDateIsANoOp(): void
    {
        $this->source->release = FakeSource::release('1.0.0');

        $result = ($this->apply('1.0.0'))(false);

        self::assertFalse($result->updated);
        self::assertSame([], $this->installer->installs);
        self::assertSame([], $this->source->downloads);
    }

    public function testForceReinstallsTheSameVersion(): void
    {
        $this->source->release = FakeSource::release('1.0.0');

        self::assertTrue(($this->apply('1.0.0'))(true)->updated);
    }

    public function testNeverDowngradesEvenWithForce(): void
    {
        $this->source->release = FakeSource::release('0.9.0');

        $this->assertRefused(UpdateRefused::DOWNGRADE, fn () => ($this->apply('1.0.0'))(true));
    }

    public function testRefusesOutsideAPhar(): void
    {
        $this->source->release = FakeSource::release('1.1.0');

        $this->assertRefused(UpdateRefused::NOT_PHAR, fn () => ($this->apply('1.0.0', ''))(true));
        self::assertSame(0, $this->source->latestCalls, 'refuses before contacting GitHub');
    }

    public function testDevBuildsNeedForce(): void
    {
        $this->source->release = FakeSource::release('1.1.0');

        $this->assertRefused(UpdateRefused::DEV_BUILD, fn () => ($this->apply('dev'))(false));
        self::assertTrue(($this->apply('dev'))(true)->updated);
    }

    public function testRefusesWhenNoReleaseIsPublished(): void
    {
        $this->assertRefused(UpdateRefused::NO_RELEASE, fn () => ($this->apply('1.0.0'))(false));
    }

    public function testRefusesReleasesWithoutTheArtifactOrChecksum(): void
    {
        $this->source->release = FakeSource::release('1.1.0', withChecksum: false);

        $this->assertRefused(UpdateRefused::MISSING_ASSET, fn () => ($this->apply('1.0.0'))(false));
    }

    public function testRefusesWhenTheArtifactIsNotWritable(): void
    {
        $this->source->release = FakeSource::release('1.1.0');
        $this->installer->writable = false;

        $this->assertRefused(UpdateRefused::NOT_WRITABLE, fn () => ($this->apply('1.0.0'))(false));
        self::assertSame([], $this->source->downloads, 'nothing is downloaded when the swap cannot happen');
    }

    public function testChecksumMismatchAbortsBeforeInstalling(): void
    {
        $this->source->release = FakeSource::release('1.1.0');
        $this->source->checksumBody = str_repeat('a', 64) . "  deployer.phar\n";

        $this->assertRefused(UpdateRefused::CHECKSUM, fn () => ($this->apply('1.0.0'))(false));
        self::assertSame([], $this->installer->installs);
    }

    public function testMalformedChecksumFileAborts(): void
    {
        $this->source->release = FakeSource::release('1.1.0');
        $this->source->checksumBody = 'not a checksum';

        $this->assertRefused(UpdateRefused::CHECKSUM, fn () => ($this->apply('1.0.0'))(false));
    }

    public function testChecksumForAnotherFileAborts(): void
    {
        $this->source->release = FakeSource::release('1.1.0');
        $this->source->checksumBody = hash('sha256', self::PHAR) . "  other.phar\n";

        $this->assertRefused(UpdateRefused::CHECKSUM, fn () => ($this->apply('1.0.0'))(false));
    }

    public function testAcceptsBinaryModeChecksumLines(): void
    {
        $this->source->release = FakeSource::release('1.1.0');
        $this->source->checksumBody = strtoupper(hash('sha256', self::PHAR)) . " *deployer.phar\n";

        self::assertTrue(($this->apply('1.0.0'))(false)->updated);
    }

    private function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('expected UpdateRefused ' . $reason);
        } catch (UpdateRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}

final class FakeSource implements ReleaseSource
{
    public ?Release $release = null;
    public string $checksumBody;
    public bool $askedForPrereleases = false;
    public int $latestCalls = 0;
    /** @var list<string> */
    public array $downloads = [];

    public function __construct()
    {
        $this->checksumBody = hash('sha256', 'NEW-PHAR-BYTES') . "  deployer.phar\n";
    }

    public static function release(string $version, bool $withChecksum = true): Release
    {
        return new Release(
            tag: 'v' . $version,
            version: SemVer::parse($version),
            name: 'v' . $version,
            htmlUrl: 'https://github.com/acme/deployer/releases/tag/v' . $version,
            notes: 'notes',
            publishedAt: '2026-10-01T12:00:00Z',
            phar: new ReleaseAsset('deployer.phar', 'phar-url', 14),
            checksum: $withChecksum ? new ReleaseAsset('deployer.phar.sha256', 'sum-url', 80) : null,
        );
    }

    public function latest(bool $includePrereleases): ?Release
    {
        $this->latestCalls++;
        $this->askedForPrereleases = $includePrereleases;

        return $this->release;
    }

    public function download(ReleaseAsset $asset, int $maxBytes): string
    {
        $this->downloads[] = $asset->url;

        return $asset->url === 'sum-url' ? $this->checksumBody : 'NEW-PHAR-BYTES';
    }
}

final class FakeInstaller implements PharInstaller
{
    public bool $writable = true;
    /** @var list<array{string, string, string}> */
    public array $installs = [];

    public function assertWritable(string $target): void
    {
        if (!$this->writable) {
            throw new UpdateRefused(UpdateRefused::NOT_WRITABLE, 'not writable');
        }
    }

    public function install(string $target, string $bytes, string $expectedVersion): string
    {
        $this->installs[] = [$target, $bytes, $expectedVersion];

        return $target . '.bak';
    }
}
