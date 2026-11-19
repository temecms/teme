<?php

/**
 * One API error as an RFC 9457 problem: its status, its code, the words a
 * person reads, the path it was asked at, and each refused parameter for
 * invalid_parameter. Its type is always about:blank in v1, so its title
 * is the status's own phrase.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

use Symfony\Component\HttpFoundation\Response;

final readonly class Problem
{
    /**
     * @param  array<string, string>  $errors  parameter => why it was refused
     */
    public function __construct(
        public int $status,
        public ProblemCode $code,
        public string $detail,
        public string $instance,
        public array $errors = [],
    ) {}

    /**
     * The problem's members, in the order the API writes them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $problem = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$this->status] ?? 'Error',
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'code' => $this->code->value,
        ];

        if ($this->code === ProblemCode::InvalidParameter) {
            $problem['errors'] = array_map(
                fn (string $parameter, string $detail): array => ['parameter' => $parameter, 'detail' => $detail],
                array_keys($this->errors),
                array_values($this->errors),
            );
        }

        return $problem;
    }
}
