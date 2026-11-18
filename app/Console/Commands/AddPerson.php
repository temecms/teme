<?php

/**
 * teme:people:add — adds a person with no account, who can be credited on
 * items, as the operator, through CreatePerson (I-3366).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Addresses\Addresses;
use App\Domain\People\Credit;
use App\Domain\People\Operations\CreatePerson;
use App\Domain\People\Operations\Input\CreatePersonInput;
use App\Domain\People\Queries\People;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class AddPerson extends Command
{
    /** @var string */
    protected $signature = 'teme:people:add {name : Their name} {--slug= : The last part of their author page\'s address} {--bio= : Their biography}';

    /** @var string */
    protected $description = 'Add a person with no account, to credit on items';

    public function handle(CreatePerson $create, People $people): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $slug = $this->option('slug');
        $bio = $this->option('bio');

        try {
            $id = $create->execute(new CreatePersonInput(null, (string) $this->argument('name'), is_string($slug) ? $slug : null, is_string($bio) ? $bio : null));
        } catch (ValidationException $refused) {
            $this->error(collect($refused->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $shown = $people->shown([Credit::person((string) $id)])['person:'.$id] ?? null;

        $this->info(__('Added :name at :address.', ['name' => (string) $shown?->name, 'address' => Addresses::toUrl((string) $shown?->path)]));

        return self::SUCCESS;
    }
}
