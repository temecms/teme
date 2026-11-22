<?php

declare(strict_types=1);

use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    Storage::disk('local')->deleteDirectory('imports');
    Storage::disk('local')->makeDirectory('imports');
    $this->wxr = (string) file_get_contents(base_path('tests/Fixtures/Import/wxr/small.xml'));
});

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('imports');
});

it('uploads an export file in pieces through the admin routes', function (): void {
    Queue::fake();

    $start = $this->actingAs($this->admin)
        ->postJson('/admin/tools/import/files/start', ['name' => 'small.xml', 'size' => strlen($this->wxr)])
        ->assertOk()
        ->json();

    expect($start['fileId'])->toBeString()
        ->and($start['carriedOn'])->toBeFalse();

    $this->actingAs($this->admin)
        ->call(
            'POST',
            "/admin/tools/import/files/{$start['fileId']}/pieces",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/octet-stream',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_UPLOAD_OFFSET' => '0',
            ],
            $this->wxr,
        )
        ->assertOk()
        ->assertJson(['receivedBytes' => strlen($this->wxr)]);

    $this->actingAs($this->admin)
        ->postJson("/admin/tools/import/files/{$start['fileId']}/finish")
        ->assertOk()
        ->assertJson(['refused' => false, 'fileId' => $start['fileId']]);

    expect(ImportFile::query()->findOrFail($start['fileId'])->state)->toBe('reading');
    Queue::assertPushed(ReadImportFile::class);

    $this->actingAs($this->admin)
        ->getJson("/admin/tools/import/files/{$start['fileId']}")
        ->assertOk()
        ->assertJsonPath('state', 'reading');
});

it('refuses the upload routes for anyone who may not import', function (): void {
    $this->actingAs($this->editor)
        ->postJson('/admin/tools/import/files/start', ['name' => 'small.xml', 'size' => 10])
        ->assertForbidden();
});
