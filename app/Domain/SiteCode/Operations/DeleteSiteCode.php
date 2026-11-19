<?php

/**
 * Deletes an entry of site code; the security log keeps a copy of its code.
 * Elevated (I-864), and every stored page purged when pages drew it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\Concerns\ChangesSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class DeleteSiteCode extends BaseOperation implements RequiresElevation
{
    use ChangesSiteCode;

    protected function validate(OperationInput $input): void
    {
        // The entry is found under its lock, in perform().
    }

    /**
     * @return array{name: string, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var DeleteSiteCodeInput $input */
        $entry = SiteCodeEntry::query()->lockForUpdate()->find($input->entryId)
            ?? throw ValidationException::withMessages(['entry' => 'This code was deleted while you had it open.']);

        $before = $entry->snapshot();
        $entry->delete();

        $this->logChange(SecurityEventType::SiteCodeDeleted, $input, $entry->id, $before, null);
        $this->purgeIfDrawn($before, null);

        return ['name' => $before['name'], 'changed' => true];
    }
}
