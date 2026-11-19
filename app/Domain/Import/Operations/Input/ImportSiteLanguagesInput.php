<?php

/**
 * What ImportSiteLanguages needs: the old site's other languages, each as
 * the Teme language it becomes and its address prefix, in its order; the
 * content types and taxonomies not translated here, or null to leave them;
 * and who is asking.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ImportSiteLanguagesInput implements OperationInput
{
    /**
     * @param  list<array{language: string, prefix: string}>  $languages
     * @param  list<string>|null  $untranslatedTypes
     * @param  list<string>|null  $untranslatedTaxonomies
     */
    public function __construct(
        public array $languages,
        public ?array $untranslatedTypes,
        public ?array $untranslatedTaxonomies,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
