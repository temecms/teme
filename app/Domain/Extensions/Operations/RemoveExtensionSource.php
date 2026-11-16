<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\RemoveExtensionSourceInput;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Forgets a saved package source and its key (I-433): a person who holds extensions.install, after
 * their password, or the operator. Refused while a package in the current plugins and themes came from
 * it, or an open request uses it, since the installer needs it to build them again.
 */
final class RemoveExtensionSource extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    public function __construct(private readonly ExtensionsScreen $extensions) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var RemoveExtensionSourceInput $input */
        $source = DB::table('extension_sources')->where('id', $input->sourceId)->lockForUpdate()->first(['id', 'url']);

        if ($source === null) {
            throw ValidationException::withMessages(['source' => [__('There’s no such source.')]]);
        }

        $open = DB::table('extension_requests')->where('source_id', $source->id)->whereIn('status', RequestExtensionChange::OPEN)->exists();

        if ($open) {
            throw ValidationException::withMessages(['source' => [__('A request under way uses this source. Wait for it to end, or cancel it.')]]);
        }

        if ($this->extensions->releaseUsesSource((string) $source->id, $source->url === null ? null : (string) $source->url)) {
            throw ValidationException::withMessages(['source' => [__('Packages installed now came from this source. Remove them first, or keep the source.')]]);
        }

        DB::table('extension_sources')->where('id', $source->id)->delete();

        return null;
    }
}
