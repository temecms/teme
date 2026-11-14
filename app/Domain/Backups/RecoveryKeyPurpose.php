<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Why a recovery key was shown: setting backups up, adding one, or replacing
 * one (I-359).
 */
enum RecoveryKeyPurpose: string
{
    case SetUp = 'set-up';
    case Add = 'add';
    case Replace = 'replace';
}
