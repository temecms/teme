<?php

/**
 * Runs Composer in one release's folder and nowhere else, with no package's
 * scripts or Composer plugins, and an environment that holds none of the
 * site's secrets: only what Composer needs, and the sources' keys in
 * COMPOSER_AUTH. Not final, so a test can stand in for the advisory check.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\Sources\ExtensionRequest;
use JsonException;
use LogicException;
use SensitiveParameter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ComposerRunner
{
    /** How long one Composer run may take, in seconds. */
    public const int TIMEOUT = 900;

    /** What every run adds: nothing interactive, and no package code runs. */
    public const array ALWAYS = ['--no-interaction', '--no-scripts', '--no-plugins'];

    public function __construct(
        private readonly string $binary,
        private readonly string $extensionsPath,
    ) {}

    /**
     * Changes the release's packages as the request asks.
     *
     * @param  array<string, mixed>  $auth  COMPOSER_AUTH, merged from every source in use
     * @param  list<string>  $blocking  for a rebuild, the locked packages that must move
     */
    public function change(ExtensionRequest $request, string $release, #[SensitiveParameter] array $auth = [], array $blocking = []): ComposerResult
    {
        $command = match ($request->action) {
            ExtensionAction::Install, ExtensionAction::Update => ['require', "{$request->package}:{$request->constraint}", '--update-with-dependencies', '--update-no-dev'],
            ExtensionAction::Remove => ['remove', (string) $request->package, '--update-with-dependencies', '--update-no-dev'],
            ExtensionAction::Rebuild => ['update', ...$blocking, '--with-all-dependencies', '--minimal-changes', '--no-dev'],
            ExtensionAction::Rollback => throw new LogicException('A rollback runs no Composer.'),
        };

        // `remove` takes no --prefer-dist; the packages it keeps are already installed.
        $options = $request->action === ExtensionAction::Remove ? ['--no-progress', '--optimize-autoloader'] : ['--no-progress', '--prefer-dist', '--optimize-autoloader'];

        return $this->run([...$command, ...$options], $release, $auth);
    }

    /** Checks that the lock matches composer.json. */
    public function validate(string $release): ComposerResult
    {
        return $this->run(['validate', '--no-check-all', '--no-check-publish', '--check-lock'], $release);
    }

    /** Checks the running PHP and its extensions against what the lock needs. */
    public function checkPlatform(string $release): ComposerResult
    {
        return $this->run(['check-platform-reqs', '--lock', '--no-dev'], $release);
    }

    /**
     * The security advisories for the locked packages, or null when the
     * advisory database couldn't be reached.
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    public function audit(string $release): ?array
    {
        $result = $this->run(['audit', '--locked', '--no-dev', '--format=json'], $release);
        $start = strpos($result->output, '{');

        try {
            $report = $start === false ? null : json_decode(substr($result->output, $start), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $report = null;
        }

        if (! is_array($report) || ! is_array($report['advisories'] ?? null)) {
            return null;
        }

        $advisories = [];

        foreach ($report['advisories'] as $package => $found) {
            if (is_array($found) && $found !== []) {
                $advisories[(string) $package] = array_values(array_filter($found, 'is_array'));
            }
        }

        return $advisories;
    }

    /**
     * Runs Composer with these arguments in the release's folder.
     *
     * @param  list<string>  $arguments
     * @param  array<string, mixed>  $auth
     */
    public function run(array $arguments, string $release, #[SensitiveParameter] array $auth = []): ComposerResult
    {
        $process = new Process(
            [$this->binary, ...$arguments, ...self::ALWAYS, '--working-dir='.$release],
            $release,
            $this->environment($auth),
            null,
            self::TIMEOUT,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ComposerResult(124, $process->getOutput().$process->getErrorOutput()."\nComposer took longer than ".(self::TIMEOUT / 60).' minutes.');
        }

        return new ComposerResult((int) $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /**
     * The child's whole environment: only what Composer needs, every
     * variable it would otherwise inherit removed (false, for Symfony's
     * Process), so APP_KEY, the database password and every other secret of
     * the site stay out.
     *
     * @param  array<string, mixed>  $auth
     *
     * @return array<string, string|false>
     */
    public function environment(#[SensitiveParameter] array $auth = []): array
    {
        $home = $this->extensionsPath.'/.composer';

        return [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $home,
            'COMPOSER_HOME' => $home,
            'COMPOSER_AUTH' => $auth === [] ? false : json_encode($auth, JSON_THROW_ON_ERROR),
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_MEMORY_LIMIT' => '768M',
            // The image's php.ini reads memory_limit from this one; removed, it reads empty and PHP warns "Failed to set memory limit to 0 bytes".
            'PHP_MEMORY_LIMIT' => '768M',
            'COMPOSER_ALLOW_SUPERUSER' => (string) getenv('COMPOSER_ALLOW_SUPERUSER') === '1' ? '1' : false,
        ] + array_fill_keys(array_keys(getenv()), false)
            + array_fill_keys(array_keys($_ENV), false)
            + array_fill_keys(array_keys(array_filter($_SERVER, 'is_string')), false);
    }
}
