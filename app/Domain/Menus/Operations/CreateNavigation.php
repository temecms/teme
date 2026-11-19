<?php

/**
 * Creates an empty menu, in the site's default language or another of its
 * languages (I-3360). Its handle is made from its name, unique among menus,
 * and never changes, so a theme or 1.7's cache tags can rely on it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations;

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Concerns\ManagesNavigation;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;

final class CreateNavigation extends BaseOperation
{
    use ManagesNavigation;

    protected function authorize(OperationInput $input): void
    {
        /** @var CreateNavigationInput $input */
        $this->authorizeNavigation($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateNavigationInput $input */
        $this->validateName($input->name);
        $this->validateLanguage($input->language);
    }

    protected function perform(OperationInput $input): Navigation
    {
        /** @var CreateNavigationInput $input */
        return Navigation::query()->create([
            'name' => trim($input->name),
            'handle' => self::handleFor($input->name),
            'language' => self::storedLanguage($input->language),
        ]);
    }

    /**
     * A new menu's handle, made from its name and unique among menus: its
     * slug cut to 50 characters, or "menu", with the next free number when
     * taken. Shared with ImportNavigation.
     */
    public static function handleFor(string $name): string
    {
        // 50, not 60: room for a numeric suffix inside the 60-character column.
        $base = rtrim(mb_substr(Str::slug($name), 0, 50), '-') ?: 'menu';

        return self::uniqueHandle($base);
    }

    /**
     * $base if no menu has it, otherwise $base with the next free numeric
     * suffix, found in one query, as Slugs::unique() finds a slug.
     */
    private static function uniqueHandle(string $base): string
    {
        $row = DB::table('navigations')
            ->where(fn ($query) => $query->where('handle', $base)->orWhere('handle', 'like', $base.'-%'))
            ->whereRaw('handle ~ ?', ['^'.preg_quote($base, '/').'(-[0-9]+)?$'])
            ->selectRaw(
                'max(case when handle = ? then 1 else 0 end) as base_taken, '
                // Cast the position: an untyped parameter makes Postgres pick
                // substring(text, text), which silently returns null (Slugs).
                .'max(case when handle = ? then 1 else substring(handle from ?::int)::int end) as highest',
                [$base, $base, mb_strlen($base) + 2],
            )
            ->first();

        if ((int) ($row->base_taken ?? 0) === 0) {
            return $base;
        }

        return $base.'-'.((int) $row->highest + 1);
    }
}
