<?php

declare(strict_types=1);

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ManageApi\ManageApiFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->person = User::factory()->create(['name' => 'Ada Writer'])->assignRole('author');
    ManageApiFixture::on();
    $this->secret = ManageApiFixture::key($this->person, ['media.write']);
});

it('stores a file sent as multipart, credited to the key’s person', function (): void {
    $file = new UploadedFile(mediaPdf(), 'Annual report.pdf', 'application/pdf', null, true);

    $response = $this->post('/api/manage/v1/media', ['file' => $file], ManageApiFixture::headers($this->secret));

    $response->assertStatus(201)
        ->assertJsonPath('data.title', 'Annual report')
        ->assertJsonPath('data.mime_type', 'application/pdf');

    $row = DB::table('media')->first();

    expect((int) $row->uploaded_by)->toBe($this->person->id)
        ->and($response->json('data.id'))->toBe($row->id)
        ->and($response->json('data.url'))->toStartWith(rtrim(url('/'), '/').'/')
        ->and(Storage::disk('media')->exists($row->original_path))->toBeTrue();
});

it('stores a file sent as the raw body, named by Content-Disposition', function (): void {
    $response = $this->call('POST', '/api/manage/v1/media', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->secret,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'image/jpeg',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="Sunset over the bay.jpg"',
    ], (string) file_get_contents(mediaJpeg()));

    $response->assertStatus(201)->assertJsonPath('data.title', 'Sunset over the bay');

    expect(DB::table('media')->value('original_filename'))->toBe('Sunset over the bay.jpg')
        ->and((int) DB::table('media')->value('uploaded_by'))->toBe($this->person->id);
});

it('refuses a raw body without a file name, a type UploadMedia refuses and a file over the limit, storing nothing', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    scElevated(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('media', ['max_upload_mb' => 1], [], [], 0, (string) $admin->id)));
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret, 'HTTP_ACCEPT' => 'application/json'];

    $this->call('POST', '/api/manage/v1/media', [], [], [], $headers, (string) file_get_contents(mediaJpeg()))
        ->assertStatus(422)->assertJsonPath('code', 'invalid_parameter');

    $text = new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'teme'), fn (string $path) => file_put_contents($path, '<?php echo "hello";')), 'shell.jpg', 'image/jpeg', null, true);
    $this->post('/api/manage/v1/media', ['file' => $text], ManageApiFixture::headers($this->secret))
        ->assertStatus(422)->assertJsonPath('errors.0.parameter', 'file');

    $big = tempnam(sys_get_temp_dir(), 'teme');
    file_put_contents($big, "%PDF-1.4\n".str_repeat('0', 2 * 1024 * 1024)."\n%%EOF\n");
    $this->post('/api/manage/v1/media', ['file' => new UploadedFile($big, 'big.pdf', 'application/pdf', null, true)], ManageApiFixture::headers($this->secret))
        ->assertStatus(422)->assertJsonPath('errors.0.parameter', 'file');

    expect(DB::table('media')->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(Storage::disk('media-originals')->allFiles())->toBe([]);
});

it('changes alt text, caption, title and credit through UpdateMediaDetails, keeping what isn’t sent', function (): void {
    $id = $this->post('/api/manage/v1/media', ['file' => new UploadedFile(mediaJpeg(), 'Dock.jpg', 'image/jpeg', null, true)], ManageApiFixture::headers($this->secret))->json('data.id');

    $this->patchJson("/api/manage/v1/media/{$id}", ['alt' => 'Boats at the dock', 'caption' => 'Early morning'], ManageApiFixture::headers($this->secret))
        ->assertOk()
        ->assertJsonPath('data.alt', 'Boats at the dock')
        ->assertJsonPath('data.caption', 'Early morning')
        ->assertJsonPath('data.title', 'Dock');

    $this->patchJson("/api/manage/v1/media/{$id}", ['credit' => 'A. Writer'], ManageApiFixture::headers($this->secret))
        ->assertOk()
        ->assertJsonPath('data.alt', 'Boats at the dock')
        ->assertJsonPath('data.credit', 'A. Writer');

    $this->patchJson("/api/manage/v1/media/{$id}", ['alt' => str_repeat('a', 1001)], ManageApiFixture::headers($this->secret))
        ->assertStatus(422)->assertJsonPath('errors.0.parameter', 'alt');

    $this->patchJson('/api/manage/v1/media/01aaaaaaaaaaaaaaaaaaaaaaaa', ['alt' => 'x'], ManageApiFixture::headers($this->secret))
        ->assertStatus(404)->assertJsonPath('code', 'not_found');
});

it('needs media.write for both', function (): void {
    $secret = ManageApiFixture::key($this->person, ['site.read'], name: 'Read only');
    $file = new UploadedFile(mediaPdf(), 'a.pdf', 'application/pdf', null, true);

    $this->post('/api/manage/v1/media', ['file' => $file], ManageApiFixture::headers($secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->patchJson('/api/manage/v1/media/01aaaaaaaaaaaaaaaaaaaaaaaa', ['alt' => 'x'], ManageApiFixture::headers($secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');

    expect(DB::table('media')->count())->toBe(0);
});

it('lets another person’s media be changed only as the key’s person may', function (): void {
    $other = User::factory()->create()->assignRole('author');
    $theirs = mediaUpload($other, mediaJpeg(), 'Theirs.jpg');

    $this->patchJson("/api/manage/v1/media/{$theirs->id}", ['alt' => 'Mine now'], ManageApiFixture::headers($this->secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');

    expect(DB::table('media')->where('id', $theirs->id)->value('alt'))->toBeNull();

    $editor = User::factory()->create()->assignRole('editor');
    $editorKey = ManageApiFixture::key($editor, ['media.write'], name: 'Editor');

    $this->patchJson("/api/manage/v1/media/{$theirs->id}", ['alt' => 'Fixed by an editor'], ManageApiFixture::headers($editorKey))
        ->assertOk();
});

it('refuses an upload for a person who may not upload, storing nothing', function (): void {
    $reader = User::factory()->create();
    $secret = ManageApiFixture::key($reader, ['media.write'], name: 'Reader');

    $this->post('/api/manage/v1/media', ['file' => new UploadedFile(mediaPdf(), 'a.pdf', 'application/pdf', null, true)], ManageApiFixture::headers($secret))
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');

    expect(DB::table('media')->count())->toBe(0);
});
