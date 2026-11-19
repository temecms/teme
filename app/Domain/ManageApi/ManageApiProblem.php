<?php

/**
 * One refusal of the management API, as the API's problems are written
 * (RFC 9457, App\Domain\Api\Problems): a status, a code a client switches
 * on, a plain sentence from ManageApiWords, and each refused field's
 * reason. Never the exception's own message, SQL, class or path (I-091),
 * and never stored by any cache.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\Api\Problems;
use App\Domain\Media\Exceptions\DuplicateMediaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ManageApiProblem extends RuntimeException implements Responsable, ShouldntReport
{
    /**
     * @param  array<string, string>  $errors  field => why it was refused
     * @param  array<string, mixed>  $extra  more members, such as the draft a refused publish kept
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $problem,
        public readonly string $detail,
        public readonly array $errors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    /**
     * No key, or one that is unknown, revoked or past its expiry, all alike.
     */
    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', ManageApiWords::unauthorized(), headers: ['WWW-Authenticate' => 'Bearer']);
    }

    /**
     * The management API is switched off.
     */
    public static function off(): self
    {
        return new self(404, 'api_off', ManageApiWords::off());
    }

    /**
     * The key wasn't given an ability the address needs.
     */
    public static function missingAbility(ApiAbility $ability): self
    {
        return new self(403, 'forbidden', ManageApiWords::missingAbility($ability));
    }

    /**
     * The key's person may not do this, as the Operation decided.
     */
    public static function notPermitted(): self
    {
        return new self(403, 'forbidden', ManageApiWords::notPermitted());
    }

    /**
     * Nothing this key may see has this ID or name.
     */
    public static function notFound(string $what): self
    {
        return new self(404, 'not_found', $what);
    }

    /**
     * Fields refused, each with its reason.
     *
     * @param  array<string, string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(422, 'invalid_parameter', ManageApiWords::invalid(), $errors);
    }

    /**
     * An Operation's refusal of its input, each field's first reason.
     */
    public static function fromValidation(ValidationException $refusal): self
    {
        $errors = [];

        foreach ($refusal->errors() as $field => $messages) {
            $errors[(string) $field] = (string) ($messages[0] ?? '');
        }

        return self::invalid($errors);
    }

    /**
     * An Operation's refusal as the API answers it: the person's permission
     * as forbidden, refused input as invalid_parameter, a duplicate file as
     * duplicate. Anything else is thrown on, and answered server_error.
     */
    public static function fromRefusal(Throwable $refusal): Throwable
    {
        return match (true) {
            $refusal instanceof self => $refusal,
            $refusal instanceof AuthorizationException => self::notPermitted(),
            $refusal instanceof ValidationException => self::fromValidation($refusal),
            $refusal instanceof DuplicateMediaException => self::duplicate($refusal->existingId),
            default => $refusal,
        };
    }

    /**
     * Something this release doesn't do yet.
     */
    public static function notSupportedYet(string $detail): self
    {
        return new self(422, 'not_supported_yet', $detail);
    }

    /**
     * The same file is already in the media library.
     */
    public static function duplicate(string $existingId): self
    {
        return new self(409, 'duplicate', ManageApiWords::duplicate(), extra: ['existing_id' => $existingId]);
    }

    /**
     * This problem with more members, such as the draft a refused publish kept.
     *
     * @param  array<string, mixed>  $extra
     */
    public function with(array $extra): self
    {
        return new self($this->status, $this->problem, $this->detail, $this->errors, [...$this->extra, ...$extra], $this->headers);
    }

    /**
     * The problem's members, in the order the API writes them.
     *
     * @return array<string, mixed>
     */
    public function body(string $path): array
    {
        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$this->status] ?? 'Error',
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $path,
            'code' => $this->problem,
        ];

        if ($this->errors !== []) {
            $body['errors'] = array_map(
                fn (string $field, string $detail): array => ['parameter' => $field, 'detail' => $detail],
                array_keys($this->errors),
                array_values($this->errors),
            );
        }

        return [...$body, ...$this->extra];
    }

    public function toResponse($request): JsonResponse
    {
        $response = new JsonResponse($this->body($request->getPathInfo()), $this->status, $this->headers, Problems::JSON_FLAGS);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
