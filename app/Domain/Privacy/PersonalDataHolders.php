<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Ai\Privacy\AiActivityHolder;
use App\Domain\Comments\Privacy\CommentsHolder;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Inbox\Privacy\InboxHolder;
use App\Domain\People\Privacy\PeopleHolder;
use App\Domain\Plugins\Privacy\PluginConsentHolder;
use App\Domain\Security\Privacy\SecurityLogHolder;
use App\Domain\Settings\Privacy\SiteSettingsHolder;
use App\Domain\Users\Privacy\AccountHolder;
use App\Domain\Users\Privacy\PictureHolder;
use App\Domain\Users\Privacy\SignInHolder;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\PersonalDataHolder;

/**
 * Every holder of personal data a privacy request walks (I-3375): core's,
 * in a fixed order, then the plugins' through CollectPersonalDataHolders.
 * Each key is checked here, so two holders never answer for one entry.
 */
final class PersonalDataHolders
{
    /**
     * Core's holders, in the order a request asks them.
     *
     * @var list<class-string<PersonalDataHolder>>
     */
    public const array CORE = [
        CommentsHolder::class,
        AccountHolder::class,
        SignInHolder::class,
        SecurityLogHolder::class,
        SiteSettingsHolder::class,
        InboxHolder::class,
        AiActivityHolder::class,
        PluginConsentHolder::class,
        PeopleHolder::class,
        PictureHolder::class,
        PrivacyRequestsHolder::class,
    ];

    /**
     * @param  list<class-string<PersonalDataHolder>>  $core
     */
    public function __construct(
        private readonly HookDispatcher $hooks,
        private readonly array $core = self::CORE,
    ) {}

    /**
     * Returns every holder, core's first, then the plugins' in listener
     * order.
     *
     * @return list<PersonalDataHolder>
     *
     * @throws ValidationException when a key is malformed or two holders share one
     */
    public function all(): array
    {
        /** @var list<PersonalDataHolder> $plugins */
        $plugins = $this->hooks->collect(new CollectPersonalDataHolders);
        $holders = [...array_map(fn (string $class): PersonalDataHolder => app($class), $this->core), ...$plugins];
        $seen = [];

        foreach ($holders as $holder) {
            $key = $holder->key();

            if (preg_match(SendToInbox::KEY, $key) !== 1) {
                throw ValidationException::withMessages(['holders' => __('The holder key “:key” isn’t lower-case words joined by hyphens and dots.', ['key' => $key])]);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['holders' => __('Two holders of personal data share the key :key.', ['key' => $key])]);
            }

            $seen[$key] = true;
        }

        return $holders;
    }

    /**
     * Returns every holder by its key, in all()'s order.
     *
     * @return array<string, PersonalDataHolder>
     *
     * @throws ValidationException when a key is malformed or two holders share one
     */
    public function byKey(): array
    {
        $holders = [];

        foreach ($this->all() as $holder) {
            $holders[$holder->key()] = $holder;
        }

        return $holders;
    }

    /**
     * Returns the keys of core's holders, whose reasons are core's own
     * fixed sentences.
     *
     * @return list<string>
     */
    public function coreKeys(): array
    {
        return array_map(fn (string $class): string => app($class)->key(), $this->core);
    }
}
