<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Public readers that read an account for something other than a byline,
 * each by its reason: the signed-in reader's own edit links, and the
 * represented person Search and social chose, which stays an account.
 */
const PUBLIC_READERS_OF_ACCOUNTS = [
    'Site/Queries/EditLinks.php',
    'StructuredData/PublisherNodes.php',
];

it('reads no account in a public reader but through People', function (): void {
    $reading = [];

    foreach ((new Finder)->files()->name('*.php')->in(array_map(fn (string $area): string => app_path('Domain/'.$area), ['Site', 'Feeds', 'Sitemaps', 'StructuredData', 'Api'])) as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, "DB::table('users')") || str_contains($source, 'User::query()') || str_contains($source, "'users.")) {
            $reading[] = str_replace(app_path('Domain').'/', '', $file->getPathname());
        }
    }

    sort($reading);

    expect($reading)->toBe(PUBLIC_READERS_OF_ACCOUNTS);
});
