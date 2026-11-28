<?php

declare(strict_types=1);

use App\Domain\Site\Insertions\InsertionCleaner;
use Illuminate\View\ComponentAttributeBag;

it('removes a template from plugin markup, with whatever it holds, so nothing core copies later runs', function (): void {
    $cleaned = InsertionCleaner::clean('<p>Hi</p><template data-teme-fixed-from="1"><script>alert(1)</script><img src=x onerror=alert(2)></template>')['html'];

    expect($cleaned)->toContain('<p>Hi</p>')
        ->not->toContain('template')
        ->not->toContain('alert')
        ->not->toContain('onerror');
});

it('strips every data-teme attribute from plugin markup, so only core\'s own placeholders carry them', function (): void {
    $cleaned = InsertionCleaner::clean('<div data-teme-region-min="1" data-teme-region-src="https://ads.example/x" data-teme-fixed="a" data-keep="1">Ad</div>')['html'];

    expect($cleaned)->not->toContain('data-teme')
        ->toContain('data-keep="1"')
        ->toContain('Ad');
});

it('draws the region placeholder and the fixed bars only inside core\'s own wrappers', function (): void {
    $region = view('teme::components.region', [
        'attributes' => new ComponentAttributeBag, 'name' => 'after-content', 'html' => '<p>Body</p>', 'minWidth' => 1440, 'source' => '/_teme/region', 'before' => '', 'after' => '', 'start' => '', 'end' => '', 'undeclared' => false,
    ])->render();
    $fixed = view('teme::components.fixed-insertions', ['fixed' => [[
        'key' => 'a.b', 'edge' => 'top', 'width' => 0, 'minWidth' => 0, 'showFrom' => 900, 'label' => 'Ad', 'name' => 'Ad, at the top of the screen', 'html' => '<i>x</i>',
    ]]])->render();

    expect($region)->toContain('<div data-teme-region-wrap><div class="teme-region')
        ->and($fixed)->toContain('<div data-teme-fixed-root style="display:contents">');
});
