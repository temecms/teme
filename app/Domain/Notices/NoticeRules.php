<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Models\User;
use Closure;

/**
 * Core's own rules beside a notice kind, never part of the published
 * contract: whether the kind exists on this site now, the site's switch for
 * it, the line shown when it is switched off, and its label worded for one
 * person.
 */
final readonly class NoticeRules
{
    /**
     * @param  (Closure(): bool)|null  $offered  whether the kind exists on this site now; not offered, it is neither sent nor listed
     * @param  (Closure(): bool)|null  $sends  the site's switch; off, it is not sent and the screen shows it disabled
     * @param  string|null  $switchedOff  the screen's line for a kind switched off for everyone, in English
     * @param  (Closure(User): string)|null  $labelFor  the label worded for this person, already translated
     */
    public function __construct(
        public ?Closure $offered = null,
        public ?Closure $sends = null,
        public ?string $switchedOff = null,
        public ?Closure $labelFor = null,
    ) {}
}
