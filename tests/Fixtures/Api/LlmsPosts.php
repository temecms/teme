<?php

declare(strict_types=1);

namespace Tests\Fixtures\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Many listed posts for the llms.txt tests, each made through the real
 * Operations and dated a minute apart, so their order never rests on a tie.
 */
final class LlmsPosts
{
    /**
     * Publishes this many posts by the author and returns their titles,
     * newest first.
     *
     * @return list<string>
     */
    public static function many(User $author, int $count): array
    {
        $titles = [];

        for ($number = 1; $number <= $count; $number++) {
            $title = 'Story number '.$number;
            $post = headPost($author, $title);
            DB::table('contents')->where('id', $post->id)->update(['published_at' => now()->subDay()->subMinutes($count - $number)]);
            array_unshift($titles, $title);
        }

        return $titles;
    }
}
