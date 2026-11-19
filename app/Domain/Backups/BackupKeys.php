<?php

/**
 * The keys that open the places backups are kept (I-359): the machine key,
 * one for the site, made once and kept as the backups group's secret unless
 * the server supplies it; and, at each place, restic's own keys, added
 * through restic's standard input, removed by their IDs, and swept of the
 * keys Teme added and gave up on.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

final readonly class BackupKeys
{
    public const string MACHINE_LABEL = 'Machine key';

    public function __construct(
        private Restic $restic,
        private SecretStore $secrets,
        private SettingsWriter $writer,
    ) {}

    /**
     * The machine key: the server's TEME_BACKUP_MACHINE_KEY, else the stored one.
     *
     * @throws ValidationException
     */
    public function machineKey(): string
    {
        return $this->secrets->secret(BackupSettings::GROUP, 'machine_key')
            ?? throw ValidationException::withMessages(['place' => 'Backups aren’t set up: there’s no machine key.']);
    }

    /**
     * The machine key, made and stored first if there is none. Called inside
     * an Operation's transaction, so a setup that fails keeps none.
     */
    public function ensureMachineKey(?string $actorId): string
    {
        $key = $this->secrets->secret(BackupSettings::GROUP, 'machine_key');

        if ($key !== null) {
            return $key;
        }

        $key = self::newMachineKey();
        $this->writer->write(new SettingsWrite(group: BackupSettings::GROUP, values: [], secrets: ['machine_key' => $key], actorId: $actorId));

        return $key;
    }

    /**
     * The IDs of the keys that open $repository, and which of them opened it this time.
     *
     * @return array{current: string, ids: list<string>}
     */
    public function keyIds(ResticRepository $repository, string $place): array
    {
        return $this->parsed($this->restic->run($repository, ['key', 'list'], timeout: PlaceRepositories::ANSWER_SECONDS), $place);
    }

    /**
     * The restic ID of the key $repository is opened with, or null when that
     * key doesn't open it: a recovery key being checked, or one typed to add
     * a place (I-2402). Any other failure is the place's.
     */
    public function opensWith(ResticRepository $repository, string $place): ?string
    {
        $listed = $this->restic->run($repository, ['key', 'list'], timeout: PlaceRepositories::ANSWER_SECONDS);

        return $listed->outcome === ResticOutcome::WrongKey ? null : $this->parsed($listed, $place)['current'];
    }

    /**
     * @return array{current: string, ids: list<string>}
     */
    private function parsed(ResticResult $listed, string $place): array
    {
        $keys = $listed->json();

        if (! $listed->succeeded() || ! is_array($keys)) {
            throw PlaceFailure::from($listed, $place, 'reading the keys');
        }

        $current = null;
        $ids = [];

        foreach ($keys as $key) {
            $id = is_array($key) ? ($key['id'] ?? null) : null;

            if (is_string($id) && preg_match('/^[0-9a-f]{64}$/', $id) === 1) {
                $ids[] = $id;
                $current = ($key['current'] ?? false) === true ? $id : $current;
            }
        }

        if ($current === null) {
            throw PlaceFailure::from($listed, $place, 'reading the keys');
        }

        return ['current' => $current, 'ids' => $ids];
    }

    /**
     * Adds $newKey to the repository $openedWith opens, through restic's
     * standard input, never its command line, and returns its restic ID.
     */
    public function add(ResticRepository $openedWith, #[SensitiveParameter] string $newKey, string $place): string
    {
        $before = $this->keyIds($openedWith, $place)['ids'];
        // teme as this key's user and host; init's own key, the machine key, records the server's.
        $added = $this->restic->run($openedWith, ['key', 'add', '--user', 'teme', '--host', 'teme'], input: $newKey."\n", timeout: PlaceRepositories::ANSWER_SECONDS);

        if (! $added->succeeded()) {
            throw PlaceFailure::from($added, $place, 'adding a key');
        }

        // restic prints key add's answer as plain text, even with --json: read it from the list.
        $new = array_values(array_diff($this->keyIds($openedWith, $place)['ids'], $before));

        if (count($new) !== 1) {
            throw new RuntimeException("restic added a key at {$place}, but Teme couldn’t tell which.");
        }

        return $new[0];
    }

    public function remove(ResticRepository $openedWith, string $resticKeyId, string $place): void
    {
        $removed = $this->restic->run($openedWith, ['key', 'remove', $resticKeyId], timeout: PlaceRepositories::ANSWER_SECONDS);

        if (! $removed->succeeded()) {
            throw PlaceFailure::from($removed, $place, 'removing a key');
        }
    }

    /**
     * Removes from $place each key whose backup_keys row there is marked
     * removed: one shown and never typed back, or one replaced whose removal
     * failed. A key with no row is never touched, since the database isn't
     * the whole truth about a repository's keys (I-359). Returns how many.
     */
    public function removeAbandoned(BackupDestination $place, ResticRepository $openedWith): int
    {
        $keys = $this->keyIds($openedWith, $place->name);
        $givenUp = $place->keys()->whereNotNull('removed_at')->pluck('restic_key_id')->all();
        $removed = 0;

        foreach (array_diff(array_intersect($keys['ids'], $givenUp), [$keys['current']]) as $id) {
            $this->remove($openedWith, $id, $place->name);
            $removed++;
        }

        return $removed;
    }

    /**
     * A new machine key: 256 random bits as 64 hexadecimal characters.
     */
    public static function newMachineKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
