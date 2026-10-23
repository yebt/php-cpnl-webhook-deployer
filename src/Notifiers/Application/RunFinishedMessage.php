<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Application;

use Deployer\Projects\Domain\Project;
use Deployer\Runs\Domain\Duration;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunStatus;

/** Renders the run-finished notification in Telegram's HTML subset. */
final class RunFinishedMessage
{
    /** Telegram's sendMessage limit is 4096 characters. */
    private const MAX_LENGTH = 4000;
    private const MAX_LINE_LENGTH = 300;

    /** @param list<string> $logTail last lines of the failed step */
    public static function render(Project $project, Run $run, ?string $failedStep, array $logTail): string
    {
        $lines = [
            sprintf('%s <b>%s</b> · %s', self::emoji($run->status), self::e($project->name), self::e($run->status->value)),
            sprintf('Run #%d · trigger: %s · %s', $run->id, self::e($run->trigger), Duration::human(Duration::millis($run->startedAt, $run->finishedAt))),
        ];
        if ($run->action !== null) {
            $name = $run->actionName ?? $run->action;
            // Right below the headline, so the action is visible in the notification preview.
            array_splice($lines, 1, 0, $name === $run->action
                ? sprintf('Action: <b>%s</b>', self::e($name))
                : sprintf('Action: <b>%s</b> (%s)', self::e($name), self::e($run->action)));
        }
        $meta = array_filter([
            isset($run->triggerMeta['branch']) ? 'branch ' . self::e($run->triggerMeta['branch']) : null,
            isset($run->triggerMeta['commit']) ? 'commit ' . self::e(substr($run->triggerMeta['commit'], 0, 8)) : null,
        ]);
        if ($meta !== []) {
            $lines[] = implode(' · ', $meta);
        }
        if ($failedStep !== null) {
            $lines[] = sprintf('Failed step: <b>%s</b>', self::e($failedStep));
        }

        $head = implode("\n", $lines);
        $detail = $logTail !== [] ? $logTail : ($run->status === RunStatus::Failed && $run->error !== null ? [$run->error] : []);
        if ($detail === []) {
            return $head;
        }

        $budget = self::MAX_LENGTH - mb_strlen($head) - strlen("\n<pre></pre>");
        $block = [];
        // Keep the most recent lines when the budget runs out.
        foreach (array_reverse($detail) as $line) {
            $escaped = self::e(mb_strimwidth($line, 0, self::MAX_LINE_LENGTH, '…'));
            if (mb_strlen($escaped) + 1 > $budget) {
                break;
            }
            $budget -= mb_strlen($escaped) + 1;
            array_unshift($block, $escaped);
        }

        return $block === [] ? $head : $head . "\n<pre>" . implode("\n", $block) . '</pre>';
    }

    public static function test(string $channelName): string
    {
        return sprintf("\u{1F514} <b>Deployer</b>\nThis is a test message for notifier <b>%s</b>.", self::e($channelName));
    }

    private static function emoji(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Success => "\u{2705}",
            RunStatus::Failed => "\u{274C}",
            RunStatus::Cancelled => "\u{1F6AB}",
            default => "\u{2139}\u{FE0F}",
        };
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
