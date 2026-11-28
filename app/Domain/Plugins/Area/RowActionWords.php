<?php

/**
 * The words a custom row action asks and flashes: the plugin's own, or
 * core's defaults when it declares none. Never splits or rewrites a label.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

final class RowActionWords
{
    /**
     * Fills {action}, {name} and {plugin} in a template the plugin or core wrote.
     *
     * @param  array{action?: string, name?: string, plugin?: string}  $vars
     */
    public static function fill(string $template, array $vars): string
    {
        $pairs = [];

        foreach ($vars as $key => $value) {
            $pairs['{'.$key.'}'] = $value;
        }

        return strtr($template, $pairs);
    }

    /**
     * The confirm dialog's words, or null when the action does not ask first.
     * A string confirm is the dialog's text; title, button and cancel use defaults.
     *
     * @param  array{title?: mixed, text?: mixed, button?: mixed, cancel?: mixed}|string|null  $confirm
     * @param  callable(string): string  $words
     *
     * @return array{title: string, text: string|null, button: string, cancel: string}|null
     */
    public static function confirm(string $label, array|string|null $confirm, callable $words): ?array
    {
        if ($confirm === null) {
            return null;
        }

        $button = self::buttonLabel($label);

        if (is_string($confirm)) {
            $text = trim($confirm);

            return [
                'title' => self::fill(__('Run “{action}” on “{name}”?'), ['action' => $label]),
                'text' => $text === '' ? null : $words($text),
                'button' => $button,
                'cancel' => __('Cancel'),
            ];
        }

        $title = is_string($confirm['title'] ?? null) && trim($confirm['title']) !== ''
            ? self::fill($words(trim($confirm['title'])), ['action' => $label])
            : self::fill(__('Run “{action}” on “{name}”?'), ['action' => $label]);
        $text = is_string($confirm['text'] ?? null) && trim($confirm['text']) !== ''
            ? $words(trim($confirm['text']))
            : null;
        $declaredButton = is_string($confirm['button'] ?? null) && trim($confirm['button']) !== ''
            ? $words(trim($confirm['button']))
            : $button;
        $cancel = is_string($confirm['cancel'] ?? null) && trim($confirm['cancel']) !== ''
            ? $words(trim($confirm['cancel']))
            : __('Cancel');

        return [
            'title' => $title,
            'text' => $text,
            'button' => $declaredButton,
            'cancel' => $cancel,
        ];
    }

    /**
     * The toast template after a row action queues; may still hold {name}.
     *
     * @param  callable(string): string  $words
     */
    public static function toast(string $label, ?string $toast, callable $words): string
    {
        if ($toast !== null && trim($toast) !== '') {
            return self::fill($words(trim($toast)), ['action' => $label]);
        }

        return self::fill(__('Queued: {action} for “{name}”.'), ['action' => $label]);
    }

    /**
     * The quiet pill after status while the job runs.
     *
     * @param  callable(string): string  $words
     */
    public static function queued(string $label, ?string $queued, callable $words): string
    {
        if ($queued !== null && trim($queued) !== '') {
            return $words(trim($queued));
        }

        return __('Queued');
    }

    /**
     * The confirm button's default: the label without a trailing ellipsis.
     */
    public static function buttonLabel(string $label): string
    {
        return (string) preg_replace('/…$/u', '', $label);
    }
}
