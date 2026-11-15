<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\SetImportSourceHostsInput;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Sets the other old addresses of an import source: staging hosts and
 * former domains that convert like the home host. A person confirms their
 * password first; the operator doesn't. Refused while a run is running.
 */
final class SetImportSourceHosts extends BaseOperation implements RequiresElevation
{
    use AuthorizesImports;

    public function requiresElevation(OperationInput $input): bool
    {
        return $input->actorId() !== null;
    }

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetImportSourceHostsInput $input */
        Validator::make(
            ['source' => $input->sourceId],
            ['source' => ['required', 'string', Rule::exists('import_sources', 'id')]],
        )->validate();

        if (count($input->hosts) > 10) {
            throw ValidationException::withMessages(['hosts' => 'Ten other addresses at most.']);
        }
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var SetImportSourceHostsInput $input */
        $source = ImportSource::query()->whereKey($input->sourceId)->lockForUpdate()->firstOrFail();

        if ($source->runs()->where('state', ImportRunState::Running->value)->exists()) {
            throw ValidationException::withMessages(['source' => 'Stop the import that’s running first: it needs the application password.']);
        }

        $home = OldSiteUrls::bare((string) parse_url($source->address, PHP_URL_HOST));
        $bare = [];

        foreach ($input->hosts as $raw) {
            $host = self::normalize($raw);

            if ($host === null) {
                throw ValidationException::withMessages(['hosts' => 'Enter a host name only, such as stage.example.com.']);
            }

            if ($host === $home) {
                throw ValidationException::withMessages(['hosts' => 'That is this site’s own address already.']);
            }

            // B-05: drop duplicates after normalizing rather than refusing them.
            if (in_array($host, $bare, true)) {
                continue;
            }

            $bare[] = $host;
        }

        $source->forceFill(['other_hosts' => $bare])->save();

        return null;
    }

    /**
     * A host name trimmed, lower-cased and without `www.`, or null when it
     * isn't a bare host (scheme, path, port, user, or no dot).
     */
    private static function normalize(string $raw): ?string
    {
        $host = trim($raw);

        if ($host === '' || str_contains($host, '/') || str_contains($host, ':') || str_contains($host, '@') || str_contains($host, ' ')) {
            return null;
        }

        $bare = OldSiteUrls::bare($host);

        if (! str_contains($bare, '.') || filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $bare;
    }
}
