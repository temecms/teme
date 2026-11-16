<?php

declare(strict_types=1);

namespace Teme\Testing;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\SessionElevation;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Runs one step as a person who has just confirmed their password, as the
 * admin's own requests are, and turns a refusal into a failed test that
 * says why. Puts back what was bound before.
 */
final class Acting
{
    /**
     * Returns what $step returns, run as $person: signed in, with core's
     * session elevation over a request whose session holds a password
     * confirmed now. A refusal fails the test with its message.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     *
     * @return T
     */
    public function as(Person $person, Closure $step, string $doing): mixed
    {
        $user = User::query()->findOrFail($person->id);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());
        $request->setUserResolver(fn (): User => $user);

        $elevation = app(Elevation::class);
        $signedIn = Auth::user();
        app()->instance(Elevation::class, new SessionElevation($request, app(SettingsReader::class)));
        Auth::setUser($user);

        try {
            return $step();
        } catch (ValidationException $refused) {
            Assert::fail("{$doing} was refused: ".implode(' ', array_map(strval(...), array_merge(...array_values($refused->errors())))));
        } catch (AuthorizationException|ElevationRequiredException $refused) {
            Assert::fail("{$doing} was refused: ".$refused->getMessage());
        } finally {
            app()->instance(Elevation::class, $elevation);
            $signedIn === null ? Auth::forgetUser() : Auth::setUser($signedIn);
        }
    }
}
