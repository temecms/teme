<?php

declare(strict_types=1);

// The API decides what a reader may see in one place, Live (D19): no other
// file of the API filters by an item's status, visibility, trash or
// published revision, and no API route binds a model, which would query
// past Live (and past EnsureApiOn, which runs after SubstituteBindings).
// No booted app here (B-092), so files are read directly.

/**
 * Each line under these folders or files that matches, as "path:line  text",
 * leaving out the allowed files.
 *
 * @param  list<string>  $paths
 * @param  list<string>  $allowed
 *
 * @return list<string>
 */
function apiLinesMatching(array $paths, string $pattern, array $allowed = []): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach ($paths as $path) {
        $files = is_dir($root.'/'.$path)
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$path, FilesystemIterator::SKIP_DOTS)))
            : (is_file($root.'/'.$path) ? [new SplFileInfo($root.'/'.$path)] : []);

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! $file->isFile() || ! str_ends_with($relative, '.php') || in_array($relative, $allowed, true)) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $relative.':'.($number + 1).'  '.trim($line);
                }
            }
        }
    }

    return $found;
}

/** What a reader may see, judged outside Live: a status or visibility by a literal or an enum case, or a filter on status, visibility, the trash or the published revision. */
const API_OWN_STATUS = '/\bwhere\w*\(\s*[\'"](\w+\.)?(status|visibility|deleted_at|published_revision_id)[\'"]|\b(ContentStatus|Visibility)::|(===|!==|==|!=|<>)\s*[\'"](published|draft|scheduled|pending|private|protected|public)[\'"]|[\'"](published|draft|scheduled|pending|private|protected|public)[\'"]\s*(===|!==|==|!=|<>)/';

/** A model bound to a route: a model imported, a typed closure parameter other than the request, or Route::model() and Route::bind(). */
const API_BOUND_MODEL = '/^use\s+App\\\\(Domain\\\\\w+\\\\)?Models\\\\|^use\s+Illuminate\\\\Database\\\\Eloquent\\\\Model;|Route::(model|bind)\(|\bfn\s*\([^)]*\b(?!Request\b)[A-Z]\w*\s+\$|\bfunction\s*\([^)]*\b(?!Request\b)[A-Z]\w*\s+\$/';

it('judges what a reader may see only in Live', function (): void {
    expect(apiLinesMatching(['app/Http/Api', 'app/Domain/Api'], API_OWN_STATUS, ['app/Domain/Api/Live.php']))->toBe([]);
});

it('binds no model on an API route, and gives each route parameter its form', function (): void {
    $root = dirname(__DIR__, 2);
    $unformed = [];

    // A statement that declares a route with a {parameter} also says its form (whereUlid(), where()), so an ID is a string checked before any query.
    foreach ([$root.'/routes/api.php', ...(glob($root.'/routes/api/*.php') ?: [])] as $file) {
        foreach (explode(';', (string) file_get_contents($file)) as $statement) {
            if (preg_match('/Route::/', $statement) === 1 && preg_match('/\{\w+\??\}/', $statement) === 1 && preg_match('/->where\w*\(/', $statement) !== 1) {
                $unformed[] = basename($file).': '.trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }
    }

    expect(apiLinesMatching(['routes/api.php', 'routes/api', 'app/Http/Api'], API_BOUND_MODEL))->toBe([])
        ->and($unformed)->toBe([]);
});
