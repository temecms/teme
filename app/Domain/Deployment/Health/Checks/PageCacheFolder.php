<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Cache\FilePageStore;
use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Support\Facades\Cache;

/**
 * Says whether this process reaches the folder the web server stores pages
 * in (I-480): the file store names its folder in the cache, and a process
 * that removes pages from another leaves them served until they expire.
 */
final readonly class PageCacheFolder implements DoctorCheck
{
    private const string FIX = 'Mount the same page-cache volume at PAGE_CACHE_PATH in every role.';

    public function title(): string
    {
        return 'Page cache folder';
    }

    public function examine(): array
    {
        $path = (string) config('page_cache.stores.file.path');
        $stored = Cache::get(FilePageStore::FOLDER_KEY);
        $findings = [];

        if (! is_string($stored) || $stored === '') {
            $findings[] = new Finding(Severity::Info, "No page has been stored since the cache was last cleared, so there's nothing to compare yet.");
        } elseif (FilePageStore::folderNameAt($path) === $stored) {
            $findings[] = new Finding(Severity::Ok, 'This process sees the folder the web server stores pages in.');
        } else {
            $findings[] = new Finding(Severity::Warning, "This process's page-cache folder, {$path}, isn't the one the web server stores pages in, so pages it removes stay served until they expire.", self::FIX);
        }

        $unshared = Cache::get(FilePageStore::UNSHARED_KEY);

        if (is_array($unshared)) {
            $host = (string) ($unshared['host'] ?? 'another server');
            $findings[] = new Finding(Severity::Warning, "On {$host}, the page cache removed pages from a folder they aren't stored in, in the last day.", self::FIX);
        }

        return $findings;
    }
}
