<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->upload = fn (?UploadedFile $file, ?User $actor = null) => $this->actingAs($actor ?? $this->editor)->from('/admin/tools/redirects')
        ->post('/admin/tools/redirects/import', $file === null ? [] : ['file' => $file]);
});

it('imports a file whole, and says how many it added (I-872)', function (): void {
    ($this->upload)(UploadedFile::fake()->createWithContent('redirects.csv', "from,to,status,note\n/old-fares,/news/,301,\n/withdrawn,,410,\n"))
        ->assertRedirect('/admin/tools/redirects')
        ->assertInertiaFlash('toast.message', '2 redirects imported, marked Imported.')
        ->assertSessionMissing('redirects.imported');

    expect(DB::table('redirects')->where('origin', 'import')->orderBy('source')->pluck('source')->all())->toBe(['old-fares', 'withdrawn']);
});

it('keeps the report of the lines it left out, for the screen to show once', function (): void {
    $report = ['added' => 1, 'refused' => [['line' => 2, 'reason' => 'Line 2: 404 isn’t a status a redirect can have.']]];

    ($this->upload)(UploadedFile::fake()->createWithContent('redirects.csv', "/old-fares,/news/\n/bad,/news/,404\n"))
        ->assertRedirect('/admin/tools/redirects')
        ->assertInertiaFlashMissing('toast')
        ->assertSessionHas('redirects.imported', $report);

    $shown = fn (): mixed => $this->actingAs($this->editor)->get('/admin/tools/redirects')->assertOk()->viewData('page')['props']['imported'];

    expect($shown())->toBe($report)
        ->and($shown())->toBeNull();
});

it('refuses a file it can\'t take, under the field the dialog shows, and adds nothing', function (string $case, string $field, string $words): void {
    $file = match ($case) {
        'no file' => null,
        'too large' => UploadedFile::fake()->create('redirects.csv', 2049, 'text/csv'),
        default => UploadedFile::fake()->createWithContent('redirects.csv', "from,to\n/caf\xE9,/news/\n"),
    };

    ($this->upload)($file)->assertRedirect('/admin/tools/redirects')->assertSessionHasErrors([$field => $words]);

    expect(DB::table('redirects')->count())->toBe(0);
})->with([
    'no file' => ['no file', 'file', 'Choose a CSV file to import.'],
    'too large' => ['too large', 'file', 'Choose a file of 2 MB or less, or split it into parts.'],
    'not UTF-8' => ['not UTF-8', 'csv', 'The file isn’t in UTF-8. Save it as CSV UTF-8 and import it again.'],
]);

it('downloads every redirect as CSV, a byte-order mark first, so a spreadsheet reads it as UTF-8 (I-872)', function (): void {
    rdSaved('/old-fares', '/news/');

    $response = $this->actingAs($this->editor)->get('/admin/tools/redirects/export/csv')->assertOk()->assertDownload('redirects.csv');

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->streamedContent())->toBe("\xEF\xBB\xBFfrom,to,status,note,on\n/old-fares,/news/,301,,yes\n");
});

it('downloads the nginx rules for the redirects that are on (I-872)', function (): void {
    rdSaved('/old-fares', '/news/');
    rdSaved('/switched-off', '/news/', on: false);

    $response = $this->actingAs($this->editor)->get('/admin/tools/redirects/export/nginx')->assertOk()->assertDownload('redirects-nginx.conf');
    $lines = explode("\n", rtrim((string) $response->streamedContent(), "\n"));

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($lines[0])->toStartWith('# Redirects from localhost, exported by Teme on ')
        ->and(array_slice($lines, 2))->toBe(['location ~* "^/old-fares/?$" { return 301 "/news/"; }']);
});

it('takes no file and gives none to an author or a contributor (authorization)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    ($this->upload)(UploadedFile::fake()->createWithContent('redirects.csv', "/old-fares,/news/\n"), $person)->assertForbidden();
    $this->actingAs($person)->get('/admin/tools/redirects/export/csv')->assertForbidden();

    expect(DB::table('redirects')->count())->toBe(0);
})->with(['author', 'contributor']);
