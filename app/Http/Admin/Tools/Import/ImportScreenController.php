<?php

/**
 * Tools › Import's two pages (I-227): the sites Teme knows with the connect form, and a site's steps.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\Planning\CompanionPlugin;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Plugins\Readers\ImportReaderOffer;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Support\Network\AddressRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Teme\Contracts\Settings\SettingsReader;

final class ImportScreenController extends Controller
{
    public function index(ImportScreen $screen, SettingsReader $settings, ImportReaders $readers): Response
    {
        return Inertia::render('tools/import/index', [
            'sites' => $screen->sites(),
            'sourceReaders' => $screen->readerLabels($readers),
            'readers' => array_values(array_map(
                fn (ImportReaderOffer $offer): array => ['key' => $offer->key(), 'label' => $offer->label, 'fileTypes' => $offer->fileTypes],
                $readers->all(),
            )),
            'companion' => ['version' => CompanionPlugin::VERSION, 'releases' => CompanionPlugin::RELEASES],
            'siteTimezone' => $settings->string('general', 'timezone'),
            'maxFileMb' => max(1, (int) config('teme.import.max_file_mb')),
        ]);
    }

    public function show(Request $request, string $source, ImportScreen $screen, TrackedJobList $jobs, ImportAnalysis $analysis, ImportChoices $choices, SettingsReader $settings): Response
    {
        $site = $screen->site($source);
        abort_if($site === null, 404);

        $latestId = $screen->latestRun($source);
        $asked = $request->query('run');
        // An earlier import of this site, when asked for by its address; never another site's.
        $runId = is_string($asked) && $screen->sourceOf($asked) === $source ? $asked : $latestId;
        $step = $runId !== $latestId ? 'report' : (string) $screen->step($source);
        $person = (string) $request->user()?->getAuthIdentifier();

        $keptHosts = $request->session()->get(ImportSourceController::REMEMBERED_HOSTS.$source);
        $rememberedHosts = is_array($keptHosts) ? array_values(array_filter($keptHosts, is_string(...))) : null;

        return Inertia::render('tools/import/show', [
            'site' => $site,
            'step' => $step,
            'run' => $runId === null ? null : $screen->run($runId),
            'job' => $runId === null ? null : ($jobs->ofRun(ImportRunJobs::RUN_TYPE, $runId)[0] ?? null),
            'latest' => $runId === $latestId,
            'runs' => $screen->runs($source),
            'report' => $runId === null ? null : $screen->report($runId),
            'downloads' => $runId === null ? null : [
                'everything' => route('tools.importer.download', ['run' => $runId, 'file' => 'everything'], false),
                'attention' => route('tools.importer.download', ['run' => $runId, 'file' => 'attention'], false),
            ],
            // Deferred, so a slow site never holds the page (I-227).
            'plan' => $step === 'plan' ? Inertia::defer(fn (): array => self::plan($request, $source, $person, $analysis, $choices, $screen)) : null,
            // Report (and any step) can restore ticks after the password confirm.
            'rememberedHosts' => $rememberedHosts,
            'siteTimezone' => $settings->string('general', 'timezone'),
            'maxFileMb' => max(1, (int) config('teme.import.max_file_mb')),
        ]);
    }

    public function download(string $run, string $file, ImportScreen $screen): StreamedResponse
    {
        $attention = $file === 'attention';
        $name = $screen->fileName($run, $attention);
        abort_if($name === null, 404);

        return response()->streamDownload(function () use ($screen, $run, $attention): void {
            // A spreadsheet reads the file as UTF-8 only with its byte-order mark.
            echo "\xEF\xBB\xBF";

            foreach ($screen->lines($run, $attention) as $line) {
                echo $line;
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The analysis, the choices' form and the choices kept across a password's
     * confirmation; or why the site couldn't be read. Suggested hosts are
     * filled from that same analysis read.
     *
     * @return array{analysis: array<string, mixed>|null, problem: string|null, form: array<string, mixed>|null, fileForm: array<string, mixed>|null, oldSiteAnswers: bool|null, remembered: mixed, resumeStart: bool, rememberedHosts: list<string>|null, suggestedHosts: list<array{host: string, links: int}>}
     */
    private static function plan(Request $request, string $source, string $person, ImportAnalysis $analysis, ImportChoices $choices, ImportScreen $screen): array
    {
        try {
            $read = $analysis->read($source);
        } catch (SourceRefused|AddressRefused $refused) {
            return ['analysis' => null, 'problem' => $refused->sentence(), 'form' => null, 'fileForm' => null, 'oldSiteAnswers' => null, 'remembered' => null, 'resumeStart' => false, 'rememberedHosts' => null, 'suggestedHosts' => []];
        }

        $site = $screen->site($source);
        $keptHosts = $request->session()->get(ImportSourceController::REMEMBERED_HOSTS.$source);
        $rememberedHosts = is_array($keptHosts) ? array_values(array_filter($keptHosts, is_string(...))) : null;

        return [
            'analysis' => $read->toArray(),
            'problem' => null,
            'form' => $read->reported && ! isset($site['file']['id']) ? $choices->form($read, $person) : null,
            // An export file's plan has its own choices: its authors, its media, its addresses.
            'fileForm' => $read->reported && isset($site['file']['id'])
                ? $choices->fileForm($read, $site['file']['id'], $person, $screen->catchUpWith($source)['choices'] ?? [])
                : null,
            // Whether the old site answers now, so its media files can be downloaded during the import.
            'oldSiteAnswers' => isset($site['file']['id']) ? app(FileDownloader::class)->answers($site['address']) : null,
            'remembered' => $request->session()->get(ImportRunController::REMEMBERED.$source),
            // A start the password's confirmation interrupted: the plan sends it again once, now the password is confirmed.
            'resumeStart' => self::resumes($request, $source),
            'rememberedHosts' => $rememberedHosts,
            'suggestedHosts' => $site['suggestedHosts'] ?? [],
        ];
    }

    /**
     * Whether a start the password's confirmation interrupted waits to be
     * sent again, now that the password is confirmed: once, so a reload
     * doesn't start a second import.
     */
    private static function resumes(Request $request, string $source): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (! $request->session()->has(ImportRunController::RESUME.$source) || time() - $confirmedAt > (int) config('auth.password_timeout', 900)) {
            return false;
        }

        $request->session()->forget(ImportRunController::RESUME.$source);

        return true;
    }
}
