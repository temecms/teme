<?php

declare(strict_types=1);

use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Writes an export file of $count posts, each about $kilobytes of block
 * markup, straight to disk, so the test holds none of it.
 */
function rmWrite(string $path, int $count, int $kilobytes): void
{
    $out = fopen($path, 'wb');
    fwrite($out, "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<rss version=\"2.0\" xmlns:excerpt=\"http://wordpress.org/export/1.2/excerpt/\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:wfw=\"http://wellformedweb.org/CommentAPI/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:wp=\"http://wordpress.org/export/1.2/\">\n<channel>\n"
        ."\t<title>Heavy</title>\n\t<link>https://heavy.example</link>\n\t<wp:wxr_version>1.2</wp:wxr_version>\n\t<wp:base_site_url>https://heavy.example</wp:base_site_url>\n\t<wp:base_blog_url>https://heavy.example</wp:base_blog_url>\n"
        ."\t<wp:author><wp:author_id>1</wp:author_id><wp:author_login><![CDATA[ada]]></wp:author_login><wp:author_email><![CDATA[ada@heavy.example]]></wp:author_email><wp:author_display_name><![CDATA[Ada]]></wp:author_display_name></wp:author>\n");
    $paragraph = '<!-- wp:paragraph {"className":"lede"} --><p class="lede">'.str_repeat('The tide came in over the harbour wall and the ferries waited. ', 30).'</p><!-- /wp:paragraph -->';
    $blocks = str_repeat($paragraph.'<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Part</h2><!-- /wp:heading --></div><!-- /wp:group -->', (int) ceil($kilobytes * 1024 / (strlen($paragraph) + 110)));

    for ($id = 1; $id <= $count; $id++) {
        fwrite($out, "\t<item>\n\t\t<title><![CDATA[Post {$id}]]></title>\n\t\t<link>https://heavy.example/post-{$id}/</link>\n\t\t<dc:creator><![CDATA[ada]]></dc:creator>\n"
            ."\t\t<content:encoded><![CDATA[{$blocks}]]></content:encoded>\n\t\t<excerpt:encoded><![CDATA[]]></excerpt:encoded>\n"
            ."\t\t<wp:post_id>{$id}</wp:post_id>\n\t\t<wp:post_date><![CDATA[2024-03-02 10:00:00]]></wp:post_date>\n\t\t<wp:post_date_gmt><![CDATA[2024-03-02 10:00:00]]></wp:post_date_gmt>\n"
            ."\t\t<wp:post_name><![CDATA[post-{$id}]]></wp:post_name>\n\t\t<wp:status><![CDATA[publish]]></wp:status>\n\t\t<wp:post_parent>0</wp:post_parent>\n\t\t<wp:post_type><![CDATA[post]]></wp:post_type>\n\t</item>\n");
    }

    fwrite($out, "</channel>\n</rss>\n");
    fclose($out);
}

it('reads a block-heavy file within a worker’s memory, writing every row once', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    $admin = User::factory()->create()->assignRole('administrator');
    $file = ImportFile::query()->create(['uploaded_by' => $admin->id, 'name' => 'heavy.xml', 'size_bytes' => 1, 'received_bytes' => 1, 'sha256' => str_repeat('0', 64), 'state' => 'reading']);
    $path = Storage::disk('local')->path($file->path());
    @mkdir(dirname($path), 0777, true);
    rmWrite($path, 600, 200);

    // As on a worker with debug mode off: debug mode's error page keeps the
    // last 100 queries with their values, a part's rows among them.
    Event::forget(QueryExecuted::class);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);
    $job = new ReadImportFile($file->id, JobActor::person($admin->id));

    while ($job !== null) {
        Queue::fake();
        $job->handle();
        $queued = Queue::pushedJobs()[ReadImportFile::class] ?? [];
        $job = $queued === [] ? null : $queued[0]['job'];
    }

    $peak = memory_get_peak_usage(true) - $before;
    fwrite(STDERR, sprintf("\nread %.1f MB file, peak %.1f MB above the start\n", filesize($path) / 1048576, $peak / 1048576));

    $rows = DB::table('import_file_rows')->where('file_id', $file->id)->where('list', ItemRows::CONTENT);

    expect($file->refresh()->state)->toBe('ready')
        ->and($rows->count())->toBe(600)
        ->and((clone $rows)->distinct()->count('object_id'))->toBe(600)
        ->and($peak)->toBeLessThan(64 * 1024 * 1024);
});
