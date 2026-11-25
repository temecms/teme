<?php

/**
 * Custom HTML in a theme's region: code of its own, such as one ad unit,
 * pasted by someone who may manage site code, with the hosts it connects
 * to (docs/architecture/security.md). The code is drawn exactly as
 * written, its scripts and hosts given to the page's policy as site
 * code's are (I-3375).
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
use App\Domain\Plugins\HostPattern;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\SiteCode;
use App\Domain\SiteCode\SiteCodePolicy;
use App\Domain\SiteCode\SiteCodeWithheld;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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
        return 'HTML of its own, such as one ad unit, with the hosts it connects to. Only administrators add, read or change its code; editors may move or remove it.';
    }

    public function since(): string
    {
        return '1.0.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            // Null where it is hidden from someone who may not read it.
            'code' => new CardAttribute('code', ['nullable', 'string', 'max:'.SiteCode::MAX_LENGTH, self::storable(...)]),
            'origins' => new CardAttribute('origins', ['array', 'max:20'], []),
            'on' => new CardAttribute('on', ['boolean'], true),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Custom HTML', 'Cards', ['html', 'code', 'ad', 'embed', 'widget', 'snippet'], 'code-xml', regionOnly: true);
    }

    /**
     * The card's code, exactly as written, when it is on and site code may
     * be drawn on this page; nothing otherwise, and never an error. Its
     * scripts' hashes and its hosts join the page's policy.
     */
    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $code = is_string($attrs['code'] ?? null) ? $attrs['code'] : '';

        if ($context->target !== 'web' || $code === '' || ($attrs['on'] ?? true) === false || SiteCode::disallowed() || app(SiteCodeWithheld::class)->isWithheld()) {
            return '';
        }

        $origins = RegionCode::origins($attrs['origins'] ?? []);
        // The same code and hosts always give the same policy, so it is kept by them.
        $found = Cache::rememberForever('region-code:'.hash('sha256', $code."\0".implode(' ', $origins)), fn (): array => SiteCodePolicy::of($code, $origins));

        $policy = app(PagePolicy::class);
        $policy->allowScriptHashes($found['scripts']);
        $policy->allowHandlerHashes($found['handlers']);

        foreach ($found['origins'] as $directive => $hosts) {
            $policy->allowOrigins($directive, $hosts);
        }

        return '<div class="card-site-code">'.$code.'</div>';
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

    /**
     * Each host it connects to is a host name, as site code's are.
     */
    public function validate(array $attrs): void
    {
        foreach (RegionCode::origins($attrs['origins'] ?? []) as $host) {
            if (preg_match(HostPattern::PATTERN, $host) !== 1) {
                throw ValidationException::withMessages(['origins' => __('“:value” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.', ['value' => $host])]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $changes = [];

        if (ChangeList::differs($before, $after, 'code')) {
            $changes[] = 'code changed';
        }

        if (ChangeList::differs($before, $after, 'origins')) {
            $changes[] = 'hosts changed';
        }

        if (ChangeList::differs($before, $after, 'on')) {
            $changes[] = ($after['on'] ?? true) === false ? 'switched off' : 'switched on';
        }

        return ChangeList::line($this->label(), $changes);
    }

    /**
     * Refuses code a JSONB column can't hold: a NUL, or bytes that aren't UTF-8.
     */
    private static function storable(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
            $fail(__('The code holds characters that can’t be kept.'));
        }
    }
}
