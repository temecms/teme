<?php

declare(strict_types=1);

use App\Domain\Themes\ThemeScripts;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/csp-inline'), base_path('tests/Fixtures/themes/good')]]);
});

it("refuses an inline script in a theme's views, naming the file and line", function (): void {
    $code = Artisan::call('teme:theme:check', ['code' => 'csp-inline']);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('views/templates/index.blade.php, line 5: an inline <script> stops working when the content security policy is enforced. Move it into the theme\'s build, as a module.');
});

it('refuses an on* attribute', function (): void {
    expect(ThemeScripts::refusals(base_path('tests/Fixtures/themes/csp-inline')))
        ->toContain('views/templates/index.blade.php, line 6: an on… attribute stops working when the content security policy is enforced. Add the handler in the theme\'s script instead.');
});

it("refuses core's inline-script component", function (): void {
    expect(ThemeScripts::refusals(base_path('tests/Fixtures/themes/csp-inline')))
        ->toContain('views/partials/core.blade.php, line 1: <x-teme::inline-script> is core\'s own. A theme\'s scripts go in its build.');
});

it('allows a script with src and a JSON-LD script', function (): void {
    $refusals = ThemeScripts::refusals(base_path('tests/Fixtures/themes/csp-inline'));

    expect($refusals)->toHaveCount(3)
        ->and(implode("\n", $refusals))->not->toContain('line 3:')->not->toContain('line 4:');
});

it('passes the default theme', function (): void {
    expect(ThemeScripts::refusals(base_path('themes/default')))->toBe([]);

    $code = Artisan::call('teme:theme:check', ['code' => 'default']);
    $output = Artisan::output();

    expect($output)->not->toContain('content security policy')
        ->and($code)->toBe(0);
});

it('reports in JSON too', function (): void {
    Artisan::call('teme:theme:check', ['code' => 'csp-inline', '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['errors'])->toBe(ThemeScripts::refusals(base_path('tests/Fixtures/themes/csp-inline')))
        ->and($report['errors'])->toHaveCount(3);
});

it('reads each view whole: a tag over two lines, and data-src for no src', function (): void {
    expect(ThemeScripts::refusals(base_path('tests/Fixtures/themes/csp-split')))->toBe([
        'views/templates/index.blade.php, line 2: an inline <script> stops working when the content security policy is enforced. Move it into the theme\'s build, as a module.',
        'views/templates/index.blade.php, line 5: an on… attribute stops working when the content security policy is enforced. Add the handler in the theme\'s script instead.',
        'views/templates/index.blade.php, line 6: an inline <script> stops working when the content security policy is enforced. Move it into the theme\'s build, as a module.',
    ]);
});
