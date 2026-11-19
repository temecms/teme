<?php

/**
 * Why a recovery key was shown: setting backups up, adding one, or replacing
 * one (I-359).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum RecoveryKeyPurpose: string
{
    case SetUp = 'set-up';
    case Add = 'add';
    case Replace = 'replace';
}
