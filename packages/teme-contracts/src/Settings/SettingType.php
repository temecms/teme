<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * The kinds of value a setting can hold. Each has one form control.
 *
 * @since 0.1.0
 */
enum SettingType: string
{
    /** @since 0.1.0 */
    case Text = 'text';

    /** @since 0.1.0 */
    case Textarea = 'textarea';

    /** @since 0.1.0 */
    case Number = 'number';

    /** @since 0.1.0 */
    case Boolean = 'boolean';

    /** @since 0.1.0 */
    case Select = 'select';

    /** @since 0.1.0 */
    case MultiSelect = 'multiselect';

    /** @since 0.1.0 */
    case Url = 'url';

    /** @since 0.1.0 */
    case Email = 'email';

    /** @since 0.1.0 */
    case Secret = 'secret';

    /** @since 0.1.0 */
    case Timezone = 'timezone';

    /** @since 0.1.0 */
    case Color = 'color';

    /** @since 0.1.0 */
    case Media = 'media';

    /** @since 0.1.0 */
    case Pairs = 'pairs';

    /** @since 0.1.0 */
    case Urls = 'urls';

    /** @since 0.1.0 */
    case Person = 'person';

    /** @since 0.1.0 */
    case Hours = 'hours';

    /** @since 1.0.0 */
    case Term = 'term';

    /** @since 1.0.0 */
    case ContentType = 'type';

    /** Markup in a monospace box, up to 65,535 characters. @since 1.0.0 */
    case Code = 'code';

    /** A list of items of one shape, stored as a JSON list. @since 1.0.0 */
    case Repeater = 'repeater';
}
