<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

/**
 * The admin screens the written guides point at, named by route under
 * `screens:` in each page's front matter, and the pictures of them in
 * docs/screenshots/guides that the Screenshots workflow keeps.
 */
final class GuideScreens
{
    /**
     * Screens that cannot be pictured, by route name, each with its reason in
     * plain words. They are left out of the pictures' index.
     */
    public const array SKIPPED = [
        'invitation.show' => 'Reached only through the link in an invitation email, which carries a one-time token that is used up when the page is opened.',
        'my-jobs.index' => 'Not a page: it hands the admin\'s jobs tray its list of running and finished jobs as data.',
        'tools.backups.keys.index' => 'Not a page: it sends the reader on to Tools › Backups, where the recovery keys are managed.',
        'content.revisions.index' => 'Not a page: it hands the editor\'s History pane its list of versions as data. The pane itself is in the picture of content.edit.',
    ];

    /**
     * The caption of a screen whose own heading holds a greeting or the name of
     * a demo item, by route name. Every other screen is captioned with its own
     * heading, which names the screen and never the demo's data.
     */
    public const array LABELS = [
        'content.compare' => 'Compare versions',
        'content.edit' => 'The editor',
        'dashboard' => 'Home',
        'navigation.edit' => 'A menu',
    ];

    /** The folder the pictures and their index are kept in, from the repository's root. */
    public const string FOLDER = 'docs/screenshots/guides';

    /**
     * Every route named under `screens:` in any guide, once each, sorted.
     *
     * @return list<string>
     */
    public static function named(): array
    {
        $names = [];

        foreach (['editors', 'operators'] as $audience) {
            foreach (GuideFile::in($audience) as $page) {
                foreach ((array) ($page->frontMatter()['screens'] ?? []) as $name) {
                    $names[(string) $name] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * The index of pictures as the repository holds it.
     *
     * @return array<string, array{label: string, light: string, dark: string}>
     */
    public static function index(): array
    {
        $text = @file_get_contents(GuideFile::root().'/'.self::FOLDER.'/index.json');

        return $text === false ? [] : (array) json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    }
}
