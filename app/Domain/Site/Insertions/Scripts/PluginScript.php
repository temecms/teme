<?php

/**
 * One inline script a plugin asked to keep in what it inserts, and what a
 * person decided about it: waiting, approved or refused, by its exact
 * text's hash (docs/plans/plugin-sockets-2.md, B4). Only the plugin script
 * Operations write it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Scripts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $plugin_code
 * @property string $name
 * @property string $hash
 * @property string $script
 * @property string $state
 * @property int|null $requested_by
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 */
final class PluginScript extends Model
{
    use HasUlids;

    public const string WAITING = 'waiting';

    public const string APPROVED = 'approved';

    public const string REFUSED = 'refused';

    /** The most scripts that may wait for one plugin at once. */
    public const int MOST_WAITING = 20;

    /** The longest script, in characters. */
    public const int LONGEST = 4096;

    public $timestamps = false;

    protected $table = 'plugin_scripts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'requested_by' => 'integer', 'decided_by' => 'integer'];
    }

    /**
     * The script as the security log keeps it, before or after a decision.
     *
     * @return array{plugin: string, name: string, state: string, hash: string, script: string}
     */
    public function snapshot(): array
    {
        return ['plugin' => $this->plugin_code, 'name' => $this->name, 'state' => $this->state, 'hash' => $this->hash, 'script' => $this->script];
    }
}
