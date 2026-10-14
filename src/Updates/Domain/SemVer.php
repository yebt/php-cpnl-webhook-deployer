<?php

declare(strict_types=1);

namespace Deployer\Updates\Domain;

use InvalidArgumentException;
use Stringable;

/** Semantic version (major.minor.patch[-prerelease]) with semver 2.0 precedence. */
final class SemVer implements Stringable
{
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

    /** @param list<string> $prerelease */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly array $prerelease,
    ) {
    }

    public static function tryParse(string $value): ?self
    {
        if (preg_match(self::PATTERN, trim($value), $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : []);
    }

    public static function parse(string $value): self
    {
        return self::tryParse($value) ?? throw new InvalidArgumentException(sprintf('"%s" is not a semantic version.', $value));
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== [];
    }

    /** @return int -1, 0 or 1 */
    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($core !== 0) {
            return $core;
        }
        // A release ranks above any of its prereleases.
        if ($this->prerelease === [] || $other->prerelease === []) {
            return match (true) {
                $this->prerelease === [] && $other->prerelease === [] => 0,
                $this->prerelease === [] => 1,
                default => -1,
            };
        }
        foreach ($this->prerelease as $i => $id) {
            if (!array_key_exists($i, $other->prerelease)) {
                return 1;
            }
            $cmp = self::compareIdentifier($id, $other->prerelease[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return count($this->prerelease) < count($other->prerelease) ? -1 : 0;
    }

    public function __toString(): string
    {
        $core = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        return $this->prerelease === [] ? $core : $core . '-' . implode('.', $this->prerelease);
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);
        if ($aNumeric && $bNumeric) {
            return (int) $a <=> (int) $b;
        }
        if ($aNumeric !== $bNumeric) {
            // Numeric identifiers have lower precedence than alphanumeric ones.
            return $aNumeric ? -1 : 1;
        }

        return strcmp($a, $b) <=> 0;
    }
}
