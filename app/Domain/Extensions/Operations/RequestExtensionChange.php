<?php

/**
 * Asks the installer to install, update or remove a plugin or theme, or to roll back to the plugins and
 * themes kept before (I-211): a person who holds extensions.install, after their password, or the
 * operator. It records the request and changes no code; the installer carries it out. One at a time, and
 * never while Teme is being updated. Returns the request's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Design\Queries\DesignScreen;
use App\Domain\Extensions\Directory\DirectoryRefused;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Models\ExtensionUploadRecord;
use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Queries\DirectoryPackages;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\Sources\DirectorySource;
use App\Domain\Extensions\Sources\PackagistSource;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Types\TypeOwners;
use App\Domain\Updates\Queries\TemeUpdates;
use Carbon\CarbonImmutable;
use Composer\Semver\VersionParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use UnexpectedValueException;

final class RequestExtensionChange extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    /** The advisory lock that puts one request in line at a time. */
    public const string LOCK = 'teme.extensions.request';

    /** The statuses of a request still open. */
    public const array OPEN = ['requested', 'running', 'waiting'];

    /** The source kind of an uploaded archive (part C registers it). */
    public const string UPLOAD = 'upload';

    /** Composer's own pattern for a package's name. */
    public const string PACKAGE_NAME = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}';

    public function __construct(
        private readonly ExtensionsScreen $extensions,
        private readonly TemeUpdates $updates,
        private readonly SourceKinds $kinds,
        private readonly DirectoryPackages $directory,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RequestExtensionChangeInput $input */
        $refusal = $this->refusal($input);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['request' => [$refusal]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RequestExtensionChangeInput $input */
        // One request at a time, from every server, until this transaction ends; then the rules again, under it.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
        $this->validate($input);

        $id = strtolower((string) Str::ulid());
        DB::table('extension_requests')->insert([
            'id' => $id,
            'action' => $input->action->value,
            'kind' => $input->kind->value,
            'package' => $input->action->namesPackage() ? $input->package : null,
            'constraint' => $this->constraint($input),
            'source_kind' => $this->sourceKind($input),
            'source_id' => $this->changesCode($input) && $this->sourceKind($input) !== PackagistSource::KEY ? $this->sourceId($input) : null,
            'upload_id' => $this->changesCode($input) ? $input->uploadId : null,
            'status' => RequestStatus::Requested->value,
            'requested_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'requested_at' => CarbonImmutable::now(),
        ]);

        return $id;
    }

    /**
     * Why this request can't be recorded now, in words people read, or null when it can.
     */
    private function refusal(RequestExtensionChangeInput $input): ?string
    {
        if ($input->action === ExtensionAction::Rebuild) {
            return __('Teme rebuilds the installed plugins and themes itself after an update; it can’t be asked for.');
        }

        $package = $input->package ?? '';

        if ($input->action->namesPackage() && (strlen($package) > 214 || preg_match(self::PACKAGE_NAME, $package) !== 1)) {
            return __('“:name” isn’t a Composer package name, such as acme/link-suggestions.', ['name' => $package]);
        }

        if (DB::table('extension_requests')->whereIn('status', self::OPEN)->exists()) {
            return __('Another plugin or theme request is under way. Wait for it to end, or cancel it.');
        }

        if ($this->updates->open() !== null) {
            return __('Teme is being updated. Ask again once the update has ended.');
        }

        if ($this->extensions->root() === null && $input->action === ExtensionAction::Rollback) {
            return __('This server has no extensions folder, so there is nothing to roll back.');
        }

        return match ($input->action) {
            ExtensionAction::Install => $this->installRefusal($input, $package),
            ExtensionAction::Update => $this->changeRefusal($input, $package) ?? $this->sourceRefusal($input, $package),
            ExtensionAction::Remove => $this->changeRefusal($input, $package) ?? $this->removeRefusal($input, $package),
            default => $this->extensions->earlierReleases() === [] ? __('No earlier plugins and themes are kept to roll back to.') : null,
        };
    }

    private function installRefusal(RequestExtensionChangeInput $input, string $package): ?string
    {
        if ($this->extensions->imageHas($package)) {
            return __(':name is part of Teme’s build, so it can’t be installed again.', ['name' => $package]);
        }

        if (array_key_exists($package, $this->extensions->folderPackages())) {
            return __(':name is already installed. Update it instead.', ['name' => $package]);
        }

        return $this->sourceRefusal($input, $package);
    }

    /**
     * Why an update or removal of $package can't be asked for: it must be one asked for from the admin,
     * of the kind the request names.
     */
    private function changeRefusal(RequestExtensionChangeInput $input, string $package): ?string
    {
        $installed = $this->extensions->folderPackages()[$package] ?? null;

        if ($installed === null || ! $installed['requested']) {
            return $this->extensions->imageHas($package)
                ? __('It is part of Teme’s build; change it where the site is built.')
                : __(':name isn’t installed from the admin.', ['name' => $package]);
        }

        if ($installed['type'] !== $input->kind->packageType()) {
            return $input->kind === ExtensionKind::Plugin
                ? __(':name is a theme, not a plugin.', ['name' => $package])
                : __(':name is a plugin, not a theme.', ['name' => $package]);
        }

        return null;
    }

    /**
     * Why $package can't be removed now: an enabled plugin is disabled first, a plugin whose types hold
     * items keeps them until they are deleted, and the theme the site is
     * drawn with, or the one the design's draft switches to, is switched away from first.
     */
    private function removeRefusal(RequestExtensionChangeInput $input, string $package): ?string
    {
        if ($input->kind === ExtensionKind::Plugin) {
            $enabled = PluginRecord::query()->where('name', $package)->where('state', PluginRecord::ENABLED)->exists();

            if ($enabled) {
                return __('Disable :name first; its data stays until you remove it.', ['name' => $package]);
            }

            // Its types' items would have no type left to be read by (docs/architecture/plugins.md, *Content types from a plugin*).
            $items = TypeOwners::items($package);

            if ($items !== []) {
                $held = Arr::join(array_map(fn (array $type): string => trans_choice(':plural have :n item at /admin/:segment|:plural have :n items at /admin/:segment', $type['items'], ['plural' => $type['plural'], 'n' => (string) $type['items'], 'segment' => $type['segment']]), array_values($items)), ', ', ' '.__('and').' ');

                return __(':name can’t be removed while its content types hold items: :held, trashed ones included. Enable it, delete them and empty the trash, then disable it again.', ['name' => $package, 'held' => $held]);
            }

            return null;
        }

        $code = $this->extensions->folderPackages()[$package]['code'] ?? null;

        if ($code !== null && $code === app(ActiveTheme::class)->chosen()) {
            return __('The site is drawn with this theme. Activate another theme first.');
        }

        if ($code !== null && $code === app(DesignScreen::class)->draftedTheme()) {
            return __('The design’s draft switches to this theme. Publish or discard the draft first.');
        }

        return null;
    }

    /**
     * Why the source the request names can't be used for $package.
     */
    private function sourceRefusal(RequestExtensionChangeInput $input, string $package): ?string
    {
        $kind = $this->sourceKind($input);

        if ($kind === null || $this->kinds->find($kind) === null) {
            return __('“:kind” isn’t a kind of package source Teme knows.', ['kind' => (string) $kind]);
        }

        $constraint = $this->constraint($input) ?? '';

        try {
            (new VersionParser)->parseConstraints($constraint);
        } catch (UnexpectedValueException) {
            return __('“:version” isn’t a version Composer understands, such as ^1.2 or 1.4.0.', ['version' => $constraint]);
        }

        if ($kind === PackagistSource::KEY) {
            return config('teme.extensions.packagist') ? null : __('Packagist is turned off on this server. Use another source.');
        }

        if ($kind === self::UPLOAD) {
            if ($input->action === ExtensionAction::Update && $input->uploadId === null) {
                return __(':name was uploaded by hand, so it can’t be updated in place. Upload the new version.', ['name' => $package]);
            }

            $upload = $input->uploadId === null ? null : ExtensionUploadRecord::query()->find($input->uploadId);

            return match (true) {
                $upload === null => __('That upload is gone. Upload the archive again.'),
                $upload->package !== $package => __('The uploaded archive holds :other, not :name.', ['other' => $upload->package, 'name' => $package]),
                default => null,
            };
        }

        if ($kind === DirectorySource::KEY) {
            return $this->directoryRefusal($input, $package);
        }

        $sourceId = $this->sourceId($input);
        $source = $sourceId === null ? null : ExtensionSourceRecord::query()->find($sourceId);

        return $source === null || $source->kind !== $kind ? __('Choose a saved source, or add one.') : null;
    }

    /**
     * Why $package can't be installed from the directory: it is off, its index can't be used, or the
     * package isn't listed there as the kind the request names.
     */
    private function directoryRefusal(RequestExtensionChangeInput $input, string $package): ?string
    {
        try {
            $index = $this->directory->usable();
        } catch (DirectoryRefused $refused) {
            return $refused->getMessage();
        }

        return $index->package($package, $input->kind) === null ? DirectoryRefused::notListed($package)->getMessage() : null;
    }

    /**
     * The source kind the request is stored with: Packagist unless it names another, for a change that
     * installs code; none for a removal or a rollback.
     */
    private function sourceKind(RequestExtensionChangeInput $input): ?string
    {
        if (! $this->changesCode($input)) {
            return null;
        }

        return $input->sourceKind ?? $this->installedFrom($input)->source_kind ?? PackagistSource::KEY;
    }

    /**
     * The saved source the request uses: the one it names, or for an update that names none, the one
     * the package was installed from.
     */
    private function sourceId(RequestExtensionChangeInput $input): ?string
    {
        if ($input->sourceKind !== null) {
            return $input->sourceId;
        }

        return $input->sourceId ?? $this->installedFrom($input)?->source_id;
    }

    /**
     * The last finished request that installed or updated the package an update names, which holds the
     * source it came from; null for an install, an update that names its source, or a package no request
     * here installed.
     */
    private function installedFrom(RequestExtensionChangeInput $input): ?object
    {
        if ($input->action !== ExtensionAction::Update || $input->sourceKind !== null || $input->package === null) {
            return null;
        }

        return DB::table('extension_requests')
            ->where('package', $input->package)
            ->whereIn('action', [ExtensionAction::Install->value, ExtensionAction::Update->value])
            ->where('status', RequestStatus::Done->value)
            ->orderByDesc('requested_at')
            ->first(['source_kind', 'source_id']);
    }

    /**
     * The versions asked for: those given, the uploaded archive's own version for an upload, else any.
     */
    private function constraint(RequestExtensionChangeInput $input): ?string
    {
        if (! $this->changesCode($input)) {
            return null;
        }

        $given = trim((string) $input->constraint);

        if ($given !== '') {
            return $given;
        }

        if ($this->sourceKind($input) === self::UPLOAD && $input->uploadId !== null) {
            return ExtensionUploadRecord::query()->whereKey($input->uploadId)->value('version') ?? '*';
        }

        return '*';
    }

    /** Whether the request installs code: an install or an update. */
    private function changesCode(RequestExtensionChangeInput $input): bool
    {
        return $input->action === ExtensionAction::Install || $input->action === ExtensionAction::Update;
    }
}
