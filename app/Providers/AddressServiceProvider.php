<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\AddressSettingsGroup;
use App\Domain\Languages\DefaultLanguageCheck;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\Checks\ApiAddressTaken;
use App\Domain\Status\Checks\LanguageHomeShadowsRedirect;
use App\Domain\Status\StatusChecks;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Wires public addresses: the `addresses` settings group, built from the
 * types and taxonomies when read, and again when they change, and the
 * banner for anything that already had an address under /api.
 */
final class AddressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Once per request or job; ChangeAddresses forgets it after commit.
        // The default language keeps while others are added (I-3360, Q1 A).
        $this->app->tag([DefaultLanguageCheck::class], SettingsCheck::TAG);

        $this->app->scoped(AddressSettings::class, fn (): AddressSettings => AddressSettings::read(
            $this->app->make(SettingsReader::class),
            $this->app->make(ContentTypeRegistry::class),
            $this->app->make(TaxonomyRegistry::class),
        ));
    }

    public function boot(): void
    {
        // Built when read, and again when the content types change (I-3296).
        $this->app->make(SettingsRegistry::class)->derive(
            'addresses',
            fn (): SettingsGroup => AddressSettingsGroup::build(app(ContentTypeRegistry::class), app(TaxonomyRegistry::class)),
            fn (): string => app(ContentTypeRegistry::class)->stamp().app(TaxonomyRegistry::class)->stamp(),
        );

        // What already had an address under /api, which the API now answers (I-091).
        $this->app->make(StatusChecks::class)->register(ApiAddressTaken::class);

        // A redirect a language's home now answers first (I-3360).
        $this->app->make(StatusChecks::class)->register(LanguageHomeShadowsRedirect::class);
    }
}
