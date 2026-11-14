<?php

declare(strict_types=1);

// Slice 1.5·0: post, page, category and tag are declared once, in CoreTypes;
// everything else asks the registries. No booted app here (B-092), so files
// are read directly.
it('names post, page, category and tag only in CoreTypes', function (): void {
    $root = dirname(__DIR__, 2);
    $words = 'post|page|category|tag|posts|pages|categories|tags';
    $php = "/in:post,page|in:category,tag|['\"]({$words})['\"]\s*(===|!==|=>)|(===|!==)\s*['\"]({$words})['\"]|\[\s*['\"]posts?['\"]\s*,\s*['\"]pages?['\"]\s*\]/";
    $js = "/['\"]({$words})['\"]\s*(\||===|!==)|(\||===|!==)\s*['\"]({$words})['\"]/";

    $scan = function (string $dir, string $extension, string $pattern, array $allowed) use ($root): array {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), $extension)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $isAllowed = array_filter($allowed, fn (string $prefix): bool => str_starts_with($relative, $prefix)) !== [];

            if ($isAllowed) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $relative.':'.($number + 1).'  '.trim($line);
                }
            }
        }

        return $found;
    };

    // Wayfinder's routes/, actions/ and wayfinder/ are generated and
    // git-ignored, and change with the routes; wayfinder/ names HTTP verbs.
    $generated = ['resources/js/routes/', 'resources/js/actions/', 'resources/js/wayfinder/', 'resources/js/components/ui/'];

    $offenders = [
        ...$scan('app', '.php', $php, ['app/Domain/Types/CoreTypes.php', 'app/Domain/Benchmark/']),
        ...$scan('resources/js', '.tsx', $js, $generated),
        ...$scan('resources/js', '.ts', $js, $generated),
    ];

    expect($offenders)->toBe([]);
});
