<?php

/**
 * One field of an item as a template draws it: its handle, label and type, its
 * value as a plain PHP value, the words to print, and, for a web address, an
 * item or an image, what to link or draw.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

use DateTimeImmutable;
use Stringable;

/**
 * One field of an item as a template draws it: its handle, label
 * and type, its value as a plain PHP value, the words to print, and, for a
 * web address, an item or an image, what to link or draw. It prints as its
 * text, so {{ $page->field('runtime') }} shows the value.
 */
final readonly class FieldView implements Stringable
{
    /**
     * @param  string  $handle  the field's handle now
     * @param  string  $label  as the site's builder wrote it
     * @param  string  $type  text, textarea, number, select, boolean, url, date, datetime, media or entry
     * @param  string|int|float|bool|DateTimeImmutable|null  $value  the plain value: see the field-value table in docs/reference/theme.md
     * @param  string  $text  the value as words, escaped by the template like any text; '' when empty
     * @param  string|null  $url  a web address's address, or an item's address now
     * @param  ImageRef|null  $image  a media field's image, with the alt text for this use
     * @param  string  $show  where Show on the site lists it: after, before or hidden
     * @param  bool  $empty  whether there is nothing to show
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $type,
        public string|int|float|bool|DateTimeImmutable|null $value,
        public string $text,
        public ?string $url,
        public ?ImageRef $image,
        public string $show,
        public bool $empty,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
