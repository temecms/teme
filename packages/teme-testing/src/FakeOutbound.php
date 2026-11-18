<?php

/**
 * Answers the requests plugins send through their outbound door, below the
 * door, so every check the door makes still runs: what each host answers,
 * 200 with an empty body unless told otherwise, and every request sent.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

final class FakeOutbound
{
    /** @var array<string, array{status: int, body: string, headers: array<string, string>}> what each host answers once its turns are used */
    private array $answers = [];

    /** @var array<string, list<int>> the statuses each host answers next, in turn */
    private array $turns = [];

    /** @var list<SentRequest> */
    private array $sent = [];

    /**
     * Starts answering every request the site sends.
     */
    public function __construct()
    {
        Http::fake(fn (Request $request): PromiseInterface => $this->reply($request));
    }

    /**
     * Makes this host answer every request with this status, body (an array
     * is sent as JSON) and headers.
     *
     * @param  string|array<array-key, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function answer(string $host, int $status = 200, string|array $body = '', array $headers = []): self
    {
        if (is_array($body)) {
            $headers = ['Content-Type' => 'application/json', ...$headers];
            $body = (string) json_encode($body, JSON_THROW_ON_ERROR);
        }

        $this->answers[strtolower($host)] = ['status' => $status, 'body' => $body, 'headers' => $headers];

        return $this;
    }

    /**
     * Makes this host answer its next requests with these statuses, one each,
     * and then as answer() said, or 200.
     *
     * @param  list<int>  $statuses
     */
    public function answerInTurn(string $host, array $statuses): self
    {
        $this->turns[strtolower($host)] = $statuses;

        return $this;
    }

    /**
     * Returns every request sent, oldest first, or only those to this host.
     *
     * @return list<SentRequest>
     */
    public function sent(?string $host = null): array
    {
        return $host === null
            ? $this->sent
            : array_values(array_filter($this->sent, fn (SentRequest $request): bool => $request->host === strtolower($host)));
    }

    /**
     * Fails the test unless exactly $times requests sent match.
     *
     * @param  Closure(SentRequest): bool  $match
     */
    public function assertSent(Closure $match, int $times = 1): void
    {
        $matched = count(array_filter($this->sent, $match));

        Assert::assertSame($times, $matched, "Expected {$times} matching request(s) sent; {$matched} were.");
    }

    /**
     * Fails the test if any request was sent.
     */
    public function assertNothingSent(): void
    {
        Assert::assertSame([], array_map(fn (SentRequest $request): string => $request->method.' '.$request->url, $this->sent), 'Expected no request sent.');
    }

    private function reply(Request $request): PromiseInterface
    {
        $host = strtolower((string) parse_url($request->url(), PHP_URL_HOST));
        $headers = [];

        foreach ($request->headers() as $name => $values) {
            $headers[(string) $name] = array_map(strval(...), (array) $values);
        }

        $this->sent[] = new SentRequest($request->method(), $request->url(), $host, $headers, $request->body());

        if (($this->turns[$host] ?? []) !== []) {
            return Http::response('', (int) array_shift($this->turns[$host]));
        }

        $answer = $this->answers[$host] ?? ['status' => 200, 'body' => '', 'headers' => []];

        return Http::response($answer['body'], $answer['status'], $answer['headers']);
    }
}
