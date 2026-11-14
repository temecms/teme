<?php

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

/**
 * The kinds an export writes, in order: each before the kinds that refer
 * to it, so an importer can read the files in turn (I-365). A test binds
 * its own list.
 */
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
     * @param  list<ExportKind>|null  $kinds  null for the core kinds
     */
    public function __construct(?array $kinds = null)
    {
        if ($kinds === null) {
            $kinds = [];

            foreach (self::CORE as $class) {
                $kinds[] = app($class);
            }
        }

        $this->kinds = $kinds;
    }

    /**
     * @return list<ExportKind>
     */
    public function all(): array
    {
        return $this->kinds;
    }
}
