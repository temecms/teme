<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

/**
 * A password confirmation within security.elevation_minutes, by the person the
 * Operation acts for. Fortify records the confirmation time.
 */
final class SessionElevation implements Elevation
{
    public function __construct(
        private readonly Request $request,
        private readonly SettingsReader $settings,
    ) {}

    public function ensure(OperationInput $input): void
    {
        $userId = $this->request->user()?->getAuthIdentifier();
        $confirmedAt = $this->request->hasSession() ? (int) $this->request->session()->get('auth.password_confirmed_at', 0) : 0;
        $window = $this->settings->int('security', 'elevation_minutes') * 60;

        $isSamePerson = $userId !== null && $input->actorId() === (string) $userId;
        $isFresh = $confirmedAt > 0 && Date::now()->getTimestamp() - $confirmedAt <= $window;

        if (! $isSamePerson || ! $isFresh) {
            throw new ElevationRequiredException;
        }
    }

    public function isOperator(): bool
    {
        return false;
    }
}
