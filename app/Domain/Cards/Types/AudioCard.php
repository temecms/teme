<?php

/**
 * One sound file from the library, played by the browser's own player, with
 * a caption and a transcript readers can open (I-446). The file is held by
 * ID; its address is worked out when drawn, never stored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;

final class AudioCard implements CardType
{
    public function name(): string
    {
        return 'audio';
    }

    public function label(): string
    {
        return 'Audio';
    }

    public function description(): string
    {
        return 'A sound file from the library, played in the page, with a caption and a transcript.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'mediaId' => new CardAttribute('mediaId', ['required', 'string', 'size:26']),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
            'transcript' => new CardAttribute('transcript', ['nullable', 'string', 'max:100000']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Audio', 'Cards', ['audio', 'sound', 'podcast', 'music', 'recording', 'mp3'], 'audio');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $file = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;

        if ($file === null || $file['kind'] !== 'audio' || $file['fileUrl'] === null) {
            return '';
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));
        $transcript = self::paragraphs((string) ($attrs['transcript'] ?? ''));

        return sprintf(
            '<div class="card-audio"><figure><audio src="%s" controls preload="metadata"></audio>%s</figure>%s</div>',
            e($file['fileUrl']),
            $caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>',
            $transcript === '' ? '' : '<details class="card-audio__transcript"><summary>'.e((string) __('Transcript')).'</summary>'.$transcript.'</details>',
        );
    }

    /**
     * The caption and the transcript, so search finds what was said.
     */
    public function plainText(array $attrs): string
    {
        $words = implode(' ', [(string) ($attrs['caption'] ?? ''), (string) ($attrs['transcript'] ?? '')]);

        return trim((string) preg_replace('/\s+/u', ' ', $words));
    }

    public function references(array $attrs): array
    {
        $id = $attrs['mediaId'] ?? null;

        return is_string($id) && $id !== '' ? [CardReference::media($id)] : [];
    }

    /**
     * The attributes' own rules say all there is to check.
     */
    public function validate(array $attrs): void {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'mediaId')) {
            $parts[] = 'audio replaced';
        }

        if (ChangeList::differs($before, $after, 'caption')) {
            $parts[] = 'caption changed';
        }

        $had = trim((string) ($before['transcript'] ?? '')) !== '';
        $has = trim((string) ($after['transcript'] ?? '')) !== '';

        if ($had !== $has) {
            $parts[] = $has ? 'transcript added' : 'transcript removed';
        } elseif (ChangeList::differs($before, $after, 'transcript')) {
            $parts[] = 'transcript changed';
        }

        return ChangeList::line($this->label(), $parts);
    }

    /**
     * The transcript as escaped paragraphs: split at blank lines, each single
     * line break kept, whatever the line endings, and no empty paragraph.
     */
    private static function paragraphs(string $text): string
    {
        $html = '';

        foreach (preg_split('/\n[ \t]*\n/', str_replace(["\r\n", "\r"], "\n", $text)) ?: [] as $paragraph) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $paragraph)), fn (string $line): bool => $line !== ''));

            if ($lines !== []) {
                $html .= '<p>'.implode('<br>', array_map(fn (string $line): string => e($line), $lines)).'</p>';
            }
        }

        return $html;
    }
}
