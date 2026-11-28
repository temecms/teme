<?php

/**
 * On a phone the default theme keeps a long word, a long address and a long
 * code line from widening the page: words break, the code block scrolls inside.
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
    $longWord = str_repeat('supercalifragilisticexpialidocious', 4);
    $longAddress = 'https://example.com/'.str_repeat('harbour-tide-tables-and-ferry-timetables/', 6).'week';
    $longCode = 'echo "'.str_repeat('abcdefghijklmnopqrstuvwxyz', 12).'";';

    pageArticleFromDocument($editor, 'Overflow check', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "A long word: {$longWord}"]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "See {$longAddress} for the tables."]]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Inline '],
            ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'overflow-wrap'],
            ['type' => 'text', 'text' => ' and a rule below.'],
        ]],
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [
            ['type' => 'text', 'text' => $longCode],
        ]],
    ]);
});

it('keeps a long word and a long address from widening the page on a phone', function (): void {
    visit('/overflow-check/')->resize(375, 812)
        ->assertSee('supercalifragilisticexpialidocious')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("getComputedStyle(document.querySelector('.prose')).overflowWrap", 'break-word')
        ->assertScript("(() => { const code = document.querySelector('.prose p code'); const style = getComputedStyle(code); return style.backgroundColor !== 'rgba(0, 0, 0, 0)' && style.fontFamily.length > 0; })()")
        ->assertScript("(() => { const hr = document.querySelector('.prose hr'); return hr !== null && getComputedStyle(hr).backgroundColor !== 'rgba(0, 0, 0, 0)'; })()");
});

it('scrolls a long code line inside its block without widening the page', function (): void {
    visit('/overflow-check/')->resize(375, 812)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("(() => { const pre = document.querySelector('.prose pre'); const style = getComputedStyle(pre); return style.overflowX === 'auto' && pre.scrollWidth > pre.clientWidth; })()");
});
