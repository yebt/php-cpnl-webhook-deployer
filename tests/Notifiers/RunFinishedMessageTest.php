<?php

declare(strict_types=1);

namespace Deployer\Tests\Notifiers;

use Deployer\Notifiers\Application\RunFinishedMessage;
use Deployer\Projects\Domain\Project;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunFinishedMessageTest extends TestCase
{
    public function testSuccessMessage(): void
    {
        $html = RunFinishedMessage::render($this->project('Shop <prod>'), $this->makeRun(RunStatus::Success, meta: ['branch' => 'main', 'commit' => 'abcdef1234567890']), null, []);

        self::assertStringStartsWith("\u{2705} <b>Shop &lt;prod&gt;</b>", $html);
        self::assertStringContainsString('success', $html);
        self::assertStringContainsString('Run #7', $html);
        self::assertStringContainsString('webhook', $html);
        self::assertStringContainsString('1m 05s', $html);
        self::assertStringContainsString('main', $html);
        self::assertStringContainsString('abcdef12', $html);
        self::assertStringNotContainsString('<pre>', $html);
    }

    public function testFailureMessageNamesTheStepAndShowsEscapedLogTail(): void
    {
        $html = RunFinishedMessage::render($this->project('Shop'), $this->makeRun(RunStatus::Failed), 'Install <deps>', ['npm ERR! <missing>', 'exit & done']);

        self::assertStringStartsWith("\u{274C} <b>Shop</b>", $html);
        self::assertStringContainsString('Failed step: <b>Install &lt;deps&gt;</b>', $html);
        self::assertStringContainsString("<pre>npm ERR! &lt;missing&gt;\nexit &amp; done</pre>", $html);
    }

    public function testFailureWithoutStepShowsTheRunError(): void
    {
        $html = RunFinishedMessage::render($this->project('Shop'), $this->makeRun(RunStatus::Failed, error: 'Pipeline error: steps is required'), null, []);

        self::assertStringContainsString('<pre>Pipeline error: steps is required</pre>', $html);
    }

    public function testCancelledMessageAndLengthCap(): void
    {
        $html = RunFinishedMessage::render($this->project('Shop'), $this->makeRun(RunStatus::Cancelled), 'Long', array_fill(0, 50, str_repeat('x', 1000)));

        self::assertStringStartsWith("\u{1F6AB} <b>Shop</b>", $html);
        self::assertLessThanOrEqual(4096, mb_strlen($html));
        self::assertStringEndsWith('</pre>', $html);
    }

    private function project(string $name): Project
    {
        return new Project(1, 'shop', $name, '/srv/shop');
    }

    /** @param array<string,string>|null $meta */
    private function makeRun(RunStatus $status, ?array $meta = null, ?string $error = null): Run
    {
        return new Run(7, 1, $status, 'webhook', false, null, '2026-01-01 12:00:00.000', '2026-01-01 12:00:00.000', '2026-01-01 12:01:05.000', $error, null, $meta);
    }
}
