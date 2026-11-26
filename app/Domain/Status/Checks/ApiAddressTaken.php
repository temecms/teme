<?php

/**
 * Tells whoever manages addresses when something of the site already had an
 * address under /api/, which the API now answers (I-091): an item, term or
 * person there, an address setting starting with it, or an old address or
 * a redirect beneath it. Nothing is moved; the address is the owner's to change.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ApiAddressTaken implements StatusCheck
{
    /** The API's first segment. */
    private const string SEGMENT = 'api';

    /**
     * One thing that lost its address: below a server that stopped its work
     * (the scheduler, the queue), an unusable theme, backups in trouble and
     * refused types; above a backup caution.
     */
    public const int PRIORITY = 70;

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since 1.6.0
     */
    public function key(): string
    {
        return 'api-address-taken';
    }

    public function ability(): string
    {
        return 'settings.addresses.manage';
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Management API');
    }

    public function banner(): ?StatusBanner
    {
        return $this->current() ?? $this->setting() ?? $this->beneath();
    }

    /**
     * An item, term or person whose current address is /api/, or under it.
     */
    private function current(): ?StatusBanner
    {
        // paths.path is unique: one index lookup.
        $row = DB::table('paths')->where('path', self::SEGMENT)->where('is_current', true)->first(['target_type', 'target_id'])
            ?? DB::table('paths')->where('path', 'like', self::SEGMENT.'/%')->where('is_current', true)->orderBy('path')->first(['target_type', 'target_id']);

        if ($row === null) {
            return null;
        }

        $type = (string) $row->target_type;
        $id = (string) $row->target_id;

        return new StatusBanner(
            'warning',
            __('Teme’s API now answers every address starting /api/, so :what can’t be reached at /api/ any more. Give it another address.', ['what' => Paths::describe($type, $id)]),
            __('Edit it'),
            $this->editor($type, $id),
            self::PRIORITY,
        );
    }

    /**
     * An address setting whose first word is api: a type's pattern, a
     * taxonomy's base, the author base, or a type's archive page.
     */
    private function setting(): ?StatusBanner
    {
        $settings = AddressSettings::current();
        $named = null;

        foreach ($settings->patterns as $type => $pattern) {
            try {
                $first = AddressPattern::parse($pattern)->firstFixedSegment();
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($first === self::SEGMENT) {
                $named = __('the address pattern of :type', ['type' => $this->typePlural((string) $type)]);

                break;
            }
        }

        if ($named === null) {
            foreach ($settings->bases as $taxonomy => $base) {
                if (explode('/', trim($base, '/'))[0] === self::SEGMENT) {
                    $taxonomies = app(TaxonomyRegistry::class);
                    $named = __('the base of :taxonomy', ['taxonomy' => $taxonomies->has((string) $taxonomy) ? $taxonomies->get((string) $taxonomy)->plural : (string) $taxonomy]);

                    break;
                }
            }
        }

        if ($named === null && explode('/', trim($settings->authorBase, '/'))[0] === self::SEGMENT) {
            $named = __('the author base');
        }

        if ($named === null) {
            $archive = TypeArchives::at(self::SEGMENT);
            $named = $archive === null ? null : __('the archive page of :type', ['type' => $this->typePlural($archive)]);
        }

        return $named === null ? null : new StatusBanner(
            'warning',
            __('Teme’s API now answers every address starting /api/, so :setting can’t use it any more. Choose another in Settings › Addresses.', ['setting' => $named]),
            __('Open Addresses'),
            route('settings.addresses.show', [], false),
            self::PRIORITY,
        );
    }

    /**
     * An old address beneath /api/, which answered with a 301, or a
     * redirect from /api or beneath it: neither leads anywhere now.
     */
    private function beneath(): ?StatusBanner
    {
        $old = DB::table('paths')->where('path', 'like', self::SEGMENT.'/%')->where('is_current', false)->orderBy('path')->value('path');

        // redirects.source has collation C, so LIKE reads its index.
        $path = $old ?? DB::table('redirects')
            ->where(fn ($query) => $query->where('source', self::SEGMENT)->orWhere('source', 'like', self::SEGMENT.'/%'))
            ->orderBy('source')
            ->value('source');

        return $path === null ? null : new StatusBanner(
            'warning',
            __('Teme’s API now answers every address starting /api/, so the old address :path no longer leads anywhere. Point the links to it at the new address, or remove it in Settings › Redirects.', ['path' => Addresses::toUrl((string) $path)]),
            __('Open Redirects'),
            route('tools.redirects.index', [], false),
            self::PRIORITY,
        );
    }

    /**
     * Where a target is edited: an item's editor, a term's screen, the people screen.
     */
    private function editor(string $type, string $id): ?string
    {
        // By kind of Paths::KINDS; any other has nothing to edit here.
        if ($type === 'content') {
            return route('content.edit', ['content' => $id], false);
        }

        if ($type === 'term') {
            $taxonomy = (string) DB::table('terms')->where('id', $id)->value('taxonomy');
            $taxonomies = app(TaxonomyRegistry::class);

            return $taxonomies->has($taxonomy) ? route('terms.index', ['taxonomy' => $taxonomies->get($taxonomy)->segment], false) : null;
        }

        return $type === 'user' ? route('users.index', [], false) : null;
    }

    private function typePlural(string $type): string
    {
        $types = app(ContentTypeRegistry::class);

        return $types->has($type) ? $types->get($type)->plural : $type;
    }
}
