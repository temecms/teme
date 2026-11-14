<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

/**
 * A whole redirect as a form or a CSV line asks for it, read and checked on
 * its own, before the table is consulted (I-297): its source, its status,
 * its target (none for 410 and 451) and its note.
 */
final readonly class RedirectProposal
{
    /** The longest note, in characters. */
    public const int NOTE = 200;

    private function __construct(
        public RedirectSource $source,
        public RedirectStatus $status,
        public ?RedirectTarget $target,
        public string $note,
    ) {}

    /**
     * @throws RedirectRefused
     */
    public static function read(string $from, int $status, string $to, ?string $contentId, ?string $mediaId, string $note): self
    {
        $source = RedirectSource::read($from);
        $code = RedirectStatus::tryFrom($status) ?? throw new RedirectRefused('status', __('Choose a status: 301, 302, 307, 308, 410 or 451.'));
        $note = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $note));

        if (mb_strlen($note) > self::NOTE) {
            throw new RedirectRefused('note', __('Shorten the note to 200 characters or fewer.'));
        }

        return new self($source, $code, $code->sendsNowhere() ? null : RedirectTarget::read($to, $contentId, $mediaId, $source), $note);
    }

    /**
     * The table's columns for this redirect, but its ID, origin, switch and dates.
     *
     * @return array{source: string, kind: string, pattern: string|null, status: int, target_kind: string|null, target: string|null, target_path: string|null, note: string}
     */
    public function columns(): array
    {
        return [
            'source' => $this->source->path,
            'kind' => $this->source->isPattern ? 'pattern' : 'exact',
            'pattern' => $this->source->regex,
            'status' => $this->status->value,
            'target_kind' => $this->target?->kind,
            'target' => $this->target?->target,
            'target_path' => $this->target?->path,
            'note' => $this->note,
        ];
    }
}
