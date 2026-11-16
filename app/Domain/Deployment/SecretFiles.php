<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use Illuminate\Support\Env;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Secrets a server hands Teme as files (I-375): for each name below, the
 * same name with _FILE added names the file that holds the value. Read once
 * the environment is loaded and before configuration, in every process, and
 * never put where a program Teme starts would inherit it.
 */
final class SecretFiles
{
    /**
     * Every secret the image may take from a file, by its variable's name.
     *
     * @var list<string>
     */
    public const array NAMES = [
        'APP_KEY',
        'APP_PREVIOUS_KEYS',
        'DB_PASSWORD',
        'REDIS_PASSWORD',
        'MAIL_PASSWORD',
        'CLOUDFLARE_API_TOKEN',
        'TEME_AI_ANTHROPIC_KEY',
        'TEME_AI_OPENAI_KEY',
        'TEME_AI_DEEPSEEK_KEY',
        'TEME_AI_OPENROUTER_KEY',
        'TEME_BACKUP_MACHINE_KEY',
        'TEME_VERIFY_DB_PASSWORD',
        'TEME_GITHUB_TOKEN',
        'TEME_DOKPLOY_TOKEN',
        'MEDIA_BUCKET_SECRET_ACCESS_KEY',
    ];

    /**
     * Reads each named secret whose _FILE variable is set into $_SERVER,
     * where env() looks first.
     *
     * @throws SecretFileRefused when a variable and its file disagree, or the file can't be read
     */
    public static function resolve(): void
    {
        foreach (self::NAMES as $name) {
            $path = Env::getRepository()->get($name.'_FILE');

            if ($path === null || $path === '') {
                continue;
            }

            $value = self::read($name, $path);
            $shown = Env::get($name);

            if ($shown !== null && $shown !== '') {
                // The same secret twice is no conflict: a second start in one
                // process, as config:cache makes, finds the first one's value.
                if (! hash_equals((string) Env::getRepository()->get($name), $value)) {
                    throw SecretFileRefused::bothSet($name);
                }

                continue;
            }

            // $_SERVER alone: Symfony's Process hands a child $_ENV and the
            // process environment, never a name set only here.
            $_SERVER[$name] = $value;
        }
    }

    /**
     * resolve(), for bootstrap/app.php: a refusal stops the process with one
     * line on stderr, as Laravel stops on a broken .env file, before any
     * logger exists to write it anywhere else.
     */
    public static function resolveOrStop(): void
    {
        try {
            self::resolve();
        } catch (SecretFileRefused $refusal) {
            (new ConsoleOutput)->getErrorOutput()->writeln('Teme cannot start: '.$refusal->getMessage(), OutputInterface::OUTPUT_RAW);
            http_response_code(500);

            exit(1);
        }
    }

    /**
     * The file's contents, less the one line break an editor or echo leaves
     * at the end; anything else in it is the secret's.
     */
    private static function read(string $name, string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw SecretFileRefused::unreadable($name, $path);
        }

        return (string) preg_replace('/\r?\n\z/', '', $contents);
    }
}
