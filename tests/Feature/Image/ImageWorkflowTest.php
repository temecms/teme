<?php

declare(strict_types=1);

use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The image workflow, read as data: it runs on GitHub, by hand, on a
 * version tag (I-3103), or for a pull request that changes the image; never
 * in the suite.
 */

it('runs by hand, from the nightly run, on version tags, and for a pull request that changes the image, never for a pushed branch', function (): void {
    $on = ImageFiles::workflow()['on'];

    expect(array_keys($on))->toBe(['pull_request', 'workflow_dispatch', 'workflow_call', 'push'])
        ->and($on['pull_request'])->toBe(['paths' => ['Dockerfile', '.dockerignore', 'docker/**', '.github/workflows/image.yml']])
        ->and($on['push'])->toBe(['tags' => ['v*']])
        ->and($on['workflow_dispatch']['inputs']['publish']['default'])->toBeFalse()
        ->and($on['workflow_call']['inputs']['publish']['default'])->toBeFalse();
});

it('starts the image as one container that runs every role, and waits for its health check', function (): void {
    $steps = collect(ImageFiles::workflow()['jobs']['image']['steps'])->keyBy('name');
    $start = (string) $steps['Start one container that runs every role']['run'];

    expect($start)->toContain('--env TEME_ROLE=all', '"$TEME_IMAGE"', 'test "$status" = healthy', '/up)" = 200')
        ->and($steps['Stop the one container']['if'])->toBe('always()');
});

it('pins every action it uses by commit', function (): void {
    preg_match_all('/uses:\s*(\S+)/', ImageFiles::text('.github/workflows/image.yml'), $found);

    expect($found[1])->toBe([
        'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
        'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
        'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
        'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
    ]);
});

it('installs Trivy only once its published checksum matches', function (): void {
    $job = ImageFiles::workflow()['jobs']['image'];
    $install = collect($job['steps'])->firstWhere('name', 'Install Trivy, checked against its published SHA-256')['run'];

    expect($job['env']['TRIVY_VERSION'])->toBe('0.75.0')
        ->and($job['env']['TRIVY_SHA256'])->toBe('c6e65abddb348e25f10549df887045629cf28cc72453cd1c63acb717316b3f3f')
        ->and(strpos($install, 'sha256sum -c -'))->toBeLessThan(strpos($install, 'sudo install -m 0755'));
});

it('writes an SBOM, keeps the report whatever happens, and fails on a critical vulnerability with a fix', function (): void {
    $steps = collect(ImageFiles::workflow()['jobs']['image']['steps']);
    $scan = $steps->firstWhere('name', 'Scan the image and write its SBOM')['run'];

    expect($scan)->toContain('--format cyclonedx --output image-report/sbom.cdx.json')
        ->and($scan)->toContain('--severity CRITICAL --ignore-unfixed', '--exit-code 1')
        ->and($steps->firstWhere('name', 'Keep the scan and the SBOM')['if'])->toBe('always()');
});

it('builds amd64 from the checkout, then runs the recipe, deploys twice at once and checks the site', function (): void {
    $steps = collect(ImageFiles::workflow()['jobs']['image']['steps'])->keyBy('name');

    expect($steps['Build the image']['run'])->toContain('docker build --pull --platform linux/amd64')
        ->and($steps['Start the recipe']['working-directory'])->toBe('docker')
        ->and(substr_count($steps['Deploy twice at once, as two releases starting together would']['run'], 'docker compose run --rm --no-deps deploy &'))->toBe(2)
        ->and($steps['Check the running site']['run'])->toContain(
            '/up)" = 200',
            'web php artisan teme:doctor',
            '/admin/login)" = 200',
            'teme:cache:check-static --url=http://127.0.0.1:8080',
            "count '^content-encoding: gzip$'",
            "count '^x-frame-options: deny$'",
            'max-age=31536000, immutable',
            "count '^strict-transport-security: max-age=31536000$'",
            'docker compose exec -T worker curl',
            "neighbour -H 'X-Forwarded-Proto: https' http://web:8080/admin/login | count '^strict-transport-security:')\" = 1",
            'CF-Connecting-IP: 203.0.113.9',
            "grep -qE '\\| 172\\.",
            '/admin/tools/backups/set-up',
        );
});

