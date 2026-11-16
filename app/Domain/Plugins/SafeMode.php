<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Where no plugin starts, so a plugin that breaks Teme can always be
 * disabled (study C Decision 3): signing in and confirming a password, the
 * Plugins page, Tools › Updates, the health check, and the console commands
 * that build, migrate, describe or take down Teme. A plugin can't opt out.
 */
final readonly class SafeMode
{
    /** Request paths, as Request::is() reads them. */
    public const array PATHS = [
        'admin/login', 'admin/logout', 'admin/two-factor-challenge', 'admin/forgot-password', 'admin/reset-password', 'admin/reset-password/*',
        'admin/user/confirm-password', 'admin/user/confirmed-password-status',
        'admin/email/verify', 'admin/email/verify/*', 'admin/email/verification-notification',
        // A site may send an administrator who hasn't set up two-factor to the Two-factor sign-in page before any other admin page (I-115).
        'admin/account/security', 'admin/account/two-factor', 'admin/user/two-factor-authentication', 'admin/user/confirmed-two-factor-authentication',
        'admin/user/two-factor-qr-code', 'admin/user/two-factor-recovery-codes', 'admin/user/two-factor-secret-key',
        'admin/plugins', 'admin/plugins/*', 'admin/tools/updates', 'admin/tools/updates/*',
        'admin/extensions', 'admin/extensions/*',
        // Laravel's health check.
        'up',
    ];

    /** Console commands: a name ending in a colon is a prefix; any other must match whole. */
    public const array COMMANDS = [
        'migrate', 'migrate:', 'db:', 'teme:plugins', 'teme:plugins:', 'teme:docs:', 'teme:themes:', 'package:discover',
        'optimize', 'optimize:', 'config:', 'route:', 'view:', 'event:', 'cache:clear', 'queue:restart', 'list', 'help',
        'teme:deploy', 'teme:updater', 'teme:updates:', 'teme:installer', 'teme:extensions:', 'down', 'up',
    ];

    public function __construct(private Application $app) {}

    /**
     * Whether this process is on a safe page or runs a safe command, so no plugin starts.
     */
    public function active(): bool
    {
        // The test suite's own process loads plugins only when a test asks the loader to (forPath and forCommand are tested alone).
        if ($this->app->runningUnitTests()) {
            return false;
        }

        if ($this->app->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];

            return self::forArgv(is_array($argv) ? array_values(array_map(strval(...), $argv)) : []);
        }

        return self::forPath($this->app->make('request')->path());
    }

    /**
     * Whether no plugin starts for the command in this argv, its name read
     * as Laravel's console reads it: against the console's global options
     * (Symfony's own and Laravel's --env), so neither an option before the
     * name nor the value an option takes is read as the name.
     *
     * @param  list<string>  $argv
     */
    public static function forArgv(array $argv): bool
    {
        $definition = (new ConsoleApplication)->getDefinition();
        $definition->addOption(new InputOption('--env', null, InputOption::VALUE_OPTIONAL));
        $input = new ArgvInput($argv);

        try {
            $input->bind($definition);
        } catch (Throwable) {
            // As the console does: a command's own options are unknown here, and what was read before them stands.
        }

        return self::forCommand((string) $input->getFirstArgument());
    }

    /**
     * Whether no plugin starts on this request path.
     */
    public static function forPath(string $path): bool
    {
        return Request::create('/'.ltrim($path, '/'))->is(...self::PATHS);
    }

    /**
     * Whether no plugin starts for this console command. '' is `php artisan`
     * alone, which lists the commands: safe.
     */
    public static function forCommand(string $command): bool
    {
        foreach (self::COMMANDS as $safe) {
            if ($command === $safe || (str_ends_with($safe, ':') && str_starts_with($command, $safe))) {
                return true;
            }
        }

        return $command === '';
    }
}
