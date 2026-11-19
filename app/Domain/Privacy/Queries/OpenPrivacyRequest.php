<?php

/**
 * Finds the open request of a kind for an address, so a run a killed
 * process left behind is continued rather than recorded again, and reads a
 * request as the console reports it. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Queries;

use App\Domain\Privacy\Models\PrivacyRequest;

final class OpenPrivacyRequest
{
    /**
     * Returns the ID of the pending or running request of this kind for this
     * address, or null when there is none.
     */
    public function for(string $kind, string $email): ?string
    {
        $id = PrivacyRequest::query()
            ->where('kind', $kind)
            ->where('email_hash', PrivacyRequest::hashOf($email))
            ->whereIn('status', [PrivacyRequest::PENDING, PrivacyRequest::RUNNING])
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Returns a request as the console reports it, or null when there is no
     * such request.
     *
     * @return array{id: string, kind: string, email: string|null, status: string, reply: bool, results: list<array<string, mixed>>, export_path: string|null, created_at: string}|null
     */
    public function summary(string $id): ?array
    {
        $request = PrivacyRequest::query()->find($id);

        if ($request === null) {
            return null;
        }

        return [
            'id' => $request->id,
            'kind' => $request->kind,
            'email' => $request->email,
            'status' => $request->status,
            'reply' => $request->reply,
            'results' => $request->results,
            'export_path' => $request->export_path,
            'created_at' => (string) $request->created_at?->toIso8601String(),
        ];
    }
}
