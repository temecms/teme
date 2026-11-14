<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Contracts\Container\Container;
use UnexpectedValueException;

/**
 * Every registered AI provider (I-314), read through the container each time,
 * so it holds no state; and the models and prices they offer.
 */
final readonly class AiProviders
{
    /** The container tag a provider is registered under, from its part's own provider's register(). */
    public const string TAG = 'teme.ai.providers';

    public function __construct(private Container $container) {}

    /**
     * Every provider, in the order registered, each key checked.
     *
     * @return list<AiProvider>
     */
    public function all(): array
    {
        $providers = [];
        $seen = [];

        foreach ($this->container->tagged(self::TAG) as $provider) {
            if (! $provider instanceof AiProvider) {
                throw new UnexpectedValueException('Registered as an AI provider, but '.get_debug_type($provider).' isn\'t one.');
            }

            AiKeys::check('AI provider', $provider->key(), $seen);
            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * The provider with this key, or null.
     */
    public function find(string $key): ?AiProvider
    {
        foreach ($this->all() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The model the choice names, or null when its provider or the model is
     * gone.
     */
    public function model(ModelRef $ref): ?AiModel
    {
        foreach ($this->find($ref->provider)?->models() ?? [] as $model) {
            if ($model->id === $ref->model) {
                return $model;
            }
        }

        return null;
    }

    /**
     * What these tokens cost at the model chosen, in micro-dollars, or null
     * when the provider, the model or its price is unknown (I-1302).
     */
    public function cost(ModelRef $ref, AiUsage $usage): ?int
    {
        return $this->model($ref)?->price?->cost($usage);
    }
}
