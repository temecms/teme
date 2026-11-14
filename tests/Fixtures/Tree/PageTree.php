<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tree;

use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Tree\TreePlace;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A tree of pages in every state a reader can or can't see, for page
 * parents' tests (I-3363). Every item is made through the real Operations;
 * place() writes each parent. Pages' pattern is the default, /{parent}/{slug}/.
 *
 * The pages, each with the address it stands for:
 * - about: published, at the top (about);
 * - team: published, under about, position 0 (about/team);
 * - people: published, under team (about/team/people);
 * - history: published, under about, position 1 (about/history);
 * - plans: a draft, under about (no address);
 * - secret: published under about, then made private (no address);
 * - secretChild: published, under secret (about/secret-child: a private parent never shows);
 * - services: a draft, at the top (no address);
 * - web: published, under services (web: a draft parent never shows);
 * - archived: published under about, then trashed (no address);
 * - archivedChild: published, under archived (about/archived-child: nor one in the trash);
 * and a post, teamPost, whose slug is team (team).
 *
 * place() moves a page through UpdateContentDetails with a TreePlace, as
 * an editor, as a person would (I-3363).
 */
final readonly class PageTree
{
    public function __construct(
        public User $admin,
        public User $editor,
        public User $author,
        public Content $about,
        public Content $team,
        public Content $people,
        public Content $history,
        public Content $plans,
        public Content $secret,
        public Content $secretChild,
        public Content $services,
        public Content $web,
        public Content $archived,
        public Content $archivedChild,
        public Content $teamPost,
    ) {}

    /**
     * The tree, every page in its state; the caller seeds the roles first.
     */
    public static function build(): self
    {
        $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
        $editor = User::factory()->create(['name' => 'Eddie Editor'])->assignRole('editor');
        $author = User::factory()->create(['name' => 'Aria Author'])->assignRole('author');

        $about = self::published($editor, 'About');
        $team = self::published($editor, 'Team');
        self::place($team, $about, 0);
        $people = self::published($editor, 'People');
        self::place($people, $team);
        $history = self::published($editor, 'History');
        self::place($history, $about, 1);
        $plans = lifecycleDraft($editor, 'Plans', 'page');
        self::place($plans, $about, 2);

        $secret = self::published($editor, 'Secret');
        self::place($secret, $about, 3);
        app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($secret->id, 'private', null, (string) $editor->id));
        $secretChild = self::published($editor, 'Secret child');
        self::place($secretChild, $secret);

        $services = lifecycleDraft($editor, 'Services', 'page');
        $web = self::published($editor, 'Web');
        self::place($web, $services);

        $archived = self::published($editor, 'Archived');
        self::place($archived, $about, 4);
        $archivedChild = self::published($editor, 'Archived child');
        // Placed before its parent goes to the trash: nothing moves a page under one there.
        self::place($archivedChild, $archived);
        app(TrashContent::class)->execute(new ContentActionInput($archived->id, (string) $editor->id));

        $teamPost = addrPublished($editor, 'Team');

        $fresh = fn (Content $item): Content => Content::withTrashed()->findOrFail($item->id);

        return new self(
            $admin, $editor, $author,
            $fresh($about), $fresh($team), $fresh($people), $fresh($history), $fresh($plans),
            $fresh($secret), $fresh($secretChild), $fresh($services), $fresh($web),
            $fresh($archived), $fresh($archivedChild), $fresh($teamPost),
        );
    }

    /**
     * Puts the item under the parent, or at the top, at this position,
     * through UpdateContentDetails with a TreePlace, as the editor given or
     * the site's first editor; its address and those of the pages under it
     * follow, as they would for a person.
     */
    public static function place(Content $item, ?Content $parent, int $position = 0, ?User $editor = null): void
    {
        $editor ??= User::role('editor')->oldest('id')->first() ?? User::factory()->create()->assignRole('editor');
        $row = Content::withTrashed()->findOrFail($item->id);

        app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
            contentId: (string) $row->id,
            expectedRevisionId: (string) $row->working_revision_id,
            actorId: (string) $editor->id,
            place: new TreePlace($parent?->id, $position),
        ));

        $item->refresh();
    }

    /**
     * Writes this many published pages under the parent, straight to the
     * table from its own row, slugs `{prefix}-1` on; with addresses, brings
     * theirs in step as the operator. Answers their IDs, in order.
     *
     * @return list<string>
     */
    public static function many(Content $parent, int $count, string $prefix = 'many', bool $withAddresses = false): array
    {
        $template = (array) DB::table('contents')->where('id', $parent->id)->first();
        $rows = [];

        foreach (range(1, $count) as $n) {
            $rows[] = ['id' => strtolower((string) Str::ulid()), 'parent_id' => $parent->id, 'slug' => "{$prefix}-{$n}", 'title' => ucfirst($prefix)." {$n}", 'position' => $n, 'working_revision_id' => null, 'published_revision_id' => null] + $template;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('contents')->insert($chunk);
        }

        $ids = array_column($rows, 'id');

        if ($withAddresses) {
            self::asOperator(function () use ($ids): void {
                foreach (array_chunk($ids, 500) as $chunk) {
                    app(RebuildPaths::class)->execute(new RebuildPathsInput('content', $chunk, null));
                }
            });
        }

        return $ids;
    }

    private static function asOperator(callable $work): void
    {
        $before = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            $work();
        } finally {
            app()->instance(Elevation::class, $before);
        }
    }

    private static function published(User $editor, string $title): Content
    {
        return addrPublished($editor, $title, 'page');
    }
}
