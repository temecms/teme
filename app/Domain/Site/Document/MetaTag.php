<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

use InvalidArgumentException;

/**
 * One `<meta>` in the page's head (I-228): `name` and content, as robots and
 * a verification tag are written, or `property` and content, as Open
 * Graph's are. Its key is checked when it is made, and its content is
 * escaped when it is drawn, so no value can add markup to the head.
 */
final readonly class MetaTag
{
    /**
     * What a key may be: letters and digits, joined by colons, dots, hyphens
     * or underscores, as `robots`, `og:image:width` and `msvalidate.01` are.
     */
    public const string KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9]*(?:[:._-][A-Za-z0-9]+)*$/';

    private function __construct(public string $attribute, public string $key, public string $content)
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > 100) {
            throw new InvalidArgumentException("A meta tag's {$attribute} [{$key}] isn't one: letters and digits joined by colons, dots, hyphens or underscores.");
        }
    }

    /**
     * `<meta name="…" content="…">`.
     */
    public static function name(string $name, string $content): self
    {
        return new self('name', $name, $content);
    }

    /**
     * `<meta property="…" content="…">`, as Open Graph's tags are written.
     */
    public static function property(string $property, string $content): self
    {
        return new self('property', $property, $content);
    }

    /**
     * What makes two entries the same tag, so the first one wins: its
     * attribute and its key, in any case.
     */
    public function identity(): string
    {
        return $this->attribute.':'.strtolower($this->key);
    }

    /**
     * The tag, its key and content escaped as Blade's {{ }} escapes text.
     */
    public function html(): string
    {
        return '<meta '.$this->attribute.'="'.e($this->key).'" content="'.e($this->content).'">';
    }
}
