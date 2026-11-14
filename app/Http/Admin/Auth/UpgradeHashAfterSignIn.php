<?php

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use App\Domain\Users\Operations\Input\UpgradePasswordHashInput;
use App\Domain\Users\Operations\UpgradePasswordHash;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;

/**
 * The last step of Fortify's sign-in pipeline: once the password has signed
 * someone in, a stored hash in another format or at other costs is replaced
 * with Argon2id through `UpgradePasswordHash`, never by Laravel's own rehash
 * (I-124). A sign-in that fails, is throttled or waits for a second factor
 * never reaches it.
 */
final class UpgradeHashAfterSignIn
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly Hasher $hasher,
        private readonly UpgradePasswordHash $upgrade,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $this->guard->user();
        $password = $request->input('password');

        if ($user !== null && is_string($password) && $this->hasher->needsRehash($user->getAuthPassword())) {
            $id = (string) $user->getAuthIdentifier();

            $this->upgrade->execute(new UpgradePasswordHashInput(userId: $id, password: $password, actorId: $id));

            // Sign-in kept the old hash's fingerprint for AuthenticateSession; forget it,
            // so the next request records the new one and the person stays signed in (I-124).
            $request->session()->forget('password_hash_'.config('auth.defaults.guard'));
        }

        return $next($request);
    }
}
