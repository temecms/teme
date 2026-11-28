<?php

/**
 * A plugin's area or panel object that lists the options of a select field
 * whose manifest says "choices": "plugin", each time the form opens.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * Lists the options one select field offers, asked when the form opens.
 * Asked within a time budget; one that throws or runs over gives no options,
 * and the field keeps its saved value.
 *
 * @since next
 */
interface OffersFieldChoices
{
    /**
     * The options for one field whose manifest says "choices": "plugin", at
     * most 50, in the order shown.
     *
     * @param  string  $form  the area record's name, or the panel action's id
     * @param  string  $field  the field's key
     *
     * @return list<FieldChoice>
     *
     * @since next
     */
    public function fieldChoices(string $form, string $field): array;
}
