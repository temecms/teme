<?php

/**
 * Tables in the default theme's article body have a header band, row rules,
 * padding and a caption, and scroll sideways on a phone instead of widening
 * the page.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    $editor = User::factory()->create()->assignRole('editor');
    $cell = fn (string $type, string $text): array => [
        'type' => $type,
        'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => [180], 'align' => null],
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
    ];

    pageArticleFromDocument($editor, 'Table check', [
        [
            'type' => 'table',
            'content' => [
                ['type' => 'tableRow', 'content' => [
                    $cell('tableHeader', 'Route'),
                    $cell('tableHeader', 'Departs'),
                    $cell('tableHeader', 'Arrives'),
                    $cell('tableHeader', 'Notes from the harbour master'),
                ]],
                ['type' => 'tableRow', 'content' => [
                    $cell('tableCell', 'North quay'),
                    $cell('tableCell', '06:15'),
                    $cell('tableCell', '07:40'),
                    $cell('tableCell', 'Crowded on market days'),
                ]],
                ['type' => 'tableRow', 'content' => [
                    $cell('tableCell', 'South pier'),
                    $cell('tableCell', '08:00'),
                    $cell('tableCell', '09:25'),
                    $cell('tableCell', 'Weather permitting'),
                ]],
            ],
        ],
    ]);
});

it('styles a table and scrolls it sideways on a phone without widening the page', function (): void {
    $page = visit('/table-check/')->resize(375, 812);

    $page->assertSee('harbour master')
        ->assertScript("(() => { const th = document.querySelector('.prose th'); const td = document.querySelector('.prose td'); const thStyle = getComputedStyle(th); const tdStyle = getComputedStyle(td); return parseFloat(thStyle.paddingInlineStart) >= 8 && parseFloat(tdStyle.paddingInlineStart) >= 8 && thStyle.backgroundColor !== 'rgba(0, 0, 0, 0)' && (tdStyle.borderBottomWidth !== '0px' || thStyle.borderBottomWidth !== '0px'); })()")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("(() => { const table = document.querySelector('.prose table'); const scroller = table.closest('.table-scroll') ?? table; return scroller.scrollWidth > scroller.clientWidth; })()");
});
