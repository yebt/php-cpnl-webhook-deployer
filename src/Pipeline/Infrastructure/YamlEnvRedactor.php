<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Infrastructure;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Hides `env` values (top level and per step) in a pipeline YAML before it is
 * exposed, e.g. as a run's pipeline snapshot. A document without env is
 * returned verbatim; one with env is re-dumped (comments and formatting are
 * lost) with every env value replaced by a placeholder.
 */
final class YamlEnvRedactor
{
    public const PLACEHOLDER = '[redacted]';
    private const NOTICE = "# env values redacted; formatting normalized.\n";

    public function redact(string $yaml): string
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException) {
            return $yaml; // never ran, so no env was ever exported from it
        }
        if (!is_array($data)) {
            return $yaml;
        }

        $found = false;
        $data = $this->redactEnvOf($data, $found);
        if (isset($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $i => $step) {
                if (is_array($step)) {
                    $data['steps'][$i] = $this->redactEnvOf($step, $found);
                }
            }
        }

        return $found
            ? self::NOTICE . Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
            : $yaml;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function redactEnvOf(array $node, bool &$found): array
    {
        if (!array_key_exists('env', $node) || $node['env'] === null || $node['env'] === []) {
            return $node;
        }
        $found = true;
        $node['env'] = is_array($node['env'])
            ? array_map(static fn (): string => self::PLACEHOLDER, $node['env'])
            : self::PLACEHOLDER;

        return $node;
    }
}
