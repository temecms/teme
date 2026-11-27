<?php

/**
 * A plugin's redirects door: adds, lists and removes the plugin's own
 * redirects through the redirect Operations, as the site.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\DeleteRedirects;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\Operations\Input\DeleteRedirectsInput;
use App\Domain\Redirects\Queries\PluginRedirects as OwnRedirects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Redirects\RedirectRef;
use Teme\Contracts\Redirects\RedirectWriter;

/**
 * A plugin's redirects door, offered when it was granted redirects. Every
 * redirect it adds carries its code, and it lists and removes only those.
 */
final readonly class PluginRedirects implements RedirectWriter
{
    /** Redirects one call to remove may name. */
    private const int MOST_REMOVED = 500;

    public function __construct(private string $code) {}

    public function add(string $from, string $to, int $status = 301, string $note = ''): RedirectRef
    {
        if (! in_array($status, [301, 302], true)) {
            throw new WriteRefused(__('A redirect a plugin adds answers 301 or 302; to say a path is gone, use gone().'));
        }

        return $this->create($from, $status, $to, $note);
    }

    public function gone(string $from, string $note = ''): RedirectRef
    {
        return $this->create($from, 410, '', $note);
    }

    public function mine(): array
    {
        return app(OwnRedirects::class)->of($this->code);
    }

    public function remove(array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, is_string(...))));

        if ($ids === []) {
            return 0;
        }

        if (count($ids) > self::MOST_REMOVED) {
            throw new WriteRefused(__('Remove at most :most redirects at a time.', ['most' => self::MOST_REMOVED]));
        }

        try {
            return app(DeleteRedirects::class)->execute(new DeleteRedirectsInput($ids, plugin: $this->code));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        }
    }

    private function create(string $from, int $status, string $to, string $note): RedirectRef
    {
        try {
            $made = app(CreateRedirect::class)->execute(new CreateRedirectInput($from, $status, $to, note: $note, plugin: $this->code));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The redirect was refused.'), previous: $refused);
        }

        return app(OwnRedirects::class)->find($this->code, (string) $made['id']) ?? throw new WriteRefused(__('The redirect was refused.'));
    }
}
