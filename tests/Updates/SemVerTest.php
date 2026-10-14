<?php

declare(strict_types=1);

namespace Deployer\Tests\Updates;

use Deployer\Updates\Domain\SemVer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemVerTest extends TestCase
{
    public function testParsesTagsWithOrWithoutLeadingV(): void
    {
        self::assertSame('1.2.3', (string) SemVer::parse('v1.2.3'));
        self::assertSame('1.2.3-rc.1', (string) SemVer::parse('1.2.3-rc.1'));
        self::assertTrue(SemVer::parse('1.2.3-rc.1')->isPrerelease());
        self::assertFalse(SemVer::parse('1.2.3')->isPrerelease());
    }

    public function testRejectsNonSemverStrings(): void
    {
        self::assertNull(SemVer::tryParse('dev'));
        self::assertNull(SemVer::tryParse('1.2'));
        self::assertNull(SemVer::tryParse('latest'));
        self::assertNull(SemVer::tryParse('01.2.3'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function comparisons(): iterable
    {
        yield 'patch' => ['1.0.1', '1.0.0', 1];
        yield 'minor beats patch' => ['1.1.0', '1.0.9', 1];
        yield 'numeric not lexical' => ['1.10.0', '1.9.0', 1];
        yield 'equal' => ['2.0.0', 'v2.0.0', 0];
        yield 'release beats its prerelease' => ['1.0.0', '1.0.0-rc.1', 1];
        yield 'numeric prerelease ids' => ['1.0.0-rc.10', '1.0.0-rc.2', 1];
        yield 'alphanumeric beats numeric id' => ['1.0.0-alpha.beta', '1.0.0-alpha.1', 1];
        yield 'longer prerelease wins on tie' => ['1.0.0-alpha.1', '1.0.0-alpha', 1];
        yield 'older major' => ['1.9.9', '2.0.0', -1];
    }

    #[DataProvider('comparisons')]
    public function testComparesBySemverPrecedence(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, SemVer::parse($a)->compare(SemVer::parse($b)));
        self::assertSame(-$expected, SemVer::parse($b)->compare(SemVer::parse($a)));
    }
}
