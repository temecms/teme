<?php

/**
 * The provider slots core declares (docs/guides/developers/plugins/provider-slots.md):
 * each service that needs exactly one active provider, with the contract a
 * provider implements and whether core has its own default. A slot with no
 * contract yet can't be filled by a plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Slots;

use Teme\Contracts\Comments\HasCommentCheck;
use Teme\Contracts\Media\HasImageService;

final class PluginSlots
{
    /** The image service's slot: the driver that gives images' addresses. */
    public const string IMAGE_SERVICE = 'image-service';

    /** The search engine's slot. Its contract comes with issue 780. */
    public const string SEARCH = 'search';

    /** The comment check's slot: a plugin judges each comment before it is saved. */
    public const string COMMENT_CHECK = 'comment-check';

    /** Most slots a plugin may fill in one manifest. */
    public const int MAX_PER_PLUGIN = 3;

    /**
     * Every slot core declares, with its label, the contract a provider implements (or null), and whether core has a default.
     *
     * @return array<string, array{label: string, contract: class-string|null, default: bool}>
     */
    public static function all(): array
    {
        return [
            self::IMAGE_SERVICE => ['label' => 'Image service', 'contract' => HasImageService::class, 'default' => true],
            self::SEARCH => ['label' => 'Search', 'contract' => null, 'default' => true],
            self::COMMENT_CHECK => ['label' => 'Comment check', 'contract' => HasCommentCheck::class, 'default' => false],
        ];
    }

    /**
     * Whether a slot id names a slot core declares.
     */
    public static function exists(string $slot): bool
    {
        return array_key_exists($slot, self::all());
    }

    /**
     * Whether a plugin may fill a slot now: it is declared and has a contract.
     */
    public static function fillable(string $slot): bool
    {
        return self::exists($slot) && self::all()[$slot]['contract'] !== null;
    }
}
