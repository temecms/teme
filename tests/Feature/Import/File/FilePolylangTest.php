<?php

declare(strict_types=1);

use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The fixture file with Polylang's languages and a translation group in its channel.
 */
function fpPolylang(): string
{
    $terms = implode("\n\t", [
        '<wp:term><wp:term_id>901</wp:term_id><wp:term_taxonomy>language</wp:term_taxonomy><wp:term_slug><![CDATA[en]]></wp:term_slug><wp:term_parent><![CDATA[]]></wp:term_parent><wp:term_name><![CDATA[English]]></wp:term_name></wp:term>',
        '<wp:term><wp:term_id>902</wp:term_id><wp:term_taxonomy>language</wp:term_taxonomy><wp:term_slug><![CDATA[hi]]></wp:term_slug><wp:term_parent><![CDATA[]]></wp:term_parent><wp:term_name><![CDATA[हिन्दी]]></wp:term_name></wp:term>',
        '<wp:term><wp:term_id>903</wp:term_id><wp:term_taxonomy>post_translations</wp:term_taxonomy><wp:term_slug><![CDATA[pll_5f1]]></wp:term_slug><wp:term_parent><![CDATA[]]></wp:term_parent><wp:term_name><![CDATA[pll_5f1]]></wp:term_name></wp:term>',
    ]);

    return (string) preg_replace('~(\s*<generator>)~', "\n\t{$terms}".'$1', (string) file_get_contents(ExportFiles::path('companion-site.xml')), 1);
}

/**
 * Whether the file's plan says its languages don't come across.
 */
function fpSaysLanguages(string $bytes, User $admin): bool
{
    $file = FileImports::ready('companion-site.xml', $admin, $bytes);

    return app(ImportChoices::class)->fileForm(app(ImportAnalysis::class)->read((string) $file->source_id), $file->id, (string) $admin->id, [])['polylang'];
}

it('tells the plan when the file holds Polylang’s languages', function (): void {
    expect(fpSaysLanguages(fpPolylang(), $this->admin))->toBeTrue();
});

it('says nothing of languages for a file without them', function (): void {
    expect(fpSaysLanguages((string) file_get_contents(ExportFiles::path('companion-site.xml')), $this->admin))->toBeFalse();
});
