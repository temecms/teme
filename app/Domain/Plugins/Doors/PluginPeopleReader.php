<?php

/**
 * A plugin's people.read door (docs/plans/plugin-sockets-3.md): the people
 * content can be credited to, as AuthorOptions finds them, each by account
 * ID and display name only, in account order a page at a time.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Users\Queries\AuthorOptions;
use App\Models\User;
use InvalidArgumentException;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\People\PersonPage;
use Teme\Contracts\People\PersonRef;

final readonly class PluginPeopleReader implements PeopleReader
{
    public const int MOST_PEOPLE = 200;

    private const string PREFIX = 'people:';

    public function __construct(private AuthorOptions $authors) {}

    public function writers(?string $after = null, int $limit = 100): PersonPage
    {
        if ($limit < 1 || $limit > self::MOST_PEOPLE) {
            throw new InvalidArgumentException('A page lists 1 to '.self::MOST_PEOPLE.' people.');
        }

        $afterId = $after === null ? null : self::decode($after);

        $rows = $this->authors->people()
            ->when($afterId !== null, fn ($query) => $query->where('users.id', '>', $afterId))
            ->orderBy('users.id')
            ->limit($limit + 1)
            ->get(['users.id', 'users.name']);

        $more = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return new PersonPage(
            $page->map(fn (User $person): PersonRef => new PersonRef((string) $person->id, (string) $person->name))->all(),
            $more && $last instanceof User ? self::encode((int) $last->id) : null,
        );
    }

    private static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode(self::PREFIX.$id), '+/', '-_'), '=');
    }

    /**
     * The account ID a cursor names.
     *
     * @throws InvalidArgumentException for anything this reader couldn't have given
     */
    private static function decode(string $cursor): int
    {
        $plain = strlen($cursor) > 64 ? false : base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($plain === false || preg_match('/^'.self::PREFIX.'([1-9][0-9]{0,18})$/', $plain, $match) !== 1) {
            throw new InvalidArgumentException('That cursor wasn’t given by this list.');
        }

        return (int) $match[1];
    }
}
