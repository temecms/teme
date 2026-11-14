<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\People\Credits;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\People\Operations\SetBylines;
use App\Domain\People\Queries\People;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * teme:bylines:set — sets the people an item credits, by their address
 * slugs, in order, as the operator, through SetBylines; --owner credits its
 * owner again (I-3366). Nothing is written until every slug is found.
 */
final class SetBylinesCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:bylines:set {item : The item\'s ID} {people?* : The people to credit, by address slug, in order} {--owner : Credit its owner again}';

    /** @var string */
    protected $description = 'Set the people an item credits';

    public function handle(SetBylines $set, People $people, Credits $credits): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $item = (string) $this->argument('item');
        $slugs = array_values(array_map(strval(...), (array) $this->argument('people')));
        $owner = (bool) $this->option('owner');

        if (($slugs === []) === ! $owner) {
            $this->error(__('Name the people to credit, or pass --owner.'));

            return self::FAILURE;
        }

        $named = [];

        foreach ($slugs as $slug) {
            $credit = $people->bySlug($slug);

            if ($credit === null) {
                $this->error(__("There's no one with the address slug :slug. Nothing was changed.", ['slug' => $slug]));

                return self::FAILURE;
            }

            $named[] = $credit->key();
        }

        $title = $credits->title($item);

        if ($title === null) {
            $this->error(__("There's no item with the ID :id. Nothing was changed.", ['id' => $item]));

            return self::FAILURE;
        }

        try {
            $set->execute(new SetBylinesInput(null, $item, $named));
        } catch (ValidationException $refused) {
            $this->error(collect($refused->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $names = array_map(fn (object $person): string => (string) $person->name, $credits->shown([$item])[$item] ?? []);

        $this->info($owner
            ? __('“:title” now credits its owner, :name.', ['title' => $title, 'name' => $names[0] ?? ''])
            : __('“:title” now credits :names.', ['title' => $title, 'names' => implode(', ', $names)]));

        return self::SUCCESS;
    }
}
