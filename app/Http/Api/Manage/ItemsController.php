<?php

/**
 * POST /api/manage/v1/items: a new item of a type, as a draft, published or
 * scheduled (docs/proposals/management-api.md). Checks the request's shape
 * here and leaves every write to ApiItems' Operations.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\ManageApi\ApiItemRequest;
use App\Domain\ManageApi\ApiItems;
use App\Domain\ManageApi\HtmlBodies;
use App\Domain\ManageApi\ManageApiProblem;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemsController
{
    /** The longest html body converted: the import's converter parses all of it at once. */
    private const int LARGEST_HTML = 2 * 1024 * 1024;

    public function store(Request $request, ApiItems $items, HtmlBodies $html): JsonResponse
    {
        $refused = [];
        $type = $request->input('type');
        $title = $request->input('title', '');
        $status = $request->input('status', ApiItems::STATUSES[0]);
        $body = $request->input('body');
        $markup = $request->input('html');
        $slug = $request->input('slug');
        $terms = $request->input('terms', []);
        $fields = $request->input('fields', []);
        $featured = $request->input('featured_media');
        $author = $request->input('author');
        $publishAt = $request->input('publish_at');

        if (! is_string($type) || $type === '') {
            $refused['type'] = __('type must name a content type, such as post or page.');
        }

        if (! is_string($title)) {
            $refused['title'] = __('title must be text.');
        }

        if ($body !== null && $markup !== null) {
            $refused['body'] = __('Send body or html, not both.');
        } elseif ($markup === null && ! is_array($body)) {
            $refused['body'] = __('body must be a Tiptap document, or send html instead.');
        } elseif ($markup !== null && (! is_string($markup) || trim($markup) === '')) {
            $refused['html'] = __('html must be text.');
        } elseif (is_string($markup) && strlen($markup) > self::LARGEST_HTML) {
            $refused['html'] = __('html is larger than :size MB. Send a shorter body.', ['size' => intdiv(self::LARGEST_HTML, 1024 * 1024)]);
        }

        if (! is_string($status) || ! in_array($status, ApiItems::STATUSES, true)) {
            $refused['status'] = __('status must be draft, published or scheduled.');
        } elseif (ApiItems::needsDate($status) && ! self::isDateWithOffset($publishAt)) {
            $refused['publish_at'] = __('publish_at must be a date and time with its offset, such as 2026-11-10T09:30:00Z.');
        }

        if ($slug !== null && ! is_string($slug)) {
            $refused['slug'] = __('slug must be text.');
        }

        if (! is_array($terms) || array_filter($terms, fn (mixed $ids, mixed $taxonomy): bool => ! is_string($taxonomy) || ! is_array($ids) || array_filter($ids, fn (mixed $id): bool => ! is_string($id)) !== [], ARRAY_FILTER_USE_BOTH) !== []) {
            $refused['terms'] = __('terms must list term IDs by taxonomy, such as {"category": ["01…"]}.');
        }

        if (! is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            $refused['fields'] = __('fields must give values by field handle, such as {"meta_description": "…"}.');
        }

        if ($featured !== null && ! is_string($featured)) {
            $refused['featured_media'] = __('featured_media must be the ID of a media item.');
        }

        if ($author !== null && (! is_int($author) || $author < 1)) {
            $refused['author'] = __('author must be the ID of a person from /people.');
        }

        if ($refused !== []) {
            throw ManageApiProblem::invalid($refused);
        }

        /** @var array<string, list<string>> $terms */
        /** @var array<string, mixed> $fields */
        $item = new ApiItemRequest(
            type: (string) $type,
            title: (string) $title,
            status: (string) $status,
            slug: is_string($slug) ? $slug : null,
            terms: $terms,
            featuredMedia: is_string($featured) ? $featured : null,
            authorId: is_int($author) ? $author : null,
            fields: $fields,
            publishAt: is_string($publishAt) ? $publishAt : null,
        );

        $notes = [];

        if (is_string($markup)) {
            $converted = $html->convert($markup);
            $document = $converted['document'];
            $notes = $converted['notes'];
        } else {
            /** @var array<string, mixed> $document */
            $document = $body;
        }

        $answer = $items->create(ManageRequest::caller($request), $item, $document);

        return ManageAnswer::data($notes === [] ? $answer['data'] : [...$answer['data'], 'notes' => $notes], $answer['status']);
    }

    /**
     * Whether the value is a date and time with its offset, as publish_at's
     * message asks: 2026-11-10T09:30:00Z or 2026-11-10T09:30:00+02:00, and a
     * day that exists.
     */
    private static function isDateWithOffset(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $problems = DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0));
    }
}
