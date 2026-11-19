<?php

/**
 * The Discussion settings, read and typed for the comment Operations and
 * core's moderator (docs/studies/comments.md §3.10), and how a new item of a
 * type starts (I-274). Read afresh at each call; the reader's memo keeps it
 * to one lookup a request.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\SettingsReader;

final readonly class DiscussionSettings
{
    /** The settings group these are read from. */
    private const string GROUP = 'discussion';

    /**
     * @param  list<string>  $openFor  the types whose new items start open for comments
     * @param  list<string>  $holdList  the hold list, a line an entry
     * @param  list<string>  $blockList  the block list, a line an entry
     * @param  int  $perPage  how many conversations a page of comments holds
     * @param  bool  $askWebsite  whether the form asks guests for a website
     * @param  bool  $emailModerators  whether whoever looks after an item is emailed when a comment waits
     * @param  bool  $emailAuthors  whether an item's author is emailed when a comment on it is approved
     * @param  int  $keepIpDays  how many days after a comment arrives its IP address and browser are erased
     */
    public function __construct(
        public array $openFor,
        public bool $signedInOnly,
        public int $closeAfterDays,
        public int $threadDepth,
        public bool $holdAll,
        public bool $approveReturning,
        public int $holdLinks,
        public array $holdList,
        public array $blockList,
        public int $perPage,
        public bool $askWebsite,
        public bool $emailModerators,
        public bool $emailAuthors,
        public int $keepIpDays,
    ) {}

    /**
     * The settings in force now.
     */
    public static function current(): self
    {
        $settings = app(SettingsReader::class);

        return new self(
            $settings->stringList(self::GROUP, 'open_for'),
            $settings->bool(self::GROUP, 'signed_in_only'),
            $settings->int(self::GROUP, 'close_after_days'),
            $settings->int(self::GROUP, 'thread_depth'),
            $settings->bool(self::GROUP, 'hold_all'),
            $settings->bool(self::GROUP, 'approve_returning'),
            $settings->int(self::GROUP, 'hold_links'),
            self::lines($settings->string(self::GROUP, 'hold_list')),
            self::lines($settings->string(self::GROUP, 'block_list')),
            $settings->int(self::GROUP, 'per_page'),
            $settings->bool(self::GROUP, 'ask_website'),
            $settings->bool(self::GROUP, 'email_moderators'),
            $settings->bool(self::GROUP, 'email_authors'),
            $settings->int(self::GROUP, 'keep_ip_days'),
        );
    }

    /**
     * How a new item of this type starts (I-274): Closed when its type takes
     * no comments; a type saved on the site as its own definition says; a
     * type code declares Open when the settings name it, and otherwise Closed.
     */
    public function forNewItem(string $type): Discussion
    {
        $types = app(ContentTypeRegistry::class);

        if (! $types->has($type) || $types->get($type)->comments === null) {
            return Discussion::Closed;
        }

        $contentType = $types->get($type);

        if ($contentType->stored) {
            return $contentType->comments === Discussion::Open ? Discussion::Open : Discussion::Closed;
        }

        return in_array($type, $this->openFor, true) ? Discussion::Open : Discussion::Closed;
    }

    /**
     * A list setting's entries: a line each, trimmed, blank lines dropped.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $text) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }
}
