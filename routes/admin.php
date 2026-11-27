<?php

/**
 * The admin's routes, under /admin and behind sign-in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Comments\CommentModeration;
use App\Domain\Extensions\ExtensionsAccess;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Themes\ManifestValidator;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypeAccess;
use App\Http\Admin\Account\NotificationsController;
use App\Http\Admin\Account\PictureController;
use App\Http\Admin\Account\PreferencesController;
use App\Http\Admin\Account\ProfileController;
use App\Http\Admin\Account\SecurityController;
use App\Http\Admin\Account\TwoFactorController;
use App\Http\Admin\AdminErrorPage;
use App\Http\Admin\AdminScreenController;
use App\Http\Admin\Ai\AiRequestController;
use App\Http\Admin\Auth\FirstRunController;
use App\Http\Admin\Auth\InvitationController;
use App\Http\Admin\Comments\CommentDeletionController;
use App\Http\Admin\Comments\CommentEditController;
use App\Http\Admin\Comments\CommentEmptyController;
use App\Http\Admin\Comments\CommentModerationController;
use App\Http\Admin\Comments\CommentReplyController;
use App\Http\Admin\Comments\CommentScreenController;
use App\Http\Admin\Contents\AutosaveController;
use App\Http\Admin\Contents\BulkContentController;
use App\Http\Admin\Contents\BulkMatchingController;
use App\Http\Admin\Contents\CardMediaController;
use App\Http\Admin\Contents\ContentActionController;
use App\Http\Admin\Contents\ContentEditorController;
use App\Http\Admin\Contents\ContentListController;
use App\Http\Admin\Contents\ContentPreviewController;
use App\Http\Admin\Contents\ContentTreeController;
use App\Http\Admin\Contents\EditorPanelController;
use App\Http\Admin\Contents\LinkChoicesController;
use App\Http\Admin\Contents\LinkedContentController;
use App\Http\Admin\Contents\LinkTargetsController;
use App\Http\Admin\Contents\PluginCardChoicesController;
use App\Http\Admin\Contents\PluginChecksController;
use App\Http\Admin\Contents\PreviewLinkController;
use App\Http\Admin\Contents\ReviewController;
use App\Http\Admin\Contents\RevisionController;
use App\Http\Admin\Contents\ScheduleController;
use App\Http\Admin\Contents\StructuredDataController;
use App\Http\Admin\Dashboard\DashboardController;
use App\Http\Admin\Dashboard\QuickDraftController;
use App\Http\Admin\Dev\CatalogueController;
use App\Http\Admin\ExtensionsController;
use App\Http\Admin\InboxController;
use App\Http\Admin\Media\ImportImageController;
use App\Http\Admin\Media\MediaImageController;
use App\Http\Admin\Media\MediaItemController;
use App\Http\Admin\Media\MediaLibraryController;
use App\Http\Admin\Media\MediaPickerController;
use App\Http\Admin\MyJobsController;
use App\Http\Admin\NavigationController;
use App\Http\Admin\PluginActionController;
use App\Http\Admin\PluginAreaController;
use App\Http\Admin\PluginListActionController;
use App\Http\Admin\PluginsController;
use App\Http\Admin\RegionsController;
use App\Http\Admin\SearchController;
use App\Http\Admin\Settings\AddressesController;
use App\Http\Admin\Settings\AiSettingsController;
use App\Http\Admin\Settings\BrandColourController;
use App\Http\Admin\Settings\CheckAiKeyController;
use App\Http\Admin\Settings\DiscussionSettingsController;
use App\Http\Admin\Settings\FediverseSettingsController;
use App\Http\Admin\Settings\FindSettingsController;
use App\Http\Admin\Settings\HomePageController;
use App\Http\Admin\Settings\SearchAndSocialController;
use App\Http\Admin\Settings\SettingsController;
use App\Http\Admin\Settings\SettingsHubController;
use App\Http\Admin\Settings\SiteCodeController;
use App\Http\Admin\TaxonomiesController;
use App\Http\Admin\Taxonomy\TermController;
use App\Http\Admin\Taxonomy\TermSearchController;
use App\Http\Admin\ThemesController;
use App\Http\Admin\Tools\AiActivityController;
use App\Http\Admin\Tools\Backups\BackUpNowController;
use App\Http\Admin\Tools\Backups\BackupPlacesController;
use App\Http\Admin\Tools\Backups\BackupScheduleController;
use App\Http\Admin\Tools\Backups\BackupSetupController;
use App\Http\Admin\Tools\Backups\BackupsScreenController;
use App\Http\Admin\Tools\Backups\RecoveryKeyController;
use App\Http\Admin\Tools\Backups\RequireFreshPassword;
use App\Http\Admin\Tools\Backups\RestoredSiteController;
use App\Http\Admin\Tools\Backups\SftpStepController;
use App\Http\Admin\Tools\ExportRedirectsController;
use App\Http\Admin\Tools\Import\ImportFileController;
use App\Http\Admin\Tools\Import\ImportRunController;
use App\Http\Admin\Tools\Import\ImportScreenController;
use App\Http\Admin\Tools\Import\ImportSourceController;
use App\Http\Admin\Tools\ImportRedirectsController;
use App\Http\Admin\Tools\JobsController;
use App\Http\Admin\Tools\PageCacheController;
use App\Http\Admin\Tools\RedirectController;
use App\Http\Admin\Tools\RedirectScreenController;
use App\Http\Admin\Tools\ToolsController;
use App\Http\Admin\Tools\Updates\CancelUpdateController;
use App\Http\Admin\Tools\Updates\RequestUpdateController;
use App\Http\Admin\Tools\UpdatesController;
use App\Http\Admin\TypeFieldsController;
use App\Http\Admin\TypesController;
use App\Http\Admin\Users\UserController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['admin', 'guest'])->group(function (): void {
    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation', [InvitationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitation.accept');

    // The first-run page (issue 246): open only while no account exists.
    Route::get('setup', [FirstRunController::class, 'show'])->name('first-run.show');
    Route::post('setup', [FirstRunController::class, 'store'])->name('first-run.store');
});

Route::prefix('admin')->middleware(['admin', 'auth'])->group(function (): void {
    // Reachable while unverified, so someone who changed their address can
    // re-send the verification link.
    Route::redirect('account', '/admin/account/profile');
    Route::get('account/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('account/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::middleware('verified')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        // No can: middleware: the ability is the main type's, and CreateDraft
        // authorizes (a refusal is a 403).
        Route::post('quick-draft', QuickDraftController::class)->name('dashboard.quick-draft');
        // The design system's catalogue (docs/architecture/design-system.md, section 9.3): local and tests only.
        if (app()->environment(['local', 'testing'])) {
            Route::get('_catalogue', CatalogueController::class)->name('catalogue');
        }
        Route::get('search', SearchController::class)
            ->middleware('throttle:admin-search')
            ->name('search');
        // The Settings screens' Find a setting, under the palette's limit.
        Route::get('search/settings', FindSettingsController::class)
            ->middleware('throttle:admin-search')
            ->name('search.settings');

        Route::get('account/security', [SecurityController::class, 'edit'])
            ->middleware(RequirePassword::class)
            ->name('security.edit');
        Route::get('account/two-factor', [TwoFactorController::class, 'edit'])
            ->middleware(RequirePassword::class)
            ->name('two-factor.edit');

        Route::put('account/password', [SecurityController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('user-password.update');

        Route::get('account/preferences', [PreferencesController::class, 'edit'])->name('preferences.edit');
        Route::patch('account/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
        Route::get('account/notifications', [NotificationsController::class, 'edit'])->name('notifications.edit');
        Route::patch('account/notifications', [NotificationsController::class, 'update'])->name('notifications.update');
        Route::put('account/picture', [PictureController::class, 'update'])->name('profile.picture.update');
        Route::delete('account/picture', [PictureController::class, 'destroy'])->name('profile.picture.destroy');

        // The Settings hub: a card for each Settings page the person may open.
        Route::get('settings', SettingsHubController::class)->name('settings.index');

        // Before settings/{group}, which would otherwise catch it: the
        // addresses group has its own screen and its own Operation.
        Route::middleware('can:settings.addresses.manage')->group(function (): void {
            Route::get('settings/addresses', [AddressesController::class, 'show'])->name('settings.addresses.show');
            Route::put('settings/addresses', [AddressesController::class, 'update'])->name('settings.addresses.update');
            Route::get('settings/addresses/progress', [AddressesController::class, 'progress'])->name('settings.addresses.progress');
        });

        // Site › Themes (docs/architecture/themes.md): the design group and
        // every theme's own are changed here, through their own Operations.
        Route::middleware('can:settings.design.manage')
            ->prefix('themes')
            ->name('themes.')
            ->group(function (): void {
                Route::get('/', [ThemesController::class, 'index'])->name('index');
                Route::put('draft', [ThemesController::class, 'saveDraft'])->name('draft.save');
                Route::delete('draft', [ThemesController::class, 'discard'])->name('draft.discard');
                Route::post('publish', [ThemesController::class, 'publish'])->name('publish');
                Route::post('preview-link', [ThemesController::class, 'previewLink'])->name('preview-link');
                Route::post('{code}/preview', [ThemesController::class, 'startPreview'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('preview.start');
                Route::delete('{code}/preview', [ThemesController::class, 'stopPreview'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('preview.stop');
                Route::post('{code}/activate', [ThemesController::class, 'activate'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('activate');
                Route::get('{code}/screenshot', [ThemesController::class, 'screenshot'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('screenshot');
                Route::get('{code}/settings', [ThemesController::class, 'settings'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('settings');
            });

        // Site › Plugins (I-3353): the plugins found, enabled through a consent
        // dialog and disabled with one click, by their Operations. Its paths
        // are on the safe list, so it is drawn however a plugin behaves.
        Route::middleware('can:'.PluginAccess::MANAGE)
            ->prefix('plugins')
            ->name('plugins.')
            ->group(function (): void {
                Route::get('/', [PluginsController::class, 'index'])->name('index');
                Route::get('{code}/icon', [PluginsController::class, 'icon'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('icon');
                Route::post('{code}/enable', [PluginsController::class, 'enable'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('enable');
                Route::post('{code}/migrate', [PluginsController::class, 'migrate'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('migrate');
                Route::post('{code}/remove-data', [PluginsController::class, 'removeData'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('remove-data');
                Route::post('{code}/forget', [PluginsController::class, 'forget'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('forget');
                Route::post('{code}/disable', [PluginsController::class, 'disable'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->name('disable');
                Route::post('{code}/list-actions/{action}', [PluginListActionController::class, 'run'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->where('action', '[a-z][a-z0-9-]{1,39}')
                    ->name('list-actions.run');
                Route::post('{code}/actions/{action}', [PluginActionController::class, 'run'])
                    ->where('code', ManifestValidator::CODE_PATTERN)
                    ->where('action', '[a-z][a-z0-9-]{1,39}')
                    ->name('actions.run');
            });

        // A plugin's own area (docs/plans/plugin-sockets-2.md, A6): its pages,
        // a record's form, and the writes, each behind the area's own
        // permission, which the controller checks.
        Route::prefix('plugin/{code}')
            ->name('plugins.area.')
            ->where(['code' => ManifestValidator::CODE_PATTERN, 'record' => '[a-z][a-z0-9-]{1,39}', 'id' => '(?!new$|act$)[A-Za-z0-9_.:-]{1,64}', 'action' => '[a-z][a-z0-9-]{1,39}'])
            ->group(function (): void {
                Route::post('actions/{action}', [PluginAreaController::class, 'action'])->name('action');
                Route::post('scripts/{script}', [PluginAreaController::class, 'approveScript'])->where('script', '[0-9a-z]{26}')->name('script');
                Route::get('records/{record}/new', [PluginAreaController::class, 'create'])->name('new');
                Route::post('records/{record}/act', [PluginAreaController::class, 'act'])->name('act');
                Route::get('records/{record}/{id}', [PluginAreaController::class, 'edit'])->name('edit');
                Route::post('records/{record}/{id?}', [PluginAreaController::class, 'save'])->name('save');
                // Before {page?}: the plugin's own icon for the area header.
                Route::get('_icon', [PluginAreaController::class, 'icon'])->name('icon');
                // Before {page?}: a plugin with an area keeps its settings as this tab (#707).
                Route::get('settings', [PluginAreaController::class, 'settings'])->name('settings');
                Route::get('{page?}', [PluginAreaController::class, 'show'])->where('page', '[a-z][a-z0-9-]{1,39}')->name('show');
            });

        // Plugins and themes installed from the admin (I-211): requests the installer carries out, the
        // sources and uploads it reads, Packagist's search and the directory's. Each change asks for the password again.
        Route::middleware('can:'.ExtensionsAccess::INSTALL)
            ->prefix('extensions')
            ->name('extensions.')
            ->where(['id' => '[0-9a-hjkmnp-tv-z]{26}'])
            ->group(function (): void {
                Route::post('requests', [ExtensionsController::class, 'store'])->middleware('throttle:6,1')->name('requests.store');
                Route::post('requests/{id}/cancel', [ExtensionsController::class, 'cancel'])->name('requests.cancel');
                Route::post('requests/{id}/agree', [ExtensionsController::class, 'agree'])->name('requests.agree');
                Route::get('requests/{id}', [ExtensionsController::class, 'show'])->name('requests.show');
                Route::get('search', [ExtensionsController::class, 'search'])->middleware('throttle:60,1')->name('search');
                Route::get('directory', [ExtensionsController::class, 'directory'])->middleware('throttle:60,1')->name('directory');
                Route::get('directory/package', [ExtensionsController::class, 'directoryPackage'])->middleware('throttle:60,1')->name('directory.package');
                Route::post('sources', [ExtensionsController::class, 'storeSource'])->name('sources.store');
                Route::delete('sources/{id}', [ExtensionsController::class, 'destroySource'])->name('sources.destroy');
                Route::post('uploads', [ExtensionsController::class, 'upload'])->middleware('throttle:6,1')->name('uploads.store');
            });

        // Site › Types (I-3302): the site's own content types, changed through
        // their Operations; code's are shown, never changed. The fixed paths
        // hold a hyphen or a dot, which no type's key can, so none hides a type.
        Route::middleware('can:'.TypeAccess::MANAGE)
            ->prefix('types')
            ->name('types.')
            ->group(function (): void {
                Route::get('/', [TypesController::class, 'index'])->name('index');
                Route::post('/', [TypesController::class, 'store'])->name('store');
                Route::get('new-type', [TypesController::class, 'create'])->name('create');
                Route::get('teme-types.json', [TypesController::class, 'download'])->name('download');
                Route::get('{key}', [TypesController::class, 'show'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('show');
                Route::get('{key}/fields', [TypeFieldsController::class, 'index'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('fields.index');
                Route::post('{key}/fields', [TypeFieldsController::class, 'store'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('fields.store');
                Route::put('{key}/fields/{field}', [TypeFieldsController::class, 'update'])->where(['key' => '[a-z][a-z0-9_]{0,31}', 'field' => '[0-9A-HJKMNP-TV-Z]{26}'])->name('fields.update');
                Route::put('{key}/fields/{field}/position', [TypeFieldsController::class, 'move'])->where(['key' => '[a-z][a-z0-9_]{0,31}', 'field' => '[0-9A-HJKMNP-TV-Z]{26}'])->name('fields.move');
                Route::delete('{key}/fields/{field}', [TypeFieldsController::class, 'destroy'])->where(['key' => '[a-z][a-z0-9_]{0,31}', 'field' => '[0-9A-HJKMNP-TV-Z]{26}'])->name('fields.destroy');
                Route::post('{key}/fields/{field}/restore', [TypeFieldsController::class, 'restore'])->where(['key' => '[a-z][a-z0-9_]{0,31}', 'field' => '[0-9A-HJKMNP-TV-Z]{26}'])->name('fields.restore');
                Route::put('{key}', [TypesController::class, 'update'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('update');
                Route::delete('{key}', [TypesController::class, 'destroy'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('destroy');
            });

        // Site › Taxonomies (I-3319): the site's own taxonomies, changed through
        // their Operations; code's are shown, never changed. new-taxonomy holds
        // a hyphen, which no key can, so it hides no taxonomy.
        Route::middleware('can:'.TypeAccess::MANAGE)
            ->prefix('taxonomies')
            ->name('taxonomies.')
            ->group(function (): void {
                Route::get('/', [TaxonomiesController::class, 'index'])->name('index');
                Route::post('/', [TaxonomiesController::class, 'store'])->name('store');
                Route::get('new-taxonomy', [TaxonomiesController::class, 'create'])->name('create');
                Route::get('{key}', [TaxonomiesController::class, 'show'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('show');
                Route::put('{key}', [TaxonomiesController::class, 'update'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('update');
                Route::delete('{key}', [TaxonomiesController::class, 'destroy'])->where('key', '[a-z][a-z0-9_]{0,31}')->name('destroy');
            });

        // Before settings/{group} too: the discussion group and the modules
        // group's switch are edited here, each with its own Operation (1.8d's D2).
        Route::middleware('can:settings.discussion.manage')->group(function (): void {
            Route::get('settings/discussion', [DiscussionSettingsController::class, 'show'])->name('settings.discussion');
            Route::put('settings/discussion', [DiscussionSettingsController::class, 'update'])->name('settings.discussion.update');
            Route::put('settings/discussion/switch', [DiscussionSettingsController::class, 'switchComments'])->name('settings.discussion.switch');
        });

        // Before settings/{group} too: Settings › Site code (I-863) keeps its
        // entries in a table of its own, each change by its own Operation.
        Route::middleware('can:site-code.manage')->group(function (): void {
            Route::get('settings/site-code', [SiteCodeController::class, 'show'])->name('settings.site-code');
            Route::post('settings/site-code', [SiteCodeController::class, 'store'])->name('settings.site-code.store');
            Route::put('settings/site-code/ads-txt', [SiteCodeController::class, 'updateAdsTxt'])->name('settings.site-code.ads-txt');
            Route::put('settings/site-code/{entry}', [SiteCodeController::class, 'update'])
                ->where('entry', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('settings.site-code.update');
            // Not 'switch': a reserved word in JavaScript, and Wayfinder names functions after it.
            Route::put('settings/site-code/{entry}/switch', [SiteCodeController::class, 'toggle'])
                ->where('entry', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('settings.site-code.toggle');
            Route::delete('settings/site-code/{entry}', [SiteCodeController::class, 'destroy'])
                ->where('entry', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('settings.site-code.destroy');
            // A plugin's inline script (docs/plans/plugin-sockets-2.md, B4), decided here by its Operations.
            Route::post('settings/site-code/scripts/{script}/approve', [SiteCodeController::class, 'approveScript'])
                ->where('script', '[0-9a-z]{26}')
                ->name('settings.site-code.scripts.approve');
            Route::post('settings/site-code/scripts/{script}/refuse', [SiteCodeController::class, 'refuseScript'])
                ->where('script', '[0-9a-z]{26}')
                ->name('settings.site-code.scripts.refuse');
            Route::post('settings/site-code/scripts/{script}/revoke', [SiteCodeController::class, 'revokeScript'])
                ->where('script', '[0-9a-z]{26}')
                ->name('settings.site-code.scripts.revoke');
        });

        // Before settings/{group} too: Settings › Search and social draws its
        // sections on a screen of its own and saves through UpdateSettings (I-874).
        Route::middleware('can:settings.seo.manage')->group(function (): void {
            Route::get('settings/seo', [SearchAndSocialController::class, 'show'])->name('settings.seo');
            Route::put('settings/seo', [SearchAndSocialController::class, 'update'])->name('settings.seo.update');
        });

        // Check key (I-315): one provider's key, asked of the provider; JSON for Settings › AI.
        Route::post('settings/ai/{provider}/check-key', CheckAiKeyController::class)
            ->middleware(['can:settings.ai.manage', 'throttle:6,1'])
            ->where('provider', '[a-z][a-z0-9]*(?:-[a-z0-9]+)*')
            ->name('settings.ai.check-key');

        // Before settings/{group} too: Settings › AI draws the group on a
        // screen of its own and saves through UpdateSettings (I-1308).
        Route::middleware('can:settings.ai.manage')->group(function (): void {
            Route::get('settings/ai', [AiSettingsController::class, 'show'])->name('settings.ai');
            Route::put('settings/ai', [AiSettingsController::class, 'update'])->name('settings.ai.update');
        });

        // Before settings/{group} too: Settings › Fediverse (I-058), each
        // change by its own Operation; the switch also needs settings.modules.manage.
        Route::middleware('can:settings.fediverse.manage')->group(function (): void {
            Route::get('settings/fediverse', [FediverseSettingsController::class, 'show'])->name('settings.fediverse');
            Route::put('settings/fediverse', [FediverseSettingsController::class, 'update'])->name('settings.fediverse.update');
            Route::put('settings/fediverse/switch', [FediverseSettingsController::class, 'switchFediverse'])->name('settings.fediverse.switch');
            Route::post('settings/fediverse/authors/{person}', [FediverseSettingsController::class, 'enableAuthor'])
                ->where('person', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('settings.fediverse.authors.enable');
            Route::delete('settings/fediverse/authors/{person}', [FediverseSettingsController::class, 'disableAuthor'])
                ->where('person', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('settings.fediverse.authors.disable');
            Route::post('settings/fediverse/blocked', [FediverseSettingsController::class, 'block'])->name('settings.fediverse.blocked.store');
            Route::delete('settings/fediverse/blocked/{host}', [FediverseSettingsController::class, 'unblock'])
                ->where('host', '[a-z0-9.-]{1,253}')
                ->name('settings.fediverse.blocked.destroy');
        });

        // Before settings/{group} too: General draws the design group's
        // brand colour, saved by its own Operation (I-457).
        Route::put('settings/general/brand-colour', BrandColourController::class)
            ->middleware('can:settings.design.manage')
            ->name('settings.brand-colour.update');

        // Before settings/{group} too: Reading draws the addresses group's
        // home page, saved by that group's own Operation (I-440).
        Route::put('settings/reading/home-page', HomePageController::class)
            ->middleware('can:settings.addresses.manage')
            ->name('settings.home-page.update');

        // Before settings/{group} too: Settings › Languages and the
        // translations' routes (I-3360).
        require __DIR__.'/admin/languages.php';
        require __DIR__.'/admin/translations.php';
        require __DIR__.'/admin/management-api.php';

        // A group's words in other languages (I-3360); TranslateSettings asks the group's own ability.
        Route::put('settings/{group}/words', [SettingsController::class, 'words'])
            ->where('group', '[a-z0-9_.-]+')
            ->name('settings.words');
        Route::get('settings/{group}', [SettingsController::class, 'show'])
            ->where('group', '[a-z0-9_.-]+')
            ->name('settings.show');
        Route::put('settings/{group}', [SettingsController::class, 'update'])
            ->where('group', '[a-z0-9_.-]+')
            ->name('settings.update');

        Route::middleware('can:users.manage')->prefix('users')->name('users.')->group(function (): void {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::put('{user}/role', [UserController::class, 'updateRole'])->whereNumber('user')->name('role.update');
            Route::post('{user}/suspension', [UserController::class, 'suspend'])->whereNumber('user')->name('suspension.store');
            Route::delete('{user}/suspension', [UserController::class, 'reactivate'])->whereNumber('user')->name('suspension.destroy');
            Route::post('{user}/invitation', [UserController::class, 'resendInvitation'])->whereNumber('user')->name('invitation.store');
            Route::delete('{user}/invitation', [UserController::class, 'revokeInvitation'])->whereNumber('user')->name('invitation.destroy');
            Route::delete('{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('destroy');
            Route::delete('{user}/picture', [UserController::class, 'removePicture'])->whereNumber('user')->name('picture.destroy');
        });

        // Whoever looks after any item's comments (1.8c's D18); each comment
        // is authorized again by its own Operation.
        Route::middleware('can:'.CommentModeration::LOOK_AFTER)->prefix('comments')->name('comments.')->group(function (): void {
            Route::get('/', [CommentScreenController::class, 'index'])->name('index');
            Route::post('moderate', CommentModerationController::class)->name('moderate');
            Route::post('delete', CommentDeletionController::class)->name('delete');
            Route::post('empty', CommentEmptyController::class)->name('empty');
            Route::post('edit', CommentEditController::class)->name('edit');
            Route::post('reply', CommentReplyController::class)->name('reply');
        });

        // The editor. `content` is a reserved segment, so no type's list can
        // take it; permission is the article's own, checked by the query and
        // the Operations.
        Route::get('content/link-targets', LinkTargetsController::class)
            ->middleware('can:content.create')
            ->name('content.link-targets');
        Route::get('content/link-choices', LinkChoicesController::class)
            ->middleware('can:content.create')
            ->name('content.link-choices');
        Route::get('content/linked', LinkedContentController::class)
            ->middleware('can:content.create')
            ->name('content.linked');
        Route::get('content/card-media', CardMediaController::class)
            ->middleware('can:content.create')
            ->name('content.card-media');
        Route::get('content/{content}/edit', [ContentEditorController::class, 'edit'])->name('content.edit');
        // A plugin card's choice field: the options its plugin gives (docs/guides/developers/plugins/cards.md).
        Route::get('content/{content}/card-choices/{card}/{field}', PluginCardChoicesController::class)
            ->middleware('can:regions.manage')
            ->where(['card' => '[a-z0-9][a-z0-9-]*:[a-z][a-z0-9-]*', 'field' => '[a-z][a-z0-9_]*'])
            ->name('content.card-choices');
        // A plugin's panel in the editor: what it shows, its actions, and the person's use of its suggestions.
        Route::get('content/{content}/panels/{code}/{panel}', [EditorPanelController::class, 'show'])->name('content.panels.show');
        Route::post('content/{content}/panels/{code}/{panel}/actions/{action}', [EditorPanelController::class, 'action'])->middleware('throttle:editor-panels')->name('content.panels.actions');
        Route::post('content/{content}/panels/{code}/{panel}/suggestions/{suggestion}', [EditorPanelController::class, 'settle'])->middleware('throttle:editor-panels')->name('content.panels.suggestions');
        Route::get('content/{content}/revisions', [RevisionController::class, 'index'])->name('content.revisions.index');
        Route::get('content/{content}/compare', [RevisionController::class, 'compare'])->name('content.compare');
        Route::post('content/{content}/revisions/{revision}/restore', [RevisionController::class, 'restore'])->name('content.revisions.restore');
        Route::get('content/{content}/preview-links', [PreviewLinkController::class, 'index'])->name('content.preview-links.index');
        Route::post('content/{content}/preview-links', [PreviewLinkController::class, 'store'])->name('content.preview-links.store');
        Route::delete('content/{content}/preview-links/{link}', [PreviewLinkController::class, 'destroy'])->name('content.preview-links.destroy');
        Route::post('content/{content}/autosave', [AutosaveController::class, 'store'])->name('content.autosave');
        Route::post('content/{content}/checkpoint', [ContentEditorController::class, 'checkpoint'])->name('content.checkpoint');
        Route::post('content/{content}/save-as-draft', [ContentEditorController::class, 'saveAsDraft'])->name('content.save-as-draft');
        Route::post('content/{content}/publish', [ContentEditorController::class, 'publish'])->name('content.publish');
        Route::post('content/{content}/address', [ContentEditorController::class, 'address'])->name('content.address');
        Route::post('content/{content}/discussion', [ContentEditorController::class, 'discussion'])->name('content.discussion');
        Route::post('content/{content}/visibility', [ContentEditorController::class, 'visibility'])->name('content.visibility');
        Route::post('content/{content}/author', [ContentEditorController::class, 'author'])->name('content.author');
        Route::get('content/{content}/structured-data', StructuredDataController::class)->name('content.structured-data');
        // The plugins' checks on one revision, beside core's (docs/guides/developers/plugins/seo.md).
        Route::get('content/{content}/plugin-checks', PluginChecksController::class)->middleware('throttle:editor-panels')->name('content.plugin-checks');
        // The person's own look at the latest saved version, through the
        // theme, under the admin's headers and a policy of its own (I-1324).
        Route::get('content/{content}/preview', ContentPreviewController::class)->name('content.preview');
        Route::post('content/{content}/schedule', [ScheduleController::class, 'store'])->name('content.schedule.store');
        Route::delete('content/{content}/schedule', [ScheduleController::class, 'destroy'])->name('content.schedule.destroy');
        Route::post('content/{content}/review', [ReviewController::class, 'store'])->name('content.review.store');
        Route::post('content/{content}/review/approve', [ReviewController::class, 'approve'])->name('content.review.approve');
        // Not 'return': a reserved word in JavaScript, and Wayfinder names
        // functions after it.
        Route::post('content/{content}/review/send-back', [ReviewController::class, 'sendBack'])->name('content.review.send-back');

        Route::get('media/picker', MediaPickerController::class)
            ->middleware('can:content.create')
            ->name('media.picker');

        Route::middleware('can:media.upload')
            ->prefix('media')
            ->name('media.')
            ->where(['media' => '[0-9A-HJKMNP-TV-Z]{26}'])
            ->group(function (): void {
                Route::get('/', [MediaLibraryController::class, 'index'])->name('index');
                Route::get('more', [MediaLibraryController::class, 'more'])->name('more');
                Route::post('/', [MediaLibraryController::class, 'store'])->middleware('throttle:120,1')->name('store');
                Route::get('{media}', [MediaItemController::class, 'show'])->name('show');
                Route::get('{media}/image', MediaImageController::class)->name('edit-image');
                Route::patch('{media}', [MediaItemController::class, 'update'])->name('update');
                Route::post('{media}/file', [MediaItemController::class, 'replace'])->name('replace');
                Route::get('{media}/original', [MediaItemController::class, 'original'])->name('original');
                Route::get('{media}/usages', [MediaItemController::class, 'usages'])->name('usages');
                Route::post('{media}/{action}', [MediaItemController::class, 'action'])->where('action', 'trash|restore|retry')->name('action');
                Route::delete('{media}', [MediaItemController::class, 'destroy'])->name('destroy');
            });

        // Content › Navigation. A menu's ID is lower case (HasUlids, I-418).
        Route::middleware('can:navigation.manage')
            ->prefix('navigation')
            ->name('navigation.')
            ->where(['id' => '[0-9a-hjkmnp-tv-z]{26}'])
            ->group(function (): void {
                Route::get('/', [NavigationController::class, 'index'])->name('index');
                Route::post('/', [NavigationController::class, 'store'])->name('store');
                Route::get('{id}', [NavigationController::class, 'edit'])->name('edit');
                Route::put('{id}', [NavigationController::class, 'update'])->name('update');
                Route::put('{id}/items', [NavigationController::class, 'updateItems'])->name('items.update');
                Route::put('{id}/slots', [NavigationController::class, 'updateSlots'])->name('slots.update');
                Route::delete('{id}', [NavigationController::class, 'destroy'])->name('destroy');
            });

        // Content › Regions (I-395): the regions on one screen; a region's full editor is the content editor.
        Route::middleware('can:regions.manage')
            ->prefix('regions')
            ->name('regions.')
            ->group(function (): void {
                Route::get('/', [RegionsController::class, 'index'])->name('index');
                Route::post('/', [RegionsController::class, 'store'])->name('store');
                Route::post('{content}/discard', [RegionsController::class, 'discard'])->whereUlid('content')->name('discard');
            });

        // Asking AI for a suggestion, collecting it, putting it aside and using it (I-317).
        Route::middleware('can:ai.use')->prefix('ai/requests')->name('ai.requests.')->group(function (): void {
            Route::post('/', [AiRequestController::class, 'store'])->middleware('throttle:ai')->name('store');
            Route::get('{activity}', [AiRequestController::class, 'show'])->whereUlid('activity')->name('show');
            Route::delete('{activity}', [AiRequestController::class, 'destroy'])->whereUlid('activity')->name('destroy');
            Route::post('{activity}/use', [AiRequestController::class, 'apply'])->whereUlid('activity')->name('apply');
        });

        // Tools (I-467): the hub, then each tool behind its own ability.
        Route::get('tools', ToolsController::class)->name('tools.index');

        // Tools › Import (I-227): its names say importer, since import and export are JavaScript's reserved words and Wayfinder names functions after them.
        Route::middleware('can:imports.run')->prefix('tools/import')->name('tools.importer.')->group(function (): void {
            Route::get('/', [ImportScreenController::class, 'index'])->name('index');
            Route::post('/', [ImportSourceController::class, 'connect'])->name('connect');
            // Export-file uploads in pieces: before {source} so "files" is never a site ID.
            Route::post('files/start', [ImportFileController::class, 'start'])->name('files.start');
            Route::get('files/{file}', [ImportFileController::class, 'show'])->where('file', '[0-9a-hjkmnp-tv-z]{26}')->name('files.show');
            Route::post('files/{file}/pieces', [ImportFileController::class, 'piece'])->where('file', '[0-9a-hjkmnp-tv-z]{26}')->name('files.piece');
            Route::post('files/{file}/finish', [ImportFileController::class, 'finish'])->where('file', '[0-9a-hjkmnp-tv-z]{26}')->name('files.finish');
            Route::post('files/{file}/cancel', [ImportFileController::class, 'cancel'])->where('file', '[0-9a-hjkmnp-tv-z]{26}')->name('files.cancel');
            Route::post('files/{file}/delete', [ImportFileController::class, 'destroy'])->where('file', '[0-9a-hjkmnp-tv-z]{26}')->name('files.destroy');
            // A site's and a run's IDs are lower case (HasUlids, I-418).
            Route::get('{source}', [ImportScreenController::class, 'show'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('show');
            Route::post('{source}/analyze', [ImportSourceController::class, 'analyze'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('analyze');
            Route::post('{source}/hosts', [ImportSourceController::class, 'hosts'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('hosts');
            Route::post('{source}/forget', [ImportSourceController::class, 'forget'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('forget');
            Route::post('{source}/start', [ImportRunController::class, 'start'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('start');
            Route::post('{source}/catch-up', [ImportRunController::class, 'catchUp'])->where('source', '[0-9a-hjkmnp-tv-z]{26}')->name('catch-up');
            Route::post('runs/{run}/stop', [ImportRunController::class, 'stop'])->where('run', '[0-9a-hjkmnp-tv-z]{26}')->name('stop');
            Route::post('runs/{run}/carry-on', [ImportRunController::class, 'carryOn'])->where('run', '[0-9a-hjkmnp-tv-z]{26}')->name('carry-on');
            Route::post('runs/{run}/pictures', [ImportRunController::class, 'pictures'])->where('run', '[0-9a-hjkmnp-tv-z]{26}')->name('pictures');
            Route::get('runs/{run}/{file}', [ImportScreenController::class, 'download'])
                ->where(['run' => '[0-9a-hjkmnp-tv-z]{26}', 'file' => 'everything|attention'])
                ->name('download');
        });

        // The editor's Import images, one image per request (I-453).
        Route::post('media/from-address', ImportImageController::class)
            ->middleware(['can:media.upload', 'throttle:60,1'])
            ->name('media.from-address');

        Route::middleware('can:page-cache.clear')->prefix('tools/page-cache')->name('tools.page-cache.')->group(function (): void {
            Route::get('/', [PageCacheController::class, 'show'])->name('show');
            Route::post('clear', [PageCacheController::class, 'clear'])->name('clear');
        });

        Route::middleware('can:redirects.manage')->prefix('tools/redirects')->name('tools.redirects.')->group(function (): void {
            Route::get('/', [RedirectScreenController::class, 'index'])->name('index');
            Route::get('404', [RedirectScreenController::class, 'notFound'])->name('not-found');
            Route::post('/', [RedirectController::class, 'store'])->name('store');
            Route::put('{redirect}', [RedirectController::class, 'update'])
                ->where('redirect', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('update');
            // Not 'switch' or 'delete': reserved words in JavaScript, and Wayfinder names functions after them.
            Route::post('switch', [RedirectController::class, 'toggle'])->name('toggle');
            Route::post('delete', [RedirectController::class, 'destroy'])->name('destroy');
            Route::post('gone', [RedirectController::class, 'gone'])->name('gone');
            // Not 'import' or 'export' either.
            Route::post('import', ImportRedirectsController::class)->name('upload');
            Route::get('export/{format}', ExportRedirectsController::class)
                ->where('format', 'csv|nginx')
                ->name('download');
        });

        // Tools › Jobs (I-104): everyone's background work, for those who look after it.
        Route::middleware('can:jobs.manage')->prefix('tools/jobs')->name('tools.jobs.')->group(function (): void {
            Route::get('/', [JobsController::class, 'index'])->name('index');
            Route::post('retry-failed', [JobsController::class, 'retryFailed'])->name('retry-failed');
            // A job's ID is lower case (HasUlids, I-418).
            Route::post('{job}/retry', [JobsController::class, 'retry'])->where('job', '[0-9a-hjkmnp-tv-z]{26}')->name('retry');
            Route::post('{job}/cancel', [JobsController::class, 'cancel'])->where('job', '[0-9a-hjkmnp-tv-z]{26}')->name('cancel');
        });

        // The inbox (I-221): each person's own background results.
        Route::prefix('inbox')->name('inbox.')->group(function (): void {
            Route::get('/', [InboxController::class, 'index'])->name('index');
            Route::get('latest', [InboxController::class, 'latest'])->name('latest');
            Route::post('read', [InboxController::class, 'readAll'])->name('read-all');
            // An item's ID is lower case (HasUlids, I-418).
            Route::post('{item}/read', [InboxController::class, 'read'])
                ->where('item', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('read');
            Route::post('{item}/open', [InboxController::class, 'open'])
                ->where('item', '[0-9a-hjkmnp-tv-z]{26}')
                ->name('open');
        });

        // The header's jobs button (I-104): a person's own jobs; RetryJob and
        // CancelJob refuse anyone else's.
        Route::prefix('my-jobs')->name('my-jobs.')->group(function (): void {
            Route::get('/', [MyJobsController::class, 'index'])->name('index');
            // A job's ID is lower case (HasUlids, I-418).
            Route::post('{job}/retry', [MyJobsController::class, 'retry'])->where('job', '[0-9a-hjkmnp-tv-z]{26}')->name('retry');
            Route::post('{job}/cancel', [MyJobsController::class, 'cancel'])->where('job', '[0-9a-hjkmnp-tv-z]{26}')->name('cancel');
        });
        Route::get('tools/ai-activity', AiActivityController::class)
            ->middleware('can:ai-activity.view')
            ->name('tools.ai-activity.index');

        // Tools › Updates (I-3329): the release running, the ones after it, and the update from the admin (I-3326).
        Route::middleware('can:updates.manage')->prefix('tools/updates')->name('tools.updates.')->group(function (): void {
            Route::get('/', UpdatesController::class)->name('index');
            Route::post('requests', RequestUpdateController::class)->middleware('throttle:6,1')->name('requests.store');
            Route::post('requests/{update}/cancel', CancelUpdateController::class)->where('update', '[0-9a-hjkmnp-tv-z]{26}')->name('requests.cancel');
        });

        // Tools › Backups (I-364): administrators only; every change asks for the password again.
        Route::middleware('can:backups.manage')
            ->prefix('tools/backups')
            ->name('tools.backups.')
            ->where(['place' => '[0-9a-hjkmnp-tv-z]{26}'])
            ->group(function (): void {
                Route::get('/', [BackupsScreenController::class, 'index'])->name('index');
                Route::post('now', BackUpNowController::class)->name('now');
                Route::put('schedule', BackupScheduleController::class)->name('schedule.update');
                Route::post('places/{place}/test', [BackupPlacesController::class, 'test'])->name('places.test');
                Route::delete('places/{place}', [BackupPlacesController::class, 'destroy'])->name('places.destroy');
                Route::get('set-up', [BackupSetupController::class, 'create'])->middleware(RequireFreshPassword::class)->name('setup');
                Route::post('set-up', [BackupSetupController::class, 'store'])->name('setup.store');
                Route::post('key/confirm', [RecoveryKeyController::class, 'confirm'])->middleware('throttle:10,1')->name('key.confirm');
                Route::get('sftp', [SftpStepController::class, 'show'])->middleware(RequireFreshPassword::class)->name('sftp');
                Route::post('sftp', [SftpStepController::class, 'store'])->name('sftp.store');
                Route::delete('sftp', [SftpStepController::class, 'destroy'])->name('sftp.destroy');

                Route::get('places/new', [BackupPlacesController::class, 'create'])->middleware(RequireFreshPassword::class)->name('places.create');
                Route::post('places', [BackupPlacesController::class, 'store'])->name('places.store');
                Route::get('places/{place}/edit', [BackupPlacesController::class, 'edit'])->middleware(RequireFreshPassword::class)->name('places.edit');
                Route::put('places/{place}', [BackupPlacesController::class, 'update'])->name('places.update');
                Route::post('places/{place}/storage', [BackupPlacesController::class, 'storage'])->middleware('throttle:6,1')->name('places.storage');

                Route::get('keys', [RecoveryKeyController::class, 'index'])->name('keys.index');
                Route::get('keys/new', [RecoveryKeyController::class, 'create'])->middleware(RequireFreshPassword::class)->name('keys.create');
                Route::post('keys', [RecoveryKeyController::class, 'store'])->name('keys.store');
                Route::post('keys/extend', [RecoveryKeyController::class, 'extend'])->name('keys.extend');
                Route::post('restored', RestoredSiteController::class)->name('restored');
                Route::post('keys/check', [RecoveryKeyController::class, 'check'])->middleware('throttle:10,1')->name('keys.check');
            });

        // Last but two: the screens at /admin/{segment}, a taxonomy's terms
        // or a type's items. Their segments share one pattern, so these three
        // find which on each request, and one made after the routes were
        // registered, or cached, answers at once (I-3296). The named routes
        // below keep giving every address; these answer it.
        Route::get('terms/search', TermSearchController::class)
            ->middleware('can:content.create')
            ->name('terms.search');

        Route::where(['segment' => ContentTypeRegistry::segmentPattern()])->group(function (): void {
            Route::get('{segment}', [AdminScreenController::class, 'index'])->name('screens.index');
            Route::post('{segment}', [AdminScreenController::class, 'store'])->name('screens.store');
            Route::delete('{segment}/{id}', [AdminScreenController::class, 'destroy'])->name('screens.destroy');
        });

        Route::middleware('can:terms.manage')
            ->where(['taxonomy' => ContentTypeRegistry::segmentPattern()])
            ->group(function (): void {
                Route::get('{taxonomy}', [TermController::class, 'index'])->name('terms.index');
                Route::post('{taxonomy}', [TermController::class, 'store'])->name('terms.store');
                Route::put('{taxonomy}/{term}', [TermController::class, 'update'])->name('terms.update');
                Route::delete('{taxonomy}/{term}', [TermController::class, 'destroy'])->name('terms.destroy');
            });

        // Every listed type's screens, the stored ones too: the type is found
        // from its segment on each request, so one made after these routes
        // were registered, or cached, has them at once (I-3296). Last, and
        // never one of the admin's own segments, so /admin/media stays Media.
        // Each list asks its type's own create permission.
        Route::middleware('can:'.TypeAccess::OPEN_LIST.',type')
            ->where(['type' => ContentTypeRegistry::segmentPattern()])
            ->group(function (): void {
                Route::get('{type}', [ContentListController::class, 'index'])->name('content.index');
                Route::get('{type}/tree', ContentTreeController::class)->name('content.tree');
                Route::post('{type}', [ContentListController::class, 'store'])->name('content.store');
                Route::post('{type}/bulk', BulkContentController::class)->name('content.bulk');
                Route::post('{type}/bulk/matching', BulkMatchingController::class)->name('content.bulk-matching');
                Route::patch('{type}/{content}/details', [ContentActionController::class, 'details'])->name('content.details');
                Route::post('{type}/{content}/{action}', [ContentActionController::class, 'action'])
                    ->where('action', 'trash|restore|publish|unpublish')
                    ->name('content.action');
                Route::delete('{type}/{content}', [ContentActionController::class, 'destroy'])->name('content.destroy');
            });
    });
});

// Any other admin address, last of all routes: a 404, drawn inside the admin
// for someone signed in and on the card for anyone else.
Route::prefix('admin')->middleware('admin')->group(function (): void {
    Route::get('{missing}', [AdminErrorPage::class, 'notFound'])
        ->where('missing', '.*')
        ->name('admin.not-found')
        ->fallback();
});
