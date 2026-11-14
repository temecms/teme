<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Watches every process a test starts through Laravel's Process (I-2400):
 * records its arguments, its environment and its standard input, then
 * either runs it for real or answers it as a small scripted restic that
 * keeps its repositories and keys in memory, for S3 and SFTP places no
 * test may reach. A program restic starts itself, such as ssh, isn't seen.
 */
final class WatchedProcesses
{
    /** @var list<array{command: list<string>, environment: array<string, string|false>, input: string|null}> */
    public array $seen = [];

    /** @var array<string, array{id: string, keys: array<string, string>}> by location: the repository's ID, and each key's ID by its password */
    private array $repositories = [];

    /** @var array<string, string> the commands the scripted restic fails, such as 'key add', and its words for each */
    private array $failing = [];

    private function __construct() {}

    public static function runForReal(): self
    {
        $watched = new self;

        Process::fake(['*' => function (PendingProcess $process) use ($watched): ProcessResult {
            $watched->record($process);
            $real = new SymfonyProcess(array_values((array) $process->command), $process->path, $process->environment, $process->input, $process->timeout);
            $real->run();

            return new ProcessResult($real);
        }]);

        return $watched;
    }

    public static function asRestic(): self
    {
        $watched = new self;

        Process::fake(['*' => function (PendingProcess $process) use ($watched): FakeProcessResult {
            $watched->record($process);

            return $watched->answer(array_values((array) $process->command), $process->environment, is_string($process->input) ? $process->input : null);
        }]);

        return $watched;
    }

    /**
     * Tells the scripted restic of a repository made elsewhere.
     *
     * @param  array<string, string>  $keys  password => restic key ID
     */
    public function knows(string $location, string $repositoryId, array $keys): self
    {
        $this->repositories[$location] = ['id' => $repositoryId, 'keys' => $keys];

        return $this;
    }

    /**
     * Makes the scripted restic fail $command, such as 'key add', with $message as its own words.
     */
    public function failing(string $command, string $message = 'Fatal: a scripted failure'): self
    {
        $this->failing[$command] = $message;

        return $this;
    }

    /**
     * Every argument of every process seen, the program's path included.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return array_merge(...array_map(fn (array $seen): array => $seen['command'], $this->seen ?: [['command' => []]]));
    }

    /**
     * The processes whose restic command (after --json, the cache flag and -o options) starts so.
     *
     * @return list<array{command: list<string>, environment: array<string, string|false>, input: string|null}>
     */
    public function restic(string $command): array
    {
        return array_values(array_filter($this->seen, fn (array $seen): bool => str_starts_with(implode(' ', self::resticArguments($seen['command'])).' ', $command.' ')));
    }

    private function record(PendingProcess $process): void
    {
        $this->seen[] = [
            'command' => array_values((array) $process->command),
            'environment' => $process->environment,
            'input' => is_string($process->input) ? $process->input : null,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $environment
     */
    private function answer(array $command, array $environment, ?string $input): FakeProcessResult
    {
        $arguments = self::resticArguments($command);
        $verb = implode(' ', array_slice($arguments, 0, ($arguments[0] ?? '') === 'init' ? 1 : 2));
        $location = (string) ($environment['RESTIC_REPOSITORY'] ?? '');
        $password = (string) ($environment['RESTIC_PASSWORD'] ?? '');

        if (array_key_exists($verb, $this->failing)) {
            return new FakeProcessResult(exitCode: 1, errorOutput: ScriptedRestic::exitError(1, $this->failing[$verb]));
        }

        if ($verb === 'init') {
            $from = $environment['RESTIC_FROM_REPOSITORY'] ?? false;

            if ($from !== false && ! array_key_exists((string) ($environment['RESTIC_FROM_PASSWORD'] ?? ''), $this->repositories[$from]['keys'] ?? [])) {
                return new FakeProcessResult(exitCode: 12, errorOutput: ScriptedRestic::exitError(12, 'Fatal: wrong password or no key found'));
            }

            $this->repositories[$location] = ['id' => bin2hex(random_bytes(32)), 'keys' => [$password => bin2hex(random_bytes(32))]];

            return new FakeProcessResult(output: json_encode(['message_type' => 'initialized', 'id' => $this->repositories[$location]['id'], 'repository' => $location], JSON_THROW_ON_ERROR)."\n");
        }

        $repository = $this->repositories[$location] ?? null;

        if ($repository === null) {
            return new FakeProcessResult(exitCode: 10, errorOutput: ScriptedRestic::exitError(10, 'Fatal: repository does not exist'));
        }

        if (! array_key_exists($password, $repository['keys'])) {
            return new FakeProcessResult(exitCode: 12, errorOutput: ScriptedRestic::exitError(12, 'Fatal: wrong password or no key found'));
        }

        if ($verb === 'key add') {
            $this->repositories[$location]['keys'][trim((string) $input)] = bin2hex(random_bytes(32));

            return new FakeProcessResult(output: "saved new key\n");
        }

        if ($verb === 'key remove') {
            $this->repositories[$location]['keys'] = array_filter($repository['keys'], fn (string $id): bool => $id !== ($arguments[2] ?? ''));

            return new FakeProcessResult;
        }

        return match ($verb) {
            'key list' => new FakeProcessResult(output: json_encode(array_map(
                fn (string $id): array => ['current' => $id === $repository['keys'][$password], 'id' => $id],
                array_values($repository['keys']),
            ), JSON_THROW_ON_ERROR)),
            'cat config' => new FakeProcessResult(output: json_encode(['version' => 2, 'id' => $repository['id'], 'chunker_polynomial' => '2c61f12d9a41bb'], JSON_THROW_ON_ERROR)),
            default => new FakeProcessResult(exitCode: 1, errorOutput: ScriptedRestic::exitError(1, 'Fatal: not scripted')),
        };
    }

    /**
     * restic's own command: what follows the program, --json, the cache flag and the -o options.
     *
     * @param  list<string>  $command
     *
     * @return list<string>
     */
    private static function resticArguments(array $command): array
    {
        $arguments = array_slice($command, 1);

        while ($arguments !== [] && (in_array($arguments[0], ['--json', '--no-cache', '-o'], true) || str_starts_with($arguments[0], '--cache-dir='))) {
            $arguments = array_slice($arguments, $arguments[0] === '-o' ? 2 : 1);
        }

        return $arguments;
    }
}