it('ignores only what the v4 decision holds, each entry naming issue #147 with an expiry', function (): void {
    $lines = preg_split('/\R/', trim(ImageFiles::text('.trivyignore'))) ?: [];
    $scan = collect(ImageFiles::workflow()['jobs']['image']['steps'])->firstWhere('name', 'Scan the image and write its SBOM')['run'];

    expect($lines[0])->toBe('# Vulnerabilities the image can\'t fix while the project keeps serversideup/php')
        ->and($scan)->toContain('--ignorefile .trivyignore --exit-code 1');

    foreach ($lines as $at => $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        expect($line)->toMatch('/^CVE-\d{4}-\d{4,} exp:\d{4}-\d{2}-\d{2}$/')
            ->and($lines[$at - 1] ?? '')->toStartWith('#')
            ->and($lines[$at - 1] ?? '')->toContain('#147');
    }
});

it('publishes by commit and by version, never latest, and only on a tag or when asked', function (): void {
    $publish = collect(ImageFiles::workflow()['jobs']['image']['steps'])->firstWhere('name', "Publish to GitHub's registry");

    expect($publish['if'])->toBe("github.ref_type == 'tag' || inputs.publish")
        ->and($publish['run'])->toContain('"${IMAGE}:${SHA_TAG}"', '"${IMAGE}:${VERSION}"', '--password-stdin')
        ->and(ImageFiles::text('.github/workflows/image.yml'))->not->toMatch('/\blatest\b/');
});

it('gives the job only what publishing needs, and writes no expression into a script', function (): void {
    $workflow = ImageFiles::workflow();
    $steps = $workflow['jobs']['image']['steps'];

    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['jobs']['image']['permissions'])->toBe(['contents' => 'read', 'packages' => 'write'])
        ->and($steps[0]['with']['persist-credentials'])->toBeFalse();

    foreach ($steps as $step) {
        expect((string) ($step['run'] ?? ''))->not->toContain('${{');
    }
});

it('gives a version tag the image already built for its commit, with the tag’s version on the same layers, checked before it is pushed (I-3264)', function (): void {
    $workflow = ImageFiles::workflow();
    $existing = $workflow['jobs']['existing'];
    $promote = collect($existing['steps'])->firstWhere('name', 'Give the image already built for this commit the tag’s version, if there is one');
    $run = (string) $promote['run'];

    expect($existing['if'])->toBe("github.ref_type == 'tag'")
        ->and($existing['permissions'])->toBe(['packages' => 'write'])
        ->and($existing['outputs']['promoted'])->toBe('${{ steps.promote.outputs.promoted }}')
        ->and($promote['id'])->toBe('promote')
        ->and($run)->toContain(
            'docker pull "${IMAGE}:${SHA_TAG}"',
            "printf 'FROM %s\\nENV TEME_VERSION=%s\\nLABEL org.opencontainers.image.version=%s\\n' \"\${IMAGE}:\${SHA_TAG}\" \"\$VERSION\" \"\$VERSION\"",
            'docker build --platform linux/amd64 --tag "${IMAGE}:${VERSION}" -',
            "built=\"\$(docker image inspect --format '{{json .RootFS.Layers}}' \"\${IMAGE}:\${SHA_TAG}\")\"",
            "tagged=\"\$(docker image inspect --format '{{json .RootFS.Layers}}' \"\${IMAGE}:\${VERSION}\")\"",
            'test "$built" = "$tagged"',
            'test "$env" = "TEME_VERSION=${VERSION}"',
            'docker push "${IMAGE}:${VERSION}"',
            'echo "promoted=true" >> "$GITHUB_OUTPUT"',
            'echo "promoted=false" >> "$GITHUB_OUTPUT"',
            "grep -qi 'manifest unknown'",
            'exit 1',
            '--password-stdin',
        )
        // Nothing is pushed until the layers and the version are checked.
        ->and(strpos($run, 'test "$built" = "$tagged"'))->toBeLessThan(strpos($run, 'docker push'))
        ->and(strpos($run, 'test "$env" = "TEME_VERSION=${VERSION}"'))->toBeLessThan(strpos($run, 'docker push'))
        ->and($run)->not->toContain('skopeo copy')
        ->and($run)->not->toContain('crane')
        ->and($existing['needs'])->toBe('since')
        ->and($workflow['jobs']['image']['needs'])->toBe(['since', 'existing'])
        ->and($workflow['jobs']['image']['if'])->toBe("!cancelled() && needs.since.result != 'failure' && needs.existing.result != 'failure' && needs.existing.outputs.promoted != 'true'");

    foreach ($existing['steps'] as $step) {
        expect((string) ($step['run'] ?? ''))->not->toContain('${{');
    }
});

