<?php

declare(strict_types=1);

namespace Deployer\Tests\Pipeline;

use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\Pipeline;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Deployer\Pipeline\Infrastructure\YamlEnvRedactor;
use Deployer\Pipeline\Infrastructure\YamlPipelineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PipelineActionsTest extends TestCase
{
    private function parse(string $yaml): Pipeline
    {
        return (new YamlPipelineParser())->parse($yaml, new BuiltinVars('shop', 42, '/home/user/apps/shop'));
    }

    /** @return list<string> */
    private function errorsOf(string $yaml): array
    {
        try {
            $this->parse($yaml);
        } catch (PipelineValidationError $e) {
            return $e->errors();
        }
        self::fail('Expected PipelineValidationError');
    }

    public function testPipelineWithoutActionsHasNone(): void
    {
        self::assertSame([], $this->parse("steps:\n  - {name: a, run: b}")->actions);
    }

    public function testParsesActionsInDeclarationOrder(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            vars:
              APP: ${PROJECT_DIR}/app
            env:
              APP_ENV: production
              LEVEL: global
            steps:
              - name: Deploy
                run: git pull
            actions:
              clear-cache:
                name: Clear cache
                description: Empties the application cache.
                steps:
                  - name: Clear
                    run: php ${APP}/artisan cache:clear
                    env:
                      LEVEL: step
              rebuild_cache:
                steps:
                  - name: Warm up
                    run:
                      - php artisan config:cache
                      - php artisan route:cache
                    timeout: 120
                    continue_on_error: true
                    strict: false
            YAML);

        self::assertSame(['clear-cache', 'rebuild_cache'], array_keys($pipeline->actions));

        $clear = $pipeline->actions['clear-cache'];
        self::assertSame('clear-cache', $clear->id);
        self::assertSame('Clear cache', $clear->name);
        self::assertSame('Empties the application cache.', $clear->description);
        self::assertCount(1, $clear->steps);
        self::assertSame('php /home/user/apps/shop/app/artisan cache:clear', $clear->steps[0]->command);
        self::assertSame(['APP_ENV' => 'production', 'LEVEL' => 'step'], $clear->steps[0]->env);

        $rebuild = $pipeline->actions['rebuild_cache'];
        self::assertSame('rebuild_cache', $rebuild->name, 'the name defaults to the id');
        self::assertNull($rebuild->description);
        self::assertSame(['php artisan config:cache', 'php artisan route:cache'], $rebuild->steps[0]->commands);
        self::assertSame(120, $rebuild->steps[0]->timeout);
        self::assertTrue($rebuild->steps[0]->continueOnError);
        self::assertFalse($rebuild->steps[0]->strict);
        self::assertSame(['APP_ENV' => 'production', 'LEVEL' => 'global'], $rebuild->steps[0]->env);

        self::assertCount(1, $pipeline->steps, 'the deploy steps are unchanged');
    }

    public function testEmptyActionsMappingIsAllowed(): void
    {
        self::assertSame([], $this->parse("steps:\n  - {name: a, run: b}\nactions: {}")->actions);
        self::assertSame([], $this->parse("steps:\n  - {name: a, run: b}\nactions: ~")->actions);
    }

    public function testDeployStepsStayRequiredWhenActionsExist(): void
    {
        $errors = $this->errorsOf("actions:\n  x:\n    steps:\n      - {name: a, run: b}");

        self::assertContains('"steps" is required.', $errors);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidActions(): iterable
    {
        $deploy = "steps:\n  - {name: a, run: b}\n";
        yield 'actions is a list' => [$deploy . "actions: [x]", '"actions" must be a mapping of id: action.'];
        yield 'actions is a scalar' => [$deploy . "actions: nope", '"actions" must be a mapping of id: action.'];
        yield 'invalid id' => [$deploy . "actions:\n  Bad Id:\n    steps: [{name: a, run: b}]", 'actions: invalid id "Bad Id"'];
        yield 'id starting with dash' => [$deploy . "actions:\n  -x:\n    steps: [{name: a, run: b}]", 'actions: invalid id "-x"'];
        yield 'id too long' => [$deploy . "actions:\n  " . str_repeat('a', 65) . ":\n    steps: [{name: a, run: b}]", 'actions: invalid id "' . str_repeat('a', 65) . '"'];
        yield 'action not a mapping' => [$deploy . "actions:\n  x: echo hi", 'actions.x: must be a mapping with "steps".'];
        yield 'unknown action key' => [$deploy . "actions:\n  x:\n    run: b\n    steps: [{name: a, run: b}]", 'actions.x: unknown key "run"; allowed keys: name, description, steps.'];
        yield 'name not a string' => [$deploy . "actions:\n  x:\n    name: [1]\n    steps: [{name: a, run: b}]", 'actions.x: "name" must be a non-empty string.'];
        yield 'empty name' => [$deploy . "actions:\n  x:\n    name: ' '\n    steps: [{name: a, run: b}]", 'actions.x: "name" must be a non-empty string.'];
        yield 'description not a string' => [$deploy . "actions:\n  x:\n    description: [1]\n    steps: [{name: a, run: b}]", 'actions.x: "description" must be a string.'];
        yield 'missing steps' => [$deploy . "actions:\n  x:\n    name: X", 'actions.x: "steps" is required.'];
        yield 'empty steps' => [$deploy . "actions:\n  x:\n    steps: []", 'actions.x: "steps" must be a non-empty list.'];
        yield 'step without run' => [$deploy . "actions:\n  x:\n    steps: [{name: a}]", 'actions.x.steps[0] (a): "run" is required'];
        yield 'step unknown key' => [$deploy . "actions:\n  x:\n    steps: [{name: a, run: b, when: c}]", 'actions.x.steps[0] (a): unknown key "when"'];
        yield 'step unknown var' => [$deploy . "actions:\n  x:\n    steps: [{name: a, run: 'echo \${NOPE}'}]", 'actions.x.steps[0] (a): unknown variable "NOPE"'];
        yield 'step env unknown var' => [$deploy . "actions:\n  x:\n    steps: [{name: a, run: b, env: {A: '\${NOPE}'}}]", 'actions.x.steps[0] (a): env.A: unknown variable "NOPE"'];
        yield 'step bad timeout' => [$deploy . "actions:\n  x:\n    steps: [{name: a, run: b, timeout: 0}]", 'actions.x.steps[0] (a): "timeout" must be a positive integer'];
    }

    #[DataProvider('invalidActions')]
    public function testRejectsInvalidActions(string $yaml, string $expected): void
    {
        $errors = $this->errorsOf($yaml);

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, $expected)),
            sprintf('Expected an error containing "%s", got: %s', $expected, implode(' | ', $errors)));
    }

    public function testRedactorHidesActionEnvValues(): void
    {
        $yaml = <<<'YAML'
            steps:
              - name: a
                run: b
            actions:
              x:
                steps:
                  - name: c
                    run: d
                    env:
                      SECRET: hunter2
            YAML;

        $redacted = (new YamlEnvRedactor())->redact($yaml);

        self::assertStringNotContainsString('hunter2', $redacted);
        self::assertStringContainsString(YamlEnvRedactor::PLACEHOLDER, $redacted);
    }
}
