<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use App\Domain\Security\EncryptedColumn;
use App\Domain\Security\EncryptedForm;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Finds the encrypted casts on the models it is given, from each model's
 * own getCasts() and getTable(), and says which the registry lacks.
 */
final class EncryptedCasts
{
    /**
     * Every concrete Eloquent model under app/, found by its file.
     *
     * @return list<class-string<Model>>
     */
    public static function appModels(): array
    {
        $models = [];

        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * Each encrypted cast as `table.column` => the form its cast writes.
     *
     * @param  iterable<class-string<Model>>  $models
     *
     * @return array<string, EncryptedForm>
     */
    public static function found(iterable $models): array
    {
        $found = [];

        foreach ($models as $class) {
            $model = new $class;

            foreach ($model->getCasts() as $column => $cast) {
                $form = match (true) {
                    $cast === 'encrypted' => EncryptedForm::String,
                    in_array($cast, ['encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], true),
                    str_starts_with((string) $cast, AsEncryptedArrayObject::class),
                    str_starts_with((string) $cast, AsEncryptedCollection::class) => EncryptedForm::Json,
                    default => null,
                };

                if ($form !== null) {
                    $found[$model->getTable().'.'.$column] = $form;
                }
            }
        }

        return $found;
    }

    /**
     * The casts the registry lacks, or names in another form, as
     * `table.column (form)`.
     *
     * @param  iterable<class-string<Model>>  $models
     * @param  list<EncryptedColumn>  $registry
     *
     * @return list<string>
     */
    public static function unlisted(iterable $models, array $registry): array
    {
        $registered = [];

        foreach ($registry as $column) {
            $registered[$column->name()] = $column->form;
        }

        $unlisted = [];

        foreach (self::found($models) as $name => $form) {
            if (($registered[$name] ?? null) !== $form) {
                $unlisted[] = "{$name} ({$form->value})";
            }
        }

        return $unlisted;
    }
}
