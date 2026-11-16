<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * The installer's steps for one request, in the order it takes them
 * (docs/plans/plugin-installer.md).
 */
enum RequestStep: string
{
    case Checking = 'checking';
    case Preparing = 'preparing';
    case Fetching = 'fetching';
    case Verifying = 'verifying';
    case Validating = 'validating';
    case Switching = 'switching';
    case Finishing = 'finishing';

    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string WAITING = 'waiting';

    /** The step after this one, or null after the last. */
    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[$index + 1] ?? null;
    }
}
