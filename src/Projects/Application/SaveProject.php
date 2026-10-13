<?php

declare(strict_types=1);

namespace Deployer\Projects\Application;

use Deployer\Notifiers\Domain\NotifierRepository;
use Deployer\Projects\Domain\Project;
use Deployer\Projects\Domain\ProjectData;
use Deployer\Projects\Domain\ProjectRepository;
use Deployer\Shared\Domain\ValidationFailed;

final class SaveProject
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly NotifierRepository $notifiers,
    ) {
    }

    /**
     * Creates ($existing null) or updates a project; on update, missing fields keep their value.
     *
     * @param array<string,mixed> $input
     * @throws ValidationFailed
     * @throws SlugTaken
     */
    public function __invoke(array $input, ?Project $existing = null): Project
    {
        $errors = [];
        $name = self::string($input, 'name') ?? $existing?->name ?? '';
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Use 1-100 characters.';
        }

        $slug = self::string($input, 'slug') ?? $existing?->slug ?? self::slugify($name);
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            $errors['slug'] = 'Use 1-63 lowercase letters, digits or dashes, starting with a letter or digit.';
        }

        $folder = self::string($input, 'folder') ?? $existing?->folder ?? '';
        if (!self::isAbsolute($folder)) {
            $errors['folder'] = 'Use an absolute path.';
        } elseif ($folder !== '/') {
            $folder = rtrim($folder, '/');
        }

        $instructions = self::string($input, 'instructions_file') ?? $existing?->instructionsFile ?? Project::DEFAULT_INSTRUCTIONS_FILE;
        if ($instructions === '') {
            $instructions = Project::DEFAULT_INSTRUCTIONS_FILE;
        }
        if ($error = self::instructionsError($instructions)) {
            $errors['instructions_file'] = $error;
        }

        $notifierId = array_key_exists('notifier_id', $input) ? $input['notifier_id'] : $existing?->notifierId;
        if ($notifierId !== null && (!is_int($notifierId) || $this->notifiers->find($notifierId) === null)) {
            $errors['notifier_id'] = 'Unknown notifier.';
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
        $owner = $this->projects->findBySlug($slug);
        if ($owner !== null && $owner->id !== $existing?->id) {
            throw new SlugTaken($slug);
        }

        /** @var int|null $notifierId */
        if ($existing === null) {
            return $this->projects->create($slug, $name, $folder, $instructions, $notifierId);
        }

        return $this->projects->update($existing->id, new ProjectData($slug, $name, $folder, $instructions, $notifierId));
    }

    public static function slugify(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') && !str_contains($path, "\0") && strlen($path) <= 4096;
    }

    /**
     * Relative paths are confined to the project folder (no `..` segments; symlinks are
     * checked at read/write time). Absolute paths are an explicit operator choice.
     */
    private static function instructionsError(string $path): ?string
    {
        if (str_contains($path, "\0") || strlen($path) > 4096 || str_ends_with($path, '/')) {
            return 'Use a file path.';
        }
        if (str_starts_with($path, '/')) {
            return null;
        }
        if (in_array('..', explode('/', $path), true)) {
            return 'A relative path must stay inside the project folder (no "..").';
        }

        return null;
    }

    /** @param array<string,mixed> $input */
    private static function string(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }

        return is_string($input[$key]) ? trim($input[$key]) : '';
    }
}
