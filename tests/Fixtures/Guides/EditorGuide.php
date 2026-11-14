<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

/**
 * The pages the editors' guide must have, and the roles table its first page
 * must keep true.
 */
final class EditorGuide
{
    /** Every page, by its path under docs/guides/editors/. */
    public const array PAGES = [
        'README.md',
        'signing-in.md',
        'your-account.md',
        'writing.md',
        'publishing.md',
        'revisions-and-preview.md',
        'lists.md',
        'media.md',
        'organising.md',
        'comments.md',
        'redirects.md',
        'settings.md',
        'page-cache.md',
        'status-banners.md',
        'backups.md',
    ];

    /** What a page is: the index, or a set of tasks. */
    public const array KINDS = ['index', 'task'];

    /** The roles table's columns, in order. */
    public const array ROLES = ['contributor', 'author', 'editor', 'administrator'];

    /**
     * The rows of the roles table in the first page, each the permission
     * that makes it true.
     */
    public const array ABILITIES = [
        'Write and save your own drafts' => 'content.edit.own',
        'Write and edit pages' => 'page.create',
        'Publish your own work' => 'content.publish',
        'Edit work written by others' => 'content.edit.any',
        'Delete any item' => 'content.delete.any',
        'Upload media' => 'media.upload',
        'Edit any media' => 'media.edit.any',
        'Organise categories and tags' => 'terms.manage',
        'Manage menus' => 'navigation.manage',
        'Moderate comments' => 'comments.moderate',
        'Manage redirects' => 'redirects.manage',
        'Clear the page cache' => 'page-cache.clear',
        'Change settings' => 'settings.general.manage',
        'Manage people' => 'users.manage',
        'Manage backups' => 'backups.manage',
    ];

    /**
     * The pages as a dataset, each under its own name.
     *
     * @return array<string, array{GuideFile}>
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (self::PAGES as $name) {
            $pages[$name] = [self::page($name)];
        }

        return $pages;
    }

    public static function page(string $name): GuideFile
    {
        return new GuideFile("docs/guides/editors/{$name}");
    }
}
