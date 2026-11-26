<?php

/**
 * One request a plugin sent through its outbound door while the test's
 * outbound() fake was answering: what was sent, as the remote host would
 * have received it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

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

    /**
     * Returns the first value of a header as sent, whatever its case, or null
     * when the request carried none.
     *
     * @since next
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $sent => $values) {
            if (strcasecmp($sent, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * Returns the parts of a multipart body, in the order sent: the field's
     * name, the file's name and content type when it is a file, and the
     * part's bytes. An empty list when the body isn't multipart.
     *
     * @return list<array{name: string, filename: string|null, contentType: string|null, body: string}>
     *
     * @since next
     */
    public function multipart(): array
    {
        if (preg_match('/boundary=([^;\s]+)/', (string) $this->header('Content-Type'), $boundary) !== 1) {
            return [];
        }

        $parts = [];

        foreach (explode('--'.$boundary[1], $this->body) as $chunk) {
            $chunk = ltrim($chunk, "\r\n");

            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }

            [$head, $body] = array_pad(explode("\r\n\r\n", $chunk, 2), 2, '');

            if (preg_match('/name="([^"]*)"/', $head, $name) !== 1) {
                continue;
            }

            preg_match('/filename="([^"]*)"/', $head, $file);
            preg_match('/Content-Type: ([^\r\n]+)/i', $head, $type);

            $parts[] = [
                'name' => $name[1],
                'filename' => $file[1] ?? null,
                'contentType' => $type[1] ?? null,
                'body' => str_ends_with($body, "\r\n") ? substr($body, 0, -2) : $body,
            ];
        }

        return $parts;
    }
}
