<?php

/**
 * What one of a plugin's checks found: its state, a short message, and where
 * the editor should take the person to change it.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use InvalidArgumentException;

/**
 * What one of a plugin's checks found: its state, a short message, and
 * where the editor should take the person to change it. Core draws it as
 * plain text, under the plugin's name.
 *
 * @since 1.1.0
 */
final readonly class CheckResult
{
    /** Nothing to change. @since 1.1.0 */
    public const string OK = 'ok';

    /** Worth improving. @since 1.1.0 */
    public const string IMPROVE = 'improve';

    /** Worth fixing before publishing. @since 1.1.0 */
    public const string PROBLEM = 'problem';

    /** The states a result may take. @since 1.1.0 */
    public const array STATES = [self::OK, self::IMPROVE, self::PROBLEM];

    /** The longest message, in characters. @since 1.1.0 */
    public const int MOST_MESSAGE = 300;

    /**
     * @param  string  $check  the id of one of the checks the manifest declares
     * @param  string  $state  ok, improve or problem
     * @param  string  $message  1 to 300 characters of plain text
     * @param  string|null  $label  up to 60 characters; the declared check's label when null
     * @param  string|null  $field  where to take the person: `title`, or one of the type's field handles, such as `meta_description`
     * @param  string|null  $ownField  or the key of one of the plugin's own Search and social fields
     *
     * @throws InvalidArgumentException for a value outside those bounds, or both places given
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $check,
        public string $state,
        public string $message,
        public ?string $label = null,
        public ?string $field = null,
        public ?string $ownField = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,23}$/D', $check) !== 1) {
            throw new InvalidArgumentException('A check result names one of the plugin’s declared checks.');
        }

        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('A check result’s state must be ok, improve or problem.');
        }

        if (trim($message) === '' || mb_strlen($message) > self::MOST_MESSAGE) {
            throw new InvalidArgumentException('A check result’s message must be 1 to 300 characters.');
        }

        if ($label !== null && (trim($label) === '' || mb_strlen($label) > 60)) {
            throw new InvalidArgumentException('A check result’s label must be 1 to 60 characters.');
        }

        if ($field !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A check result’s field is `title` or a field’s handle.');
        }

        if ($ownField !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $ownField) !== 1) {
            throw new InvalidArgumentException('A check result’s own field is the key of one of the plugin’s Search and social fields.');
        }

        if ($field !== null && $ownField !== null) {
            throw new InvalidArgumentException('A check result leads to one field at most.');
        }
    }
}
