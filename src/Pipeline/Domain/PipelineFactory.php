<?php

declare(strict_types=1);

namespace Deployer\Pipeline\Domain;

/**
 * Builds a validated Pipeline from decoded (format-agnostic) data.
 *
 * Interpolation rules:
 *  - `${NAME}` is replaced by a var or built-in; an unknown name is an error.
 *  - vars are resolved in declaration order and may only reference earlier vars
 *    or built-ins (no forward or self references, hence no cycles).
 *  - `$${NAME}` is an escape that yields a literal `${NAME}` for the shell.
 *  - `$NAME` without braces is left untouched for the shell.
 */
final class PipelineFactory
{
    private const TOP_LEVEL_KEYS = ['vars', 'steps'];
    private const STEP_KEYS = ['name', 'run', 'timeout', 'continue_on_error', 'strict'];
    private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const PLACEHOLDER = '/\$?\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /** @throws PipelineValidationError */
    public function create(mixed $data, BuiltinVars $builtins): Pipeline
    {
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new PipelineValidationError(['The pipeline must be a mapping with a "steps" key.']);
        }

        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = sprintf('Unknown top-level key "%s"; allowed keys: %s.', $key, implode(', ', self::TOP_LEVEL_KEYS));
            }
        }

        $vars = $this->resolveVars($data['vars'] ?? [], $builtins, $errors);
        $scope = $builtins->toArray() + $vars;
        $steps = $this->buildSteps($data, $scope, $errors);

        if ($errors !== []) {
            throw new PipelineValidationError($errors);
        }

        return new Pipeline($steps, $vars);
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string>
     */
    private function resolveVars(mixed $raw, BuiltinVars $builtins, array &$errors): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            $errors[] = '"vars" must be a mapping of NAME: value.';

            return [];
        }

        $positions = array_flip(array_map('strval', array_keys($raw)));
        $resolved = [];
        foreach ($raw as $name => $value) {
            $name = (string) $name;
            $position = $positions[$name];
            $label = 'vars.' . $name;
            if (!preg_match(self::NAME_PATTERN, $name)) {
                $errors[] = sprintf('vars: invalid name "%s"; use letters, digits and underscores, not starting with a digit.', $name);
                continue;
            }
            if (in_array($name, BuiltinVars::NAMES, true)) {
                $errors[] = sprintf('%s: "%s" is a built-in variable and cannot be redefined.', $label, $name);
                continue;
            }
            if (!is_scalar($value)) {
                $errors[] = sprintf('%s: value must be a scalar (string, number or boolean).', $label);
                continue;
            }

            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $resolved[$name] = $this->interpolate(
                $string,
                $builtins->toArray() + $resolved,
                $label,
                $errors,
                static function (string $ref) use ($name, $position, $positions): ?string {
                    if ($ref === $name) {
                        return sprintf('"%s" references itself.', $name);
                    }
                    if (($positions[$ref] ?? -1) > $position) {
                        return sprintf('"%s" is declared after "%s"; vars may only reference earlier vars.', $ref, $name);
                    }
                    if (isset($positions[$ref])) {
                        return sprintf('"%s" is invalid and cannot be referenced.', $ref);
                    }

                    return null;
                },
            );
        }

        return $resolved;
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $scope
     * @param list<string>          $errors
     *
     * @return list<Step>
     */
    private function buildSteps(array $data, array $scope, array &$errors): array
    {
        if (!array_key_exists('steps', $data)) {
            $errors[] = '"steps" is required.';

            return [];
        }
        $raw = $data['steps'];
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            $errors[] = '"steps" must be a non-empty list.';

            return [];
        }

        $steps = [];
        foreach ($raw as $index => $step) {
            $label = sprintf('steps[%d]', $index);
            if (!is_array($step) || ($step !== [] && array_is_list($step))) {
                $errors[] = $label . ': must be a mapping with "name" and "run".';
                continue;
            }

            $name = $step['name'] ?? null;
            $validName = is_string($name) && trim($name) !== '';
            if ($validName) {
                $label .= sprintf(' (%s)', $name);
            } else {
                $errors[] = $label . ': "name" is required and must be a non-empty string.';
            }

            $before = count($errors);
            foreach (array_keys($step) as $key) {
                if (!in_array($key, self::STEP_KEYS, true)) {
                    $errors[] = sprintf('%s: unknown key "%s"; allowed keys: %s.', $label, $key, implode(', ', self::STEP_KEYS));
                }
            }

            $run = $step['run'] ?? null;
            if (!is_string($run) || trim($run) === '') {
                $errors[] = $label . ': "run" is required and must be a non-empty string.';
                $run = null;
            }

            $timeout = $step['timeout'] ?? Pipeline::DEFAULT_TIMEOUT;
            if (!is_int($timeout) || $timeout <= 0) {
                $errors[] = $label . ': "timeout" must be a positive integer (seconds).';
            }

            $continueOnError = $step['continue_on_error'] ?? false;
            if (!is_bool($continueOnError)) {
                $errors[] = $label . ': "continue_on_error" must be a boolean.';
            }

            $strict = $step['strict'] ?? true;
            if (!is_bool($strict)) {
                $errors[] = $label . ': "strict" must be a boolean.';
            }

            $command = $run === null ? '' : $this->interpolate($run, $scope, $label, $errors);

            if ($validName && count($errors) === $before) {
                $steps[] = new Step(trim($name), $command, $timeout, $continueOnError, $strict);
            }
        }

        return $steps;
    }

    /**
     * @param array<string, string>            $scope
     * @param list<string>                     $errors
     * @param (callable(string): ?string)|null $explainMissing
     */
    private function interpolate(string $value, array $scope, string $label, array &$errors, ?callable $explainMissing = null): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($scope, $label, &$errors, $explainMissing): string {
            if (str_starts_with($m[0], '$$')) {
                return substr($m[0], 1);
            }
            $ref = $m[1];
            if (array_key_exists($ref, $scope)) {
                return $scope[$ref];
            }
            $reason = $explainMissing !== null ? $explainMissing($ref) : null;
            $errors[] = sprintf('%s: %s', $label, $reason ?? sprintf('unknown variable "%s".', $ref));

            return $m[0];
        }, $value);
    }
}
