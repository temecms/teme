<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tree;

/**
 * The fixture theme that draws a page's trail, its parent and the pages
 * under it, as a theme using the view data would (I-3363), so a test can
 * read every one in the page's HTML.
 */
final class FamilyTheme
{
    /**
     * Makes it the active theme.
     */
    public static function activate(): void
    {
        config(['teme.theme_paths' => [base_path('tests/Fixtures/Tree/themes/family')]]);
        themeActivate('family');
    }
}
