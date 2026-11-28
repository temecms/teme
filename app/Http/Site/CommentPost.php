<?php

/**
 * A comment form as it was posted, read once: each field a string, '' for
 * one missing or sent as anything else. `path` is the item's address, never
 * its ID (1.8b's D9); `subject` is the honeypot (D8); `token` is the
 * browser's own key, which core's script fills (1.8b′).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Comments\Checks\CommentField;
use Illuminate\Http\Request;

final readonly class CommentPost
{
    public function __construct(
        public string $path,
        public string $parent,
        public string $text,
        public string $name,
        public string $email,
        public string $url,
        public string $token,
        public string $honeypot,
        /** @var array<string, string> the plugin's hidden field as posted, or a missing-field signal when the form didn't carry it (issue 805) */
        public array $signals = [],
    ) {}

    /**
     * The form as posted; $field is the name of the comment-check plugin's declared hidden field, or null when none is declared.
     */
    public static function from(Request $request, ?string $field = null): self
    {
        $value = fn (string $key): string => is_string($input = $request->input($key)) ? $input : '';
        $signals = [];

        if ($field !== null) {
            $posted = $request->input($field);

            // A value over the field's limit reaches the check as a flag, never as the value (issue 805).
            $signals = match (true) {
                ! is_string($posted) => ['missing-field' => $field],
                mb_strlen($posted) > CommentField::MOST_VALUE => ['too-long' => $field],
                default => [$field => $posted],
            };
        }

        return new self($value('path'), $value('parent'), $value('text'), $value('name'), $value('email'), $value('url'), $value('token'), $value('subject'), $signals);
    }

    /**
     * What the reader typed, for the form drawn again.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return ['text' => $this->text, 'name' => $this->name, 'email' => $this->email, 'url' => $this->url];
    }
}
