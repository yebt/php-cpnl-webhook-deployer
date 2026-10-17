<?php

declare(strict_types=1);

namespace Deployer\Runs\Infrastructure\Process;

/**
 * Shell helpers shared by the runner launcher and the step executor.
 */
final class Shell
{
    /**
     * Bash snippet that closes every inherited descriptor above 2. PHP does not
     * set close-on-exec on files opened with fopen(): without this, a step that
     * starts a long-lived background process would keep the project flock (or,
     * under PHP-FPM, the client connection) open forever.
     */
    public const CLOSE_INHERITED_FDS = 'for fd in /proc/$$/fd/*; do n=${fd##*/}; case $n in 0|1|2) ;; *) eval "exec $n>&-" 2>/dev/null ;; esac; done';

    private static ?string $setsid = null;
    private static ?string $bash = null;

    /** Absolute path of setsid(1), or '' when unavailable. */
    public static function setsid(): string
    {
        return self::$setsid ??= self::find('setsid');
    }

    /**
     * Absolute path of bash, resolved from the base PATH so a step's own `PATH`
     * (from `env:`) cannot hide the shell that runs it. Falls back to 'bash'.
     */
    public static function bash(): string
    {
        return self::$bash ??= (self::find('bash') ?: 'bash');
    }

    private static function find(string $binary): string
    {
        foreach (explode(':', self::environment()['PATH']) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $binary)) {
                return $dir . '/' . $binary;
            }
        }

        return '';
    }

    /**
     * Current environment with sane PATH and HOME. PHP-FPM usually clears the
     * environment (clear_env), and git/composer need both.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    public static function environment(array $overrides = []): array
    {
        /** @var array<string, string> $env */
        $env = array_filter(getenv(), 'is_string');
        if (($env['PATH'] ?? '') === '') {
            $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        }
        if (($env['HOME'] ?? '') === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && ($user['dir'] ?? '') !== '') {
                $env['HOME'] = $user['dir'];
            }
        }

        return array_merge($env, $overrides);
    }
}
