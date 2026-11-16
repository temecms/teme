<?php

declare(strict_types=1);

use App\Domain\Extensions\Operations\AgreeToExtensionRequest;
use App\Domain\Extensions\Operations\CancelExtensionRequest;
use App\Domain\Extensions\Operations\Input\AgreeToExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\CancelExtensionRequestInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = extAdmin();
    $this->needs = ['files' => [['package' => 'acme/helpers', 'files' => ['src/helpers.php']]], 'composerPlugins' => ['acme/installer-plugin']];
    $this->cancel = fn (string $id, ?string $actor = null) => fn () => app(CancelExtensionRequest::class)->execute(new CancelExtensionRequestInput($id, $actor ?? (string) $this->admin->id));
    $this->agree = fn (string $id, array $needs, ?string $actor = null) => fn () => app(AgreeToExtensionRequest::class)->execute(new AgreeToExtensionRequestInput($id, $needs, $actor ?? (string) $this->admin->id));
});

it('cancels a request the installer hasn’t begun, or one waiting for agreement, saying who and when', function (string $status): void {
    $id = extRequestRow($status);

    (($this->cancel)($id))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('cancelled')
        ->and((int) $row->cancelled_by)->toBe($this->admin->id)
        ->and($row->finished_at)->not->toBeNull();
})->with(['requested', 'waiting']);

it('can’t cancel a running request, or one that has ended, and writes nothing', function (string $status, string $message): void {
    $id = extRequestRow($status);

    expect(($this->cancel)($id))->toThrow(ValidationException::class, $message);
    expect(DB::table('extension_requests')->where('id', $id)->value('status'))->toBe($status)
        ->and(DB::table('extension_requests')->where('id', $id)->value('cancelled_by'))->toBeNull();
})->with([
    ['running', 'The installer is carrying out this request, so it can’t be cancelled now. It ends on its own.'],
    ['done', 'This request has already ended.'],
    ['failed', 'This request has already ended.'],
    ['cancelled', 'This request has already ended.'],
]);

it('deletes the request’s upload when it is cancelled', function (): void {
    $upload = strtolower((string) Str::ulid());
    DB::statement("INSERT INTO extension_uploads (id, file_name, size, sha256, package, version, bytes, created_at) VALUES (?, 'hello.zip', 4, ?, 'acme/hello', '1.0.0', decode('UEsDBA==', 'base64'), now())", [$upload, str_repeat('a', 64)]);
    $id = extRequestRow('requested', ['source_kind' => 'upload', 'upload_id' => $upload]);

    (($this->cancel)($id))();

    expect(DB::table('extension_uploads')->count())->toBe(0);
});

it('agrees to exactly what was shown, saying who', function (): void {
    $id = extRequestRow('waiting', ['step' => 'validating', 'needs' => json_encode($this->needs)]);

    // The same list, its keys in another order, as the browser may send it back.
    (($this->agree)($id, array_reverse($this->needs, true)))();
    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect(json_decode((string) $row->agreed, true))->toEqual($this->needs)
        ->and((int) $row->agreed_by)->toBe($this->admin->id)
        ->and($row->status)->toBe('waiting');
});

it('refuses to agree when what the installer found has changed, and writes nothing', function (): void {
    $id = extRequestRow('waiting', ['step' => 'validating', 'needs' => json_encode($this->needs)]);

    expect(($this->agree)($id, ['files' => $this->needs['files']]))->toThrow(ValidationException::class, 'What the installer found has changed. Look again.');
    expect(DB::table('extension_requests')->where('id', $id)->value('agreed'))->toBe('{}')
        ->and(DB::table('extension_requests')->where('id', $id)->value('agreed_by'))->toBeNull();
});

it('agrees only to a request that waits for it', function (string $status): void {
    $id = extRequestRow($status, ['needs' => json_encode($this->needs)]);

    expect(($this->agree)($id, $this->needs))->toThrow(ValidationException::class, 'This request isn’t waiting for your agreement.');
    expect(DB::table('extension_requests')->where('id', $id)->value('agreed'))->toBe('{}');
})->with(['requested', 'running', 'done', 'failed', 'cancelled']);

it('writes nothing when the person may not install plugins and themes', function (string $role): void {
    $person = (string) User::factory()->create()->assignRole($role)->id;
    $waiting = extRequestRow('waiting', ['needs' => json_encode($this->needs)]);

    expect(($this->cancel)($waiting, $person))->toThrow(AuthorizationException::class)
        ->and(($this->agree)($waiting, $this->needs, $person))->toThrow(AuthorizationException::class);
    expect(DB::table('extension_requests')->where('id', $waiting)->first())
        ->status->toBe('waiting')
        ->agreed->toBe('{}');
})->with(['editor', 'author', 'contributor']);

it('asks for the password again, and writes nothing without it', function (): void {
    $waiting = extRequestRow('waiting', ['needs' => json_encode($this->needs)]);
    app()->instance(Elevation::class, new DeniedElevation);

    expect(($this->cancel)($waiting))->toThrow(ElevationRequiredException::class)
        ->and(($this->agree)($waiting, $this->needs))->toThrow(ElevationRequiredException::class);
    expect(DB::table('extension_requests')->where('id', $waiting)->first())
        ->status->toBe('waiting')
        ->agreed->toBe('{}');
});
