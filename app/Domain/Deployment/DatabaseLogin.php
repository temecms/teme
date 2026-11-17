<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use SensitiveParameter;

/**
 * The database login Teme runs as, and where: the one the database step made, or the one it was given when it
 * could make none. It holds a password, so it prints only as lines for a shell to read, never in a sentence.
 */
final readonly class DatabaseLogin
{
    public function __construct(
        public string $host,
        public string $port,
        public string $database,
        public string $username,
        #[SensitiveParameter] public string $password,
        public ?string $verifyDatabase,
        public bool $created,
    ) {}

    /**
     * The login as `export NAME='value'` lines, each value quoted so no character in it is read by the shell.
     */
    public function shell(): string
    {
        $settings = [
            'DB_HOST' => $this->host,
            'DB_PORT' => $this->port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->username,
            'DB_PASSWORD' => $this->password,
        ];

        if ($this->verifyDatabase !== null) {
            $settings['TEME_VERIFY_DATABASE'] = $this->verifyDatabase;
        }

        $lines = '';

        foreach ($settings as $name => $value) {
            $lines .= "export {$name}=".self::quote($value)."\n";
        }

        return $lines;
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
