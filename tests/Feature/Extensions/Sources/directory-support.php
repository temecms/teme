<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

// A directory built in each test: a key pair made there and then, never one kept in a file.

if (! function_exists('dirKeys')) {
    /**
     * A fresh Ed25519 key pair: the secret key and the public key as base64.
     *
     * @return array{secret: string, public: string}
     */
    function dirKeys(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['secret' => sodium_crypto_sign_secretkey($pair), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }

    /**
     * A listed package as index.json holds it, with its detail file.
     *
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>  $detail
     *
     * @return array{listing: array<string, mixed>, detail: array<string, mixed>}
     */
    function dirPackage(string $name, string $kind = 'plugin', array $listing = [], array $detail = []): array
    {
        return [
            'listing' => [
                'name' => $name, 'kind' => $kind, 'categories' => ['seo'], 'picked' => false,
                'label' => ucfirst(explode('/', $name)[1]), 'summary' => 'Does one thing well.',
                'author' => ['name' => 'Acme', 'github' => 'acme'], 'latest' => '1.2.0', 'updated' => '2026-11-18', 'listed' => '2026-11-01',
                'works_with' => ['1.1'], 'icon' => 'https://temecms.github.io/directory/assets/'.$name.'/1.2.0/icon.png',
                'detail' => 'packages/'.$name.'.json',
                ...$listing,
            ],
            'detail' => [
                'readme' => "Install it.\n", 'changelog' => "## 1.2.0\n", 'stars' => 4, 'issues' => 1,
                'screenshots' => [['url' => 'https://temecms.github.io/directory/assets/'.$name.'/1.2.0/one.png', 'caption' => 'The screen']],
                'capabilities' => [['key' => 'content.read', 'words' => 'Reads posts'], ['key' => 'http', 'words' => 'Connects to api.example.org']],
                'links' => ['source' => 'https://github.com/'.$name, 'issues' => 'https://github.com/'.$name.'/issues', 'docs' => null, 'support' => 'http://not-https.example'],
                'releases' => [
                    ['version' => '1.2.0', 'date' => '2026-11-18', 'works_with' => ['1.1'], 'dist' => ['url' => 'https://api.github.com/repos/'.$name.'/zipball/1.2.0', 'sha256' => str_repeat('a', 64)], 'held' => false],
                    ['version' => '1.3.0', 'date' => '2026-11-19', 'works_with' => ['1.1'], 'dist' => ['url' => 'https://api.github.com/repos/'.$name.'/zipball/1.3.0', 'sha256' => str_repeat('b', 64)], 'held' => true],
                ],
                ...$detail,
            ],
        ];
    }

    /**
     * The directory's files, path => bytes, signed with $secret.
     *
     * @param  list<array{listing: array<string, mixed>, detail: array<string, mixed>}>  $packages
     * @param  array<string, mixed>  $index  replaces index.json's own keys
     *
     * @return array<string, string>
     */
    function dirFiles(string $secret, array $packages = [], array $index = []): array
    {
        $files = [];
        $listings = [];

        foreach ($packages as $package) {
            $detail = json_encode($package['detail'], JSON_UNESCAPED_SLASHES);
            $files[$package['listing']['detail']] = $detail;
            $listings[] = [...$package['listing'], 'detail_sha256' => hash('sha256', $detail)];
        }

        $composer = json_encode(['packages' => array_fill_keys(array_map(fn (array $p): string => $p['listing']['name'], $packages), [])], JSON_UNESCAPED_SLASHES);
        $files['composer/packages.json'] = $composer;

        $bytes = json_encode([
            'format' => 1, 'sequence' => 5,
            'generated' => now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires' => now()->addDays(14)->utc()->format('Y-m-d\TH:i:s\Z'),
            'composer' => ['path' => 'composer/packages.json', 'sha256' => hash('sha256', $composer)],
            'packages' => $listings,
            ...$index,
        ], JSON_UNESCAPED_SLASHES);

        $files['index.json'] = $bytes;
        $files['index.json.sig'] = base64_encode(sodium_crypto_sign_detached($bytes, $secret));

        return $files;
    }

    /**
     * Serves $files at the directory's default address, in place of what was served before; every other
     * path is a 404.
     *
     * @param  array<string, string>  $files
     */
    function dirServe(array $files): void
    {
        $faked = app()->bound('teme.test.directory-files');
        app()->instance('teme.test.directory-files', $files);

        if ($faked) {
            return;
        }

        Http::fake(function ($request) {
            $files = app('teme.test.directory-files');
            $base = 'https://temecms.github.io/directory/';
            $path = substr($request->url(), strlen($base));

            return str_starts_with($request->url(), $base) && isset($files[$path])
                ? Http::response($files[$path], 200)
                : Http::response('not here', 404);
        });
    }
}
