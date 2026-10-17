<?php

declare(strict_types=1);

namespace Deployer\Tests\Pipeline;

use Deployer\Pipeline\Domain\BuiltinVars;
use Deployer\Pipeline\Domain\Pipeline;
use Deployer\Pipeline\Domain\PipelineValidationError;
use Deployer\Pipeline\Infrastructure\YamlPipelineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlPipelineParserTest extends TestCase
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

    public function testParsesStepsWithDefaults(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            steps:
              - name: Fetch
                run: git fetch origin
              - name: Install
                run: composer install
                timeout: 600
                continue_on_error: true
            YAML);

        self::assertCount(2, $pipeline->steps);
        self::assertSame('Fetch', $pipeline->steps[0]->name);
        self::assertSame('git fetch origin', $pipeline->steps[0]->command);
        self::assertSame(Pipeline::DEFAULT_TIMEOUT, $pipeline->steps[0]->timeout);
        self::assertFalse($pipeline->steps[0]->continueOnError);
        self::assertSame(600, $pipeline->steps[1]->timeout);
        self::assertTrue($pipeline->steps[1]->continueOnError);
        self::assertTrue($pipeline->steps[0]->strict, 'strict mode is the default');
    }

    public function testStrictCanBeDisabledPerStep(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            steps:
              - name: Lenient
                run: 'false; true'
                strict: false
              - name: Explicit
                run: 'true'
                strict: true
            YAML);

        self::assertFalse($pipeline->steps[0]->strict);
        self::assertTrue($pipeline->steps[1]->strict);
    }

    public function testInterpolatesChainedVarsAndBuiltins(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            vars:
              BASE: /home/user/apps
              APP_DIR: ${BASE}/${PROJECT}
              BRANCH: main
              REF: origin/${BRANCH}
            steps:
              - name: Fetch
                run: git -C ${APP_DIR} fetch origin ${BRANCH} && git reset --hard ${REF}
              - name: Info
                run: echo "${RUN_ID} in ${PROJECT_DIR}"
            YAML);

        self::assertSame('/home/user/apps/shop', $pipeline->vars['APP_DIR']);
        self::assertSame(
            'git -C /home/user/apps/shop fetch origin main && git reset --hard origin/main',
            $pipeline->steps[0]->command,
        );
        self::assertSame('echo "42 in /home/user/apps/shop"', $pipeline->steps[1]->command);
    }

    public function testScalarVarValuesAreStringified(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            vars:
              PORT: 8080
              DEBUG: false
              RATIO: 1.5
            steps:
              - name: Show
                run: echo ${PORT} ${DEBUG} ${RATIO}
            YAML);

        self::assertSame('echo 8080 false 1.5', $pipeline->steps[0]->command);
    }

    public function testShellSyntaxWithoutBracesAndEscapedPlaceholdersAreLeftAlone(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            steps:
              - name: Shell
                run: echo $HOME $$ $${SHELL_VAR}
            YAML);

        self::assertSame('echo $HOME $$ ${SHELL_VAR}', $pipeline->steps[0]->command);
    }

    public function testRunAcceptsAListOfCommandsInterpolatedOneByOne(): void
    {
        $pipeline = $this->parse(<<<'YAML'
            vars:
              BRANCH: main
            steps:
              - name: Update
                run:
                  - git fetch origin ${BRANCH}
                  - |
                    if [ -d vendor ]; then
                      echo cached
                    fi
                  - echo $${KEEP}
              - name: Single
                run: echo one
            YAML);

        self::assertSame(['git fetch origin main', "if [ -d vendor ]; then\n  echo cached\nfi\n", 'echo ${KEEP}'], $pipeline->steps[0]->commands);
        self::assertSame("git fetch origin main\nif [ -d vendor ]; then\n  echo cached\nfi\n\necho \${KEEP}", $pipeline->steps[0]->command);
        self::assertSame(['echo one'], $pipeline->steps[1]->commands);
        self::assertSame('echo one', $pipeline->steps[1]->command);
    }

    public function testMultipleErrorsAreCollected(): void
    {
        $errors = $this->errorsOf(<<<'YAML'
            steps:
              - run: echo a
              - name: B
            YAML);

        self::assertSame([
            'steps[0]: "name" is required and must be a non-empty string.',
            'steps[1] (B): "run" is required and must be a non-empty string or a non-empty list of strings.',
        ], $errors);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPipelines(): iterable
    {
        yield 'invalid yaml' => ["steps: [\n  - name: a", 'Invalid YAML'];
        yield 'root not a mapping' => ['- a', 'The pipeline must be a mapping'];
        yield 'empty document' => ['', 'The pipeline must be a mapping'];
        yield 'missing steps' => ["vars:\n  A: b", '"steps" is required'];
        yield 'empty steps' => ['steps: []', '"steps" must be a non-empty list'];
        yield 'steps is a mapping' => ["steps:\n  a: b", '"steps" must be a non-empty list'];
        yield 'step not a mapping' => ["steps:\n  - echo hi", 'steps[0]: must be a mapping'];
        yield 'unknown top-level key' => ["stepz: []\nsteps:\n  - {name: a, run: b}", 'Unknown top-level key "stepz"'];
        yield 'unknown step key' => ["steps:\n  - {name: a, run: b, timout: 5}", 'steps[0] (a): unknown key "timout"'];
        yield 'run not a string' => ["steps:\n  - {name: a, run: 5}", 'steps[0] (a): "run" is required and must be a non-empty string or a non-empty list of strings'];
        yield 'run empty list' => ["steps:\n  - {name: a, run: []}", 'steps[0] (a): "run" is required and must be a non-empty string or a non-empty list'];
        yield 'run list item not a string' => ["steps:\n  - {name: a, run: [echo a, [x]]}", 'steps[0] (a): "run[1]" must be a non-empty string'];
        yield 'run list item empty' => ["steps:\n  - {name: a, run: [echo a, '  ']}", 'steps[0] (a): "run[1]" must be a non-empty string'];
        yield 'run mapping' => ["steps:\n  - {name: a, run: {x: y}}", 'steps[0] (a): "run" is required'];
        yield 'unknown var in run item' => ["steps:\n  - {name: a, run: [echo a, 'echo \${NOPE}']}", 'steps[0] (a): unknown variable "NOPE"'];
        yield 'timeout zero' => ["steps:\n  - {name: a, run: b, timeout: 0}", 'steps[0] (a): "timeout" must be a positive integer'];
        yield 'timeout negative' => ["steps:\n  - {name: a, run: b, timeout: -5}", '"timeout" must be a positive integer'];
        yield 'timeout float' => ["steps:\n  - {name: a, run: b, timeout: 1.5}", '"timeout" must be a positive integer'];
        yield 'timeout string' => ["steps:\n  - {name: a, run: b, timeout: '10'}", '"timeout" must be a positive integer'];
        yield 'continue_on_error not bool' => ["steps:\n  - {name: a, run: b, continue_on_error: 'yes'}", '"continue_on_error" must be a boolean'];
        yield 'strict not bool' => ["steps:\n  - {name: a, run: b, strict: 'no'}", 'steps[0] (a): "strict" must be a boolean'];
        yield 'vars not a mapping' => ["vars: [a]\nsteps:\n  - {name: a, run: b}", '"vars" must be a mapping'];
        yield 'invalid var name' => ["vars:\n  bad-name: x\nsteps:\n  - {name: a, run: b}", 'vars: invalid name "bad-name"'];
        yield 'var not scalar' => ["vars:\n  A: [1]\nsteps:\n  - {name: a, run: b}", 'vars.A: value must be a scalar'];
        yield 'var overrides builtin' => ["vars:\n  PROJECT: x\nsteps:\n  - {name: a, run: b}", 'vars.PROJECT: "PROJECT" is a built-in variable'];
        yield 'unknown var in run' => ["steps:\n  - {name: a, run: 'echo \${NOPE}'}", 'steps[0] (a): unknown variable "NOPE"'];
        yield 'unknown var in var' => ["vars:\n  A: \${NOPE}\nsteps:\n  - {name: a, run: b}", 'vars.A: unknown variable "NOPE"'];
        yield 'forward reference' => ["vars:\n  A: \${B}\n  B: x\nsteps:\n  - {name: a, run: b}", 'vars.A: "B" is declared after "A"'];
        yield 'reference to invalid earlier var' => ["vars:\n  A: [1]\n  B: \${A}\nsteps:\n  - {name: a, run: b}", 'vars.B: "A" is invalid and cannot be referenced'];
        yield 'self reference' => ["vars:\n  A: x\${A}\nsteps:\n  - {name: a, run: b}", 'vars.A: "A" references itself'];
    }

    #[DataProvider('invalidPipelines')]
    public function testRejectsInvalidPipelines(string $yaml, string $expected): void
    {
        $errors = $this->errorsOf($yaml);

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, $expected)),
            sprintf('Expected an error containing "%s", got: %s', $expected, implode(' | ', $errors)));
    }

    public function testExceptionMessageListsAllErrors(): void
    {
        try {
            $this->parse("steps:\n  - {name: a}\n  - {run: b}");
            self::fail('Expected PipelineValidationError');
        } catch (PipelineValidationError $e) {
            self::assertStringStartsWith('Invalid pipeline:', $e->getMessage());
            self::assertStringContainsString('steps[0]', $e->getMessage());
            self::assertStringContainsString('steps[1]', $e->getMessage());
        }
    }
}
