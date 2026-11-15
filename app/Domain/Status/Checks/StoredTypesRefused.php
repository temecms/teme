<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypeAccess;

/**
 * Says when a type saved on this site can't be used (I-411): code now
 * declares its key or segment, or it names a taxonomy or feature that is
 * gone, so its screens and addresses are missing until it is put right.
 */
final readonly class StoredTypesRefused implements StatusCheck
{
    public function ability(): string
    {
        return TypeAccess::MANAGE;
    }

    public function label(): string
    {
        return __('Types');
    }

    public function banner(): ?StatusBanner
    {
        $refused = app(ContentTypeRegistry::class)->refused();

        if ($refused === []) {
            return null;
        }

        $keys = array_map(fn (string|int $key): string => '“'.$key.'”', array_keys($refused));
        $first = array_key_first($refused);

        $message = count($refused) === 1
            ? __('The saved type :key can’t be used: :reason', ['key' => $keys[0], 'reason' => $refused[$first]])
            : __(':count saved types can’t be used: :keys.', ['count' => (string) count($refused), 'keys' => implode(', ', array_slice($keys, 0, -1)).' and '.$keys[count($keys) - 1]]);

        return new StatusBanner('error', $message, __('Open Types'), route('types.index'), 80);
    }
}
