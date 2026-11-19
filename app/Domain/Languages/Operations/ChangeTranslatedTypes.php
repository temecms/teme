<?php

/**
 * Chooses which content types and taxonomies are translated (D25, I-3370),
 * stored as the ones that aren't. Turning translation off is refused while
 * anything of the type, trash included, is in another language or linked;
 * turning it on moves nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations;

use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\Operations\Input\ChangeTranslatedTypesInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ChangeTranslatedTypes extends BaseOperation
{
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangeTranslatedTypesInput $input */
        $this->mayManage($input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangeTranslatedTypesInput $input */
        $types = array_map(fn (ContentType $type): string => $type->key, app(ContentTypeRegistry::class)->all());
        $taxonomies = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->all());

        foreach (['untranslated_types' => [$input->untranslatedTypes, $types], 'untranslated_taxonomies' => [$input->untranslatedTaxonomies, $taxonomies]] as $key => [$chosen, $known]) {
            if (array_diff($chosen, $known) !== []) {
                throw ValidationException::withMessages([$key => __('Choose content types and taxonomies this site has.')]);
            }
        }

        $this->refuseInUse($input);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ChangeTranslatedTypesInput $input */
        // Alone: a draft, a translation or a term in a language holds the lock shared while it writes.
        LanguageLock::exclusive();
        $this->refuseInUse($input);

        app(SettingsWriter::class)->write(new SettingsWrite(
            group: SiteLanguages::GROUP,
            values: [
                'untranslated_types' => array_values(array_unique($input->untranslatedTypes)),
                'untranslated_taxonomies' => array_values(array_unique($input->untranslatedTaxonomies)),
            ],
            expectedVersion: $input->expectedVersion,
            actorId: $input->actorId,
            origin: 'human',
        ));

        return null;
    }

    protected function afterCommit(mixed $result): void
    {
        app()->forgetInstance(SiteLanguages::class);
    }

    /**
     * Refuses turning translation off for a type or taxonomy while anything
     * of it, trash included, has a language or a group, counting them.
     *
     * @throws ValidationException
     */
    private function refuseInUse(ChangeTranslatedTypesInput $input): void
    {
        $languages = app(SiteLanguages::class);
        $default = $languages->label($languages->defaultTag());

        foreach ([['untranslated_types', 'contents', 'type', $input->untranslatedTypes], ['untranslated_taxonomies', 'terms', 'taxonomy', $input->untranslatedTaxonomies]] as [$key, $table, $column, $keys]) {
            foreach (array_values(array_unique($keys)) as $chosen) {
                // One statement a type; a type already off has nothing in a language to count.
                $count = DB::table($table)->where($column, $chosen)->where(fn ($query) => $query->whereNotNull('language')->orWhereNotNull('translation_group'))->count();

                if ($count === 0) {
                    continue;
                }

                [$singular, $plural] = $table === 'contents'
                    ? [app(ContentTypeRegistry::class)->get($chosen)->singular, app(ContentTypeRegistry::class)->get($chosen)->plural]
                    : [app(TaxonomyRegistry::class)->get($chosen)->singular, app(TaxonomyRegistry::class)->get($chosen)->plural];

                $words = ['count' => $count, 'singular' => mb_strtolower($singular), 'plural' => mb_strtolower($plural), 'language' => $default];

                throw ValidationException::withMessages([$key => $count === 1
                    ? __('1 :singular is in other languages or linked as translations. Move it to :language and unlink it, or delete it, before :plural stop being translated.', $words)
                    : __(':count :plural are in other languages or linked as translations. Move them to :language and unlink them, or delete them, before :plural stop being translated.', $words)]);
            }
        }
    }
}
