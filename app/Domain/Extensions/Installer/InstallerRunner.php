<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use App\Domain\Extensions\CorePins;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Models\ExtensionSourceRecord;
use App\Domain\Extensions\Operations\BeginExtensionRequest;
use App\Domain\Extensions\Operations\EndExtensionRequest;
use App\Domain\Extensions\Operations\Input\BeginExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\EndExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\RecordExtensionStepInput;
use App\Domain\Extensions\Operations\RecordExtensionStep;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\SourceKind;
use App\Domain\Extensions\Sources\SourceKinds;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Carries out extension requests one step a pass, as the operator: the row
 * is the state, so a pass reads it, takes one step and records it. Also
 * checks, when the installer starts, that the current release still fits
 * the image (docs/plans/plugin-installer.md).
 */
final class InstallerRunner
{
    /** The least free space a release may be staged with, in bytes. */
    public const int LEAST_FREE = 512 * 1024 * 1024;

    /** How long a request may wait for agreement before it is cancelled, in hours. */
    public const int WAITS_FOR = 24;

    /** @var Closure(string): (float|false) */
    private Closure $freeSpace;

    /**
     * @param  array{replace: array<string, string>, provide: array<string, string>, hash: string}  $pins
     * @param  (Closure(string): (float|false))|null  $freeSpace
     */
    public function __construct(
        private readonly ReleaseStore $store,
        private readonly ComposerRunner $composer,
        private readonly StageValidator $validator,
        private readonly CompatibilityCheck $compatibility,
        private readonly ReleaseCaches $caches,
        private readonly ProjectFile $project,
        private readonly SourceKinds $sources,
        private readonly array $pins,
        private readonly bool $packagist = true,
        ?Closure $freeSpace = null,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $path): float|false => @disk_free_space($path);
    }

    /** The runner for this server's extensions folder and image. */
    public static function make(): self
    {
        $root = rtrim((string) config('teme.extensions.path'), '/');
        $composer = new ComposerRunner((string) config('teme.extensions.composer'), $root);

        return new self(
            new ReleaseStore($root),
            $composer,
            app()->make(StageValidator::class, ['composer' => $composer, 'imageVendor' => base_path('vendor')]),
            new CompatibilityCheck,
            new ReleaseCaches,
            new ProjectFile,
            app(SourceKinds::class),
            (new CorePins)->current(),
            (bool) config('teme.extensions.packagist'),
        );
    }

    /** Whether a request runs now, so the loop looks again sooner. */
    public function running(): bool
    {
        return DB::table('extension_requests')->where('status', RequestStatus::Running->value)->exists();
    }

    /**
     * One pass: begins the oldest request, takes one step of the one that
     * runs, or looks again at one that waits for agreement.
     */
    public function tick(): void
    {
        $row = DB::table('extension_requests')->whereIn('status', ['requested', 'running', 'waiting'])->orderBy('requested_at')->first();

        if ($row === null) {
            return;
        }

        $request = ExtensionRequest::fromRow($row);

        match ($request->status) {
            RequestStatus::Requested => app(BeginExtensionRequest::class)->execute(new BeginExtensionRequestInput($request->id)),
            RequestStatus::Waiting => $this->lookAgain($request),
            default => $this->step($request),
        };
    }

    /**
     * When the installer starts: checks the current release against this
     * image without Composer, stamps it when it fits, and rebuilds it when it
     * doesn't. Returns null when the site runs the release, or why its
     * extensions are held.
     */
    public function prepare(): ?string
    {
        $root = $this->store->root();

        foreach (['releases', 'packages', '.composer'] as $folder) {
            if (! is_dir("{$root}/{$folder}") && ! @mkdir("{$root}/{$folder}", $folder === '.composer' ? 0700 : 0755, true)) {
                throw new InstallerFailed("The installer can't write the extensions folder {$root}. Give it to the installer's user (uid 9990): chown -R 9990:9990 {$root}");
            }
        }

        if (! is_writable($root)) {
            throw new InstallerFailed("The installer can't write the extensions folder {$root}. Give it to the installer's user (uid 9990): chown -R 9990:9990 {$root}");
        }

        $current = $this->store->current();
        $hash = $this->pins['hash'];

        if ($current === null || is_file($this->store->path($current)."/compat/{$hash}")) {
            return null;
        }

        $problems = $this->compatibility->problems($this->store->path($current), $this->pins);

        if ($problems === []) {
            return $this->finishFor($current);
        }

        $id = app(BeginExtensionRequest::class)->execute(new BeginExtensionRequestInput(rebuild: true));

        if (! is_string($id)) {
            return 'Another request is open, so the installed plugins and themes wait to be rebuilt for this version of Teme.';
        }

        // Carried out at once: the web, worker and scheduler wait for this check.
        for ($pass = 0; $pass < 20; $pass++) {
            $row = DB::table('extension_requests')->where('id', $id)->first();

            if ($row === null || ! RequestStatus::from((string) $row->status)->isOpen()) {
                break;
            }

            $this->step(ExtensionRequest::fromRow($row));
        }

        $row = DB::table('extension_requests')->where('id', $id)->first();

        return $row !== null && $row->status === RequestStatus::Done->value ? null : (string) ($row->error ?? implode(' ', $problems));
    }

    /**
     * Swaps back to the newest kept release, older than the current one,
     * that was checked against this core.
     */
    private function rollbackTarget(): ?string
    {
        $current = $this->store->current();

        foreach (array_reverse($this->store->all()) as $id) {
            if ($current !== null && strcmp($id, $current) < 0 && is_file($this->store->path($id).'/compat/'.$this->pins['hash'])) {
                return $id;
            }
        }

        return null;
    }

    /** Takes the request's current step and records it. */
    private function step(ExtensionRequest $request): void
    {
        $step = $request->step ?? RequestStep::Checking;
        $this->record($request, $step, RequestStep::RUNNING);

        try {
            $outcome = match ($step) {
                RequestStep::Checking => $this->checking($request),
                RequestStep::Preparing => $this->preparing($request),
                RequestStep::Fetching => $this->fetching($request),
                RequestStep::Verifying => $this->verifying($request),
                RequestStep::Validating => $this->validating($request),
                RequestStep::Switching => $this->switching($request),
                RequestStep::Finishing => $this->finishing($request),
            };
        } catch (InstallerFailed $failure) {
            $this->fail($request, $step, $failure->getMessage());

            return;
        } catch (Throwable $failure) {
            report($failure);
            $this->fail($request, $step, 'The installer stopped on an error it didn’t expect; the server’s log has the details.');

            return;
        }

        if ($outcome === true && $step !== RequestStep::Finishing) {
            $this->record($request, $step, RequestStep::DONE, step: $step->next());
        }
    }

    private function checking(ExtensionRequest $request): bool
    {
        if (DB::table('teme_updates')->whereIn('status', ['requested', 'running'])->exists()) {
            throw new InstallerFailed('An update of Teme is under way; ask again once it has ended.');
        }

        $current = $this->store->current();
        $size = $current === null ? 0 : self::size($this->store->path($current));
        $needed = max(self::LEAST_FREE, 3 * $size);
        $free = ($this->freeSpace)($this->store->root());

        if ($free !== false && $free < $needed) {
            throw new InstallerFailed(sprintf('The extensions folder has %d MB free; the installer needs %d MB to build a release.', intdiv((int) $free, 1048576), intdiv($needed, 1048576)));
        }

        return true;
    }

    private function preparing(ExtensionRequest $request): bool
    {
        $current = $this->store->current();

        if ($request->action === ExtensionAction::Rollback) {
            $target = $this->rollbackTarget() ?? throw new InstallerFailed('No earlier release is kept that fits this version of Teme, so there is nothing to roll back to.');
            $this->record($request, RequestStep::Preparing, RequestStep::RUNNING, fromRelease: $current, toRelease: $target);

            return true;
        }

        $id = strtolower((string) Str::ulid());
        $release = $this->store->create($id);
        $this->record($request, RequestStep::Preparing, RequestStep::RUNNING, fromRelease: $current ?? '', toRelease: $id);

        $previous = $current === null ? null : $this->store->path($current);

        if ($previous !== null && is_file("{$previous}/composer.lock")) {
            copy("{$previous}/composer.lock", "{$release}/composer.lock");
        }

        [$kind, $source] = $this->source($request);
        $sources = $request->package !== null && $request->sourceKind !== null && $request->action !== ExtensionAction::Remove ? [$request->package => $request->sourceKind] : [];

        $this->project->write($release, $this->project->compose(
            $previous === null ? null : $this->project->read($previous),
            $this->pins,
            $kind?->repositories($source, $this->store->root()) ?? [],
            $this->packagist,
            $sources,
        ));

        $kind?->prepare($request, $release, $this->store->root());

        return true;
    }

    private function fetching(ExtensionRequest $request): bool
    {
        if ($request->action === ExtensionAction::Rollback) {
            return true;
        }

        $result = $this->composer->change($request, $this->stagePath($request), $this->auth($request));

        if (! $result->ok()) {
            throw new InstallerFailed("Composer couldn’t install the packages:\n".$result->lastLines());
        }

        return true;
    }

    private function verifying(ExtensionRequest $request): bool
    {
        if ($request->action === ExtensionAction::Rollback) {
            return true;
        }

        $problems = $this->validator->verify($this->stagePath($request));

        if ($problems !== []) {
            throw new InstallerFailed(implode(' ', $problems));
        }

        return true;
    }

    private function validating(ExtensionRequest $request): bool
    {
        if ($request->action === ExtensionAction::Rollback) {
            return true;
        }

        $current = $request->fromRelease === null || $request->fromRelease === '' ? null : $this->store->path($request->fromRelease);
        $outcome = $this->validator->validate($request, $this->stagePath($request), $this->pins, $current);

        if ($outcome->refused()) {
            throw new InstallerFailed(implode(' ', $outcome->refusals));
        }

        if (! $outcome->coveredBy($request->agreed)) {
            $this->record($request, RequestStep::Validating, RequestStep::WAITING, 'Waiting for an administrator to agree.', needs: $outcome->needs, status: RequestStatus::Waiting);

            return false;
        }

        if ($outcome->warnings !== []) {
            $this->record($request, RequestStep::Validating, RequestStep::RUNNING, implode(' ', $outcome->warnings));
        }

        return true;
    }

    private function switching(ExtensionRequest $request): bool
    {
        $target = (string) $request->toRelease;
        $hash = $this->pins['hash'];

        if ($request->action !== ExtensionAction::Rollback) {
            $failed = $this->stamp($target);

            if ($failed !== null) {
                throw new InstallerFailed($failed);
            }
        }

        $now = $this->store->current();
        $then = $request->fromRelease === '' ? null : $request->fromRelease;

        if ($now !== $then) {
            throw new InstallerFailed('The installed plugins and themes changed while this request was being built, so it stopped. Ask again.');
        }

        if (! is_file($this->store->path($target)."/compat/{$hash}")) {
            throw new InstallerFailed('The new release wasn’t checked against this version of Teme.');
        }

        $this->store->swap($target);

        return true;
    }

    private function finishing(ExtensionRequest $request): bool
    {
        Artisan::call('queue:restart');
        $this->store->prune();

        [$plugins, $themes] = $this->touched($request->fromRelease, (string) $request->toRelease);

        $this->record($request, RequestStep::Finishing, RequestStep::DONE);
        app(EndExtensionRequest::class)->execute(new EndExtensionRequestInput(
            $request->id,
            RequestStatus::Done,
            report: $this->report($request),
            plugins: $plugins,
            themes: $themes,
        ));

        return true;
    }

    /** A request that waits is looked at again: on once agreed, cancelled after a day. */
    private function lookAgain(ExtensionRequest $request): void
    {
        if ($request->needs !== [] && (new StageOutcome(needs: $request->needs))->coveredBy($request->agreed)) {
            $this->record($request, RequestStep::Validating, RequestStep::RUNNING, 'Agreed; checking again.', status: RequestStatus::Running);

            return;
        }

        $since = $request->startedAt ?? $request->requestedAt;

        if ($since->addHours(self::WAITS_FOR)->isPast()) {
            $this->discard($request);
            app(EndExtensionRequest::class)->execute(new EndExtensionRequestInput($request->id, RequestStatus::Cancelled, 'Nobody agreed within a day, so the request was cancelled.'));
        }
    }

    /** Ends a request as failed, its unswapped release deleted and current left as it was. */
    private function fail(ExtensionRequest $request, RequestStep $step, string $reason): void
    {
        $this->discard($request);

        try {
            $this->record($request, $step, RequestStep::FAILED, $reason);
        } catch (Throwable) {
            // The request ends below whatever this step's record says.
        }

        app(EndExtensionRequest::class)->execute(new EndExtensionRequestInput($request->id, RequestStatus::Failed, $reason, $this->report($request)));
    }

    /** Deletes the request's release when it never went live. */
    private function discard(ExtensionRequest $request): void
    {
        $fresh = DB::table('extension_requests')->where('id', $request->id)->value('to_release');

        if ($request->action !== ExtensionAction::Rollback && is_string($fresh) && ReleaseStore::isId($fresh) && $fresh !== $this->store->current()) {
            $this->store->delete($fresh);
        }
    }

    /**
     * Writes a release's caches and stamp for this core. Returns why it
     * couldn't, or null.
     */
    private function stamp(string $id): ?string
    {
        $release = $this->store->path($id);
        $hash = $this->pins['hash'];
        $failed = $this->caches->write($release, $hash);

        if ($failed !== null) {
            return 'Teme couldn’t read the new release’s plugins and themes: '.$failed;
        }

        if ((! is_dir("{$release}/compat") && ! @mkdir("{$release}/compat", 0755)) || @touch("{$release}/compat/{$hash}") === false) {
            return 'Teme couldn’t mark the new release as checked.';
        }

        return null;
    }

    /** Stamps the current release for this core, which fits it without Composer. */
    private function finishFor(string $id): ?string
    {
        return $this->stamp($id);
    }

    /**
     * The source kind and saved source the request comes from.
     *
     * @return array{0: SourceKind|null, 1: ExtensionSource|null}
     */
    private function source(ExtensionRequest $request): array
    {
        if ($request->sourceKind === null) {
            return [null, null];
        }

        $record = $request->sourceId === null ? null : ExtensionSourceRecord::query()->find($request->sourceId);

        return [$this->sources->get($request->sourceKind), $record?->toSource()];
    }

    /**
     * COMPOSER_AUTH for every saved source the release uses, merged.
     *
     * @return array<string, mixed>
     */
    private function auth(ExtensionRequest $request): array
    {
        $auth = [];

        foreach (ExtensionSourceRecord::query()->get() as $record) {
            $kind = $this->sources->find($record->kind);

            if ($kind !== null) {
                $auth = array_replace_recursive($auth, $kind->auth($record->toSource()));
            }
        }

        return $auth;
    }

    private function stagePath(ExtensionRequest $request): string
    {
        $fresh = DB::table('extension_requests')->where('id', $request->id)->value('to_release');

        return $this->store->path((string) ($fresh ?? $request->toRelease));
    }

    /**
     * What the request ended with: each package of the new release, its
     * version and reference.
     *
     * @return array<string, mixed>
     */
    private function report(ExtensionRequest $request): array
    {
        $to = DB::table('extension_requests')->where('id', $request->id)->value('to_release');

        if (! is_string($to) || ! ReleaseStore::isId($to) || ! is_dir($this->store->path($to))) {
            return [];
        }

        $packages = [];

        foreach (StageValidator::locked($this->store->path($to)) as $package) {
            $packages[] = [
                'name' => $package['name'],
                'version' => (string) ($package['version'] ?? ''),
                'reference' => $package['dist']['reference'] ?? $package['source']['reference'] ?? null,
                'type' => $package['type'] ?? null,
            ];
        }

        return ['release' => $to, 'packages' => $packages];
    }

    /**
     * The codes of the plugins and themes that differ between two releases.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function touched(?string $from, string $to): array
    {
        $before = $from === null || $from === '' ? [] : self::packages($this->store->path($from));
        $after = self::packages($this->store->path($to));
        $plugins = [];
        $themes = [];

        foreach (array_keys($before + $after) as $name) {
            $old = $before[$name] ?? null;
            $new = $after[$name] ?? null;

            if ($old !== null && $new !== null && $old['reference'] === $new['reference'] && $old['version'] === $new['version']) {
                continue;
            }

            $entry = $new ?? $old;

            if ($entry['code'] !== null && $entry['kind'] === ExtensionKind::Plugin) {
                $plugins[] = $entry['code'];
            } elseif ($entry['code'] !== null && $entry['kind'] === ExtensionKind::Theme) {
                $themes[] = $entry['code'];
            }
        }

        return [array_values(array_unique($plugins)), array_values(array_unique($themes))];
    }

    /**
     * A release's plugins and themes by name: version, reference, kind and code.
     *
     * @return array<string, array{version: string, reference: mixed, kind: ExtensionKind|null, code: string|null}>
     */
    private static function packages(string $release): array
    {
        $packages = [];

        foreach (StageValidator::locked($release) as $package) {
            $kind = match ($package['type'] ?? null) {
                'teme-plugin' => ExtensionKind::Plugin,
                'teme-theme' => ExtensionKind::Theme,
                default => null,
            };
            $code = null;

            if ($kind !== null) {
                $manifest = json_decode((string) @file_get_contents("{$release}/vendor/{$package['name']}/{$kind->packageType()}.json"), true);
                $code = is_array($manifest) && is_string($manifest['code'] ?? null) ? $manifest['code'] : null;
            }

            $packages[$package['name']] = [
                'version' => (string) ($package['version'] ?? ''),
                'reference' => $package['dist']['reference'] ?? $package['source']['reference'] ?? null,
                'kind' => $kind,
                'code' => $code,
            ];
        }

        return $packages;
    }

    /**
     * Records a step, through the Operation.
     *
     * @param  array<string, mixed>|null  $needs
     */
    private function record(
        ExtensionRequest $request,
        RequestStep $current,
        string $state,
        ?string $detail = null,
        ?array $needs = null,
        ?RequestStatus $status = null,
        ?string $fromRelease = null,
        ?string $toRelease = null,
        ?RequestStep $step = null,
    ): void {
        app(RecordExtensionStep::class)->execute(new RecordExtensionStepInput($request->id, $current, $state, $detail, $needs, $status, $fromRelease, $toRelease));

        if ($step !== null) {
            app(RecordExtensionStep::class)->execute(new RecordExtensionStepInput($request->id, $step, RequestStep::PENDING));
        }
    }

    /** The size of a folder in bytes. */
    private static function size(string $path): int
    {
        $size = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && ! $file->isLink()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
