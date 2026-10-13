<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure\Persistence;

/**
 * Versioned schema migrations. Append new versions; never edit an applied one.
 * Kept as PHP so they are always bundled inside the phar.
 */
final class Migrations
{
    /** @return array<int, list<string>> */
    public static function all(): array
    {
        return [
            1 => [
                'CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )',
                'CREATE TABLE notifiers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    config TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )',
                'CREATE TABLE projects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    slug TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    folder TEXT NOT NULL,
                    instructions_file TEXT NOT NULL DEFAULT \'deployer.yml\',
                    notifier_id INTEGER NULL REFERENCES notifiers(id) ON DELETE SET NULL,
                    webhook_token_hash TEXT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )',
                'CREATE TABLE runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                    status TEXT NOT NULL CHECK (status IN (\'queued\', \'running\', \'success\', \'failed\', \'cancelled\')),
                    trigger TEXT NOT NULL,
                    pipeline_yaml TEXT NULL,
                    error TEXT NULL,
                    cancel_requested INTEGER NOT NULL DEFAULT 0,
                    runner_pid INTEGER NULL,
                    created_at TEXT NOT NULL,
                    started_at TEXT NULL,
                    finished_at TEXT NULL
                )',
                'CREATE INDEX runs_project_status ON runs (project_id, status, id)',
                'CREATE TABLE run_steps (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
                    position INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    command TEXT NOT NULL,
                    timeout INTEGER NOT NULL,
                    continue_on_error INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL CHECK (status IN (\'pending\', \'running\', \'success\', \'failed\', \'cancelled\', \'skipped\')),
                    exit_code INTEGER NULL,
                    started_at TEXT NULL,
                    finished_at TEXT NULL,
                    UNIQUE (run_id, position)
                )',
                'CREATE TABLE run_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
                    step_position INTEGER NULL,
                    stream TEXT NOT NULL CHECK (stream IN (\'stdout\', \'stderr\', \'system\')),
                    line TEXT NOT NULL,
                    logged_at TEXT NOT NULL
                )',
                'CREATE INDEX run_logs_run_id ON run_logs (run_id, id)',
            ],
        ];
    }
}
