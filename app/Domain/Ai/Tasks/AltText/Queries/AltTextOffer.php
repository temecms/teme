<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText\Queries;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Tasks\AltText\AltTextImage;
use App\Domain\Ai\Tasks\AltText\AltTextTask;
use App\Domain\Ai\UsageLimits;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Whether an image's details offer Suggest alt text to one person (I-321):
 * AI is on with a model that reads images, they hold ai.use and may change
 * the image, it isn't in the trash, and it has a copy to send.
 */
final readonly class AltTextOffer
{
    public function __construct(
        private AiAvailability $availability,
        private ModelChoices $choices,
        private AiProviders $providers,
    ) {}

    /**
     * Returns where the image may be sent (the model's provider and its
     * fallback's, once each, I-1304) and this person's hourly limit; or null
     * when the details offer nothing.
     *
     * @return array{sentTo: list<string>, hourlyLimit: int}|null
     */
    public function for(?User $user, string $mediaId): ?array
    {
        $media = Media::query()->find($mediaId);

        if ($user === null || $media === null || AltTextImage::variantOf($media) === null
            || ! $this->availability->allows($user, AltTextTask::KEY) || ! Gate::forUser($user)->allows('update', $media)) {
            return null;
        }

        $choice = $this->choices->for(AltTextTask::KEY);
        $sentTo = [];

        foreach ([$choice?->primary, $choice?->fallback] as $ref) {
            $label = $ref === null ? null : $this->providers->find($ref->provider)?->label();

            if ($label !== null && ! in_array($label, $sentTo, true)) {
                $sentTo[] = $label;
            }
        }

        return ['sentTo' => $sentTo, 'hourlyLimit' => UsageLimits::hourly()];
    }
}
