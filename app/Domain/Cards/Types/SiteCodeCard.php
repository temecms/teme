<?php

/**
 * Custom HTML in a theme's region: a Settings › Site code entry placed by
 * name, holding only the entry's ID, never code of its own (I-183, I-3127).
 * The code is drawn exactly as written, its scripts and hosts given to the
 * page's policy as site code's are (I-3375).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RegionCard;
use App\Domain\Cards\RenderContext;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodeEntries;
use App\Domain\SiteCode\SiteCodeWithheld;

final class SiteCodeCard implements CardType, RegionCard
{
    public function name(): string
    {
        return 'site-code';
    }

    public function label(): string
    {
        return 'Custom HTML';
    }

    public function description(): string
    {
        return 'HTML an administrator keeps in Settings › Site code, placed here by name. Editors choose it; only administrators write or change it.';
    }

    public function since(): string
    {
        return '1.0.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            // A site code entry's ID, which is lowercase (I-418).
            'entryId' => new CardAttribute('entryId', ['required', 'string', 'regex:/^[0-9a-hjkmnp-tv-z]{26}$/']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Custom HTML', 'Cards', ['html', 'code', 'ad', 'embed', 'widget', 'snippet'], 'code-xml', regionOnly: true);
    }

    /**
     * The entry's code, when it is on, placed with a card, and site code may
     * be drawn on this page; nothing otherwise, and never an error.
     */
    public function renderHtml(array $attrs, RenderContext $context): string
    {
        if ($context->target !== 'web' || SiteCode::disallowed() || app(SiteCodeWithheld::class)->isWithheld()) {
            return '';
        }

        $entryId = (string) ($attrs['entryId'] ?? '');

        foreach (SiteCodeEntries::on()[SiteCode::PLACED] ?? [] as $entry) {
            if ($entry['id'] !== $entryId) {
                continue;
            }

            $policy = app(PagePolicy::class);
            $policy->allowScriptHashes($entry['policy']['scripts']);
            $policy->allowHandlerHashes($entry['policy']['handlers']);

            foreach ($entry['policy']['origins'] as $directive => $origins) {
                $policy->allowOrigins($directive, $origins);
            }

            // A change to the entry purges every stored page already (ChangesSiteCode).
            return '<div class="card-site-code">'.$entry['code'].'</div>';
        }

        return '';
    }

    /**
     * Code is not words, and its text is an administrator's, never the region's.
     */
    public function plainText(array $attrs): string
    {
        return '';
    }

    public function references(array $attrs): array
    {
        return [];
    }

    public function validate(array $attrs): void {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        return ChangeList::line($this->label(), ChangeList::differs($before, $after, 'entryId') ? ['code changed'] : []);
    }
}
