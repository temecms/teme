<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A site in three languages for every part's tests (I-3360): English
 * (en-GB) the default, French (fr) shown, Spanish (es) hidden. Each version
 * is made by the real Operations; its language and group are written to its
 * row before it is saved, filed and published, so its address, search words
 * and counts follow as they would for a person. The caller seeds the roles.
 *
 * - post (hello, published, filed under news), postFr (bonjour, published,
 *   under newsFr) and postEs (hola, published, in hidden Spanish): one group;
 * - page (about, published) and pageFr (a-propos, a draft): one group;
 * - news and newsFr (actualites), categories: one group;
 * - alone (published, English, no group).
 */
final readonly class LanguageSite
{
    public function __construct(
        public User $admin,
        public User $editor,
        public string $post,
        public string $postFr,
        public string $postEs,
        public string $page,
        public string $pageFr,
        public string $news,
        public string $newsFr,
        public string $alone,
        public string $postGroup,
        public string $pageGroup,
        public string $newsGroup,
    ) {}

    public static function build(): self
    {
        $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
        $editor = User::factory()->create(['name' => 'Eddie Editor'])->assignRole('editor');

        siteSetLanguage($admin, 'en-GB');
        Languages::set(['fr', 'es'], hidden: ['es']);

        $newsGroup = TranslationGroups::newId();
        $news = self::term($editor, 'News', null, $newsGroup);
        $newsFr = self::term($editor, 'Actualités', 'fr', $newsGroup);

        $postGroup = TranslationGroups::newId();
        $post = self::version($editor, 'Hello', null, $postGroup, [$news->id], publish: true);
        $postFr = self::version($editor, 'Bonjour', 'fr', $postGroup, [$newsFr->id], publish: true);
        $postEs = self::version($editor, 'Hola', 'es', $postGroup, [], publish: true);

        $pageGroup = TranslationGroups::newId();
        $page = self::version($editor, 'About', null, $pageGroup, [], publish: true, type: 'page');
        $pageFr = self::version($editor, 'À propos', 'fr', $pageGroup, [], publish: false, type: 'page');

        $alone = self::version($editor, 'Alone', null, null, [], publish: true);

        return new self($admin, $editor, $post, $postFr, $postEs, $page, $pageFr, $news->id, $newsFr->id, $alone, $postGroup, $pageGroup, $newsGroup);
    }

    /**
     * News's version in hidden Spanish, "Noticias" (noticias), for the tests
     * of what a hidden language's term never shows. Not made by build(), so
     * every other test's counts stay as they were.
     */
    public function newsEs(): string
    {
        return self::term($this->editor, 'Noticias', 'es', $this->newsGroup)->id;
    }

    /**
     * A term made by CreateTerm, then given its language and group, its
     * address following.
     */
    public static function term(User $editor, string $name, ?string $language, ?string $group, string $taxonomy = 'category'): Term
    {
        $term = typesTerm($editor, $taxonomy, $name);
        DB::table('terms')->where('id', $term->id)->update(['language' => $language, 'translation_group' => $group]);
        $term = Term::query()->findOrFail($term->id);
        app(PathProjection::class)->syncTerm($term);

        return $term;
    }

    /**
     * An item made by CreateDraft, given its language and group, then saved
     * with a one-paragraph body and these terms, and published if asked.
     *
     * @param  list<string>  $terms
     */
    public static function version(User $editor, string $title, ?string $language, ?string $group, array $terms, bool $publish, string $type = 'post'): string
    {
        $item = lifecycleDraft($editor, $title, $type);
        DB::table('contents')->where('id', $item->id)->update(['language' => $language, 'translation_group' => $group]);
        $item = Content::query()->findOrFail($item->id);

        app(SaveDraft::class)->execute(new SaveDraftInput(
            $item->id,
            $title,
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "{$title}, in a few words."]]]]],
            [],
            (string) $item->working_revision_id,
            (string) $editor->id,
            $terms,
        ));

        if ($publish) {
            listsPublish($item, $editor);
        }

        return $item->id;
    }
}
