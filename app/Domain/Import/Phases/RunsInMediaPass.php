<?php

/**
 * A phase a picture pass runs (#716). A picture pass runs these phases and
 * no others; another run asks each phase's runsIn() as before.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

interface RunsInMediaPass {}
