<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\Planning\CompanionPlugin;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Support\Network\AddressRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Tools › Import's two pages (I-227): the sites Teme knows with the connect form, and a site's steps.
 */
final class ImportScreenController extends Controller
{
    public function index(ImportScreen $screen, SettingsReader $settings): Response
    {
        return Inertia::render('tools/import/index', [
            'sites' => $screen->sites(),
            'companion' => ['version' => CompanionPlugin::VERSION, 'releases' => CompanionPlugin::RELEASES],
            'siteTimezone' => $settings->string('general', 'timezone'),
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
            'siteTimezone' => $settings->string('general', 'timezone'),
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
     * @return array{analysis: array<string, mixed>|null, problem: string|null, form: array<string, mixed>|null, remembered: mixed, rememberedHosts: list<string>|null, suggestedHosts: list<array{host: string, links: int}>}
     */
    private static function plan(Request $request, string $source, string $person, ImportAnalysis $analysis, ImportChoices $choices, ImportScreen $screen): array
    {
        try {
            $read = $analysis->read($source);
        } catch (SourceRefused|AddressRefused $refused) {
            return ['analysis' => null, 'problem' => $refused->sentence(), 'form' => null, 'remembered' => null, 'rememberedHosts' => null, 'suggestedHosts' => []];
        }

        $site = $screen->site($source);
        $keptHosts = $request->session()->get(ImportSourceController::REMEMBERED_HOSTS.$source);
        $rememberedHosts = is_array($keptHosts) ? array_values(array_filter($keptHosts, is_string(...))) : null;

        return [
            'analysis' => $read->toArray(),
            'problem' => null,
            'form' => $read->reported ? $choices->form($read, $person) : null,
            'remembered' => $request->session()->get(ImportRunController::REMEMBERED.$source),
            'rememberedHosts' => $rememberedHosts,
            'suggestedHosts' => $site['suggestedHosts'] ?? [],
        ];
    }
}
