<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * The stand-in for what restic can't be made to do on demand (I-2400): a
 * crash, an incomplete backup, a cancel and a timeout. It fakes every
 * process the test starts and answers each as scripted; a test then reads
 * the command line, the environment and the input a call was given through
 * Process::assertRan().
 */
final class ScriptedRestic
{
    public static function exits(int $code, string $errorOutput = '', string $output = ''): void
    {
        Process::fake(['*' => Process::result(output: $output, errorOutput: $errorOutput, exitCode: $code)]);
    }

    public static function timesOut(): void
    {
        Process::fake(['*' => fn (): ProcessTimedOutException => new ProcessTimedOutException(
            new SymfonyTimedOut(new SymfonyProcess(['restic']), SymfonyTimedOut::TYPE_GENERAL),
            new FakeProcessResult(exitCode: 143, errorOutput: '{"message_type":"status","percent_done":0.5}'),
        )]);
    }

    /**
     * restic's own fatal error line, as --json prints it on standard error.
     */
    public static function exitError(int $code, string $message): string
    {
        return json_encode(['message_type' => 'exit_error', 'code' => $code, 'message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
