<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure;

use DateTimeImmutable;
use Deployer\Runs\Domain\Run;
use Deployer\Runs\Domain\RunFilter;
use Deployer\Runs\Domain\RunRepository;
use Deployer\Runs\Domain\RunStatus;
use Deployer\Runs\Domain\RunStepRecord;
use Deployer\Runs\Domain\StepStatus;
use PDO;
use Throwable;

final class SqliteRunRepository implements RunRepository
{
    public const TIME_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(int $projectId, string $trigger, DateTimeImmutable $now, ?array $triggerMeta = null): int
    {
        $meta = $triggerMeta === null ? null : json_encode($triggerMeta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->exec('INSERT INTO runs (project_id, status, trigger, trigger_meta, created_at) VALUES (?, ?, ?, ?, ?)', [
            $projectId, RunStatus::Queued->value, $trigger, $meta, $this->time($now),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function search(RunFilter $filter): array
    {
        $where = [];
        $params = [];
        if ($filter->projectId !== null) {
            $where[] = 'project_id = ?';
            $params[] = $filter->projectId;
        }
        if ($filter->status !== null) {
            $where[] = 'status = ?';
            $params[] = $filter->status->value;
        }
        if ($filter->beforeId !== null) {
            $where[] = 'id < ?';
            $params[] = $filter->beforeId;
        }
        $sql = 'SELECT * FROM runs' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $filter->limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function hasActive(int $projectId): bool
    {
        return $this->exec('SELECT 1 FROM runs WHERE project_id = ? AND status IN (?, ?) LIMIT 1', [
            $projectId, RunStatus::Queued->value, RunStatus::Running->value,
        ])->fetchColumn() !== false;
    }

    public function find(int $runId): ?Run
    {
        $stmt = $this->exec('SELECT * FROM runs WHERE id = ?', [$runId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function claimNextQueued(int $projectId, int $runnerPid, DateTimeImmutable $now): ?Run
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $id = $this->exec(
                'SELECT id FROM runs WHERE project_id = ? AND status = ? ORDER BY id LIMIT 1',
                [$projectId, RunStatus::Queued->value],
            )->fetchColumn();
            if ($id !== false) {
                $this->exec('UPDATE runs SET status = ?, started_at = ?, runner_pid = ? WHERE id = ? AND status = ?', [
                    RunStatus::Running->value, $this->time($now), $runnerPid, $id, RunStatus::Queued->value,
                ]);
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $id === false ? null : $this->find((int) $id);
    }

    public function hasQueued(int $projectId): bool
    {
        return $this->exec('SELECT 1 FROM runs WHERE project_id = ? AND status = ? LIMIT 1', [
            $projectId, RunStatus::Queued->value,
        ])->fetchColumn() !== false;
    }

    public function projectsWithQueuedRuns(): array
    {
        $ids = $this->exec('SELECT DISTINCT project_id FROM runs WHERE status = ? ORDER BY project_id', [
            RunStatus::Queued->value,
        ])->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    public function runningRuns(): array
    {
        $rows = $this->exec('SELECT * FROM runs WHERE status = ? ORDER BY id', [RunStatus::Running->value])->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function snapshotPipeline(int $runId, string $yaml): void
    {
        $this->exec('UPDATE runs SET pipeline_yaml = ? WHERE id = ?', [$yaml, $runId]);
    }

    public function createSteps(int $runId, array $steps): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO run_steps (run_id, position, name, command, timeout, continue_on_error, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($steps as $position => $step) {
                $stmt->execute([$runId, $position, $step->name, $step->command, $step->timeout, (int) $step->continueOnError, StepStatus::Pending->value]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function steps(int $runId): array
    {
        $rows = $this->exec('SELECT * FROM run_steps WHERE run_id = ? ORDER BY position', [$runId])->fetchAll();

        return array_map(static fn (array $r): RunStepRecord => new RunStepRecord(
            (int) $r['position'],
            $r['name'],
            $r['command'],
            (int) $r['timeout'],
            (bool) $r['continue_on_error'],
            StepStatus::from($r['status']),
            $r['exit_code'] === null ? null : (int) $r['exit_code'],
            $r['started_at'],
            $r['finished_at'],
        ), $rows);
    }

    public function startStep(int $runId, int $position, DateTimeImmutable $now): void
    {
        $this->exec('UPDATE run_steps SET status = ?, started_at = ? WHERE run_id = ? AND position = ?', [
            StepStatus::Running->value, $this->time($now), $runId, $position,
        ]);
    }

    public function finishStep(int $runId, int $position, StepStatus $status, ?int $exitCode, DateTimeImmutable $now): void
    {
        $this->exec('UPDATE run_steps SET status = ?, exit_code = ?, finished_at = ? WHERE run_id = ? AND position = ?', [
            $status->value, $exitCode, $this->time($now), $runId, $position,
        ]);
    }

    public function closeOpenSteps(int $runId, StepStatus $runningAs, DateTimeImmutable $now): void
    {
        $this->exec('UPDATE run_steps SET status = ?, finished_at = ? WHERE run_id = ? AND status = ?', [
            $runningAs->value, $this->time($now), $runId, StepStatus::Running->value,
        ]);
        $this->exec('UPDATE run_steps SET status = ? WHERE run_id = ? AND status = ?', [
            StepStatus::Skipped->value, $runId, StepStatus::Pending->value,
        ]);
    }

    public function finish(int $runId, RunStatus $status, ?string $error, DateTimeImmutable $now): void
    {
        $this->exec('UPDATE runs SET status = ?, error = ?, finished_at = ? WHERE id = ? AND status = ?', [
            $status->value, $error, $this->time($now), $runId, RunStatus::Running->value,
        ]);
    }

    public function cancelQueued(int $runId, DateTimeImmutable $now): bool
    {
        return $this->exec('UPDATE runs SET status = ?, cancel_requested = 1, finished_at = ? WHERE id = ? AND status = ?', [
            RunStatus::Cancelled->value, $this->time($now), $runId, RunStatus::Queued->value,
        ])->rowCount() === 1;
    }

    public function requestCancel(int $runId): bool
    {
        return $this->exec('UPDATE runs SET cancel_requested = 1 WHERE id = ? AND status = ?', [
            $runId, RunStatus::Running->value,
        ])->rowCount() === 1;
    }

    public function isCancelRequested(int $runId): bool
    {
        return (bool) $this->exec('SELECT cancel_requested FROM runs WHERE id = ?', [$runId])->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Run
    {
        return new Run(
            (int) $row['id'],
            (int) $row['project_id'],
            RunStatus::from($row['status']),
            $row['trigger'],
            (bool) $row['cancel_requested'],
            $row['runner_pid'] === null ? null : (int) $row['runner_pid'],
            $row['created_at'],
            $row['started_at'],
            $row['finished_at'],
            $row['error'],
            $row['pipeline_yaml'],
            isset($row['trigger_meta']) ? (json_decode((string) $row['trigger_meta'], true) ?: null) : null,
        );
    }

    private function time(DateTimeImmutable $at): string
    {
        return $at->format(self::TIME_FORMAT);
    }

    /** @param list<mixed> $params */
    private function exec(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
