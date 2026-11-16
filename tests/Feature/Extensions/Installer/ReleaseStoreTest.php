<?php

declare(strict_types=1);

use App\Domain\Extensions\Installer\InstallerFailed;
use App\Domain\Extensions\Installer\ReleaseStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/teme-extensions-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/releases', 0755, true);
    $this->store = new ReleaseStore($this->root);
    $this->id = fn (): string => strtolower((string) Str::ulid());
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('points current at a release through a relative link, replaced in one rename', function (): void {
    $first = ($this->id)();
    $second = ($this->id)();
    $this->store->create($first);
    $this->store->create($second);

    $this->store->swap($first);
    expect(readlink($this->root.'/current'))->toBe("releases/{$first}")
        ->and($this->store->current())->toBe($first);

    $inode = fileinode($this->root.'/releases');
    $this->store->swap($second);

    expect(readlink($this->root.'/current'))->toBe("releases/{$second}")
        ->and($this->store->previous())->toBe($first)
        ->and(glob($this->root.'/current.*'))->toBe([])
        ->and(fileinode($this->root.'/releases'))->toBe($inode);
});

it('leaves the old link when a swap fails', function (): void {
    $first = ($this->id)();
    $this->store->create($first);
    $this->store->swap($first);

    expect(fn () => $this->store->swap(($this->id)()))->toThrow(InstallerFailed::class);
    expect($this->store->current())->toBe($first);

    chmod($this->root, 0555);

    try {
        $second = ($this->id)();
        mkdir($this->root.'/releases/'.$second);
        expect(fn () => $this->store->swap($second))->toThrow(InstallerFailed::class, "Couldn't switch");
    } finally {
        chmod($this->root, 0755);
    }

    expect($this->store->current())->toBe($first)
        ->and(glob($this->root.'/current.*'))->toBe([]);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes a read-only folder');

it('prunes to the current release and the two before it, never the current one or the one before', function (): void {
    $ids = [];

    foreach (range(1, 5) as $ignored) {
        $ids[] = $id = ($this->id)();
        $this->store->create($id);
        usleep(2000);
    }

    $this->store->swap($ids[3]);

    // The current release, the two before it, and the newer one that never went live.
    expect($this->store->prune())->toBe([$ids[0]])
        ->and($this->store->all())->toBe([$ids[1], $ids[2], $ids[3], $ids[4]])
        ->and($this->store->prune(1))->toBe([$ids[1]])
        ->and($this->store->all())->toBe([$ids[2], $ids[3], $ids[4]]);
});

it('deletes a release that never went live, never the current one', function (): void {
    $live = ($this->id)();
    $staged = ($this->id)();
    $this->store->create($live);
    $this->store->create($staged);
    $this->store->swap($live);

    $this->store->delete($staged);

    expect(is_dir($this->root.'/releases/'.$staged))->toBeFalse()
        ->and(fn () => $this->store->delete($live))->toThrow(InstallerFailed::class);
});

it('refuses an id that is not a lowercase ULID', function (string $id): void {
    expect(fn () => $this->store->create($id))->toThrow(InvalidArgumentException::class);
})->with(['../escape', (string) Str::ulid(), 'current', '']);
