<?php

declare(strict_types=1);

use App\Domain\Backups\RandomRecoveryKeys;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeyChallenge;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\RecoveryKeySource;
use Illuminate\Validation\ValidationException;

it('makes eight groups of four from Crockford’s base 32, different every time (I-2401)', function (): void {
    $one = RecoveryKey::generate();
    $other = RecoveryKey::generate();

    expect($one->value())->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){7}$/')
        ->and($one->groups())->toHaveCount(8)
        ->and($one->group(1))->toBe(substr($one->value(), 0, 4))
        ->and($one->group(8))->toBe(substr($one->value(), -4))
        ->and($one->value())->not->toBe($other->value());
});

it('reads a typed key whatever its case, spaces, hyphens and look-alike letters', function (): void {
    expect(RecoveryKey::fromTyped(' 7kqm 2vxd-9htr-4bnc wp6e-jf3s-ya8g-ozmk ')->value())->toBe('7KQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G-0ZMK')
        ->and(RecoveryKey::fromTyped('IIII-LLLL-oooo-0000-1111-2222-3333-4444')->value())->toBe('1111-1111-0000-0000-1111-2222-3333-4444')
        ->and(RecoveryKey::normalizeGroup(' wp6e '))->toBe('WP6E')
        ->and(RecoveryKey::normalizeGroup('WP6'))->toBe('')
        ->and(RecoveryKey::normalizeGroup('WP6U'))->toBe('');
});

it('refuses a key that isn’t 32 characters of its alphabet', function (string $typed): void {
    expect(fn () => RecoveryKey::fromTyped($typed))
        ->toThrow(ValidationException::class, 'A recovery key is eight groups of four letters and digits, separated by hyphens.');
})->with([
    'too short' => ['7KQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G'],
    'too long' => ['7KQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G-0ZMK-1'],
    'a U' => ['UKQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G-0ZMK'],
    'dots' => ['7KQM.2VXD.9HTR.4BNC.WP6E.JF3S.YA8G.0ZMK'],
    'empty' => [''],
]);

it('never serializes a recovery key, nor shows it when dumped', function (): void {
    $key = RecoveryKey::generate();

    ob_start();
    var_dump($key);
    $dumped = (string) ob_get_clean();

    expect(fn () => serialize($key))->toThrow(LogicException::class, 'A recovery key is never serialized.')
        ->and(fn () => serialize(['shown' => $key]))->toThrow(LogicException::class)
        ->and(print_r($key, true))->not->toContain($key->value())
        ->and($dumped)->not->toContain($key->value())
        ->and(json_encode($key))->toBe('{}');
});

it('asks for two different groups, from 1 to 8', function (): void {
    $source = app(RecoveryKeySource::class);

    expect($source)->toBeInstanceOf(RandomRecoveryKeys::class)
        ->and($source->make())->toBeInstanceOf(RecoveryKey::class);

    foreach (range(1, 50) as $ignored) {
        [$first, $second] = $source->positionsToAsk();

        expect($first)->toBeGreaterThanOrEqual(1)->toBeLessThan($second)
            ->and($second)->toBeLessThanOrEqual(8);
    }
});

it('matches only the two groups asked, and keeps no part of the key', function (): void {
    $key = RecoveryKey::fromTyped('7KQM-2VXD-9HTR-4BNC-WP6E-JF3S-YA8G-0ZMK');
    $challenge = RecoveryKeyChallenge::for(RecoveryKeyPurpose::SetUp, 'Recovery key', $key, [3, 7], ['01jbz6x4c7v0m2t5k8q9r3w1ab' => str_repeat('a', 64)]);
    $kept = json_encode($challenge->toArray(), JSON_THROW_ON_ERROR);

    expect($challenge->matches([3 => '9htr', 7 => ' ya8g ']))->toBeTrue()
        ->and($challenge->matches([3 => '9HTR', 7 => 'YA8H']))->toBeFalse()
        ->and($challenge->matches([3 => '9HTR']))->toBeFalse()
        ->and($challenge->matches([2 => '2VXD', 7 => 'YA8G']))->toBeFalse()
        ->and($challenge->digest)->toMatch('/^[0-9a-f]{64}$/');

    foreach ($key->groups() as $group) {
        expect($kept)->not->toContain($group);
    }
});

it('keeps its challenge as an array between requests, and refuses anything else', function (): void {
    $key = RecoveryKey::generate();
    $challenge = RecoveryKeyChallenge::for(RecoveryKeyPurpose::Replace, 'Held by Ada', $key, [2, 5], ['01jbz6x4c7v0m2t5k8q9r3w1ab' => str_repeat('b', 64)]);
    $back = RecoveryKeyChallenge::fromArray(json_decode(json_encode($challenge->toArray(), JSON_THROW_ON_ERROR), true));

    expect($back)->toEqual($challenge)
        ->and($back->matches([2 => $key->group(2), 5 => $key->group(5)]))->toBeTrue()
        // The digest covers the purpose, the label and the pending keys too.
        ->and(RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'label' => 'Held by Bea'])->matches([2 => $key->group(2), 5 => $key->group(5)]))->toBeFalse()
        ->and(RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'purpose' => 'add'])->matches([2 => $key->group(2), 5 => $key->group(5)]))->toBeFalse()
        ->and(RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'pending' => ['01jbz6x4c7v0m2t5k8q9r3w1ac' => str_repeat('b', 64)]])->matches([2 => $key->group(2), 5 => $key->group(5)]))->toBeFalse()
        ->and(fn () => RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'positions' => [5, 2]]))->toThrow(InvalidArgumentException::class, 'That isn’t a recovery key challenge.')
        ->and(fn () => RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'purpose' => 'erase']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'pending' => ['01jbz6x4c7v0m2t5k8q9r3w1ab' => 'not-an-id']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RecoveryKeyChallenge::fromArray([...$challenge->toArray(), 'digest' => 'short']))->toThrow(InvalidArgumentException::class);
});
