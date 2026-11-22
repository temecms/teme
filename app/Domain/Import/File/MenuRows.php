<?php

/**
 * An export file's classic menus in the companion's `menus` shape. Each menu
 * item is kept as it is read; once the whole file is read, the items are put
 * under their menus, and an item that shows its target's own title takes it
 * from the file. A file doesn't say where menus sit, or a term's address.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use Closure;

final class MenuRows
{
    /**
     * Returns the kept row of one `nav_menu_item` item, or null when it names no menu.
     *
     * @param  array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: list<array{key: string, value: string}>, comments: list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $item
     * @param  array<string, list<string>>  $meta  by key, every value
     * @param  array<string, array<mixed>|null>  $serialized  the read values, by key
     *
     * @return array{list: string, id: int, payload: array<string, mixed>}|null
     */
    public static function item(array $item, array $meta, array $serialized, FileLookups $lookups): ?array
    {
        $menu = null;

        foreach ($item['taxonomies'] as $category) {
            if ($category['domain'] === 'nav_menu' && $category['nicename'] !== '') {
                $menu = $lookups->term('nav_menu', $category['nicename'], $category['name']);
            }
        }

        if ($menu === null) {
            return null;
        }

        $f = $item['fields'];
        $id = (int) ($f['wp:post_id'] ?? 0);
        $value = fn (string $key): string => ContentRows::first($meta, $key) ?? '';
        $classes = $serialized['_menu_item_classes'] ?? null;

        return ['list' => ItemRows::MENU_ITEMS, 'id' => $id, 'payload' => [
            'menu' => $menu,
            'id' => $id,
            'parent' => (int) $value('_menu_item_menu_item_parent'),
            'order' => (int) ($f['wp:menu_order'] ?? 0),
            'type' => $value('_menu_item_type'),
            'object' => $value('_menu_item_object'),
            'object_id' => (int) $value('_menu_item_object_id'),
            'url' => $value('_menu_item_url'),
            'title' => $f['title'] ?? '',
            'target' => $value('_menu_item_target'),
            'attr_title' => $f['excerpt:encoded'] ?? '',
            'classes' => is_array($classes) ? array_values(array_map(strval(...), array_filter($classes, fn (mixed $class): bool => is_scalar($class) && (string) $class !== ''))) : [],
            'xfn' => $value('_menu_item_xfn'),
        ]];
    }

    /**
     * Returns the rows of every menu with its items in order, from the kept
     * items and the file's menu terms. $post and $term answer a target's
     * title and address by ID, or null when the file hasn't it.
     *
     * @param  list<array<string, mixed>>  $items  the kept items' payloads
     * @param  list<array<string, mixed>>  $menus  the `nav_menu` terms' payloads
     * @param  Closure(int): (array{title: string, url: string}|null)  $post
     * @param  Closure(int): (array{title: string, url: string}|null)  $term
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    public static function assemble(array $items, array $menus, Closure $post, Closure $term): array
    {
        usort($items, fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        $rows = [];

        foreach ($menus as $menu) {
            $id = self::int($menu['id'] ?? 0);
            $own = [];

            foreach ($items as $item) {
                if (self::int($item['menu'] ?? 0) !== $id) {
                    continue;
                }

                $type = self::text($item['type'] ?? '');
                $objectId = self::int($item['object_id'] ?? 0);
                $title = self::text($item['title'] ?? '');
                $target = match ($type) {
                    'post_type' => $post($objectId),
                    'taxonomy' => $term($objectId),
                    default => null,
                };

                $own[] = [
                    'id' => self::int($item['id'] ?? 0),
                    'parent' => self::int($item['parent'] ?? 0),
                    'order' => self::int($item['order'] ?? 0),
                    'type' => $type,
                    'object' => self::text($item['object'] ?? ''),
                    'object_id' => $objectId,
                    'url' => $type === 'custom' ? self::text($item['url'] ?? '') : ($target['url'] ?? ''),
                    'label' => $title !== '' ? $title : ($target['title'] ?? ''),
                    'label_is_custom' => $title !== '',
                    'target' => self::text($item['target'] ?? ''),
                    'attr_title' => self::text($item['attr_title'] ?? ''),
                    'classes' => is_array($item['classes'] ?? null) ? array_values($item['classes']) : [],
                    'xfn' => self::text($item['xfn'] ?? ''),
                    'invalid' => false,
                ];
            }

            $rows[] = ['list' => ItemRows::MENUS, 'id' => $id, 'payload' => [
                'id' => $id,
                'name' => self::text($menu['name'] ?? ''),
                'slug' => self::text($menu['slug'] ?? ''),
                'items' => $own,
            ]];
        }

        return $rows;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
