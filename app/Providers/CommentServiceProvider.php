<?php

/**
 * Wires reader comments (slice 1.8, docs/studies/comments.md): the statuses
 * registry with core's five, the moderator slot, the Discussion settings
 * group, built from the types registered before it boots, and the gate ability
 * that opens the Comments screen (1.8c).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Comments\BuiltInModerator;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\CoreCommentStatuses;
use App\Domain\Comments\DiscussionSettingsGroup;
use App\Domain\Comments\Queries\CommentScope;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Settings\SettingsGroup;

final class CommentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Filled once, at boot, like the other registries (I-566).
        $this->app->singleton(CommentStatusRegistry::class, function (): CommentStatusRegistry {
            $registry = new CommentStatusRegistry;
            CoreCommentStatuses::register($registry);

            return $registry;
        });

        // The spam-check slot (I-224): a plugin binds its own moderator in
        // core's place. Bound, not a singleton, so none outlives its request.
        $this->app->bind(CommentModerator::class, BuiltInModerator::class);
    }

    public function boot(): void
    {
        Gate::define(CommentModeration::LOOK_AFTER, fn (User $user): bool => CommentScope::forViewer($user)->any());

        // Built when read, and again when the content types change (I-3296).
        $this->app->make(SettingsRegistry::class)->derive(
            'discussion',
            fn (): SettingsGroup => DiscussionSettingsGroup::build(app(ContentTypeRegistry::class)),
            fn (): string => app(ContentTypeRegistry::class)->stamp(),
        );
    }
}
