<?php

declare(strict_types=1);

namespace Teme\Contracts\Http;

use InvalidArgumentException;

/**
 * The only way out for a plugin granted http: https, to the hosts agreed to,
 * no redirect followed, at most 10 seconds and 1 MB, or 300 seconds and
 * 16 MB for an answer inside a long job.
 *
 * @since 0.5.0
 */
interface OutboundHttp
{
    /**
     * A GET request.
     *
     * @param  array<string, string>  $headers
     *
     * @throws HostRefused when the address may not be reached
     * @throws AnswerTooLarge when the answer passes 1 MB
     * @throws RequestFailed when the request gets no answer
     *
     * @since 0.5.0
     */
    public function get(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer;

    /**
     * A POST request with a JSON body.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     *
     * @throws HostRefused when the address may not be reached
     * @throws AnswerTooLarge when the answer passes 1 MB
     * @throws RequestFailed when the request gets no answer, or its body passes 1 MB
     *
     * @since 0.5.0
     */
    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 5): HttpAnswer;

    /**
     * A POST request with a form body, `application/x-www-form-urlencoded`:
     * a list value repeats its name (`hub.url=a&hub.url=b`, as WebSub
     * needs), never `name[0]=`. A Content-Type the plugin passes is dropped.
     *
     * @param  array<string, string|list<string>>  $fields
     * @param  array<string, string>  $headers
     *
     * @throws InvalidArgumentException when a field is neither a string nor a list of strings, before any lookup
     * @throws HostRefused when the address may not be reached
     * @throws AnswerTooLarge when the answer passes 1 MB
     * @throws RequestFailed when the request gets no answer, or its body passes 1 MB
     *
     * @since 0.9.0
     */
    public function postForm(string $url, array $fields, array $headers = [], int $timeoutSeconds = 5): HttpAnswer;
}
