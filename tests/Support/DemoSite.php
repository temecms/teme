<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Backups\Queries\DueBackupWork;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Comments\Operations\Input\SubmitCommentInput;
use App\Domain\Comments\Operations\ModerateComment;
use App\Domain\Comments\Operations\SubmitComment;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\SetUpSiteInput;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\SetUpSite;
use App\Domain\Users\Operations\UpdateProfile;
use App\Domain\Users\SetupCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Teme\Testing\ContractsVersion;
use Tests\Fixtures\Backups\BackupRecords;

/**
 * The demo publication, Northwind Journal, made only through Teme's
 * Operations: an administrator and two authors, three categories, eight
 * published articles with pictures, a draft, an About page, a primary menu
 * and an approved comment. The screenshots and any later demo share it.
 */
final class DemoSite
{
    /** Every demo account signs in with this; the demo site is never served to the public. */
    public const string PASSWORD = 'northwind-demo-passphrase';

    /** The pictures, in tests/Fixtures/demo/images; CREDITS.md there names each one's source. */
    public const string IMAGES = 'tests/Fixtures/demo/images';

    /**
     * Presents the demo as a released, live site for a run: it reports the
     * newest released version and the production environment, which is what
     * a site that is set up properly says, with its scheduler running. Only the values the admin reads
     * are set; nothing about how they are read changes.
     */
    public static function asReleased(): void
    {
        config(['teme.version' => ContractsVersion::highest()]);
        app()['env'] = 'production';

        // The scheduler runs, as it does on a site set up properly.
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    }

