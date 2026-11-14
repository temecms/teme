<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * A check a part adds to one settings group's values, beyond what each
 * setting's type checks, such as robots.txt's extra lines against its
 * grammar (I-866). Registered under TAG from its part's own provider, and
 * asked by UpdateSettings before anything is read or written.
 */
interface SettingsCheck
{
    public const string TAG = 'teme.settings.checks';

    /**
     * The key of the group whose values it checks.
     */
    public function group(): string;

    /**
     * What is wrong with the values a form sends, one message a field,
     * keyed `values.{key}`; none when nothing is. A value that isn't of its
     * setting's type is left to the type's own rule.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array<string, string>
     */
    public function problems(array $values): array;
}
