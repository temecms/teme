<?php

/**
 * teme:backup:restored — after a restore, says whether this server is now the
 * site, as the operator (docs/architecture/operations.md): its backups resume,
 * or the backup places the restore brought back are removed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Operations\ConfirmRestoredSite;
use App\Domain\Backups\Operations\Input\ConfirmRestoredSiteInput;
use App\Domain\Backups\RestoredSiteAnswer;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AnswerRestoredSite extends Command
{
    private const string SITE = 'This server is now the site';

    private const string COPY = 'This is a copy: remove these backup places';

    /** @var string */
    protected $signature = 'teme:backup:restored {answer? : site or copy}';

    /** @var string */
    protected $description = 'After a restore, say whether this server is now the site: its backups resume, or its backup places are removed';

    public function handle(ConfirmRestoredSite $confirm): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $given = $this->argument('answer');

        if ($given === null) {
            if (! $this->input->isInteractive()) {
                $this->error('Say which: php artisan teme:backup:restored site, or php artisan teme:backup:restored copy.');

                return self::FAILURE;
            }

            $given = $this->choice('Is this server now the site?', [self::SITE, self::COPY]) === self::SITE ? 'site' : 'copy';
        }

        $answer = RestoredSiteAnswer::tryFrom((string) $given);

        if ($answer === null) {
            $this->error('Answer site or copy.');

            return self::FAILURE;
        }

        try {
            $names = $confirm->execute(new ConfirmRestoredSiteInput($answer));
        } catch (Throwable $refused) {
            // Nothing paused: the Operation's validation, which reaches here through the container.
            if (! $refused instanceof ValidationException) {
                throw $refused;
            }

            foreach (Arr::flatten($refused->errors()) as $sentence) {
                $this->error((string) $sentence);
            }

            return self::FAILURE;
        }

        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : $names[0];

        $this->line($answer === RestoredSiteAnswer::ThisIsTheSite
            ? "Backups resume at {$list}."
            : "Removed {$list} from this site’s backup places. Their backups stay where they are. Set backups up for this copy with php artisan teme:backup:setup.");

        return self::SUCCESS;
    }
}
