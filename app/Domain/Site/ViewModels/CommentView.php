<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One place in a thread, as a theme is given it: a comment, a tombstone
 * (I-278) or a ping kept from WordPress (I-277), with its replies. It holds a
 * name and the website its writer chose to show, and never an email address,
 * an IP address, a browser or a moderation record (D19).
 */
final readonly class CommentView
{
    /**
     * @param  string  $kind  comment, pingback or trackback
     * @param  string|null  $name  the writer's name, or a ping's source; null for a tombstone
     * @param  string|null  $url  the writer's website, or a ping's address; null for none
     * @param  bool  $byAuthor  written by someone the item credits, known from their account (I-597)
     * @param  string  $initials  the picture's letters; '' for a tombstone
     * @param  int  $hue  the picture's colour, 0 to 7
     * @param  string  $iso  when it was written, ISO 8601 on the site's clock
     * @param  string  $dateText  the same, as the item's language writes it (D17)
     * @param  string  $permalink  its own address, /_teme/comment/{id}
     * @param  string|null  $replyUrl  the reply page, while it takes a reply; else null
     * @param  int|null  $legacyId  its WordPress number, for the old #comment-N anchor
     * @param  string  $bodyHtml  its words, drawn by core; '' for a tombstone or a ping
     * @param  bool  $isReply  whether it answers another comment
     * @param  string|null  $parentName  the name of the comment it's under; null when that is a tombstone
     * @param  bool  $pending  waiting, shown only to its writer by core's script (1.8b′); false here
     * @param  string  $id  the comment's ID
     * @param  bool  $removed  whether it is a tombstone: the comment was removed and its replies stay (I-278)
     * @param  list<CommentView>  $replies  the comments under it, oldest first
     */
    public function __construct(
        public string $id,
        public string $kind,
        public bool $removed,
        public ?string $name,
        public ?string $url,
        public bool $byAuthor,
        public string $initials,
        public int $hue,
        public string $iso,
        public string $dateText,
        public string $permalink,
        public ?string $replyUrl,
        public ?int $legacyId,
        public string $bodyHtml,
        public bool $isReply,
        public ?string $parentName,
        public bool $pending,
        public array $replies,
    ) {}
}
