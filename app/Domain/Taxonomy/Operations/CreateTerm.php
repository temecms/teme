<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations;

use App\Domain\Languages\LanguageChoice;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\Concerns\ValidatesTerms;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\TermWriter;
use Teme\Contracts\Operations\OperationInput;

/**
 * Creates a category or tag. Only someone who may manage terms can do it; the
 * slug is made from the name unless one is given, and is unique within its
 * taxonomy and language (I-3360).
 */
final class CreateTerm extends BaseOperation
{
    use ValidatesTerms;

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveTermInput $input */
        $this->authorizeTerms($input, $input->taxonomy);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveTermInput $input */
        LanguageChoice::check($input->language);

        if ($input->language !== null && app(SiteLanguages::class)->stored($input->language) !== null) {
            TranslatedTypes::refuseTaxonomy($input->taxonomy);
        }

        $this->validateTerm($input->taxonomy, $input->name, $input->slug, $input->parentId, null);
        $this->refuseParentInOtherLanguage($input->taxonomy, $input->parentId, $input->language === null ? null : app(SiteLanguages::class)->stored($input->language));
    }

    protected function perform(OperationInput $input): Term
    {
        /** @var SaveTermInput $input */
        $language = LanguageChoice::take($input->language);

        if ($language !== null) {
            TranslatedTypes::refuseTaxonomy($input->taxonomy, fromRow: true);
        }

        [$term, $keys] = TermWriter::create($input->taxonomy, $input->name, $input->slug, $input->parentId, $input->description, $language);

        $this->purgeAfterCommit($keys);

        return $term;
    }
}
