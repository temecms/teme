<?php

declare(strict_types=1);

namespace Tests\Fixtures\Api;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Requests and changes for the join's end-to-end tests: each request made
 * as a new reader's would be, through the whole stack with the page cache
 * in front, and an edit saved as an editor saves it. Kept here, not in
 * tests/Pest.php, which other slices change at the same time.
 */
final class EndToEnd
{
    /**
     * A GET for this exact address through the whole stack, the page cache
     * included, as a request of its own: what one request remembered
     * (CardMedia's files, RenderRecord's tags) is forgotten first, as a new
     * request starts with nothing.
     *
     * @param  array<string, string>  $headers  request headers, by name
     * @param  array<string, string>  $cookies
     */
    public static function get(string $uri, array $headers = [], array $cookies = []): TestResponse
    {
        app()->forgetScopedInstances();

        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, 'GET', [], $cookies, [], $server);

        return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
    }

    /**
     * Saves a new working revision of the item with this title, its body,
     * fields and terms kept as they are, through SaveDraft; publishing it
     * is the caller's.
     *
     * @param  array<string, mixed>  $fields  fields to change, by key
     */
    public static function edit(Content $content, User $actor, string $title, array $fields = []): void
    {
        $content->refresh();
        $revision = DB::table('content_revisions')->where('id', $content->working_revision_id)->first(['fields', 'document']);
        $terms = DB::table('content_terms')->where('content_id', $content->id)->where('scope', 'working')->orderBy('position')->pluck('term_id')->map(fn (mixed $id): string => (string) $id)->all();

        app(SaveDraft::class)->execute(new SaveDraftInput(
            $content->id,
            $title,
            (array) json_decode((string) $revision?->document, true),
            [...(array) json_decode((string) ($revision?->fields ?? '{}'), true), ...$fields],
            $content->working_revision_id,
            (string) $actor->id,
            $terms,
        ));
    }

    /**
     * The by-path address for this site address.
     */
    public static function byPath(string $path): string
    {
        return '/api/v1/items/by-path?path='.rawurlencode($path);
    }
}
