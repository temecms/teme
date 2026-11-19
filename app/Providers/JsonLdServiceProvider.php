<?php

/**
 * Registers structured data (I-698): the gate's required properties, which
 * packs add to, core's nodes on every public page, and Search and social's
 * section on who publishes the site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\RequiredProperties;
use App\Domain\Status\Checks\RepresentedPersonGone;
use App\Domain\Status\StatusChecks;
use App\Domain\StructuredData\CoreGraph;
use App\Domain\StructuredData\PageAndArticleTypes;
use App\Domain\StructuredData\PublisherNodes;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\StructuredData\Vocabulary;
use App\Domain\StructuredData\WhoPublishes;
use Illuminate\Support\ServiceProvider;

final class JsonLdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequiredProperties::class);
        $this->app->singleton(Vocabulary::class);
        $this->app->scoped(PublisherNodes::class);
        $this->app->tag([CoreGraph::class], DocumentMeta::CONTRIBUTORS);
        $this->app->tag([WhoPublishes::class], SearchAndSocial::SECTIONS);
        $this->app->tag([PageAndArticleTypes::class], SearchAndSocial::SECTIONS);
        $this->app->tag([WhoPublishes::class], SettingsCheck::TAG);
    }

    public function boot(): void
    {
        $this->app->make(TypeSchemas::class)->verify();
        $this->app->make(StatusChecks::class)->register(RepresentedPersonGone::class);
    }
}
