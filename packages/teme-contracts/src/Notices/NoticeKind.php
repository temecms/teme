<?php

declare(strict_types=1);

namespace Teme\Contracts\Notices;

/**
 * One kind of notice Teme or a plugin sends: who may receive it, by which
 * channels, which are on until the person changes them, and whether the
 * person may turn it off at all. Words are written in English and
 * translated when drawn. A preview until Teme 1.0.
 *
 * @since 0.5.0
 */
final readonly class NoticeKind
{
    /** @since 0.5.0 */
    public const string MAIL = 'mail';

    /** @since 0.5.0 */
    public const string INBOX = 'inbox';

    /**
     * @param  string  $key  lower-case words and underscores, at least two parts joined by dots: `comments.waiting`
     * @param  string  $label  the choice as Account › Notifications words it: for an email-only kind, "Email me when…"
     * @param  string|null  $audience  the ability a person must have to receive it, as `can()` reads it; null for anyone the sender names
     * @param  list<string>  $channels  MAIL and INBOX, at least one
     * @param  list<string>  $defaults  the channels on until the person chooses; some of $channels
     * @param  bool  $required  always sent by every channel, never listed as a choice: security, a person's own request
     * @param  string  $reason  the footer's "why you get this", `:site` filled when sent; empty for none
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $audience = null,
        public array $channels = [self::MAIL],
        public array $defaults = [self::MAIL],
        public bool $required = false,
        public string $reason = '',
    ) {}
}
