<?php

declare(strict_types=1);

use App\Providers\AddressServiceProvider;
use App\Providers\AiProvidersServiceProvider;
use App\Providers\AiServiceProvider;
use App\Providers\AltTextServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\CardServiceProvider;
use App\Providers\CommentServiceProvider;
use App\Providers\ContentServiceProvider;
use App\Providers\CrawlersServiceProvider;
use App\Providers\DashboardServiceProvider;
use App\Providers\DeploymentServiceProvider;
use App\Providers\FeedsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HeadServiceProvider;
use App\Providers\HookServiceProvider;
use App\Providers\ImportServiceProvider;
use App\Providers\InsertionsServiceProvider;
use App\Providers\JsonLdServiceProvider;
use App\Providers\LanguageServiceProvider;
use App\Providers\ManageApiServiceProvider;
use App\Providers\MediaServiceProvider;
use App\Providers\MetadataSuggestionsServiceProvider;
use App\Providers\NavigationServiceProvider;
use App\Providers\NoticeServiceProvider;
use App\Providers\PageCacheServiceProvider;
use App\Providers\PeopleServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\PreferenceServiceProvider;
use App\Providers\RewriteServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\SeoServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\SiteCodeServiceProvider;
use App\Providers\ThemeServiceProvider;
use App\Providers\ToolsServiceProvider;
use App\Providers\TypesServiceProvider;
use App\Providers\UpdatesServiceProvider;

return [
    AppServiceProvider::class,
    HookServiceProvider::class,
    SettingsServiceProvider::class,
    LanguageServiceProvider::class,
    SecurityServiceProvider::class,

    DeploymentServiceProvider::class,

    TypesServiceProvider::class,
    AddressServiceProvider::class,
    ContentServiceProvider::class,
    PeopleServiceProvider::class,
    CommentServiceProvider::class,
    // After CommentServiceProvider: Search and social's tab follows Discussion's.
    SeoServiceProvider::class,

    HeadServiceProvider::class,

    ToolsServiceProvider::class,

    BackupServiceProvider::class,

    FeedsServiceProvider::class,

    SiteCodeServiceProvider::class,

    InsertionsServiceProvider::class,

    CrawlersServiceProvider::class,

    JsonLdServiceProvider::class,

    MediaServiceProvider::class,
    // After MediaServiceProvider: the AI tab follows Media's.
    AiServiceProvider::class,

    AiProvidersServiceProvider::class,

    RewriteServiceProvider::class,

    AltTextServiceProvider::class,

    MetadataSuggestionsServiceProvider::class,

    CardServiceProvider::class,
    NavigationServiceProvider::class,
    PreferenceServiceProvider::class,
    NoticeServiceProvider::class,
    DashboardServiceProvider::class,
    FortifyServiceProvider::class,
    ThemeServiceProvider::class,
    PageCacheServiceProvider::class,
    ImportServiceProvider::class,
    UpdatesServiceProvider::class,
    PluginServiceProvider::class,
    ManageApiServiceProvider::class,
];
