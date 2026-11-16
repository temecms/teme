<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\Storage\FakeBucket;
use Tests\Feature\Media\Storage\StorageEnvironment;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->photo = mediaUpload($this->editor, mediaJpeg(), 'Harbour at dawn.jpg');
    $this->report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
        $this->logged[] = $logged->message.' '.json_encode($logged->context);
    });
});

it('redirects an editor to a five-minute signed address for an original in the bucket, never stored or logged', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        // Signing is local: nothing reaches the bucket.
        $buckets = [FakeBucket::on('media-bucket'), FakeBucket::on('media-originals-bucket')];
        $response = $this->actingAs($this->editor)->get(route('media.original', ['media' => $this->photo->id]));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect(parse_url($location, PHP_URL_HOST))->toBe('teme-originals.objects.example.test')
            ->and(parse_url($location, PHP_URL_PATH))->toBe('/'.$this->photo->original_path)
            ->and($query['X-Amz-Expires'])->toBe('300')
            ->and($query)->toHaveKey('X-Amz-Signature')
            ->and($query['response-content-disposition'])->toBe('attachment; filename="'.$this->photo->name.'.'.$this->photo->extension.'"')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and(implode("\n", $this->logged))->not->toContain('X-Amz-Signature')->not->toContain($this->photo->original_path)
            ->and([...$buckets[0]->sent, ...$buckets[1]->sent])->toBe([]);
    });
});

it('redirects a file that isn’t an image the same way, from the public bucket', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        FakeBucket::on('media-bucket');
        $response = $this->actingAs($this->editor)->get(route('media.original', ['media' => $this->report->id]));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect(parse_url($location, PHP_URL_HOST))->toBe('teme-media.objects.example.test')
            ->and($query['X-Amz-Expires'])->toBe('300')
            ->and($query['response-content-disposition'])->toBe('attachment; filename="annual-report.pdf"');
    });
});

it('streams an original on the server’s disks as an attachment, as before', function (): void {
    $response = $this->actingAs($this->editor)->get(route('media.original', ['media' => $this->photo->id]));

    $response->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->streamedContent())->toBe(Storage::disk('media-originals')->get($this->photo->original_path));
});

it('refuses someone without media.upload, and answers an unknown ID with 404', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(), function (): void {
        $contributor = User::factory()->create()->assignRole('contributor');

        $refused = $this->actingAs($contributor)->get(route('media.original', ['media' => $this->photo->id]));
        $refused->assertForbidden();

        expect($refused->headers->get('Location'))->toBeNull();

        $this->actingAs($this->editor)->get(route('media.original', ['media' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    });
});
