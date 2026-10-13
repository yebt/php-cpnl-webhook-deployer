<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use DateTimeImmutable;
use Deployer\Runs\Domain\LogLine;
use Deployer\Runs\Domain\LogRepository;
use Deployer\Runs\Domain\LogStream;
use PDO;

final class SqliteLogRepository implements LogRepository
{
    /** Longer lines are truncated to keep rows and HTTP responses bounded. */
    public const MAX_LINE_BYTES = 16384;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function append(int $runId, ?int $stepPosition, LogStream $stream, string $line, DateTimeImmutable $at): void
    {
        if (strlen($line) > self::MAX_LINE_BYTES) {
            $line = substr($line, 0, self::MAX_LINE_BYTES) . ' [truncated]';
        }
        // Keep logs valid UTF-8 so they can be JSON-encoded later.
        if (!preg_match('//u', $line)) {
            $line = function_exists('mb_scrub') ? mb_scrub($line, 'UTF-8') : (string) preg_replace('/[\x80-\xFF]/', '?', $line);
        }

        $stmt = $this->pdo->prepare('INSERT INTO run_logs (run_id, step_position, stream, line, logged_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$runId, $stepPosition, $stream->value, $line, $at->format(SqliteRunRepository::TIME_FORMAT)]);
    }

    public function since(int $runId, int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM run_logs WHERE run_id = ? AND id > ? ORDER BY id LIMIT ?');
        $stmt->bindValue(1, $runId, PDO::PARAM_INT);
        $stmt->bindValue(2, $afterId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): LogLine => new LogLine(
            (int) $r['id'],
            (int) $r['run_id'],
            $r['step_position'] === null ? null : (int) $r['step_position'],
            LogStream::from($r['stream']),
            $r['line'],
            $r['logged_at'],
        ), $stmt->fetchAll());
    }
}
