<?php

/**
 * The kinds an export writes, in order: each before the kinds that refer
 * to it, so an importer can read the files in turn (I-365), then the files
 * the enabled plugins declare. A test binds its own list.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Export\Kinds\BylinesKind;
use App\Domain\Export\Kinds\CommentsKind;
use App\Domain\Export\Kinds\ContentsKind;
use App\Domain\Export\Kinds\MediaKind;
use App\Domain\Export\Kinds\MenuItemsKind;
use App\Domain\Export\Kinds\MenusKind;
use App\Domain\Export\Kinds\PeopleKind;
use App\Domain\Export\Kinds\RedirectsKind;
use App\Domain\Export\Kinds\SettingsKind;
use App\Domain\Export\Kinds\TermsKind;
use App\Domain\Plugins\Exports\PluginKind;
use App\Domain\Plugins\Exports\PluginKinds;

final readonly class ExportKinds
{
    /** @var list<class-string<ExportKind>> */
    public const array CORE = [
        PeopleKind::class, BylinesKind::class, TermsKind::class, MediaKind::class, ContentsKind::class, CommentsKind::class,
        MenusKind::class, MenuItemsKind::class, RedirectsKind::class, SettingsKind::class,
    ];

    /** @var list<ExportKind> */
    private array $kinds;

    /**
     * @param  list<ExportKind>|null  $kinds  null for the core kinds, then the enabled plugins' own
     */
    public function __construct(?array $kinds = null)
    {
        if ($kinds === null) {
            $kinds = [];

            foreach (self::CORE as $class) {
                $kinds[] = app($class);
            }

            array_push($kinds, ...app(PluginKinds::class)->all());
        }

        $this->kinds = $kinds;
    }

    /**
     * The kinds core writes, without any plugin's: what the format reference describes.
     *
     * @return list<ExportKind>
     */
    public function core(): array
    {
        return array_values(array_filter($this->kinds, fn (ExportKind $kind): bool => ! $kind instanceof PluginKind));
    }

    /**
     * @return list<ExportKind>
     */
    public function all(): array
    {
        return $this->kinds;
    }
}
