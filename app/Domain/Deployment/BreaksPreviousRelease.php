<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

/**
 * Carried by a migration the release before can't run against: one that
 * isn't expand-then-contract (I-383). teme:deploy runs it only while the
 * site is in maintenance mode, unless no release has run on the database.
 */
interface BreaksPreviousRelease {}
