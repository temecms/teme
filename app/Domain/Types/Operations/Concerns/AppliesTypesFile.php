<?php

/**
 * What an import of a teme-types file and a plugin's types share (I-411):
 * the checks a file must pass against this site, and adding and updating
 * its taxonomies, types and fields, never removing one only this site has.
 * A class using it uses ManagesTypes, ManagesTaxonomies and ManagesFields too.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Concerns;

use App\Domain\Addresses\AddressPattern;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\FieldRules;
use App\Domain\Fields\Stored\FieldsFile;
use App\Domain\Fields\Stored\FieldValues;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TaxonomyRules;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypeOwners;
use App\Domain\Types\TypePermissions;
use App\Domain\Types\TypeRules;
use App\Domain\Types\TypesFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait AppliesTypesFile
{
    /**
     * Everything the file can't do on this site, by the file's place; empty
     * when it can be applied. A type, taxonomy or field a plugin brought is
     * changed only by that plugin, named by $plugin; an import names none.
     *
     * @param  array<mixed>  $file
     *
     * @return array<string, list<string>>
     *
     * @throws ValidationException when the file's own shape is wrong
     */
    private function typesFileProblems(array $file, ?string $plugin = null): array
    {
        $definitions = TypesFile::definitions($file);
        $taxonomies = TypesFile::taxonomies($file);
        $types = app(ContentTypeRegistry::class);
        $registry = app(TaxonomyRegistry::class);
        $coming = array_map(fn ($taxonomy): string => $taxonomy->key, $taxonomies);
        $problems = [];

        // The first word of every address the file's own types will have: a
        // new taxonomy's base can't be one of them, though they aren't stored yet.
        $firstWords = [];

        foreach ($definitions as $type) {
            if (! $type->public) {
                continue;
            }

            try {
                $firstWords[] = AddressPattern::parse((string) $type->settled()->pattern)->firstFixedSegment();
            } catch (InvalidArgumentException) {
                // TypeRules reports the pattern.
            }
        }

        // A file's code_types may name only listed types code declares: a type
        // made here names its taxonomies itself, and a row naming one is
        // refused on load, taking the type with it.
        $codeKeys = array_map(fn ($type): string => $type->key, array_filter(app(ContentTypeRegistry::class)->fromCode(), fn ($type): bool => $type->listed));

        foreach ($taxonomies as $i => $taxonomy) {
            foreach (array_diff($taxonomy->codeTypes, $codeKeys) as $key) {
                $problems["taxonomies.{$i}.code_types"][] = __(':plural can be given here only to types code declares, and :key isn’t one. A type made on this site names its taxonomies itself.', ['plural' => $taxonomy->plural, 'key' => $key]);
            }

            try {
                $current = app(StoredTaxonomies::class)->find($taxonomy->key);
            } catch (InvalidTaxonomyDefinition) {
                foreach (TaxonomyRules::problems($taxonomy, null, $taxonomy->codeTypes, checkKey: false) as $field => $message) {
                    $problems["taxonomies.{$i}.{$field}"][] = $message;
                }

                continue;
            }

            if ($current === null && $registry->has($taxonomy->key) && ! $registry->get($taxonomy->key)->stored) {
                $problems["taxonomies.{$i}.key"][] = __(':key is declared by code on this site, so a file can’t bring it.', ['key' => $taxonomy->key]);

                continue;
            }

            foreach (TaxonomyRules::problems($taxonomy, $current, $taxonomy->codeTypes) as $field => $message) {
                $problems["taxonomies.{$i}.{$field}"][] = $message;
            }

            $settled = $taxonomy->settled();

            if (! isset($problems["taxonomies.{$i}.base"]) && $settled->public && ($current === null || ! $current->public) && in_array($settled->base, $firstWords, true)) {
                $problems["taxonomies.{$i}.base"][] = __('Addresses starting /:base/ are already in use on this site.', ['base' => (string) $settled->base]);
            }
        }

        foreach ($definitions as $i => $type) {
            try {
                $current = app(StoredTypes::class)->find($type->key);
            } catch (InvalidTypeDefinition) {
                foreach (TypeRules::problems($type, null, checkKey: false, comingTaxonomies: $coming) as $field => $message) {
                    $problems["types.{$i}.{$field}"][] = $message;
                }

                continue;
            }

            if ($current === null && $types->has($type->key) && ! $types->get($type->key)->stored) {
                $problems["types.{$i}.key"][] = __(':key is declared by code on this site, so a file can’t bring it.', ['key' => $type->key]);

                continue;
            }

            foreach (TypeRules::problems($type, $current, comingTaxonomies: $coming) as $field => $message) {
                $problems["types.{$i}.{$field}"][] = $message;
            }
        }

        return array_merge_recursive($problems, TypeOwners::problems($file, $plugin));
    }

    /**
     * Adds and updates what the file names, under the types' lock, and
     * reports it. With $kindsFollowFile, a field the file gives another kind
     * takes it while no item holds a value in it.
     *
     * @param  array<mixed>  $file
     *
     * @return array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, patternKept: list<string>, grantsKept: list<string>, taxonomies: array{added: list<string>, updated: list<string>, unchanged: list<string>, onlyHere: list<string>, baseKept: list<string>}, fieldsAdded: list<string>, fieldsUpdated: list<string>, fieldsUnchanged: list<string>, fieldsOnlyHere: list<string>}
     */
    private function applyTypesFile(array $file, ?string $actorId, bool $kindsFollowFile = false): array
    {
        StoredTypes::lock();

        $report = ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []]];
        $named = [];
        $grants = TypesFile::grants($file);

        // The taxonomies first: a type may name one the file brings.
        $namedTaxonomies = [];

        foreach (TypesFile::taxonomies($file) as $i => $taxonomy) {
            $namedTaxonomies[] = $taxonomy->key;
            $prefix = "taxonomies.{$i}.";

            try {
                $current = app(StoredTaxonomies::class)->find($taxonomy->key, forUpdate: true);
            } catch (InvalidTaxonomyDefinition) {
                // A row nobody can read: the file's taxonomy takes its place.
                app(StoredTaxonomies::class)->delete($taxonomy->key);
                $settled = $taxonomy->settled();
                $this->refuse($this->taxonomyTakenProblems($settled, new: true), $prefix);
                app(StoredTaxonomies::class)->insert($settled);
                $report['taxonomies']['updated'][] = $taxonomy->key;

                continue;
            }

            if ($current === null) {
                $settled = $taxonomy->settled();
                $this->refuse($this->taxonomyTakenProblems($settled, new: true), $prefix);
                app(StoredTaxonomies::class)->insert($settled);
                $report['taxonomies']['added'][] = $taxonomy->key;

                continue;
            }

            // An import never moves a public taxonomy's addresses (I-3300); a
            // taxonomy it makes public takes the file's base, which was checked.
            $becomesPublic = $taxonomy->public && ! $current->public;

            if (! $becomesPublic && $current->public && $taxonomy->public && $taxonomy->settled()->base !== $current->base) {
                $report['taxonomies']['baseKept'][] = $taxonomy->key;
            }

            $next = ($becomesPublic ? $taxonomy : $taxonomy->withBase($current->base))->settled();

            if ($next->toArray() === $current->toArray()) {
                $report['taxonomies']['unchanged'][] = $taxonomy->key;

                continue;
            }

            $this->refuse([
                ...$this->taxonomyTakenProblems($next, new: false, newBase: $becomesPublic),
                ...$this->termProblems($next, $current),
                ...$this->takenOffProblems($current, array_values(array_diff($current->codeTypes, $next->codeTypes))),
            ], $prefix);
            app(StoredTaxonomies::class)->update($next);
            $report['taxonomies']['updated'][] = $taxonomy->key;
        }

        $report['taxonomies']['onlyHere'] = array_values(array_diff(array_column(app(StoredTaxonomies::class)->definitions(), 'key'), $namedTaxonomies));

        foreach (TypesFile::definitions($file) as $i => $type) {
            $named[] = $type->key;
            $fileGrants = $grants[$type->key] ?? [];

            try {
                $current = app(StoredTypes::class)->find($type->key, forUpdate: true);
            } catch (InvalidTypeDefinition) {
                // A row nobody can read: the file's type takes its place, its
                // permissions judged against the row as far as it can be read.
                $mended = app(StoredTypes::class)->findLeniently($type->key, forUpdate: true);
                app(StoredTypes::class)->delete($type->key);
                $settled = $type->settled();
                $this->refuse([...$this->takenProblems($settled, new: true), ...$this->permissionProblems($settled, $mended)], "types.{$i}.");
                app(StoredTypes::class)->insert($settled);
                $this->applyPermissions($settled, $mended, $fileGrants);
                $report['updated'][] = $type->key;

                continue;
            }

            if ($current === null) {
                $settled = $type->settled();
                $this->refuse([...$this->takenProblems($settled, new: true), ...$this->permissionProblems($settled, null)], "types.{$i}.");
                app(StoredTypes::class)->insert($settled);
                $this->applyPermissions($settled, null, $fileGrants);
                $report['added'][] = $type->key;

                continue;
            }

            // A type that already has its own keeps this site's grants (I-413).
            if ($current->permissions === TypeDefinition::OWN && $type->permissions === TypeDefinition::OWN && TypePermissions::differs($type->key, $fileGrants)) {
                $report['grantsKept'][] = $type->key;
            }

            // An import never moves a public type's addresses (I-412); a type
            // it makes public takes the file's pattern, which was checked.
            if ($current->pattern !== null && $type->public && $type->settled()->pattern !== $current->pattern) {
                $report['patternKept'][] = $type->key;
            }

            $next = $current->pattern === null
                ? $type->settled()
                : $type->withPattern($current->pattern)->settled();

            if ($next->toArray() === $current->toArray()) {
                $report['unchanged'][] = $type->key;

                continue;
            }

            $this->refuse([...$this->itemProblems($next, $current), ...$this->takenProblems($next, new: false), ...$this->permissionProblems($next, $current)], "types.{$i}.");
            app(StoredTypes::class)->update($next);
            $this->applyPermissions($next, $current, $fileGrants);
            $report['updated'][] = $type->key;
        }

        $report['onlyHere'] = array_values(array_diff(array_column(app(StoredTypes::class)->definitions(), 'key'), $named));
        $report = [...$report, ...$this->importFields($file, $named, $actorId, $kindsFollowFile)];

        if ($report['added'] !== [] || $report['updated'] !== [] || $report['taxonomies']['added'] !== [] || $report['taxonomies']['updated'] !== [] || $report['fieldsAdded'] !== [] || $report['fieldsUpdated'] !== []) {
            $this->purgeEverything();
        }

        return $report;
    }

    /**
     * Under the types' lock, after the types: adds the file's new fields,
     * updates the ones it changes, restoring any removed here, and leaves a
     * field only this site has in place. Each type's fields take positions
     * from 1 in the order the file lists them, and one only here follows
     * them. Handles are checked as a whole before any field is written. A change to what items are
     * found by rebuilds their type's search after commit, as the importer.
     *
     * @param  array<mixed>  $file
     * @param  list<string>  $typeKeys
     *
     * @return array{fieldsAdded: list<string>, fieldsUpdated: list<string>, fieldsUnchanged: list<string>, fieldsOnlyHere: list<string>}
     */
    private function importFields(array $file, array $typeKeys, ?string $actorId, bool $kindsFollowFile = false): array
    {
        $fields = app(StoredFields::class);
        $report = ['fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []];
        $positions = [];
        $named = [];
        $reindex = [];
        $entries = FieldsFile::definitions($file, $typeKeys);
        $order = [];

        // Handles are checked as a whole first: one only this site has, and
        // one an entry takes from another, would otherwise meet part-way.
        $taken = [];

        foreach (FieldRules::importHandleProblems($entries) as $i => $problem) {
            $taken["{$i}.handle"] = $problem;
        }

        $this->refuse($taken, 'fields.');
        $this->renameAside($entries);

        foreach ($entries as $i => $field) {
            $positions[$field->type] = ($positions[$field->type] ?? 0) + 1;
            $next = $field->withPosition($positions[$field->type]);
            $name = $next->type.'.'.$next->handle;
            $named[] = $next->key;
            $order[$next->type][] = $next->key;

            try {
                $current = $fields->find($next->key, forUpdate: true);
            } catch (InvalidArgumentException) {
                // A row nobody can read: the file's field takes its place.
                $fields->delete($next->key);
                $current = null;
            }

            // A plugin's file may give a field another kind while no item holds a value in it (docs/architecture/plugins.md, *Content types from a plugin*).
            if ($kindsFollowFile && $current !== null && $current->fieldType !== $next->fieldType && (FieldValues::counts($current->type, [$current->key])[$current->key] ?? 0) === 0) {
                $fields->delete($current->key);
                $current = null;
                $report['fieldsUpdated'][] = $name;
                $fields->insert($next);

                if ($next->search) {
                    $reindex[$next->type] = true;
                }

                continue;
            }

            $moved = $current === null ? null : FieldRules::moveProblem($current, $next);

            if ($moved !== null) {
                $this->refuse(['type' => __('Field :n: :problems', ['n' => (string) ($i + 1), 'problems' => $moved])], "fields.{$i}.");
            }

            $holding = $current === null ? null : (FieldValues::counts($current->type, [$current->key])[$current->key] ?? 0);
            $this->refuse(FieldRules::problems($next, $current, $holding), "fields.{$i}.");

            if ($current === null) {
                $fields->insert($next);
                $report['fieldsAdded'][] = $name;

                if ($next->search) {
                    $reindex[$next->type] = true;
                }

                continue;
            }

            if ($current->removedAt === null && $current->position === $next->position && $current->toArray() === $next->toArray()) {
                $report['fieldsUnchanged'][] = $name;

                continue;
            }

            // A file names its fields live: one removed here comes back.
            $fields->update($next);
            $report['fieldsUpdated'][] = $name;

            if (self::searchChanges($current, $next)) {
                $reindex[$next->type] = true;
            }
        }

        // The file's fields first, as it lists them; those only here after them.
        foreach ($order as $type => $keys) {
            $fields->renumber($type, $keys);
        }

        foreach ($fields->definitions() as $row) {
            if ($row['removed_at'] === null && ! in_array($row['key'], $named, true)) {
                $report['fieldsOnlyHere'][] = $row['content_type'].'.'.$row['handle'];
            }
        }

        foreach (array_keys($reindex) as $type) {
            $this->reindexType($type);
        }

        if ($reindex !== []) {
            DB::afterCommit(fn () => $this->rebuildSearch($actorId));
        }

        return $report;
    }

    /**
     * Gives every live field the file names under its own type, and gives
     * another handle, a temporary handle of its own key, so writing each
     * final handle never meets another's on the way.
     *
     * @param  list<FieldDefinition>  $entries
     */
    private function renameAside(array $entries): void
    {
        $renamed = [];

        foreach ($entries as $entry) {
            foreach (app(StoredFields::class)->handles($entry->type) as $row) {
                if ($row['key'] === $entry->key && ! $row['removed'] && $row['handle'] !== $entry->handle) {
                    $renamed[] = $row['key'];
                }
            }
        }

        if ($renamed === []) {
            return;
        }

        DB::table(StoredFields::TABLE)
            ->whereIn('key', $renamed)
            ->update(['handle' => DB::raw("'tmp_' || lower(key)"), 'updated_at' => now()]);
    }
}
