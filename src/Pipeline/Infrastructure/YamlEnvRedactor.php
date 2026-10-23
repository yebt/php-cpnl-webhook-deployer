<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Infrastructure;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Hides `env` values (top level, per step and per action step) in a pipeline YAML before it is
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
        $data = $this->redactStepsOf($data, $found);
        if (isset($data['actions']) && is_array($data['actions'])) {
            foreach ($data['actions'] as $id => $action) {
                if (is_array($action)) {
                    $data['actions'][$id] = $this->redactStepsOf($action, $found);
                }
            }
        }

        return $found
            ? self::NOTICE . Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
            : $yaml;
    }

    /**
     * @param array<mixed> $node a document or an action, each with a `steps` list
     *
     * @return array<mixed>
     */
    private function redactStepsOf(array $node, bool &$found): array
    {
        if (isset($node['steps']) && is_array($node['steps'])) {
            foreach ($node['steps'] as $i => $step) {
                if (is_array($step)) {
                    $node['steps'][$i] = $this->redactEnvOf($step, $found);
                }
            }
        }

        return $node;
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
