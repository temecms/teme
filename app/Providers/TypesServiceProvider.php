<?php

/**
 * Builds the content-type and taxonomy registries with the core types, and
 * checks once everything is registered that each type's declaration holds
 * together. A misconfigured type is a deploy error, so the check throws in
 * every environment.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fields\FieldRegistry;
use App\Domain\Status\Checks\StoredTaxonomiesRefused;
use App\Domain\Status\Checks\StoredTypesRefused;
use App\Domain\Status\StatusChecks;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\CoreTypes;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeAccess;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final class TypesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        self::bindCore($this->app);
    }

    public function boot(): void
    {
        $this->verify();

        // A worker's next job, and the next request a process serves, look at
        // the stored types and the permissions again: another process may
        // have changed them (I-3296).
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(ContentTypeRegistry::class)->refreshStored();
            $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();
        });
        Event::listen(RequestHandled::class, function (): void {
            $this->app->make(ContentTypeRegistry::class)->refreshStored();
            $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();
        });

        // Spatie's before-hook reads a text argument as a guard's name and
        // finds no permission by this gate's, so the gate decides (I-413).
        Gate::define(TypeAccess::OPEN_LIST, function (User $user, string $segment): bool {
            $type = $this->app->make(ContentTypeRegistry::class)->bySegment($segment);

            return $user->can($type?->ability('create') ?? ContentType::ACTIONS['create']);
        });

        $this->app->make(StatusChecks::class)->register(StoredTypesRefused::class);
        $this->app->make(StatusChecks::class)->register(StoredTaxonomiesRefused::class);
    }

    /**
     * Binds a fresh pair of registries holding only the core types. Consumers
     * resolve the registries when they need them, so rebinding reaches them.
     */
    public static function bindCore(Application $app): void
    {
        $types = new ContentTypeRegistry;
        $taxonomies = new TaxonomyRegistry;

        // Bound before the core types register: each registry checks the
        // other's segments through the container.
        $app->instance(ContentTypeRegistry::class, $types);
        $app->instance(TaxonomyRegistry::class, $taxonomies);

        CoreTypes::register($types, $taxonomies);
    }

    /**
     * Throws if a type code declared names a taxonomy nobody registered,
     * supports a feature no field names, or ends up with two fields sharing a
     * handle. Code's types only: boot reads no database
     * (docs/architecture/themes.md), and a stored type that doesn't hold
     * together is left out when read, not thrown.
     */
    public function verify(): void
    {
        $types = $this->app->make(ContentTypeRegistry::class);

        foreach ($types->fromCode() as $type) {
            $problem = $types->unmet($type);

            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }
        }

        $this->app->make(FieldRegistry::class)->verify($types);
    }
}