    /**
     * Gives the demo backups set up as they should be: an off-server place
     * and a folder, a night's backup at each that finished a few hours before
     * $now with its read-back and test restore passed, and the spare database
     * named, so no backup notice shows and Tools › Backups tells the truth.
     */
    public static function backedUp(CarbonImmutable $now): void
    {
        config(['database.connections.'.SpareDatabase::CONNECTION.'.database' => 'northwind_verify']);

        // The nightly backup's own slot, the latest one more than its grace ago, so the site isn't overdue.
        $night = app(DueBackupWork::class)->slot($now->subHours(BackupStatus::GRACE_HOURS))->setTimezone('UTC')->format('Y-m-d H:i');
        $setUp = $now->subDays(14)->startOfDay();
        $places = [];

        foreach ([
            'Off-site storage' => ['kind' => BackupDestinationKind::S3, 'location' => ['endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'bucket' => 'northwind-journal-backups', 'path' => 'teme']],
            'This server' => ['kind' => BackupDestinationKind::Folder, 'location' => ['path' => '/var/www/teme/storage/app/backups']],
        ] as $name => $place) {
            $slug = strtolower(str_replace(' ', '-', $name));
            $places[$name] = BackupRecords::destination([
                ...$place,
                'name' => $name,
                'repository_id' => hash('sha256', "northwind-{$slug}"),
                'created_at' => $setUp,
            ]);
            BackupRecords::key($places[$name], ['restic_key_id' => hash('sha256', "{$slug}-machine"), 'added_at' => $setUp]);
            BackupRecords::key($places[$name], ['restic_key_id' => hash('sha256', "{$slug}-recovery"), 'role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => $setUp]);

            // Fixed ids, so a run's id on screen is the same each time the demo is made.
            $number = count($places);
            $started = CarbonImmutable::parse($night, 'UTC');
            $backup = BackupRecords::run([
                'id' => sprintf('01KDEMX%019d', $number * 10 + 1),
                'kind' => BackupRunKind::Backup,
                'state' => BackupRunState::Finished,
                'started_by' => BackupTrigger::Schedule,
                'destination_id' => $places[$name]->id,
                'figures' => ['seconds' => 212, 'database_bytes' => 4_800_000, 'media_files' => 24, 'media_bytes' => 36_000_000, 'bytes_added' => 2_100_000],
                'started_at' => $started,
                'ended_at' => $started->addMinutes(5),
            ]);
            BackupRecords::run([
                'id' => sprintf('01KDEMX%019d', $number * 10 + 2),
                'kind' => BackupRunKind::Check,
                'state' => BackupRunState::Finished,
                'started_by' => BackupTrigger::Schedule,
                'destination_id' => $places[$name]->id,
                'parent_id' => $backup->id,
                'figures' => ['seconds' => 30],
                'started_at' => $started->addMinutes(6),
                'ended_at' => $started->addMinutes(7),
            ]);
        }

        foreach ([
            [3, BackupRunKind::ReadBack, CarbonImmutable::parse($night, 'UTC')->addMinutes(8)],
            [4, BackupRunKind::TestRestore, $now->subDays(2)->startOfHour()],
        ] as [$number, $kind, $started]) {
            BackupRecords::run([
                'id' => sprintf('01KDEMX%019d', 90 + $number),
                'kind' => $kind,
                'state' => BackupRunState::Finished,
                'started_by' => BackupTrigger::Schedule,
                'figures' => ['seconds' => 30],
                'started_at' => $started,
                'ended_at' => $started->addMinute(),
            ]);
        }
    }

    /**
     * Builds the site on an empty database, with the clock set to each item's
     * own date while it is made, so the same input gives the same site.
     *
     * @return array{admin: User, authors: list<User>, categories: array<string, Term>, articles: array<string, Content>, draft: Content, about: Content, media: array<string, Media>}
     */
    public static function build(): array
    {
        $previous = Date::hasTestNow() ? Date::now() : null;

        // As a console command runs: the operator's own process needs no fresh password.
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            return self::make();
        } finally {
            Date::setTestNow($previous);
            app()->forgetInstance(Elevation::class);
        }
    }

    /**
     * @return array{admin: User, authors: list<User>, categories: array<string, Term>, articles: array<string, Content>, draft: Content, about: Content, media: array<string, Media>}
     */
    private static function make(): array
    {
        self::at('2026-09-01 09:00');

        $setUp = app(SetUpSite::class)->execute(new SetUpSiteInput(
            app(SetupCode::class)->forThisSite(),
            'Northwind Journal',
            'America/New_York',
            'Rowan Hale',
            'rowan@northwind.test',
            self::PASSWORD,
            self::PASSWORD,
        ));
        $admin = User::query()->findOrFail($setUp['userId']);
        $adminId = (string) $admin->id;

        $version = (int) (SettingsGroupRecord::query()->find('general')->version ?? 0);
        app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['tagline' => 'Stories about places, food and the people who make them'], [], [], $version, $adminId));

        self::profile($admin, 'Rowan edits Northwind Journal from a desk by the window and still prefers paper maps.');

        $clara = self::author($admin, 'Clara Benning', 'clara@northwind.test', 'Clara writes about travel and markets. She has never missed a morning ferry on purpose.');
        $theo = self::author($admin, 'Theo Marsh', 'theo@northwind.test', 'Theo writes about kitchens and workshops, and anyone who still makes things by hand.');

        $categories = [];

        foreach (self::CATEGORIES as $name => $description) {
            $categories[$name] = app(CreateTerm::class)->execute(new SaveTermInput('category', $name, null, null, $description, $adminId));
        }

        $writers = ['clara' => $clara, 'theo' => $theo];
        $media = [];
        $articles = [];

        foreach (self::ARTICLES as $key => $article) {
            self::at($article['date']);
            $writer = $writers[$article['author']];
            $media[$key] = self::picture($writer, $key, $article['picture']);
            $media[$key.'-card'] = self::picture($writer, $key.'-card', $article['card']);

            $fields = [
                BuiltInFields::EXCERPT => $article['standfirst'],
                BuiltInFields::FEATURED_IMAGE => ['id' => $media[$key]->id, 'alt' => null, 'decorative' => false],
            ];

            if ($article['pinned']) {
                $fields[BuiltInFields::STICKY] = true;
            }

            $articles[$key] = self::publish($writer, 'post', $article['title'], self::document($article, $media[$key.'-card']), $fields, [$categories[$article['category']]->id]);
        }

        self::at('2026-11-02 08:30');
        $draft = app(CreateDraft::class)->execute(new CreateDraftInput(
            'post',
            'Notes on handmade paper',
            self::doc([self::paragraph('A first sketch of a visit to a paper mill that still pulls each sheet by hand, from a vat of soaked cotton rag.')]),
            [BuiltInFields::EXCERPT => 'How a sheet of paper is pulled, pressed and dried by hand.'],
            (string) $clara->id,
            [$categories['Craft']->id],
        ));

        self::at('2026-09-01 10:00');
        $about = self::publish($admin, 'page', 'About', self::doc([
            self::paragraph('Northwind Journal publishes stories about places, food and the people who make them. We visit small towns, early markets and quiet workshops, and we write down what we find.'),
            self::heading('Who we are'),
            self::paragraph('We are a small team of writers and one editor. We pay our own way, and nobody pays us to visit.'),
            self::heading('Write to us'),
            self::paragraph('If you know a place, a dish or a maker we should meet, we would love to hear about it.'),
        ]), [], []);

        self::menu($admin, $categories, $about);

        self::at('2026-10-29 07:15');
        $comment = app(SubmitComment::class)->execute(new SubmitCommentInput(
            contentId: $articles['ferry']->id,
            text: 'We took the same crossing last spring and the gulls followed us the whole way. Thank you for bringing it back.',
            name: 'June Alder',
            email: 'june@reader.test',
        ));

        if ($comment->status !== 'approved') {
            app(ModerateComment::class)->execute(new ModerateCommentInput($comment->commentId, 'approved', $adminId));
        }

        return [
            'admin' => $admin->refresh(),
            'authors' => [$clara->refresh(), $theo->refresh()],
            'categories' => $categories,
            'articles' => array_map(fn (Content $item): Content => $item->refresh(), $articles),
            'draft' => $draft->refresh(),
            'about' => $about->refresh(),
            'media' => $media,
        ];
    }

    private static function at(string $moment): void
    {
        Date::setTestNow(CarbonImmutable::parse($moment, 'America/New_York')->utc());
    }

    private static function profile(User $person, string $bio): void
    {
        $id = (string) $person->id;

        app(UpdateProfile::class)->execute(new UpdateProfileInput($id, (string) $person->name, (string) $person->email, $id, $bio));
    }

    /**
     * An author invited by the administrator, who accepts and writes a bio.
     */
    private static function author(User $admin, string $name, string $email, string $bio): User
    {
        $invited = app(InviteUser::class)->execute(new InviteUserInput($name, $email, 'author', (string) $admin->id, deliver: false));
        app(AcceptInvitation::class)->execute(new AcceptInvitationInput($email, $invited['token'], self::PASSWORD, self::PASSWORD));

        $author = User::query()->findOrFail($invited['userId']);
        self::profile($author, $bio);

        return $author;
    }

    /**
     * @param  array{file: string, title: string, alt: string, caption: string}  $picture
     */
    private static function picture(User $uploader, string $name, array $picture): Media
    {
        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-demo-'.Str::random(12).'.jpg';
        copy(base_path(self::IMAGES.'/'.$picture['file']), $copy);

        $media = app(UploadMedia::class)->execute(new UploadMediaInput($copy, $picture['file'], (string) $uploader->id));
        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $uploader->id, $picture['title'], $picture['alt'], $picture['caption'], null, licence: 'CC0'));

