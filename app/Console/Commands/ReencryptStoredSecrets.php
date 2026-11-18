<?php

/**
 * teme:secrets:reencrypt — writes every value Teme keeps encrypted again under this server's APP_KEY, opening each with an old key read from a file or a hidden prompt, never an argument (I-119): after a restore by hand, or when the operator changes APP_KEY.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\OldKeys;
use App\Domain\Security\Operations\Input\ReencryptSecretsInput;
use App\Domain\Security\Operations\ReencryptSecrets;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;

final class ReencryptStoredSecrets extends Command
{
    /** @var string */
    protected $signature = 'teme:secrets:reencrypt {--key-file= : A file holding the old key: a backup’s app-key.json, or the key on a line of its own}';

    /** @var string */
    protected $description = 'Re-encrypt every secret Teme keeps under this server’s APP_KEY, opening each with an old key';

    public function handle(ReencryptSecrets $reencrypt): int
    {
        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $hasPreviousKeys = app(Encrypter::class)->getPreviousKeys() !== [];

        try {
            $keys = $this->givenKeys($hasPreviousKeys);

            if ($keys === null) {
                $this->error('Give the old key: type it at the prompt, or name a file with --key-file. Nothing was changed.');

                return self::FAILURE;
            }

            $result = $reencrypt->execute(new ReencryptSecretsInput($keys));
        } catch (ValidationException $refusal) {
            // Its words never repeat what was given (OldKeys).
            $this->error($refusal->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($result->lines() as $line) {
            $this->line($line);
        }

        if ($result->isComplete()) {
            $this->info('Every encrypted value now opens with this server’s key.');

            if ($hasPreviousKeys) {
                $this->line('Nothing Teme keeps needs APP_PREVIOUS_KEYS now: remove the old key from the server’s environment, and restart.');
            }

            return self::SUCCESS;
        }

        $this->error(trans_choice(
            ':count value opens with no key given and was left as it was. Run this again with the key it was made with; everything else is done.|:count values open with no key given and were left as they were. Run this again with the key they were made with; everything else is done.',
            $result->unopenedCount(),
        ));

        if ($result->touchesTwoFactor()) {
            $this->line('A person listed under two-factor can’t finish signing in with it until their values open.');
        }

        return self::FAILURE;
    }

    /**
     * The old keys, from --key-file or the hidden prompt; null when nothing
     * was given and the server lists no previous keys either.
     *
     * @throws ValidationException
     */
    private function givenKeys(bool $hasPreviousKeys): ?OldKeys
    {
        $file = $this->option('key-file');

        if (is_string($file) && $file !== '') {
            $contents = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

            if ($contents === false) {
                throw ValidationException::withMessages(['old_key' => "Teme can’t read {$file}."]);
            }

            return OldKeys::fromFileContents($contents);
        }

        // No fallback: a terminal that can't hide input refuses, never echoes.
        $typed = (string) $this->secret($hasPreviousKeys
            ? 'The old APP_KEY, as the old server had it (press Enter to use only this server’s APP_PREVIOUS_KEYS)'
            : 'The old APP_KEY, as the old server had it', false);

        if (trim($typed) === '') {
            return $hasPreviousKeys ? OldKeys::none() : null;
        }

        return OldKeys::fromText($typed);
    }
}
