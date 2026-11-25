<?php

/**
 * Chooses what every admin page receives from the server: the site title,
 * chosen fields of the signed-in person, their main menu, the settings groups
 * they may manage, the content types and taxonomies other screens read, the
 * header's counts, the sidebar state and the admin's words, once a visit.
 * Nothing else of the user model reaches the browser.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Inbox\Queries\InboxFor;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Languages\AdminWords;
use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusChecks;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Users\Queries\CurrentUserData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\OnceProp;
use Teme\Contracts\Settings\SettingsReader;

final class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function __construct(
        private readonly CurrentUserData $currentUser,
        private readonly SettingsNavigation $settingsNavigation,
        private readonly AdminNavigation $adminNavigation,
        private readonly SettingsReader $settings,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * The admin's words (resources/js/lib/i18n.ts), sent with a visit's first
     * page and remembered by the browser until the locale or the catalogue
     * changes, which changes the key.
     *
     * @return array<string, callable|OnceProp>
     */
    public function shareOnce(Request $request): array
    {
        $catalogue = app(AdminWords::class)->catalogue(app()->getLocale());

        return [
            ...parent::shareOnce($request),
            'i18n' => Inertia::once(fn (): array => $catalogue)->as("i18n:{$catalogue['locale']}:{$catalogue['version']}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $userId = $request->user()?->getAuthIdentifier();
        $nav = $this->adminNavigation->forUser($userId);

        // Every status check's banner this person may act on, asked once a request (I-3397).
        $banners = null;
        $bannersFor = function () use ($request, &$banners): array {
            return $banners ??= $request->user() === null ? [] : app(StatusChecks::class)->bannersFor($request->user());
        };

        return [
            ...parent::share($request),
            'name' => $this->settings->string('general', 'site_title'),
            // Every date the admin writes is on this clock, not the browser's (issue 530).
            'siteTimezone' => $this->settings->string('general', 'timezone'),
            'auth' => [
                'user' => $this->currentUser->find($userId),
            ],
            'nav' => $nav,
            'settingsNav' => $this->settingsNavigation->forUser($userId),
            // The top bar's site status: every banner, the most pressing first (I-3397).
            'siteStatus' => fn (): array => array_map(fn (StatusBanner $banner): array => $banner->toArray(), $bannersFor()),
            // Whether this person may act on any status check (Home's Site status panel).
            'siteStatusCanAct' => fn (): bool => $request->user() !== null && app(StatusChecks::class)->canAct($request->user()),
            // The header's jobs button and bell (I-104): only for someone
            // the verified routes behind both panels will answer.
            'headerCounts' => function () use ($request): ?array {
                $user = $request->user();

                if ($user === null || ! $user->hasVerifiedEmail()) {
                    return null;
                }

                return [...app(TrackedJobList::class)->countsFor((int) $user->getAuthIdentifier()), 'unread' => app(InboxFor::class)->unread($user)];
            },
            'contentTypes' => $userId === null ? [] : $this->contentTypes($request),
            'taxonomies' => $userId === null ? [] : $this->taxonomies(),
            // The signed-in person's own preferences, defaults applied: every
            // screen reads them from here, and nothing asks for them again.
            'preferences' => $request->user() === null ? [] : PreferencesFor::user($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The listed content types, in registry order, for the screens that read
     * them, each saying whether this person may create one — the check the
     * menu makes for the same entry — so the palette offers New {singular}
     * only then, never for a type with one item only, whose item the menu opens.
     *
     * @return list<array{key: string, singular: string, plural: string, segment: string, icon: string, canCreate: bool, stored: bool}>
     */
    private function contentTypes(Request $request): array
    {
        $listed = array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->listed);
        $user = $request->user();

        return array_values(array_map(fn (ContentType $type): array => [
            'key' => $type->key,
            'singular' => $type->singular,
            'plural' => $type->plural,
            'segment' => $type->segment,
            'icon' => $type->icon,
            'canCreate' => ! $type->single && (bool) $user?->can($type->ability('create')),
            // Stored types have no menu entry of their own; Types is current on their lists.
            'stored' => $type->stored,
        ], $listed));
    }

    /**
     * Every taxonomy, in registry order, for the list and terms screens.
     *
     * @return list<array{key: string, singular: string, plural: string, segment: string, icon: string, hierarchical: bool}>
     */
    private function taxonomies(): array
    {
        return array_map(fn (Taxonomy $taxonomy): array => [
            'key' => $taxonomy->key,
            'singular' => $taxonomy->singular,
            'plural' => $taxonomy->plural,
            'segment' => $taxonomy->segment,
            'icon' => $taxonomy->icon,
            'hierarchical' => $taxonomy->hierarchical,
        ], app(TaxonomyRegistry::class)->all());
    }
}
