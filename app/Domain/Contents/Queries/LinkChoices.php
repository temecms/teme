<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Taxonomy\Queries\TermPicker;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * What the editor's link box offers to link to by ID (rule 9, I-1702): the
 * articles LinkTargets finds, then, once something is typed, the terms of
 * each taxonomy with pages, the authors and the library's files. It reads
 * only.
 */
final class LinkChoices
{
    /** At most this many of each kind but articles, of which LinkTargets gives ten. */
    private const int EACH = 5;

    /**
     * The groups the link box draws, in order, each with its heading and its
     * choices: the address a link stores, a title or a name, and a word
     * about it or null. An empty group is left out; with nothing typed,
     * only the most recent articles.
     *
     * @return list<array{key: string, label: string, choices: list<array{href: string, title: string, note: string|null}>}>
     */
    public static function search(string $query): array
    {
        $needle = trim($query);
        $groups = [self::group('content', __('Items'), array_map(fn (array $article): array => [
            'href' => DocumentSchema::linkTo('content', $article['id']),
            'title' => $article['title'],
            'note' => $article['status'] === 'published' ? null : __('not published yet'),
        ], LinkTargets::search($needle)))];

        if ($needle !== '') {
            $settings = AddressSettings::current();

            foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
                if ($taxonomy->public && $settings->hasBase($taxonomy->key)) {
                    $groups[] = self::group("taxonomy:{$taxonomy->key}", $taxonomy->plural, array_map(fn (array $term): array => [
                        'href' => DocumentSchema::linkTo('term', $term['id']),
                        'title' => $term['path'],
                        'note' => null,
                    ], array_slice(app(TermPicker::class)->search($taxonomy->key, $needle), 0, self::EACH)));
                }
            }

            $groups[] = self::group('authors', __('Authors'), self::authors($needle));
            $groups[] = self::group('files', __('Files'), self::files($needle));
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['choices'] !== []));
    }

    /**
     * People with an author's page who have published something, whose
     * names hold what was typed, by name.
     *
     * @return list<array{href: string, title: string, note: string|null}>
     */
    private static function authors(string $needle): array
    {
        return DB::table('users')
            ->join('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('users.id::text'))
                ->where('paths.target_type', 'user')
                ->where('paths.is_current', true))
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('contents')
                ->whereColumn('contents.author_id', 'users.id')
                ->where('contents.status', 'published')
                ->whereNull('contents.deleted_at'))
            ->where('users.name', 'ilike', '%'.addcslashes($needle, '%_\\').'%')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit(self::EACH)
            ->get(['users.id', 'users.name'])
            ->map(fn (object $row): array => ['href' => DocumentSchema::linkTo('user', (string) $row->id), 'title' => (string) $row->name, 'note' => null])
            ->all();
    }

    /**
     * Files in the library, out of the trash and ready, whose name, title,
     * alt text or caption holds what was typed, as the library searches,
     * newest first; each one's note is its file's extension.
     *
     * @return list<array{href: string, title: string, note: string|null}>
     */
    private static function files(string $needle): array
    {
        return DB::table('media')
            ->whereNull('deleted_at')
            ->where('processing', 'ready')
            ->whereRaw('media_search_text(media.original_filename, media.title, media.alt, media.caption) LIKE ?', ['%'.addcslashes(mb_strtolower($needle), '%_\\').'%'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EACH)
            ->get(['id', 'title', 'original_filename'])
            ->map(function (object $row): array {
                $extension = strtoupper(pathinfo((string) $row->original_filename, PATHINFO_EXTENSION));

                return [
                    'href' => DocumentSchema::linkTo('media', (string) $row->id),
                    'title' => (string) $row->title !== '' ? (string) $row->title : (string) $row->original_filename,
                    'note' => $extension === '' ? null : $extension,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array{href: string, title: string, note: string|null}>  $choices
     *
     * @return array{key: string, label: string, choices: list<array{href: string, title: string, note: string|null}>}
     */
    private static function group(string $key, string $label, array $choices): array
    {
        return ['key' => $key, 'label' => $label, 'choices' => $choices];
    }
}
