<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Notices;

use App\Domain\Notices\NoticeSender;
use App\Domain\Plugins\Doors\ActingPerson;
use App\Domain\Plugins\PluginKeys;
use InvalidArgumentException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\Notices;

/**
 * A plugin's Notices door (docs/plans/plugin-sockets-3.md): it sends
 * only the kinds the plugin declares, a starter's kind only to the person
 * the plugin's work acts for, messages held to PluginNoticeRules, at most
 * NoticeRate's deliveries an hour, and never to an address. Core's sender
 * delivers after commit and decides who receives it.
 */
final readonly class PluginNotices implements Notices
{
    /**
     * @param  array<string, string>  $audiences  each declared kind's id and its audience
     */
    public function __construct(
        private string $name,
        private string $code,
        private array $audiences,
        private NoticeSender $sender,
        private NoticeRate $rate,
    ) {}

    public function send(string $kind, array $accountIds, NoticeMessage $message): void
    {
        $id = $this->declaredId($kind);
        PluginNoticeRules::check($message, $this->code);

        $ids = array_values(array_unique(array_map(strval(...), $accountIds)));

        if ($this->audiences[$id] === DeclaredNotices::STARTER) {
            $this->onlyTheStarter($ids);
        }

        $ids = array_slice($ids, 0, $this->rate->take($this->name, count($ids)));

        if ($ids !== []) {
            $this->sender->send(DeclaredNotices::key($this->code, $id), $ids, $message);
        }
    }

    public function sendToAddress(string $kind, string $email, NoticeMessage $message): void
    {
        throw new InvalidArgumentException(__('A plugin sends notices only to people with an account.'));
    }

    /**
     * The declared id a kind names: its id, or its full key under the plugin's stem.
     *
     * @throws InvalidArgumentException for a kind the plugin doesn't declare
     */
    private function declaredId(string $kind): string
    {
        $stem = PluginKeys::stem($this->code).'.';
        $id = str_starts_with($kind, $stem) ? substr($kind, strlen($stem)) : $kind;

        if (! array_key_exists($id, $this->audiences)) {
            throw new InvalidArgumentException(__('The plugin doesn’t declare the kind of notice :kind.', ['kind' => $kind]));
        }

        return $id;
    }

    /**
     * @param  list<string>  $ids
     *
     * @throws InvalidArgumentException unless the one recipient is the person the plugin's work acts for
     */
    private function onlyTheStarter(array $ids): void
    {
        try {
            if (count($ids) !== 1) {
                throw new WriteRefused('');
            }

            ActingPerson::require($ids[0]);
        } catch (WriteRefused) {
            throw new InvalidArgumentException(__('This kind of notice goes only to the person the plugin’s work acts for.'));
        }
    }
}
