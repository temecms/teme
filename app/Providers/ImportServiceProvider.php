<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Import\ImportMode;
use App\Domain\Import\Phases\Addresses\AddressClashStep;
use App\Domain\Import\Phases\Addresses\AddressSettingsStep;
use App\Domain\Import\Phases\Addresses\OldAddressesStep;
use App\Domain\Import\Phases\Check\CheckStep;
use App\Domain\Import\Phases\Comments\CommentCountsStep;
use App\Domain\Import\Phases\Comments\CommentPagesStep;
use App\Domain\Import\Phases\Comments\CommentsPhase;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\ContentVisibilityStep;
use App\Domain\Import\Phases\Content\ContentWaitingStep;
use App\Domain\Import\Phases\Content\PageParentsStep;
use App\Domain\Import\Phases\Finish\DeletedStep;
use App\Domain\Import\Phases\Finish\FidelityStep;
use App\Domain\Import\Phases\Finish\RecountStep;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Media\MediaParentsStep;
use App\Domain\Import\Phases\Media\MediaPhase;
use App\Domain\Import\Phases\Media\OutsideImagesStep;
use App\Domain\Import\Phases\Menus\MenuPlacesStep;
use App\Domain\Import\Phases\Menus\MenusPhase;
use App\Domain\Import\Phases\Menus\WidgetsStep;
use App\Domain\Import\Phases\Redirects\RedirectsStep;
use App\Domain\Import\Phases\SecondPass\SecondPassStep;
use App\Domain\Import\Phases\Settings\SiteSettingsStep;
use App\Domain\Import\Phases\Terms\TermParentsStep;
use App\Domain\Import\Phases\Terms\TermsPhase;
use App\Domain\Import\Phases\Users\AuthorsWaitingStep;
use App\Domain\Import\Phases\Users\UsersPhase;
use App\Domain\Import\PluginData\PluginDataGoneStep;
use App\Domain\Import\PluginData\PluginDataPhase;
use App\Domain\Import\PluginData\PluginOptionsStep;
use App\Domain\Import\Tracking\ImportJobKind;
use App\Domain\Import\Tracking\PageParentsJobKind;
use App\Domain\Jobs\JobKinds;
use App\Domain\Support\Network\DnsHostResolver;
use App\Domain\Support\Network\HostResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the importer: the import mode, the resolver the address check
 * asks, and the phases, by tag (I-349).
 */
final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ImportMode::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // Core's phases, each tagged ImportPhases::TAG from its own line, in I-333's order.

        $this->app->tag([AddressClashStep::class], ImportPhases::TAG);

        $this->app->tag([AddressSettingsStep::class], ImportPhases::TAG);

        $this->app->tag([UsersPhase::class, AuthorsWaitingStep::class], ImportPhases::TAG);

        $this->app->tag([TermsPhase::class, TermParentsStep::class], ImportPhases::TAG);

        $this->app->tag([MediaPhase::class, OutsideImagesStep::class, MediaParentsStep::class], ImportPhases::TAG);

        $this->app->tag([ContentVisibilityStep::class], ImportPhases::TAG);

        $this->app->tag([ContentWaitingStep::class, ContentPhase::class, PageParentsStep::class], ImportPhases::TAG);

        $this->app->tag([PluginOptionsStep::class, PluginDataPhase::class, PluginDataGoneStep::class], ImportPhases::TAG);

        $this->app->tag([SecondPassStep::class], ImportPhases::TAG);

        $this->app->tag([CommentsPhase::class, CommentCountsStep::class, CommentPagesStep::class], ImportPhases::TAG);

        $this->app->tag([MenusPhase::class, MenuPlacesStep::class, WidgetsStep::class], ImportPhases::TAG);

        $this->app->tag([RedirectsStep::class, OldAddressesStep::class], ImportPhases::TAG);

        $this->app->tag([SiteSettingsStep::class], ImportPhases::TAG);

        $this->app->tag([CheckStep::class], ImportPhases::TAG);

        $this->app->tag([FidelityStep::class, RecountStep::class], ImportPhases::TAG);

        $this->app->tag([DeletedStep::class], ImportPhases::TAG);

        $this->app->tag([ImportJobKind::class, PageParentsJobKind::class], JobKinds::TAG);
    }
}
