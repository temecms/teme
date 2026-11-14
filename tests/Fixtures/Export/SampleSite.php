<?php

declare(strict_types=1);

namespace Tests\Fixtures\Export;

use App\Domain\Backups\BackupSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\People\Credit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\People\Credited;
use Tests\Fixtures\Security\SealedSecrets;

/**
 * One of everything the export carries or leaves out, with the values
 * that must never leave the site planted where a careless read would find
 * them.
 */
final readonly class SampleSite
{
    /** Values planted in the site that no exported line may hold. */
    public const array NEVER = ['203.0.113.9', 'Mozilla/5.0 (Harbour)', 'remember-me-token-1234', 'Looks fine'];

    public const string MACHINE_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const string TOKEN_HASH = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public function __construct(
        public User $admin,
        public User $author,
        public Content $post,
        public Content $draft,
        public Content $trashed,
        public Media $image,
        public Media $trashedPdf,
        public Comment $comment,
        public Comment $spam,
    ) {}

    public static function make(): self
    {
        Storage::fake((string) config('media.disk'));
        Storage::fake((string) config('media.originals_disk'));
        app(RoleSeeder::class)->run();

        $admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@example.org'])->assignRole('administrator');
        $author = User::factory()->withTwoFactor()->create(['name' => 'Bola Author', 'email' => 'bola@example.org', 'remember_token' => 'remember-me-token-1234'])->assignRole('editor');
        User::factory()->suspended()->create(['email' => 'chidi@example.org'])->assignRole('contributor');

        $harbour = taxonomyTerm('category', 'Harbour');
        $boats = taxonomyTerm('category', 'Boats', $harbour->id);
        $tides = taxonomyTerm('tag', 'Tides');

        $image = mediaUpload($admin, mediaJpeg(), 'Harbour.jpg');
        mediaUpload($admin, mediaPdf(), 'Timetable.pdf');
        $trashedPdf = mediaUpload($admin, mediaPdf(), 'Old timetable.pdf', allowDuplicate: true);
        app(TrashMedia::class)->execute(new MediaActionInput($trashedPdf->id, (string) $admin->id));

        $about = addrPublished($admin, 'About the harbour', 'page');
        $post = pagePublish($author, 'Harbour news', [schedLinkTo($about->id, 'About us'), pageImageNode($image, strtoupper((string) Str::ulid()))], [], [$boats->id, $tides->id], $image);
        pageSaveDraft($post, 'Harbour news, revised');
        Credited::set($post->id, [Credit::person(Credited::guest('Harbour Wire', 'harbour-wire', 'A news agency.')), Credit::account((int) $author->id)]);
        $draft = lifecycleDraft($author, 'Not yet');
        $trashed = addrPublished($admin, 'Gone');

        $comment = cmComment($post, 'approved', null, [
            'author_url' => 'https://ada.example.org',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Harbour)',
            'commenter_token_hash' => self::TOKEN_HASH,
            'moderation' => ['by' => 'built-in', 'reason' => 'Looks fine'],
        ]);
        cmComment($post, 'pending', $comment, ['author_name' => 'Bola Author', 'author_email' => 'bola@example.org', 'author_user_id' => $author->id]);
        cmComment($post, 'removed', null, ['author_name' => null, 'author_email' => null, 'body' => null]);
        $spam = cmComment($post, 'spam', null, ['author_email' => 'spam@example.net']);
        cmComment($trashed, 'approved');
        pageTrash($trashed, $admin);

        $menu = navCreate($admin, 'Main');
        navSave($admin, $menu, [
            ['kind' => 'home'],
            ['kind' => 'content', 'target_id' => $about->id, 'label' => 'About', 'children' => [['kind' => 'term', 'target_id' => $boats->id]]],
            ['kind' => 'url', 'url' => 'https://tides.example.org/', 'label' => 'Tide tables', 'opens_new_tab' => true],
        ]);

        rdSaved('/old-harbour', '/about-the-harbour/');
        rdSaved('/older-news', '', 301, item: $post->id);
        rdSaved('/closed', '', 410);

        SealedSecrets::settings(BackupSettings::GROUP, ['machine_key' => self::MACHINE_KEY], app(Encrypter::class));

        return new self($admin, $author, $post->refresh(), $draft, $trashed, $image, $trashedPdf, $comment, $spam);
    }
}
