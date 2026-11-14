<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports that never happened, written straight into the import tables as
 * the phases will write them (I-345), so the report can be drawn and tested
 * before the phases land: for tests, and by hand through tinker.
 */
final class ImportRecords
{
    /** The address every seeded site has, so forget() finds them. */
    public const string ADDRESS = 'https://old.example';

    /**
     * A site, connected (its application password encrypted, as the model's
     * cast writes it) unless $changes say otherwise; its ID.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function source(array $changes = []): string
    {
        $id = self::id();

        DB::table('import_sources')->insert(array_replace([
            'id' => $id,
            'address' => self::ADDRESS,
            'api_root' => self::ADDRESS.'/wp-json/',
            'user_name' => 'ada',
            'credential' => Crypt::encryptString('abcd EFGH 1234 ijkl MNOP 5678'),
            'plugin_version' => '0.2.0',
            'api_version' => 1,
            'probed_at' => now()->subDay(),
            'forgotten_at' => null,
            'created_by' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ], $changes));

        return $id;
    }

    /**
     * A run of the site, a finished full import unless $changes say
     * otherwise (a running one needs a phase_token and no ended_at; a
     * stopped one a stop_reason); its ID.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function run(string $sourceId, array $changes = []): string
    {
        $id = self::id();

        DB::table('import_runs')->insert(array_replace([
            'id' => $id,
            'source_id' => $sourceId,
            'kind' => 'full',
            'state' => 'finished',
            'phase' => null,
            'phase_token' => null,
            'options' => '{"pause_ms": 1000}',
            'choices' => '{}',
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'warning_count' => 0,
            'high_water_at' => now()->subHours(2),
            'stop_reason' => null,
            'stop_message' => null,
            'started_by' => null,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHour(),
        ], $changes));

        return $id;
    }

    /**
     * One object's map row, its status from its outcome as
     * ImportOutcome::status() gives it; its ID.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function entry(string $sourceId, string $runId, string $type, string $objectId, string $outcome, array $changes = []): string
    {
        $id = self::id();
        $imported = in_array($outcome, ['created', 'updated'], true);

        DB::table('import_map')->insert(array_replace([
            'id' => $id,
            'source_id' => $sourceId,
            'object_type' => $type,
            'object_id' => $objectId,
            'teme_type' => $imported ? 'content' : null,
            'teme_id' => $imported ? self::id() : null,
            'status' => $imported ? 'imported' : $outcome,
            'outcome' => $outcome,
            'source_hash' => hash('sha256', $type.':'.$objectId),
            'facts' => '{}',
            'run_id' => $runId,
            'imported_at' => now()->subHour(),
        ], $changes));

        return $id;
    }

    /**
     * One message of the run, tied to an object's map row or to the run
     * alone; its ID. $at orders messages written in one test.
     */
    public static function message(string $runId, ?string $mapId, string $code, string $words, string $level = 'warning', int $at = 0): string
    {
        $id = self::id();

        DB::table('import_messages')->insert([
            'id' => $id,
            'run_id' => $runId,
            'map_id' => $mapId,
            'level' => $level,
            'code' => $code,
            'message' => $words,
            'context' => '{}',
            'created_at' => now()->subHour()->addSeconds($at),
        ]);

        return $id;
    }

    /**
     * A finished full import of old.example, as the phases will leave one:
     * people, terms, media, posts and pages, comments, a redirect and a
     * file's address; a subscriber skipped, a download and a post failed,
     * two warnings on objects and two messages of the run's own. Its counts
     * are its rows' (11 created, 1 updated, 1 skipped, 2 failed, 3 warnings).
     *
     * @return array{source: string, run: string}
     */
    public static function finishedImport(?int $by = null): array
    {
        $source = self::source(['created_by' => $by]);
        $run = self::run($source, [
            'started_by' => $by,
            'created_count' => 11,
            'updated_count' => 1,
            'skipped_count' => 1,
            'failed_count' => 2,
            'warning_count' => 3,
        ]);

        foreach ([['user', '1', 'created'], ['user', '2', 'created'], ['term', '3', 'created'], ['term', '4', 'created'], ['attachment', '10', 'created'], ['post', '20', 'created'], ['comment', '30', 'created'], ['comment', '31', 'created'], ['redirect', 'redirection:5', 'created'], ['file', 'uploads/2024/05/harbour.jpg', 'created'], ['post', '22', 'updated']] as [$type, $id, $outcome]) {
            self::entry($source, $run, $type, $id, $outcome);
        }

        $subscriber = self::entry($source, $run, 'user', '9', 'skipped');
        $shortcode = self::entry($source, $run, 'post', '21', 'created');
        $download = self::entry($source, $run, 'attachment', '12', 'failed');
        $refused = self::entry($source, $run, 'post', '23', 'failed');

        self::message($run, $subscriber, 'user.subscriber', 'A subscriber: Teme has no accounts for readers yet, so they weren’t imported.', at: 1);
        self::message($run, $shortcode, 'content.shortcode-removed', 'Removed the shortcode [gallery_bank]: its plugin is gone.', at: 2);
        self::message($run, $download, 'media.download-failed', 'The file couldn’t be downloaded (404), so it wasn’t imported. The next run tries again.', 'error', 3);
        self::message($run, $refused, 'object.refused', 'Teme refused it: The title may not be longer than 300 characters.', 'error', 4);
        self::message($run, null, 'widgets.reported', '=SUM(3) widgets in the sidebar weren’t imported. Set up the theme’s regions again.', at: 5);
        self::message($run, null, 'check.not-found', '/old-page/ answers “not found” on Teme.', 'error', 6);

        return ['source' => $source, 'run' => $run];
    }

    /**
     * Deletes every seeded site, and with it its runs, its map and its
     * messages (their foreign keys cascade).
     */
    public static function forget(): void
    {
        DB::table('import_sources')->where('address', self::ADDRESS)->delete();
    }

    private static function id(): string
    {
        return strtolower((string) Str::ulid());
    }
}
