<?php

/**
 * What the comment-check plugin declares for one hidden field in the comment
 * form (docs/guides/developers/plugins/comment-check.md): its name, and how
 * its value is made, as a static value, a time the browser fills in, or an
 * off-screen trap no person sees. Checked when the manifest is read; the
 * values reach the plugin's check as the candidate's signals.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Checks;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Slots\PluginSlots;
use Teme\Contracts\Plugins\Capability;

/**
 * The one hidden field a comment-check plugin may add to the comment form, declared in its manifest. It names the field, says how
 * its value is made, and is read back only after the manifest's own checks have passed again.
 *
 * @since next
 */
final class CommentField
{
    /** The kinds a field may take: a fixed value, the browser's time, or an off-screen trap. */
    public const array KINDS = ['static', 'time', 'trap'];

    /** A field's name, as a form field carries it. */
    public const string NAME = '/^[a-z][a-z0-9-]{2,39}$/D';

    /** The longest fixed value a static field may carry. */
    public const int MOST_VALUE = 200;

    /** Names core's comment form already carries, or reports itself. */
    public const array RESERVED = ['path', 'parent', 'text', 'name', 'email', 'url', 'token', 'subject', 'remember', 'missing-field'];

    /**
     * The declaration, or null when the manifest declares none or it isn't well formed (the validator refused it first).
     *
     * @return array{name: string, make: string, value: string}|null
     */
    public static function of(?Manifest $manifest): ?array
    {
        $declared = $manifest?->contributes()['commentField'] ?? null;

        if (! is_array($declared) || ! is_string($declared['name'] ?? null) || ! is_string($declared['make'] ?? null) || ! in_array($declared['make'], self::KINDS, true)) {
            return null;
        }

        // Checked again here, as the manifest on disk may have changed since it was validated.
        $name = $declared['name'];
        $value = is_string($declared['value'] ?? null) ? $declared['value'] : '';

        if (preg_match(self::NAME, $name) !== 1 || in_array($name, self::RESERVED, true) || mb_strlen($value) > self::MOST_VALUE) {
            return null;
        }

        return ['name' => $name, 'make' => $declared['make'], 'value' => $value];
    }

    /**
     * Whether a manifest gives the comment form's fields a reason to change: it declares a field or fills the comment-check slot. Core's pages with a comment form are purged when it does.
     */
    public static function changesForms(?Manifest $manifest): bool
    {
        if ($manifest === null) {
            return false;
        }

        return array_key_exists('commentField', $manifest->contributes()) || in_array(PluginSlots::COMMENT_CHECK, (array) ($manifest->contributes()['provides'] ?? []), true);
    }

    /**
     * Why the declared field can't be used, or nothing when it can. A field
     * goes only with the comment-check slot, since only that check is given
     * its values; the capability is checked only when the capabilities are given.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities
     *
     * @return list<string>
     */
    public static function errors(array $contributes, ?array $capabilities): array
    {
        if (! array_key_exists('commentField', $contributes)) {
            return [];
        }

        $declared = $contributes['commentField'];

        if (! is_array($declared) || array_diff(array_keys($declared), ['name', 'make', 'value']) !== []) {
            return [__('Its commentField must be an object of a name, a make and, for a static field, a value.')];
        }

        $errors = [];
        $name = $declared['name'] ?? null;

        if (! is_string($name) || preg_match(self::NAME, $name) !== 1 || in_array($name, self::RESERVED, true)) {
            $errors[] = __('Its comment field’s name must be 3 to 40 lowercase letters, digits or hyphens, starting with a letter, and not one core’s comment form uses.');
        }

        $make = $declared['make'] ?? null;

        if (! is_string($make) || ! in_array($make, self::KINDS, true)) {
            $errors[] = __('Its comment field’s make must be static, time or trap.');
        }

        $value = $declared['value'] ?? null;

        if ($make === 'static' && (! is_string($value) || $value === '' || mb_strlen($value) > self::MOST_VALUE)) {
            $errors[] = __('A static comment field needs a value of 1 to 200 characters.');
        } elseif ($make !== 'static' && array_key_exists('value', $declared)) {
            $errors[] = __('Only a static comment field takes a value.');
        }

        $provides = is_array($contributes['provides'] ?? null) ? $contributes['provides'] : [];

        if (! in_array(PluginSlots::COMMENT_CHECK, $provides, true)) {
            $errors[] = __('A comment field goes only with the comment check slot, which is where its values are read.');
        }

        if ($capabilities !== null && ! in_array(Capability::CommentsCheck->value, $capabilities, true)) {
            $errors[] = __('It adds a comment field, but the plugin doesn’t ask for :capability.', ['capability' => Capability::CommentsCheck->value]);
        }

        return $errors;
    }
}
