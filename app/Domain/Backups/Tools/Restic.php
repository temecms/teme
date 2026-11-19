<?php

/**
 * Runs restic (I-366), one process a call, against one repository: the
 * repository and its key in the child's environment only, never on its
 * command line; nothing Teme was started with that restic, S3 or
 * PostgreSQL reads; its JSON messages handed on as it prints them; and its
 * exit status turned into a ResticResult.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessFailed;

final readonly class Restic
{
    /** A call's time limit when its caller names none, in seconds. */
    public const int TIMEOUT = 3600;

    public function __construct(private BackupTools $tools) {}

    /**
     * Runs `restic {arguments}` against $repository, and waits for it to end
     * or for $timeout seconds. $from is the repository a `copy` or an
     * `init --from-repo` reads. $environment adds variables for the child,
     * such as PostgresTools::environment() for a dump read through
     * --stdin-from-command; $input goes to restic's standard input, such as
     * the new key for `key add`. $input, and each of $environment's values
     * whose name says it is secret, are hidden from the result's words.
     *
     * @param  list<string>  $arguments  the command and its own flags, never a secret
     * @param  array<string, string>  $environment
     * @param  (Closure(array<string, mixed>): void)|null  $onMessage  each JSON object restic prints on its standard output, in order, as it prints it
     */
    public function run(
        ResticRepository $repository,
        array $arguments,
        ?ResticRepository $from = null,
        #[SensitiveParameter] array $environment = [],
        #[SensitiveParameter] ?string $input = null,
        int $timeout = self::TIMEOUT,
        ?Closure $onMessage = null,
    ): ResticResult {
        // By name: PGPASSWORD is one, PGUSER isn't.
        $secrets = [
            ...$repository->secrets(),
            ...($from?->secrets() ?? []),
            ...ChildEnvironment::secretValues($environment),
            ...($input === null ? [] : [trim($input)]),
        ];

        // Both refuse before anything runs (LogicException) when the two
        // repositories need different values of one variable or option.
        $options = $this->optionFlags($repository, $from);
        $variables = $this->environment($repository, $from, $environment);

        try {
            $command = [$this->tools->restic(), '--json', ...$this->cacheFlags(), ...$options, ...$arguments];
        } catch (BackupToolMissing $missing) {
            return ResticResult::notStarted($missing->getMessage());
        }

        $process = Process::env($variables)->timeout($timeout);

        if ($input !== null) {
            $process = $process->input($input);
        }

        $pending = '';

        try {
            $result = $process->run($command, function (string $type, string $output) use (&$pending, $onMessage): void {
                if ($type !== 'out' || $onMessage === null) {
                    return;
                }

                // Output arrives in pieces that needn't end where a line does.
                $pending .= $output;

                while (($end = strpos($pending, "\n")) !== false) {
                    $message = json_decode(substr($pending, 0, $end), true);
                    $pending = substr($pending, $end + 1);

                    if (is_array($message)) {
                        $onMessage($message);
                    }
                }
            });
        } catch (ProcessTimedOutException $timedOut) {
            return ResticResult::timedOut($timeout, $timedOut->result->errorOutput(), $secrets);
        } catch (ProcessSignaledException) {
            // Killed from outside, as by the system when memory ran out.
            return ResticResult::ended(130, '', '', $secrets);
        } catch (ProcessFailed $failed) {
            return ResticResult::notStarted($failed->getMessage(), $secrets);
        }

        return ResticResult::ended($result->exitCode() ?? -1, $result->output(), $result->errorOutput(), $secrets);
    }

    /**
     * The child's variables: Teme's own less every RESTIC_, AWS_ and PG one,
     * then restic's progress rate, the backends', the caller's (which may
     * raise the rate), and last the repositories', which nothing overrides.
     *
     * @param  array<string, string>  $extra
     *
     * @return array<string, string|false>
     */
    private function environment(ResticRepository $repository, ?ResticRepository $from, array $extra): array
    {
        $backend = $repository->backendVariables();

        foreach ($from?->backendVariables() ?? [] as $name => $value) {
            if (array_key_exists($name, $backend) && $backend[$name] !== $value) {
                throw new LogicException("restic reads one {$name} for both repositories, and these two need different ones.");
            }

            $backend[$name] = $value;
        }

        return [
            ...ChildEnvironment::withoutInherited(),
            // With --json, restic prints status lines many times a second for a whole backup;
            // one in ten seconds at most keeps its output small, and the summary still comes (I-366).
            'RESTIC_PROGRESS_FPS' => '0.1',
            ...$backend,
            ...$extra,
            ...$repository->variables('RESTIC_'),
            ...($from?->variables('RESTIC_FROM_') ?? []),
        ];
    }

    /**
     * Both repositories' -o options, each once.
     *
     * @return list<string>
     */
    private function optionFlags(ResticRepository $repository, ?ResticRepository $from): array
    {
        $options = [];

        foreach ([...$repository->options(), ...($from?->options() ?? [])] as $option) {
            $key = Str::before($option, '=');

            if (array_key_exists($key, $options) && $options[$key] !== $option) {
                throw new LogicException("restic reads one {$key} for both repositories, and these two need different ones.");
            }

            $options[$key] = $option;
        }

        $flags = [];

        foreach ($options as $option) {
            $flags[] = '-o';
            $flags[] = $option;
        }

        return $flags;
    }

    /**
     * @return list<string>
     */
    private function cacheFlags(): array
    {
        $folder = trim((string) config('teme.backups.cache_path'));

        return $folder === '' ? ['--no-cache'] : ['--cache-dir='.$folder];
    }
}
