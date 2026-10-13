<?php

declare(strict_types=1);

namespace Deployer\Projects\Infrastructure;

use Deployer\Projects\Domain\InstructionsPathOutsideFolder;
use Deployer\Projects\Domain\InstructionsStore;
use Deployer\Projects\Domain\Project;
use RuntimeException;

/**
 * Reads/writes the pipeline file. A relative instructions path must resolve inside
 * the project folder (symlinked directories included); an absolute path is an
 * explicit operator choice and is used as is.
 */
final class FilesystemInstructionsStore implements InstructionsStore
{
    public function read(Project $project): string
    {
        $path = $this->checkedPath($project);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Instructions file not found or not readable: %s', $path));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read instructions file: %s', $path));
        }

        return $contents;
    }

    public function exists(Project $project): bool
    {
        return is_file($this->checkedPath($project));
    }

    public function write(Project $project, string $contents): void
    {
        $path = $this->checkedPath($project);
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException(sprintf('Directory does not exist or is not writable: %s', $dir));
        }
        if (is_dir($path)) {
            throw new RuntimeException(sprintf('Instructions path is a directory: %s', $path));
        }

        // Write to a sibling temp file and rename, so runners never read a partial file.
        $tmp = @tempnam($dir, '.deployer-yml-');
        if ($tmp === false || @file_put_contents($tmp, $contents) !== strlen($contents)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new RuntimeException(sprintf('Cannot write instructions file: %s', $path));
        }
        @chmod($tmp, is_file($path) ? (fileperms($path) & 0777) : 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Cannot replace instructions file: %s', $path));
        }
    }

    private function checkedPath(Project $project): string
    {
        $path = $project->instructionsPath();
        if ($project->hasAbsoluteInstructionsPath()) {
            return $path;
        }

        $folder = realpath($project->folder);
        $parent = realpath(dirname($path));
        if ($folder === false || $parent === false) {
            return $path; // Nothing resolvable yet; reads/writes fail on their own.
        }
        if ($parent !== $folder && !str_starts_with($parent . '/', rtrim($folder, '/') . '/')) {
            throw new InstructionsPathOutsideFolder($path);
        }
        // A final-component symlink is replaced, not followed, by write(); reads follow it
        // only if it stays inside the folder.
        if (is_link($path)) {
            $target = realpath($path);
            if ($target !== false && !str_starts_with($target, rtrim($folder, '/') . '/')) {
                throw new InstructionsPathOutsideFolder($path);
            }
        }

        return $path;
    }
}
