<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceProbe;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps a WordPress site to import from (I-1708), once the companion plugin
 * has answered with the user name and application password: its home
 * address and API root, the plugin's version, and the password, encrypted.
 * A site Teme already knows is updated, taking its password back if it was
 * forgotten. Nothing is imported here, so the password isn't asked again.
 */
final class ConnectImportSource extends BaseOperation
{
    use AuthorizesImports;

    /** What validate()'s probe found, for perform() to keep. */
    private ?SourceProbe $probe = null;

    public function __construct(private readonly CompanionClients $clients) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ConnectImportSourceInput $input */
        Validator::make(
            ['address' => $input->address, 'user_name' => $input->userName, 'application_password' => $input->applicationPassword],
            ['address' => ['required', 'string', 'max:2000'], 'user_name' => ['required', 'string', 'max:60'], 'application_password' => ['required', 'string', 'max:200']],
            [
                'address.required' => 'Enter the WordPress site’s address.',
                'address.max' => 'That address is too long.',
                'user_name.required' => 'Enter a WordPress administrator’s user name.',
                'user_name.max' => 'That user name is too long for WordPress.',
                'application_password.required' => 'Enter the application password you made in WordPress for Teme.',
                'application_password.max' => 'That application password is too long.',
            ],
        )->validate();

        try {
            $this->probe = $this->clients->probe(self::withScheme($input->address), $input->userName, $input->applicationPassword);
        } catch (AddressRefused $refusal) {
            throw ValidationException::withMessages(['address' => $refusal->sentence()]);
        } catch (SourceRefused $refusal) {
            throw ValidationException::withMessages([$refusal->field => $refusal->sentence()]);
        }
    }

    /**
     * @return array{sourceId: string, created: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ConnectImportSourceInput $input */
        $probe = $this->probe ?? throw new LogicException('ConnectImportSource probes the site in validate().');
        $source = ImportSource::query()->where('address', $probe->home)->lockForUpdate()->first();
        $created = $source === null;
        $source ??= new ImportSource(['address' => $probe->home, 'created_by' => $input->actorId() === null ? null : (int) $input->actorId()]);

        $source->fill([
            'api_root' => $probe->apiRoot,
            'user_name' => $input->userName,
            'credential' => $input->applicationPassword,
            'plugin_version' => $probe->pluginVersion,
            'api_version' => $probe->api,
            'probed_at' => now(),
            'forgotten_at' => null,
        ])->save();

        app(SecurityLog::class)->record(SecurityEventType::ImportSourceConnected, $input->actorId(), context: [
            'source' => (string) $source->id,
            'address' => $probe->home,
            'plugin_version' => $probe->pluginVersion,
        ]);

        return ['sourceId' => (string) $source->id, 'created' => $created];
    }

    /**
     * An address typed without its scheme is read as https://.
     */
    private static function withScheme(string $address): string
    {
        $address = trim($address);

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) === 1 ? $address : 'https://'.$address;
    }
}
