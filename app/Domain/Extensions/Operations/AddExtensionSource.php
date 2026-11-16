<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\AddExtensionSourceInput;
use App\Domain\Extensions\Sources\PackagistSource;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Saves a place packages come from, a Git address or a vendor's Composer source, with its key encrypted
 * under the app key (I-433): a person who holds extensions.install, after their password, or the
 * operator. The key is never shown again. Returns the source's ID.
 */
final class AddExtensionSource extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    public function __construct(private readonly SourceKinds $kinds) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AddExtensionSourceInput $input */
        $refusal = $this->refusal($input);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['source' => [$refusal]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var AddExtensionSourceInput $input */
        if (DB::table('extension_sources')->where('kind', $input->kind)->where('url', $input->url)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['source' => [__('This source is already saved.')]]);
        }

        $username = trim((string) $input->username);
        $credential = (string) $input->credential;

        $source = new ExtensionSourceRecord;
        $source->forceFill([
            'id' => strtolower((string) Str::ulid()),
            'kind' => $input->kind,
            'label' => trim($input->label),
            'url' => $input->url,
            'username' => $username === '' ? null : $username,
            // Encrypted by the model's cast (EncryptedValues lists the column).
            'credential' => $credential === '' ? null : $credential,
            'created_by' => $input->actorId() === null ? null : (int) $input->actorId(),
        ])->save();

        return $source->id;
    }

    /**
     * Why this source can't be saved, in words people read, or null when it can.
     */
    private function refusal(AddExtensionSourceInput $input): ?string
    {
        // Packagist needs no saved source, and an upload is its own.
        if ($input->kind === PackagistSource::KEY || $input->kind === RequestExtensionChange::UPLOAD || $this->kinds->find($input->kind) === null) {
            return __('“:kind” isn’t a kind of package source that is saved.', ['kind' => $input->kind]);
        }

        $label = trim($input->label);

        if ($label === '' || mb_strlen($label) > 100) {
            return __('Give the source a name of up to 100 characters.');
        }

        $parts = parse_url($input->url);

        if (strlen($input->url) > 2048 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return __('The address must start with https:// and hold no username or password.');
        }

        if (mb_strlen((string) $input->username) > 200 || strlen((string) $input->credential) > 4096) {
            return __('The username or the key is too long.');
        }

        return null;
    }
}
