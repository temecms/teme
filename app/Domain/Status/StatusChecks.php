<?php

declare(strict_types=1);

namespace App\Domain\Status;

use App\Models\User;

/**
 * Every registered status check, with who added it, every banner a person
 * may act on, and the one they see first: the highest-priority banner among
 * the checks they may act on (I-221).
 */
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
                $banners[] = $banner->labelled($check->label(), $owner === 'core' ? null : $check->label());
            }
        }

        usort($banners, fn (StatusBanner $a, StatusBanner $b): int => $b->priority <=> $a->priority);

        return $banners;
    }

    /**
     * The banner this person should see, or null when there is none for them.
     */
    public function forUser(User $user): ?StatusBanner
    {
        return $this->bannersFor($user)[0] ?? null;
    }
}
