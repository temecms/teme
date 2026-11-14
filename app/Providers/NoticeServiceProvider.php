<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notices\BuiltInNotices;
use App\Domain\Notices\MailComposer;
use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\NoticeSender;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Notices\NoticeRegistrar;
use Teme\Contracts\Notices\Notices;

/**
 * Registers the registry of notice kinds and declares core's own into it,
 * binds the contracts a plugin will use when mail.send opens (I-3364), and
 * shares the site's title with every mail view.
 */
final class NoticeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not a singleton: NoStaticStateTest allows a process-wide registry
        // only from its own list, which this part may not widen (I-087).
        $this->app->scoped(NoticeRegistry::class, function (): NoticeRegistry {
            $registry = new NoticeRegistry;
            BuiltInNotices::register($registry);

            return $registry;
        });

        $this->app->bind(NoticeRegistrar::class, NoticeRegistry::class);
        $this->app->bind(Notices::class, NoticeSender::class);
    }

    public function boot(): void
    {
        // The anonymous mail components' views take the site's title too.
        View::composer(['notifications::email', 'mail::message', 'mail::layout'], MailComposer::class.'@compose');
    }
}
