<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Http;

use Deployer\Shared\Infrastructure\Http\Request;

/**
 * Extracts a small, informational summary from common webhook payloads (GitHub,
 * GitLab, Gitea or a custom JSON body). It never drives behaviour: every webhook
 * with a valid token triggers a run, whatever the event or branch.
 */
final class WebhookPayloadSummary
{
    private const MAX_VALUE_LENGTH = 200;

    /** @return array<string,string>|null */
    public static function fromRequest(Request $request): ?array
    {
        $data = null;
        if (!$request->bodyTooLarge && trim($request->body) !== '') {
            $decoded = json_decode($request->body, true, 64);
            $data = is_array($decoded) ? $decoded : null;
        }

        $summary = [
            'event' => $request->header('x-github-event') ?? $request->header('x-gitlab-event') ?? $request->header('x-gitea-event'),
        ];
        if ($data !== null) {
            $ref = self::first($data, ['ref']);
            $summary += [
                'ref' => $ref,
                'branch' => self::first($data, ['branch']) ?? ($ref !== null && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null),
                'commit' => self::first($data, ['after', 'checkout_sha', 'head_commit.id', 'commit']),
                'message' => self::firstLine(self::first($data, ['head_commit.message', 'message'])),
                'author' => self::first($data, ['head_commit.author.name', 'pusher.name', 'user_name', 'sender.login', 'author']),
            ];
        }
        $summary = array_filter($summary, static fn (?string $v): bool => $v !== null && $v !== '');

        return $summary === [] ? null : array_map(
            static fn (string $v): string => mb_substr($v, 0, self::MAX_VALUE_LENGTH),
            $summary,
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $paths dotted paths, first scalar match wins
     */
    private static function first(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $key) {
                $value = is_array($value) ? ($value[$key] ?? null) : null;
            }
            if (is_string($value) || is_int($value)) {
                return mb_scrub((string) $value, 'UTF-8');
            }
        }

        return null;
    }

    private static function firstLine(?string $text): ?string
    {
        return $text === null ? null : trim(strtok($text, "\n") ?: '');
    }
}
