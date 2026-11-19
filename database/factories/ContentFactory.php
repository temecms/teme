<?php

/**
 * Makes Content models for tests and seeding.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Contents\AuthorSort;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Content> */
final class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'type' => 'post',
            'slug' => Str::slug($title),
            'status' => 'draft',
            'author_id' => User::factory(),
            // As the Operations write it, so a factory-made row sorts like a
            // real one. The factory resolves author_id to a key first.
            'author_sort' => fn (array $attributes): string => AuthorSort::of(User::query()->find($attributes['author_id'])?->name),
            'fields' => [],
        ];
    }
}
