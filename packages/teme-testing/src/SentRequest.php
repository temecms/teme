<?php

declare(strict_types=1);

namespace Teme\Testing;

/**
 * One request a plugin sent through its outbound door while the test's
 * outbound() fake was answering: what was sent, as the remote host would
 * have received it.
 */
final readonly class SentRequest
{
    /**
     * @param  array<string, list<string>>  $headers  by name, as sent
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $host,
        public array $headers,
        public string $body,
    ) {}

    /**
     * Returns the body decoded as JSON, or null when it isn't JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /**
     * Returns the body decoded as a form, field by field.
     *
     * @return array<array-key, mixed>
     */
    public function form(): array
    {
        parse_str($this->body, $fields);

        return $fields;
    }
}
