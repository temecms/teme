<?php

/**
 * Plugins and themes installed from the admin (I-211): asks the installer for a change, cancels or
 * agrees to one, keeps the sources and uploads it reads, searches Packagist and Teme's directory, and answers the request
 * sheet's polls. Every change goes through its Operation, which asks for the password; this records a
 * request and never writes code.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Extensions\Directory\DirectorySettings;
use App\Domain\Extensions\Directory\RefreshDirectory;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\AddExtensionSource;
use App\Domain\Extensions\Operations\AgreeToExtensionRequest;
use App\Domain\Extensions\Operations\CancelExtensionRequest;
use App\Domain\Extensions\Operations\Input\AddExtensionSourceInput;
use App\Domain\Extensions\Operations\Input\AgreeToExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\CancelExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\RemoveExtensionSourceInput;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Operations\Input\UploadExtensionInput;
use App\Domain\Extensions\Operations\RemoveExtensionSource;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Extensions\Operations\UploadExtension;
use App\Domain\Extensions\Queries\DirectoryPackages;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Extensions\Queries\PackagistSearch;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ExtensionsController extends Controller
{
    /**
     * Records a request; the sheet opens on it from the flashed ID.
     */
    public function store(Request $request, RequestExtensionChange $change): RedirectResponse
    {
        $body = $request->validate([
            'action' => ['required', Rule::in([ExtensionAction::Install->value, ExtensionAction::Update->value, ExtensionAction::Remove->value, ExtensionAction::Rollback->value])],
            'kind' => ['required', Rule::enum(ExtensionKind::class)],
            'package' => ['nullable', 'string', 'max:214'],
            'constraint' => ['nullable', 'string', 'max:100'],
            'sourceKind' => ['nullable', 'string', 'max:32'],
            'sourceId' => ['nullable', 'string', 'size:26'],
            'uploadId' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->attempt(function () use ($change, $body, $request): string {
            $id = $change->execute(new RequestExtensionChangeInput(
                action: ExtensionAction::from($body['action']),
                kind: ExtensionKind::from($body['kind']),
                package: $body['package'] ?? null,
                constraint: $body['constraint'] ?? null,
                sourceKind: $body['sourceKind'] ?? null,
                sourceId: $body['sourceId'] ?? null,
                uploadId: $body['uploadId'] ?? null,
                actorId: $this->actor($request),
            ));
            Inertia::flash('extensionRequest', $id);

            return __('Request recorded. The installer carries it out; nothing is enabled until you enable it.');
        });
    }

    public function cancel(Request $request, string $id, CancelExtensionRequest $cancel): RedirectResponse
    {
        return $this->attempt(function () use ($cancel, $id, $request): string {
            $cancel->execute(new CancelExtensionRequestInput($id, $this->actor($request)));

            return __('Request cancelled.');
        });
    }

    /**
     * Agrees to exactly the list the sheet showed, which it posts back.
     */
    public function agree(Request $request, string $id, AgreeToExtensionRequest $agree): RedirectResponse
    {
        $body = $request->validate(['needs' => ['required', 'array']]);

        return $this->attempt(function () use ($agree, $id, $body, $request): string {
            /** @var array<string, mixed> $needs */
            $needs = $body['needs'];
            $agree->execute(new AgreeToExtensionRequestInput($id, $needs, $this->actor($request)));

            return __('Agreed. The installer goes on.');
        });
    }

    /**
     * One request, for the request sheet's poll.
     */
    public function show(string $id, ExtensionsScreen $screen): JsonResponse
    {
        $found = $screen->find($id);

        abort_if($found === null, 404);

        return response()->json(['request' => $found, 'installerPresent' => $screen->installerPresent()]);
    }

    /**
     * Packagist's packages for the words given, or one package's versions.
     */
    public function search(Request $request, PackagistSearch $packagist): JsonResponse
    {
        $body = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(ExtensionKind::class)],
            'package' => ['nullable', 'string', 'max:214'],
        ]);

        if (isset($body['package'])) {
            return response()->json($packagist->versions($body['package']));
        }

        return response()->json($packagist->search((string) ($body['q'] ?? ''), ExtensionKind::from($body['kind'] ?? ExtensionKind::Plugin->value)));
    }

    /**
     * The directory's plugins or themes for the words given, asking the directory again first when its
     * list was last read more than an hour ago.
     */
    public function directory(Request $request, DirectoryPackages $directory, RefreshDirectory $refresh): JsonResponse
    {
        $body = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(ExtensionKind::class)],
        ]);

        if (! DirectorySettings::enabled()) {
            return response()->json(['enabled' => false, 'results' => [], 'error' => null]);
        }

        $refresh->ifStale($this->actor($request));

        return response()->json(['enabled' => true, ...$directory->search((string) ($body['q'] ?? ''), ExtensionKind::from($body['kind'] ?? ExtensionKind::Plugin->value))]);
    }

    /**
     * One listed package's detail: its README, screenshots, the permissions it asks for, and its releases.
     */
    public function directoryPackage(Request $request, DirectoryPackages $directory): JsonResponse
    {
        $body = $request->validate([
            'package' => ['required', 'string', 'max:214'],
            'kind' => ['nullable', Rule::enum(ExtensionKind::class)],
        ]);

        abort_unless(DirectorySettings::enabled(), 404);

        return response()->json($directory->detail($body['package'], ExtensionKind::from($body['kind'] ?? ExtensionKind::Plugin->value)));
    }

    public function storeSource(Request $request, AddExtensionSource $add): RedirectResponse
    {
        $body = $request->validate([
            'kind' => ['required', 'string', 'max:32'],
            'label' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048'],
            'username' => ['nullable', 'string', 'max:200'],
            'credential' => ['nullable', 'string', 'max:4096'],
        ]);

        return $this->attempt(function () use ($add, $body, $request): string {
            $add->execute(new AddExtensionSourceInput(
                kind: $body['kind'],
                label: $body['label'],
                url: $body['url'],
                username: $body['username'] ?? null,
                credential: $body['credential'] ?? null,
                actorId: $this->actor($request),
            ));

            return __('Saved. Teme never shows a key again.');
        });
    }

    public function destroySource(Request $request, string $id, RemoveExtensionSource $remove): RedirectResponse
    {
        return $this->attempt(function () use ($remove, $id, $request): string {
            $remove->execute(new RemoveExtensionSourceInput($id, $this->actor($request)));

            return __('Source removed, with its key.');
        });
    }

    /**
     * Keeps an uploaded archive as data; the sheet reads its package and version from the flash.
     */
    public function upload(Request $request, UploadExtension $upload): RedirectResponse
    {
        $request->validate(['archive' => ['required', 'file']]);
        $file = $request->file('archive');
        abort_unless($file instanceof UploadedFile, 422);

        return $this->attempt(function () use ($upload, $file, $request): string {
            /** @var array{id: string, package: string, version: string} $kept */
            $kept = $upload->execute(new UploadExtensionInput($file->getClientOriginalName(), (string) $file->get(), $this->actor($request)));
            Inertia::flash('extensionUpload', $kept);

            return __(':name :version uploaded. Ask to install it.', ['name' => $kept['package'], 'version' => $kept['version']]);
        });
    }

    /**
     * Runs one change and goes back with its sentence, or with the refusal as an error.
     *
     * @param  Closure(): string  $change
     */
    private function attempt(Closure $change): RedirectResponse
    {
        try {
            Inertia::flash('toast', ['type' => 'success', 'message' => $change()]);
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);
        }

        return back();
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
