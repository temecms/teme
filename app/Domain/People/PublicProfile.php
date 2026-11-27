<?php

/**
 * The facts a person chooses to show on their public author page: a role
 * line, a coverage line and a few links. It cleans what the profile form
 * sends, says what is wrong with it, and reads what an account has filled.
 * Nothing else about an account is read here.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Site\ViewModels\ProfileLink;
use Illuminate\Support\Facades\DB;

final class PublicProfile
{
    public const int MOST_LINKS = 6;

    public const int ROLE_LENGTH = 120;

    public const int COVERAGE_LENGTH = 255;

    public const int LABEL_LENGTH = 60;

    public const int ADDRESS_LENGTH = 500;

    /**
     * What the form sent, trimmed, with every empty value and every link
     * missing a label or an address dropped, and the links past the sixth
     * kept so the check can refuse them. An empty result means nothing is shown.
     *
     * @param  array<mixed>  $raw
     *
     * @return array{role?: string, coverage?: string, links?: list<array{label: string, url: string}>}
     */
    public static function clean(array $raw): array
    {
        $clean = [];

        foreach (['role', 'coverage'] as $field) {
            $value = isset($raw[$field]) && is_string($raw[$field]) ? trim($raw[$field]) : '';

            if ($value !== '') {
                $clean[$field] = $value;
            }
        }

        $links = [];

        foreach (is_array($raw['links'] ?? null) ? $raw['links'] : [] as $link) {
            $label = is_array($link) && is_string($link['label'] ?? null) ? trim($link['label']) : '';
            $url = is_array($link) && is_string($link['url'] ?? null) ? trim($link['url']) : '';

            if ($label !== '' || $url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        if ($links !== []) {
            $clean['links'] = $links;
        }

        return $clean;
    }

    /**
     * What is wrong with a cleaned profile, by the field's name as the form
     * sends it under `public_profile`; none when it can be saved.
     *
     * @param  array{role?: string, coverage?: string, links?: list<array{label: string, url: string}>}  $clean
     *
     * @return array<string, string>
     */
    public static function problems(array $clean): array
    {
        $problems = [];

        if (mb_strlen($clean['role'] ?? '') > self::ROLE_LENGTH) {
            $problems['public_profile.role'] = __('Keep the role to :most characters.', ['most' => self::ROLE_LENGTH]);
        }

        if (mb_strlen($clean['coverage'] ?? '') > self::COVERAGE_LENGTH) {
            $problems['public_profile.coverage'] = __('Keep the coverage line to :most characters.', ['most' => self::COVERAGE_LENGTH]);
        }

        $links = $clean['links'] ?? [];

        if (count($links) > self::MOST_LINKS) {
            $problems['public_profile.links'] = __('Add :most links at most.', ['most' => self::MOST_LINKS]);
        }

        foreach ($links as $index => $link) {
            if ($link['label'] === '' || mb_strlen($link['label']) > self::LABEL_LENGTH) {
                $problems["public_profile.links.{$index}.label"] = __('Give each link a label of :most characters at most.', ['most' => self::LABEL_LENGTH]);
            }

            if (! self::isAddress($link['url'])) {
                $problems["public_profile.links.{$index}.url"] = __('A link’s address starts with https:// or http://.');
            }
        }

        return $problems;
    }

    /**
     * An account's filled facts, read from its own row: the role, the
     * coverage line, the links, each empty when not filled.
     *
     * @return array{role: ?string, coverage: ?string, links: list<ProfileLink>}
     */
    public static function ofAccount(int $userId): array
    {
        $raw = DB::table('users')->where('id', $userId)->value('public_profile');
        $stored = is_string($raw) ? json_decode($raw, true) : null;

        return self::shown(is_array($stored) ? $stored : []);
    }

    /**
     * Stored facts as a page shows them: only what is still well formed, so
     * a value a later rule would refuse never reaches a template.
     *
     * @param  array<mixed>  $stored
     *
     * @return array{role: ?string, coverage: ?string, links: list<ProfileLink>}
     */
    public static function shown(array $stored): array
    {
        $clean = self::clean($stored);
        $links = [];

        foreach (array_slice($clean['links'] ?? [], 0, self::MOST_LINKS) as $link) {
            if ($link['label'] !== '' && self::isAddress($link['url'])) {
                $links[] = new ProfileLink($link['label'], $link['url']);
            }
        }

        return ['role' => $clean['role'] ?? null, 'coverage' => $clean['coverage'] ?? null, 'links' => $links];
    }

    private static function isAddress(string $url): bool
    {
        return mb_strlen($url) <= self::ADDRESS_LENGTH
            && preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
