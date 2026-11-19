<?php

/**
 * teme:database:prepare — the database step of a container that runs every role: makes Teme's own limited login
 * on the PostgreSQL service it was given (or finds it can't, and says so). With --shell it prints the login
 * as lines for a shell to read, and says everything else on the error output, so what it prints is only that.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deployment\DatabaseAddress;
use App\Domain\Deployment\DatabasePrepareFailed;
use App\Domain\Deployment\PrepareDatabase;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class PrepareDatabaseCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:database:prepare
        {--shell : Print the login Teme runs as, as export lines, and nothing else; every sentence goes to the error output}';

    /** @var string */
    protected $description = 'Make Teme\'s own limited database login on the server it was given, and print the login Teme runs as';

    public function handle(PrepareDatabase $prepare): int
    {
        $problems = DatabaseAddress::problems(config());

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->say($problem);
            }

            return self::FAILURE;
        }

        $key = (string) config('app.key');

        if ($key === '') {
            $this->say('APP_KEY is empty, and the database login\'s password is made from it. Set APP_KEY first.');

            return self::FAILURE;
        }

        try {
            $login = $prepare->run($this->given(), $key, $this->spare());
        } catch (DatabasePrepareFailed $failure) {
            $this->say($failure->getMessage());

            return self::FAILURE;
        }

        $this->say($login->created
            ? "Teme runs as its own database login, {$login->username}, which can't make databases or roles."
            : "Teme runs as {$login->username}, the database user it was given, because that user can't make a login of its own. Give Teme a PostgreSQL superuser to have it make one.");

        if ($this->option('shell')) {
            $this->output->write($login->shell(), false, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    /**
     * The login Teme was given, from its address when DATABASE_URL gives one, else from DB_HOST and the rest.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function given(): array
    {
        $url = config('database.connections.pgsql.url');
        $fromUrl = is_string($url) && $url !== '' ? DatabaseAddress::parts($url) : null;

        return $fromUrl ?? [
            'host' => (string) config('database.connections.pgsql.host'),
            'port' => (string) config('database.connections.pgsql.port'),
            'database' => (string) config('database.connections.pgsql.database'),
            'username' => (string) config('database.connections.pgsql.username'),
            'password' => (string) config('database.connections.pgsql.password'),
        ];
    }

    /** The spare database the operator named, if they did. */
    private function spare(): ?string
    {
        $name = config('database.connections.teme_verify.database');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function say(string $sentence): void
    {
        fwrite(STDERR, $sentence."\n");
    }
}
