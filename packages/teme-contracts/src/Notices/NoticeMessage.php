<?php

declare(strict_types=1);

namespace Teme\Contracts\Notices;

/**
 * What a notice says, as plain words: the layout escapes them and adds the
 * greeting, the sign-off and the footer. The action is a route's name and
 * parameters, made into an address when sent (AGENTS.md rule 9).
 *
 * @since 0.5.0
 */
final readonly class NoticeMessage
{
    /**
     * @param  string  $subject  plain words; a header, never Markdown
     * @param  list<string>  $lines  paragraphs, plain words, in order
     * @param  array<string, string|int>  $actionParameters
     * @param  string|null  $quote  someone's own words, quoted as written: a comment
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public ?string $actionLabel = null,
        public ?string $actionRoute = null,
        public array $actionParameters = [],
        public ?string $quote = null,
    ) {}
}
