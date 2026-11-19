<?php

/**
 * The files of a personal data export, on the private disk: one per page a
 * holder answered, named so a retried page overwrites itself, and the one
 * file the person gets, assembled from them in the holders' order. Every
 * file is found by its name from the request's results, never by listing a
 * folder. The disk doesn't throw, so every write and read is checked here: a
 * failed one throws, the step rolls back, and the request fails.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Notifications\PersonalDataExported;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Settings\SettingsReader;

final class ExportFile
{
    /** The disk the files live on: storage/app/private, never public. */
    public const string DISK = 'local';

    private const int FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Returns the path of one holder's page.
     */
    public function pagePath(string $requestId, string $key, int $page): string
    {
        return "privacy/{$requestId}-{$key}-{$page}.json";
    }

    /**
     * Returns the path of the file the person gets.
     */
    public function finalPath(string $requestId): string
    {
        return "privacy/{$requestId}.json";
    }

    /**
     * Returns one holder's page as it is written.
     */
    public function pageContents(ExportPage $answer): string
    {
        $groups = array_map(fn ($group): array => ['title' => $group->title, 'values' => (object) $group->values], $answer->groups);

        return json_encode($groups, self::FLAGS);
    }

    /**
     * Writes one holder's page, over any copy a rolled-back step left.
     */
    public function writePage(string $requestId, string $key, int $page, string $contents): void
    {
        $this->write($this->pagePath($requestId, $key, $page), $contents);
    }

    /**
     * Returns the paths of every page the request's results say were written.
     *
     * @param  list<array<string, mixed>>  $results
     *
     * @return list<string>
     */
    public function pagePaths(string $requestId, array $results): array
    {
        $paths = [];

        foreach ($results as $entry) {
            for ($page = 1; $page <= (int) ($entry['page'] ?? 0); $page++) {
                $paths[] = $this->pagePath($requestId, (string) $entry['key'], $page);
            }
        }

        return $paths;
    }

    /**
     * Returns the paths of every file a request may have: its pages and the
     * file the person gets.
     *
     * @param  list<array<string, mixed>>  $results
     *
     * @return list<string>
     */
    public function allPaths(string $requestId, array $results): array
    {
        return [...$this->pagePaths($requestId, $results), $this->finalPath($requestId)];
    }

    /**
     * Writes the file the person gets from the request's pages, in the
     * holders' order, and returns its path.
     */
    public function assemble(PrivacyRequest $request): string
    {
        $holders = [];

        foreach ($request->results as $entry) {
            $groups = [];

            for ($page = 1; $page <= (int) $entry['page']; $page++) {
                $path = $this->pagePath($request->id, (string) $entry['key'], $page);
                $written = $this->disk()->get($path);

                if ($written === null) {
                    throw new RuntimeException(__('A page of the export is missing: :path.', ['path' => $path]));
                }

                array_push($groups, ...(array) json_decode($written, flags: JSON_THROW_ON_ERROR));
            }

            $holder = ['key' => $entry['key'], 'label' => $entry['label'], 'groups' => $groups];

            if (($entry['missing'] ?? false) === true) {
                $holder['missing'] = true;
            }

            $holders[] = $holder;
        }

        $path = $this->finalPath($request->id);

        $this->write($path, json_encode([
            'site' => app(SettingsReader::class)->string('general', 'site_title'),
            'email' => $request->email,
            'made_at' => now()->toIso8601String(),
            'holders' => $holders,
            'copies' => __("The site's backups may still hold this data until they are replaced."),
        ], self::FLAGS));

        return $path;
    }

    /**
     * Returns whether the file is small enough to email: most mail services
     * refuse 25 MB.
     */
    public function fitsInEmail(string $path): bool
    {
        return $this->disk()->exists($path) && $this->disk()->size($path) <= PersonalDataExported::MOST_BYTES;
    }

    /**
     * Returns where a file is on the server, for the operator.
     */
    public function absolutePath(string $path): string
    {
        return $this->disk()->path($path);
    }

    /**
     * Deletes these files by name; one already gone is not an error.
     *
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        if ($paths !== []) {
            $this->disk()->delete($paths);
        }
    }

    private function write(string $path, string $contents): void
    {
        // Private: the file's owner alone can read it on the server.
        if (! $this->disk()->put($path, $contents, 'private')) {
            throw new RuntimeException(__("Couldn't write the export file :path.", ['path' => $path]));
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
