<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Columns;

use InvalidArgumentException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Fixture plugin that offers three list columns; its mode setting switches
 * how the handlers answer so the list-column tests can drive each path.
 */
final class Columns implements HasListColumns, Plugin
{
    public function __construct(
        private readonly SettingsReader $settings,
    ) {}

    public function register(Hooks $hooks): void
    {
        //
    }

    /**
     * @return array<string, ListColumn>
     */
    public function listColumns(): array
    {
        $mode = $this->mode();

        return [
            'score' => new ScoreColumn($mode),
            'views' => new ViewsColumn($mode),
            'note' => new NoteColumn($mode),
        ];
    }

    private function mode(): string
    {
        try {
            $mode = $this->settings->string('plugin.columns', 'mode');
        } catch (InvalidArgumentException) {
            return 'answer';
        }

        return $mode !== '' ? $mode : 'answer';
    }
}
