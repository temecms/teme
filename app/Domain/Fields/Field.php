<?php

declare(strict_types=1);

namespace App\Domain\Fields;

/**
 * One declared field: its immutable key, which is what stored values are
 * keyed by, a handle that may change, its type, the content types that have
 * it — named, or by a feature they support — and the documentation its
 * reference entry is generated from.
 */
final readonly class Field
{
    /**
     * @param  list<string>  $contentTypes
     * @param  array<string, string>  $options  for select fields: value => label
     * @param  string|null  $feature  a type that supports this feature has the field too (study Y)
     * @param  FieldPlace  $place  where the editor draws it: core's own panels, or the Details section
     * @param  bool  $search  whether its words join the item's site search
     * @param  bool  $filterable  whether lists may filter and sort by it, once they can
     * @param  FieldShow  $show  where the default theme lists it on the item's page (I-3322)
     * @param  bool  $stored  whether the site made it, rather than code
     */
    public function __construct(
        public string $key,
        public string $handle,
        public string $label,
        public string $description,
        public FieldType $type,
        public array $contentTypes,
        public string $since,
        public bool $required = false,
        public ?int $maxLength = null,
        public array $options = [],
        public ?string $feature = null,
        public FieldPlace $place = FieldPlace::Details,
        public bool $search = false,
        public bool $filterable = false,
        public FieldShow $show = FieldShow::Hidden,
        public bool $stored = false,
    ) {}
}
