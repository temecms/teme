<?php

/**
 * Every registered status check, with who added it, every banner a person
 * may act on, and the one they see first: the highest-priority banner among
 * the checks they may act on (I-221).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status;

use App\Domain\Status\Checks\BackupsSafe;
use App\Domain\Status\Checks\PageCacheCdnPurges;
use App\Domain\Status\Checks\PageCacheFolderShared;
use App\Models\User;

final class StatusChecks
{
    /** @var list<array{check: class-string<StatusCheck>|StatusCheck, owner: string}> */
    private array $checks = [];

    /**
     * Registers a check by its class, built each time it is asked.
     *
     * @param  class-string<StatusCheck>  $check
     */
    public function register(string $check, string $owner = 'core'): void
    {
        $this->checks[] = ['check' => $check, 'owner' => $owner];
    }

    /**
     * Registers a check that is already built, as a plugin's are, with the
     * plugin's Composer name as its owner (I-3353).
     */
    public function add(StatusCheck $check, string $owner): void
    {
        $this->checks[] = ['check' => $check, 'owner' => $owner];
    }

    /**
     * Returns who added this check, a class registered or an instance
     * added, or null when it was never registered.
     *
     * @param  StatusCheck|class-string<StatusCheck>  $check
     */
    public function ownerOf(StatusCheck|string $check): ?string
    {
        foreach ($this->checks as $entry) {
            if ($entry['check'] === $check) {
                return $entry['owner'];
            }
        }

        return null;
    }

    /**
     * Every banner this person may act on, each labelled with what its check
     * is about and, for a plugin's, the plugin's name; the most pressing
     * first, and among equals, in the order the checks were registered.
     *
     * @return list<StatusBanner>
     *
     * @since 0.8.0-alpha.1
     */
    public function bannersFor(User $user): array
    {
        $banners = [];

        foreach ($this->checks as ['check' => $registered, 'owner' => $owner]) {
            $check = $registered instanceof StatusCheck ? $registered : app($registered);

            // Asked only for someone who may fix it.
            if (! $user->can($check->ability())) {
                continue;
            }

            $banner = $check->banner();

            if ($banner !== null) {
                $banners[] = $banner->labelled(
                    $check->label(),
                    $owner === 'core' ? null : $check->label(),
                    $this->keyOf($check),
                );
            }
        }

        usort($banners, fn (StatusBanner $a, StatusBanner $b): int => $b->priority <=> $a->priority);

        return $banners;
    }

    /**
     * Whether this person holds the ability for any registered status check.
     */
    public function canAct(User $user): bool
    {
        foreach ($this->checks as ['check' => $registered]) {
            $check = $registered instanceof StatusCheck ? $registered : app($registered);

            if ($user->can($check->ability())) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stable key for matching Home's summary rows, not the translated label.
     */
    private function keyOf(StatusCheck $check): string
    {
        return match ($check::class) {
            BackupsSafe::class => 'backups',
            PageCacheCdnPurges::class, PageCacheFolderShared::class => 'page-cache',
            default => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', class_basename($check))),
        };
    }

    /**
     * The banner this person should see, or null when there is none for them.
     */
    public function forUser(User $user): ?StatusBanner
    {
        return $this->bannersFor($user)[0] ?? null;
    }
}
