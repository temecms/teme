<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of site code (I-288): a name, the placement it goes in, the code
 * exactly as an administrator wrote it, whether it is on, and the hosts it
 * also connects to (I-3375). Only the site code Operations write it.
 *
 * @property string $id
 * @property string $name
 * @property string $placement
 * @property string $code
 * @property bool $enabled
 * @property list<string> $origins
 * @property int|null $updated_by
 */
final class SiteCodeEntry extends Model
{
    use HasUlids;

    /** A script whose type is JSON-LD, in any case, its type quoted or not. */
    private const string STRUCTURED_DATA = '~<script\b[^>]*\btype\s*=\s*["\']?\s*application/ld\+json~i';

    protected $table = 'site_code_entries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'origins' => 'array'];
    }

    /**
     * Whether the code holds structured data, which would make a second
     * graph beside the one Teme writes: flagged on the screen, never removed
     * (I-865).
     */
    public function holdsStructuredData(): bool
    {
        return preg_match(self::STRUCTURED_DATA, $this->code) === 1;
    }

    /**
     * The entry as the security log keeps it, before or after a change.
     *
     * @return array{name: string, placement: string, on: bool, code: string, origins: string}
     */
    public function snapshot(): array
    {
        return ['name' => $this->name, 'placement' => $this->placement, 'on' => $this->enabled, 'code' => $this->code, 'origins' => implode(' ', $this->origins ?? [])];
    }
}
