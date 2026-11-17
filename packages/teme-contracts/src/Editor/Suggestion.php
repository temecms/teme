<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use InvalidArgumentException;

/**
 * One change the person may use, with a label and a short explanation.
 *
 * @since 1.0.0
 */
final readonly class Suggestion
{
    /** A suggestion's id. @since 1.0.0 */
    public const string ID = '/^[a-z0-9][a-z0-9:._-]{0,63}$/D';

    /**
     * @param  string  $id  1 to 64 lower-case letters, digits and : . _ -, starting with a letter or digit
     * @param  string  $label  1 to 120 characters
     * @param  string  $detail  up to 500 characters
     *
     * @throws InvalidArgumentException for an id, label or detail outside those bounds
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $detail,
        public SuggestedChange $change,
    ) {
        if (preg_match(self::ID, $id) !== 1) {
            throw new InvalidArgumentException('A suggestion’s id must be 1 to 64 lower-case letters, digits and the characters : . _ -, starting with a letter or digit.');
        }

        if (trim($label) === '' || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('A suggestion’s label must be 1 to 120 characters.');
        }

        if (mb_strlen($detail) > 500) {
            throw new InvalidArgumentException('A suggestion’s detail may be at most 500 characters.');
        }
    }
}
