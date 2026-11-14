<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use Carbon\CarbonImmutable;

/**
 * The verdict on the site's backups (I-2409): the red problems, the ones of them that email, the amber cautions, and when the newest good backup ended. Red when there is any problem, amber when there is only a caution, green when there is neither.
 */
final readonly class BackupVerdict
{
    public const string RED = 'error';

    public const string AMBER = 'warning';

    /**
     * @param  list<string>  $problems  red, in the order they are told
     * @param  list<string>  $alerting  the problems that email the administrators
     * @param  list<string>  $cautions  amber
     */
    public function __construct(
        public array $problems,
        public array $alerting,
        public array $cautions,
        public ?CarbonImmutable $lastGoodBackup,
    ) {}

    /**
     * RED when there is any problem, AMBER when only a caution, else null.
     */
    public function level(): ?string
    {
        return match (true) {
            $this->problems !== [] => self::RED,
            $this->cautions !== [] => self::AMBER,
            default => null,
        };
    }

    public function isRed(): bool
    {
        return $this->problems !== [];
    }
}