        return $media->refresh();
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms
     */
    private static function publish(User $author, string $type, string $title, array $document, array $fields, array $terms): Content
    {
        $content = app(CreateDraft::class)->execute(new CreateDraftInput($type, $title, $document, $fields, (string) $author->id, $terms));
        $content->refresh();

        app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, (string) $content->working_revision_id, (string) $author->id));

        return $content->refresh();
    }

    /**
     * @param  array<string, Term>  $categories
     */
    private static function menu(User $admin, array $categories, Content $about): void
    {
        $adminId = (string) $admin->id;
        $primary = app(CreateNavigation::class)->execute(new CreateNavigationInput('Primary', $adminId));

        app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($primary->id, [
            ['kind' => 'home'],
            ...array_map(fn (Term $term): array => ['kind' => 'term', 'target_id' => $term->id], array_values($categories)),
            ['kind' => 'content', 'target_id' => $about->id],
        ], $adminId));

        $footer = app(CreateNavigation::class)->execute(new CreateNavigationInput('Footer', $adminId));
        app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($footer->id, [['kind' => 'content', 'target_id' => $about->id]], $adminId));

        foreach (['primary' => $primary, 'footer' => $footer] as $slot => $navigation) {
            app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $navigation->id, $adminId));
        }
    }

    /**
     * An article's Tiptap document: its opening, the first heading, an image
     * card, a quote, the second heading and the close.
     *
     * @param  array{opening: list<string>, first: array{0: string, 1: list<string>}, quote: string, second: array{0: string, 1: list<string>}, card: array{file: string, title: string, alt: string, caption: string}}  $article
     *
     * @return array<string, mixed>
     */
    private static function document(array $article, Media $card): array
    {
        return self::doc([
            ...array_map(self::paragraph(...), $article['opening']),
            self::heading($article['first'][0]),
            ...array_map(self::paragraph(...), $article['first'][1]),
            [
                'type' => 'image',
                'attrs' => [
                    'id' => (string) Str::ulid(),
                    'mediaId' => $card->id,
                    'alt' => $article['card']['alt'],
                    'decorative' => false,
                    'caption' => $article['card']['caption'],
                    'credit' => null,
                    'width' => 'wide',
                ],
            ],
            ['type' => 'blockquote', 'content' => [self::paragraph($article['quote'])]],
            self::heading($article['second'][0]),
            ...array_map(self::paragraph(...), $article['second'][1]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return array<string, mixed>
     */
    private static function doc(array $nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function heading(string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @var array<string, string> */
    private const array CATEGORIES = [
        'Travel' => 'Slow journeys by ferry, train and foot, and the places at the end of them.',
        'Food' => 'Bakers, markets and home kitchens, and what they cook through the year.',
        'Craft' => 'Potters, woodworkers and other makers, and the work of their hands.',
    ];

    /**
     * The eight published articles, by key; the key also names the pictures.
     *
     * @var array<string, array{title: string, date: string, author: string, category: string, pinned: bool, standfirst: string, picture: array{file: string, title: string, alt: string, caption: string}, card: array{file: string, title: string, alt: string, caption: string}, opening: list<string>, first: array{0: string, 1: list<string>}, quote: string, second: array{0: string, 1: list<string>}}>
     */
    private const array ARTICLES = [
        'ferry' => [
            'title' => 'Ferry crossings before breakfast',
            'date' => '2026-10-28 06:40',
            'author' => 'clara',
            'category' => 'Travel',
            'pinned' => false,
            'standfirst' => 'The first boat of the day leaves in the dark, and the best seat is on the open deck.',
            'picture' => ['file' => 'ferry.jpg', 'title' => 'Boats leaving the harbor', 'alt' => 'Seen from above, a small boat leaves rows of moored boats on dark green water.', 'caption' => 'The first boat of the day leaves on time, whatever the weather.'],
            'card' => ['file' => 'ferry-card.jpg', 'title' => 'A small boat at low tide', 'alt' => 'Sailboats anchored in a calm bay, with one leaning on the rocks at low tide.', 'caption' => 'Low tide leaves the smaller boats resting on the rocks.'],
            'opening' => [
                'The ticket office opens at a quarter to six, and by six the line already reaches the parking lot. Most people here are not tourists. They are nurses on the early shift, a man with two crates of eggs, and a teenager carrying a cello case taller than she is.',
                'Nobody hurries. The ferry has run this route for longer than anyone in line has been alive, and it has never once left without the people who were waiting for it.',
            ],
            'first' => ['The open deck', [
                'Inside, the cabin smells of coffee and wet wool. Outside, the wind tugs at every loose sleeve. The regulars choose the outside, and after one crossing it is easy to see why.',
                'As the harbor lights fall behind, the water turns from black to slate to a pale silver that has no name. The engine settles into a low hum you feel through your shoes.',
            ]],
            'quote' => 'You can fly over the islands in twenty minutes, but you only arrive when you come by water.',
            'second' => ['Arriving with the light', [
                'The far shore appears as a gray line, then as houses, then as a row of fishing boats with their nets hung out to dry. Somebody on the pier waves, and half the deck waves back.',
                'By the time the ramp comes down, the sun is up and the café by the landing has its chairs out. Breakfast tastes better after an hour at sea, and nobody has ever argued with that.',
            ]],
        ],
        'village' => [
            'title' => 'Three days in a mountain village',
            'date' => '2026-10-21 10:00',
            'author' => 'theo',
            'category' => 'Travel',
            'pinned' => false,
            'standfirst' => 'At the end of a winding road, a village of two hundred people keeps its own slow hours.',
            'picture' => ['file' => 'village.jpg', 'title' => 'A village below the mountains', 'alt' => 'A village spread along a snowy valley between mountains, under a blue sky.', 'caption' => 'In winter the whole valley turns white.'],
            'card' => ['file' => 'village-card.jpg', 'title' => 'Clouds in the high valley', 'alt' => 'Clouds drift between rocky peaks above a deep green valley.', 'caption' => 'By noon the clouds come up from the valley floor.'],
            'opening' => [
                'The bus climbs for an hour, past waterfalls and switchbacks and a single goat standing on a wall as if it owns the road. Then the valley opens, and there it is: a scatter of wooden houses, a church, and a bakery that closes at noon.',
                'There is no hotel here, only rooms above the post office and a guesthouse run by a woman who asks what you would like for breakfast before she asks your name.',
            ],
            'first' => ['Mornings on the footpaths', [
                'Every path starts at the fountain in the square. Some lead up to summer pastures, others down through the pines to the river. The signposts give times, not distances, because distance means little on a slope this steep.',
                'On the second morning, a farmer walking his cows to the lower field stopped to tell me which path had the best view. He was right, and he did not ask for anything in return.',
                'By noon the clouds come up from the valley floor, so the locals walk early and spend the afternoons indoors with cards and coffee.',
            ]],
            'quote' => 'Up here we measure the year by the snow line, not the calendar.',
            'second' => ['Evenings by the stove', [
                'Supper is served at seven sharp. It is soup, bread, cheese from the dairy across the road, and a pear tart if you are lucky. Conversation comes slowly and then all at once.',
                'On the last night the church bell rang at nine, and every light in the village went out within the hour. I have rarely slept so well.',
            ]],
        ],
        'coast' => [
            'title' => 'A slow train along the coast',
            'date' => '2026-10-09 14:20',
            'author' => 'clara',
            'category' => 'Travel',
            'pinned' => false,
            'standfirst' => 'Four hours, eleven stops and one of the finest views from any train window.',
            'picture' => ['file' => 'coast.jpg', 'title' => 'A river seen from a railway bridge', 'alt' => 'A river winds through forest and green fields, seen from high above.', 'caption' => 'Halfway along, the line crosses the river high above the trees.'],
            'card' => ['file' => 'coast-card.jpg', 'title' => 'A road toward the mountains', 'alt' => 'A straight road runs across a flat valley toward a range of snowy mountains.', 'caption' => 'From the last station, a quiet road leads inland toward the mountains.'],
            'opening' => [
                'There is a faster way to make this journey. There is a highway, and there are buses with tinted windows and reclining seats. But the old coast line is still running, two carriages long, and it is the only way I want to go.',
                'The train leaves at nine and arrives a little after one. In between, it stops at every village with a platform, and a few that barely have one.',
            ],
            'first' => ['Window seats on the left', [
                'Sit on the left side, facing forward. For most of the trip the sea is close enough to see the color change over the sandbanks, and in places the rails run so near the water that spray reaches the glass.',
                'The conductor knows most of the passengers by name. He sells coffee from a flask and gives directions to anyone who asks, and to several people who did not.',
            ]],
            'quote' => 'The timetable says four hours. The sea says take as long as you need.',
            'second' => ['The long bridge', [
                'Halfway along, the line turns inland to cross a river on a long iron bridge. Everyone stands up at the same moment. It is not the first time any of them has seen it, and it does not matter.',
                'At the end of the line there is a single café, a taxi that waits for the train, and a road that heads off toward the mountains. I took the train back the same evening, just to see the coast in the other light.',
            ]],
        ],
        'bakery' => [
            'title' => 'The bakery that opens at four',
            'date' => '2026-10-30 08:00',
            'author' => 'theo',
            'category' => 'Food',
            'pinned' => true,
            'standfirst' => 'Before the town wakes up, two bakers have already shaped three hundred loaves by hand.',
            'picture' => ['file' => 'bakery.jpg', 'title' => 'Bread on a bakery counter', 'alt' => 'Baskets of fresh loaves on a wooden counter in a softly lit bakery.', 'caption' => 'The first loaves come out of the oven a little after five.'],
            'card' => ['file' => 'bakery-card.jpg', 'title' => 'Loaves on the shelves', 'alt' => 'Golden loaves of bread on wooden shelves behind open glass doors.', 'caption' => 'Each loaf rests on the shelves for an hour before it is sold.'],
            'opening' => [
                'The light goes on at four. By the time the first delivery van passes, the ovens are hot and the dough that rested overnight is ready to be shaped.',
                'The bakery is run by two sisters who took it over from their uncle. They kept his oven, his recipes and his habit of humming while he works. They added a coffee machine and a bench by the door.',
            ],
            'first' => ['Flour, water, salt and time', [
                'There is no secret, they say, only patience. The dough is mixed the afternoon before and left to rise slowly in the cool back room. Time does the work that machines and additives do elsewhere.',
                'Every loaf is shaped by hand. It takes a few seconds each, and after twenty years it looks like nothing at all, a twist and a fold and a pat, but every loaf comes out the same.',
            ]],
            'quote' => 'If the bread is ready at six, it means we started it yesterday.',
            'second' => ['The six o’clock line', [
                'The door opens at six. The first customers are always the same: a crossing guard, a retired teacher and a man who buys four baguettes and never says who they are for.',
                'By ten the shelves are half empty. By noon the sisters are sweeping up flour, and by one they are asleep, so that the light can go on again at four.',
            ]],
        ],
        'market' => [
            'title' => 'What the market sells in October',
            'date' => '2026-10-16 09:30',
            'author' => 'clara',
            'category' => 'Food',
            'pinned' => false,
            'standfirst' => 'Squash, apples and the last of the tomatoes share the tables at the Saturday market.',
            'picture' => ['file' => 'market.jpg', 'title' => 'Fresh produce at a market stall', 'alt' => 'Piles of limes, red onions, bitter gourds and root vegetables on a market stall.', 'caption' => 'In October the stalls carry summer and autumn side by side.'],
            'card' => ['file' => 'market-card.jpg', 'title' => 'Chilies hung to dry', 'alt' => 'Strings of red chilies, garlic and dried flowers hang above a market stall.', 'caption' => 'Strings of chilies hang over the stall, drying for the winter.'],
            'opening' => [
                'The Saturday market starts setting up at dawn, when the square is still blue with shadow. By eight it is full of trucks, folding tables and people trying to park bicycles in places bicycles were never meant to go.',
                'October is the best month to come. The summer crops have not quite finished, and the autumn ones have arrived all at once.',
            ],
            'first' => ['The last of the summer', [
                'At the far end, a grower sells the season’s final tomatoes, split and imperfect and wonderful. He sells them by the bowl and tells everyone to eat them that afternoon.',
                'Beside him, the apple stall offers eight varieties, each with a handwritten card describing how it tastes. Most people buy the one with the best description.',
            ]],
            'quote' => 'Buy what looks good today. Plan the menu when you get home.',
            'second' => ['Squash season', [
                'The squash arrive in every shape and color, from small striped ones to pale giants that need two hands to lift. The grower will tell you exactly how to roast each one.',
                'By noon the stalls start packing up. The smartest shoppers come back at the end, when the bread is cheaper and the flowers are being given away.',
            ]],
        ],
        'soup' => [
            'title' => 'A soup worth the long winter',
            'date' => '2026-10-04 12:00',
            'author' => 'theo',
            'category' => 'Food',
            'pinned' => false,
            'standfirst' => 'One pot, a few hours and whatever the cellar still holds: a recipe that changes every time.',
            'picture' => ['file' => 'soup.jpg', 'title' => 'A warm cup', 'alt' => 'A person in a red coat holds a warm mug in both hands.', 'caption' => 'On the coldest days, soup tastes best from a mug.'],
            'card' => ['file' => 'soup-card.jpg', 'title' => 'A stew in a pot', 'alt' => 'A dark pot of rich stew with a ladle resting in it.', 'caption' => 'When the soup thickens into a stew, nobody complains.'],
            'opening' => [
                'Every family has a winter soup. In this house it begins in October, when the first cold evening arrives and someone says, without looking up, that it might be time.',
                'There is no written recipe. There is a pot that has been in the family for three generations, and a rule that the soup must cook for longer than anyone wants to wait.',
            ],
            'first' => ['Start with onions', [
                'Everything starts with onions, cooked slowly in butter until they are soft and sweet. After that, anything goes: carrots, squash, a handful of beans, the end of a loaf, a rind of cheese.',
                'The pot stays on the back of the stove all afternoon. People drift into the kitchen, lift the lid, stir and drift away again.',
            ]],
            'quote' => 'A good soup is never finished. It is only eaten.',
            'second' => ['The second day', [
                'Everyone agrees that the soup is better on the second day, and everyone eats most of it on the first.',
                'What is left is frozen in small jars and brought out in February, when the winter feels endless and a taste of October is exactly what is needed.',
            ]],
        ],
        'pottery' => [
            'title' => 'Learning the potter’s wheel',
            'date' => '2026-10-24 15:00',
            'author' => 'clara',
            'category' => 'Craft',
            'pinned' => true,
            'standfirst' => 'Six weeks, a great deal of clay and one slightly lopsided bowl that I would not trade for anything.',
            'picture' => ['file' => 'pottery.jpg', 'title' => 'Hands shaping clay', 'alt' => 'Two hands shape wet clay into a bowl on a turning potter’s wheel.', 'caption' => 'Centering the clay takes far longer than anyone expects.'],
            'card' => ['file' => 'pottery-card.jpg', 'title' => 'Kneading clay', 'alt' => 'Two hands knead a lump of wet clay on a wooden table.', 'caption' => 'Before anything else, the clay is kneaded smooth.'],
            'opening' => [
                'The studio is in an old garage with a roll-up door and a kiln in the corner. On Tuesday evenings, six beginners sit at six wheels and try very hard not to splash each other.',
                'Our teacher has been throwing pots for thirty years. She makes it look like the clay simply wants to become a bowl. It does not.',
            ],
            'first' => ['Centering', [
                'The first lesson is centering: pressing a lump of wet clay until it spins perfectly true. It sounds simple. It took me three weeks, and most of a bag of clay.',
                'The trick, she says, is to brace your elbows and let the wheel do the work. Pushing harder only makes things worse, which is good advice for many things.',
            ]],
            'quote' => 'The clay remembers everything your hands do, so be kind to it.',
            'second' => ['The first bowl', [
                'In week five, something clicked. The clay rose, opened and held its shape. It leaned a little to the left, but it was undeniably a bowl.',
                'It came out of the kiln glazed a deep green that I did not quite expect. It holds oranges on my kitchen table now, and I look at it every morning.',
            ]],
        ],
        'woodshop' => [
            'title' => 'The quiet work of a woodshop',
            'date' => '2026-10-12 11:00',
            'author' => 'theo',
            'category' => 'Craft',
            'pinned' => false,
            'standfirst' => 'In a small shop behind the hardware store, chairs are still made the slow way.',
            'picture' => ['file' => 'woodshop.jpg', 'title' => 'A woodworking shop', 'alt' => 'An old woodshop with hand tools hung on the walls above a long workbench.', 'caption' => 'Every tool has its place on the wall, and every tool is in it.'],
            'card' => ['file' => 'woodshop-card.jpg', 'title' => 'At the workbench', 'alt' => 'A woodworker smooths a board on a low stand, with shavings on the floor.', 'caption' => 'Most of the work happens at the bench, by hand.'],
            'opening' => [
                'The shop smells of cedar and coffee. Sawdust gathers in the corners and in the sunlight from the high windows. A radio plays quietly, and nobody seems to be listening to it.',
                'The woodworker has made chairs here for twenty-two years. Each takes about a week. He has a waiting list, and he does not mind at all.',
            ],
            'first' => ['Choosing the wood', [
                'Every chair starts with a board chosen by hand from the stack outside. He looks at the grain, taps it, and sometimes puts it back without a word.',
                'Oak for the legs, ash for the spindles and walnut when someone asks for it. The wood dries for a full year before it comes inside.',
            ]],
            'quote' => 'A good chair should outlast the person who made it and the person who bought it.',
            'second' => ['Finished by hand', [
                'Machines cut the rough shapes. Everything after that is done with planes, spokeshaves and a great deal of sanding.',
                'The finished chairs leave on Friday afternoons, wrapped in old blankets. The shop is quiet for an hour, and then the next board comes in from the stack.',
            ]],
        ],
    ];
}
