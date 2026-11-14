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
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Url = 'url';
    case Email = 'email';
    case Secret = 'secret';
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
}
