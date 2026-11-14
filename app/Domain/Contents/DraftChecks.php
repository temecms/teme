<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\FieldValidator;
use App\Domain\Regions\RegionRules;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use Illuminate\Support\Facades\Validator;

/**
 * The checks a draft of an existing item must pass, whether it is being saved
 * as a revision or autosaved: its title, its document (normalised, then held
 * to the schema), its fields, its template and its terms. SaveDraft and SaveAutosave share
 * them, so a checkpoint can never fail on something an autosave accepted.
 */
final class DraftChecks
{
    /**
     * Returns when the draft fits; throws a ValidationException when not. An
     * autosave may hold unfinished cards; a revision may not (B-142).
     *
     * @param  array<mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms
     */
    public static function validate(string $contentId, string $title, array $document, array $fields, array $terms, bool $allowUnfinishedCards = false): void
    {
        // Only IDs added since the last save must exist. A term deleted in
        // the meantime stays in the list and is dropped by the projection, so
        // an editor can still save a post whose term has gone.
        // Loaded once, for its working revision, its type and a region's limits.
        $content = Content::query()->find($contentId);
        $working = ContentRevision::query()->whereKey($content?->working_revision_id)->first(['terms', 'fields']);
        $known = (array) ($working->terms ?? []);
        $added = array_values(array_diff($terms, $known));
        $type = (string) $content?->type;
        $language = $content?->language;

        // An added term in another language is refused by name (I-3360).
        TermRules::refuseOtherLanguage($added, $language);

        // Added terms must also belong to a taxonomy the type uses. Kept ones
        // are not re-checked, so a page whose old revision lists a tag can
        // still be saved (I-417); the projection ignores that tag.
        Validator::make(
            [
                'title' => trim($title),
                'document' => $document,
                'terms' => $terms,
                'added' => $added,
            ],
            [
                'title' => ['string', 'max:512'],
                'document' => ['required', 'array'],
                'terms' => ['array', 'max:50'],
                'terms.*' => ['string', 'distinct'],
                'added.*' => [TermRules::usableBy($type, $language)],
            ],
        )->validate();

        app(FieldValidator::class)->validate($type, $fields, (array) ($working->fields ?? []));
        // Like a term, a template is checked only when newly chosen (I-443).
        TemplateChoice::check($type, $fields, (array) ($working->fields ?? []));
        // A region keeps to its theme's declaration, for a save and an autosave alike.
        app(DocumentValidator::class)->validate(
            DocumentNormaliser::normalise($document),
            $allowUnfinishedCards,
            rules: $content === null ? null : RegionRules::forContent($content),
        );
    }
}