it('makes a GitHub Release with the changelog on every version tag, once its image is published or promoted', function (): void {
    $workflow = ImageFiles::workflow();
    $release = $workflow['jobs']['release'];
    $steps = collect($release['steps'])->keyBy('name');

    expect(array_keys($workflow['jobs']))->toBe(['since', 'existing', 'image', 'release'])
        ->and($release['needs'])->toBe(['existing', 'image'])
        ->and($release['if'])->toBe("github.ref_type == 'tag' && !cancelled() && needs.existing.result == 'success' && (needs.existing.outputs.promoted == 'true' || needs.image.result == 'success')")
        ->and($release['permissions'])->toBe(['contents' => 'write'])
        ->and($steps['Checkout code']['with'])->toBe(['persist-credentials' => false, 'fetch-depth' => 0])
        ->and($steps['Write the release notes']['run'])->toContain(
            "git tag --merged \"\$GITHUB_SHA\" --sort=-creatordate 'v*'",
            'git diff --name-only --diff-filter=A "$previous" "$GITHUB_SHA" -- database/migrations',
            'php scripts/release-notes.php CHANGELOG.md "$VERSION"',
        )
        ->and($steps['Create or update the release']['env'])->toBe(['GH_TOKEN' => '${{ secrets.GITHUB_TOKEN }}'])
        ->and($steps['Create or update the release']['run'])->toContain(
            'gh release create "$GITHUB_REF_NAME" --verify-tag --title "Teme ${VERSION}" --notes-file release-notes.md --prerelease="$pre"',
            'gh release edit "$GITHUB_REF_NAME" --title "Teme ${VERSION}" --notes-file release-notes.md --prerelease="$pre"',
        );

    foreach ($release['steps'] as $step) {
        expect((string) ($step['run'] ?? ''))->not->toContain('${{');
    }
});

it('bakes the tag’s version and the commit into a tag’s build, and no version into a branch’s', function (): void {
    $steps = collect(ImageFiles::workflow()['jobs']['image']['steps'])->keyBy('name');
    $build = $steps['Build the image'];

    expect($build['env'])->toMatchArray(['TEME_VERSION' => '${{ steps.name.outputs.version }}'])
        ->and($build['run'])->toContain('--build-arg "TEME_VERSION=${TEME_VERSION}"', '--build-arg "TEME_REVISION=${GITHUB_SHA}"');
});

it('stops a version tag that still says @since next before any image is published', function (): void {
    $workflow = ImageFiles::workflow();
    $since = $workflow['jobs']['since'];
    $steps = collect($since['steps'])->keyBy('name');

    expect($since['if'])->toBe("github.ref_type == 'tag'")
        ->and($steps['Checkout code']['with'])->toBe(['persist-credentials' => false])
        ->and($steps['Check that the release step stamped every @since next']['run'])->toBe('php scripts/file-headers.php check-release')
        ->and($workflow['jobs']['existing']['needs'])->toBe('since')
        ->and($workflow['jobs']['image']['if'])->toContain("needs.since.result != 'failure'");
});
