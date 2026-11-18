<?php

/**
 * How one restic call ended (I-366): its outcome and exit status, what it
 * printed, and its own words for what went wrong with every secret of the
 * call hidden.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

final readonly class ResticResult
{
    /** The most of restic's own words kept, in characters. */
    private const int DETAIL_LENGTH = 1000;

    private function __construct(
        public ResticOutcome $outcome,
        public ?int $exitCode,
        private string $output,
        private string $detail,
        private int $timeoutSeconds,
    ) {}

    /**
     * A call that ran to its end.
     *
     * @param  list<string>  $secrets
     */
    public static function ended(int $exitCode, string $output, string $errorOutput, array $secrets): self
    {
        $outcome = ResticOutcome::fromExitCode($exitCode);
        $detail = $outcome === ResticOutcome::Ok ? '' : self::hide(self::errorWords($errorOutput), $secrets);

        return new self($outcome, $exitCode, $output, $detail, 0);
    }

    /**
     * A call Teme stopped at its time limit.
     *
     * @param  list<string>  $secrets
     */
    public static function timedOut(int $timeoutSeconds, string $errorOutput, array $secrets): self
    {
        return new self(ResticOutcome::TimedOut, null, '', self::hide(self::errorWords($errorOutput), $secrets), $timeoutSeconds);
    }

    /**
     * A call that never started: restic wasn't there, or wouldn't run.
     *
     * @param  list<string>  $secrets
     */
    public static function notStarted(string $why, array $secrets = []): self
    {
        return new self(ResticOutcome::NotStarted, null, '', self::hide($why, $secrets), 0);
    }

    public function succeeded(): bool
    {
        return $this->outcome === ResticOutcome::Ok;
    }

    /**
     * The whole standard output decoded, for a command that prints one JSON
     * document (snapshots, cat config, key list); null when it printed none.
     */
    public function json(): mixed
    {
        $output = trim($this->output);

        return $output === '' ? null : json_decode($output, true);
    }

    /**
     * Each JSON object restic printed on its standard output, a line each,
     * in order, for a command that prints messages (backup, restore, init).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (preg_split('/\R/', $this->output) ?: [] as $line) {
            $message = json_decode($line, true);

            if (is_array($message) && ! array_is_list($message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The last message whose message_type is $type (summary, initialized),
     * or null.
     *
     * @return array<string, mixed>|null
     */
    public function message(string $type): ?array
    {
        $found = null;

        foreach ($this->messages() as $message) {
            if (($message['message_type'] ?? null) === $type) {
                $found = $message;
            }
        }

        return $found;
    }

    /**
     * restic's own words for what went wrong, every secret of the call
     * hidden, at most 1,000 characters; empty when it succeeded. For the log
     * and the operator's terminal: a person reads sentence().
     */
    public function detail(): string
    {
        return $this->detail;
    }

    /**
     * What went wrong at $place, in words people read; empty when it
     * succeeded.
     */
    public function sentence(string $place): string
    {
        return $this->outcome->sentence($place, $this->timeoutSeconds);
    }

    /**
     * restic's fatal error from its standard error: the exit_error message
     * that --json prints there, or else its last line.
     */
    private static function errorWords(string $errorOutput): string
    {
        $last = '';

        foreach (preg_split('/\R/', $errorOutput) ?: [] as $line) {
            $message = json_decode($line, true);

            if (is_array($message) && ($message['message_type'] ?? null) === 'exit_error' && is_string($message['message'] ?? null)) {
                return $message['message'];
            }

            if (trim($line) !== '') {
                $last = trim($line);
            }
        }

        return $last;
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function hide(string $words, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $words = str_replace($secret, '[hidden]', $words);
            }
        }

        return mb_strimwidth($words, 0, self::DETAIL_LENGTH, '…');
    }
}
