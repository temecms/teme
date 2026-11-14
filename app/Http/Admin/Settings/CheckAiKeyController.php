<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\Providers\LibraryProvider;
use Illuminate\Http\JsonResponse;

/**
 * Check key (I-315): asks one AI provider whether its key works, with a
 * request that costs nothing, and answers Settings › AI in one sentence.
 * Never the key, never the provider's words.
 */
final class CheckAiKeyController
{
    public function __invoke(string $provider, AiProviders $providers, AiAvailability $availability): JsonResponse
    {
        // A host that switched AI off contacts no AI service (I-315).
        abort_unless($availability->enabled(), 403);

        $found = $providers->find($provider);

        abort_unless($found instanceof LibraryProvider, 404);

        $check = $found->checkKey();
        $state = $found->keyState();

        return response()->json([
            'result' => $check->value,
            'sentence' => $check->sentence($found->label()),
            'state' => $state->value,
            'stateLabel' => $state->label(),
        ]);
    }
}
