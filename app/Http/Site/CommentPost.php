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
    ) {}

    public static function from(Request $request): self
    {
        $field = fn (string $key): string => is_string($value = $request->input($key)) ? $value : '';

        return new self($field('path'), $field('parent'), $field('text'), $field('name'), $field('email'), $field('url'), $field('token'), $field('subject'));
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
