<?php

/**
 * Makes PluginRecord models for tests and seeding.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PluginRecord> */
final class PluginRecordFactory extends Factory
{
    protected $model = PluginRecord::class;

    public function definition(): array
    {
        $code = fake()->unique()->lexify('plugin-??????');

        return [
            'name' => "tests/{$code}",
            'code' => $code,
            'state' => PluginRecord::ENABLED,
            'disabled_reason' => null,
            'version' => '1.0.0',
            'consent' => ['capabilities' => [], 'hosts' => [], 'hooks' => []],
            'consented_by' => null,
            'consented_at' => now(),
            'enabled_at' => now(),
            'faults' => [],
            'quarantine' => [],
        ];
    }

    /** Enabled, as EnablePlugin leaves it. */
    public function enabled(): self
    {
        return $this->state(['state' => PluginRecord::ENABLED, 'disabled_reason' => null, 'disabled_at' => null, 'disabled_by' => null]);
    }

    /** Disabled by a person, or the operator. */
    public function disabled(): self
    {
        return $this->state(['state' => PluginRecord::DISABLED, 'disabled_reason' => PluginRecord::BY_USER, 'disabled_at' => now()]);
    }

    /** Disabled by Teme after it failed to start five times. */
    public function stopped(): self
    {
        return $this->state(['state' => PluginRecord::DISABLED, 'disabled_reason' => PluginRecord::QUARANTINED, 'disabled_at' => now(), 'disabled_by' => null]);
    }
}
