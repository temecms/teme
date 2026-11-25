<?php

declare(strict_types=1);

// uiComponent() and uiTsxFiles() live in tests/Pest.php.

it('draws a flush sidebar, not an inset one', function (): void {
    expect(uiComponent('components/app-sidebar.tsx'))
        ->not->toContain('variant="inset"')
        ->toContain('<Sidebar collapsible="icon">');
});

it('holds View site, Customise menu and Fold the menu in the sidebar foot', function (string $words, string $hook): void {
    expect(uiComponent('components/app-sidebar.tsx'))
        ->toContain($words)
        ->toContain($hook);
})->with([
    'the site link' => ['View site', 'data-test="view-site"'],
    'the customise button' => ['Customise menu', 'data-test="personalise-menu"'],
    'the fold button' => ['Fold the menu', 'data-test="collapse-menu"'],
]);

it('keeps the menu coming from the server, as the navigation test needs', function (): void {
    expect(uiComponent('components/app-sidebar.tsx'))
        ->toContain('usePage().props')
        ->not->toContain('contentIndex(')
        ->not->toContain('termsIndex(');
});

it('moves the account menu out of the sidebar', function (): void {
    expect(uiComponent('components/app-sidebar.tsx'))
        ->not->toContain('NavUser')
        ->not->toContain('AccountMenu');
});

it('marks the site with its initial in the serif on the accent, its name over the product line, and keeps the folded link named', function (): void {
    $brand = uiComponent('components/brand.tsx');

    expect($brand)->toContain('data-test="sidebar-brand"')
        ->toContain('group-data-[collapsible=icon]:sr-only')
        ->not->toContain('group-data-[collapsible=icon]:hidden')
        ->toContain('font-serif')
        ->toContain('bg-accent')
        ->toContain('Later (I-1390)')
        ->and(uiComponent('hooks/use-brand.ts'))->toContain(' · ');
});

it('names the drawer under 768 pixels as the main menu', function (): void {
    expect(uiComponent('components/ui/sidebar.tsx'))
        ->toContain('<SheetTitle>Main menu</SheetTitle>')
        ->toContain('The menu for every admin screen.')
        ->not->toContain('Displays the mobile sidebar.');
});

it('puts Search or jump to… at the top of the sidebar, opening the palette', function (): void {
    expect(uiComponent('components/app-sidebar.tsx'))
        ->toContain('Search or jump to…')
        ->toContain('teme:palette')
        ->toContain('data-test="palette-button"');
});

it('puts the crumbs at the start of the top bar, then the jobs, the site status, the bell and the account menu', function (): void {
    $header = uiComponent('components/app-sidebar-header.tsx');

    expect($header)->toContain('<header')
        ->toContain('Later (I-1391)')
        ->and(strpos($header, '<Crumbs'))->toBeInt()
        ->and(strpos($header, '<Crumbs'))->toBeLessThan(strpos($header, '<JobsButton'))
        ->and(strpos($header, '<JobsButton'))->toBeLessThan(strpos($header, '<SiteStatus'))
        ->and(strpos($header, '<SiteStatus'))->toBeLessThan(strpos($header, '<InboxBell'))
        ->and(strpos($header, '<InboxBell'))->toBeLessThan(strpos($header, '<AccountMenu />'));
});

it('moves the account menu to the header', function (): void {
    expect(uiComponent('components/app-sidebar-header.tsx'))->toContain('<AccountMenu />');

    $menu = uiComponent('components/account-menu.tsx');

    expect($menu)->toContain('data-test="account-menu-button"')
        ->toContain('Account: ')
        ->toContain('Later (I-1393)')
        ->and(uiComponent('components/user-menu-content.tsx'))
        ->toContain('Sign out')
        ->toContain('data-test="logout-button"')
        ->toContain('Profile')
        ->toContain('Security')
        ->toContain('Preferences');
});

it('lays the page out as a header, then the main content, with no follow-you banner', function (): void {
    $layout = uiComponent('layouts/app/app-sidebar-layout.tsx');
    $header = strpos($layout, '<AppSidebarHeader');
    $content = strpos($layout, '<AppContent');

    expect($header)->toBeInt()
        ->and($content)->toBeGreaterThan($header)
        ->and($layout)->not->toContain('<StatusBanner')
        ->and(uiComponent('components/app-content.tsx'))
        ->toContain('<main')
        ->toContain('id="main-content"')
        ->not->toContain('SidebarInset');
});

it('has one shell, so no variant of it remains', function (): void {
    expect(uiComponent('types/ui.ts'))->not->toContain('AppVariant')
        ->and(uiComponent('components/app-shell.tsx'))->not->toContain('variant');
});

it('draws the banner as a labelled region with its text in the foreground colour', function (): void {
    $banner = uiComponent('components/status-banner.tsx');

    expect($banner)->toContain('role="region"')
        ->toContain('aria-label="Site notice"')
        ->toContain('data-test="site-notice"')
        ->toContain('Later (I-1392)')
        ->not->toContain('role="status"')
        ->not->toContain('Dismiss');
});

it('draws the trail of a page in the top bar, the page itself last, and no trail above the page', function (): void {
    expect(uiComponent('components/patterns/crumbs.tsx'))
        ->toContain('data-test="crumbs"')
        ->toContain("__('Where you are')")
        ->toContain('aria-current="page"')
        ->and(uiComponent('layouts/app/app-sidebar-layout.tsx'))->not->toContain('<PageTrail')
        ->and(file_exists(resource_path('js/components/page-trail.tsx')))->toBeFalse();
});

it('gives the crumbs’ switcher a 44 pixel target on coarse pointers', function (): void {
    expect(uiComponent('components/patterns/crumbs.tsx'))->toContain('pointer-coarse:size-11');
});

it('draws every toast through the one component, with a Dismiss and the timings the build decided', function (): void {
    $toast = uiComponent('components/flash-toast.tsx');

    expect($toast)->toContain('toast.custom')
        ->toContain('export function showToast(')
        ->toContain('export function showFlashToast(')
        ->toContain('data-test="flash-toast"')
        ->toContain('data-test="flash-toast-dismiss"')
        ->toContain('aria-label="Dismiss"')
        ->toContain('6_000')
        ->toContain('10_000')
        ->toContain('Warning: ')
        ->toContain('Error: ')
        ->and(uiComponent('hooks/use-flash-toast.ts'))
        ->toContain('showFlashToast')
        ->not->toContain('toast[');
});

it('sets the container where toasts stack, at the foot of the screen in the middle', function (): void {
    expect(uiComponent('components/ui/sonner.tsx'))
        ->toContain('position="bottom-center"')
        ->toContain('offset={24}')
        ->toContain('visibleToasts={3}')
        ->toContain("'--width': '400px'");
});

it('says sign in on the sign-in screens, in plain words', function (): void {
    $flat = fn (string $path): string => (string) preg_replace('/\s+/', ' ', uiComponent($path));

    expect($flat('pages/auth/login.tsx'))
        ->toContain("title={__('Sign in')}")
        ->toContain("lead={__('Use your email address and password.')}")
        ->toContain('data-test="login-button"')
        ->not->toContain('Log in')
        ->and($flat('pages/auth/forgot-password.tsx'))->toContain("{__('Back to sign in')}")
        ->and($flat('pages/auth/verify-email.tsx'))
        ->toContain('Sign out')
        ->toContain('Send the link again')
        ->and($flat('pages/auth/confirm-password.tsx'))
        ->toContain("title={__('Confirm your password')}")
        ->toContain("{__('Cancel')}");
});

it('frames the sign-in screens with the site\'s mark, one main and one h1', function (): void {
    $layout = uiComponent('components/patterns/auth-page.tsx');

    expect($layout)->toContain('<SignInBrand')
        ->toContain('<main')
        ->toContain('<h1')
        ->not->toContain('AppLogoIcon');
});

it('has no test that presses the sign-in button by its words', function (): void {
    $files = [...(glob(base_path('tests/Browser/*.php')) ?: []), base_path('tests/Pest.php')];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect((string) file_get_contents($file))
            ->not->toContain("press('Log in')")
            ->not->toContain("press('Sign in')");
    }
});

it('has removed the starter kit\'s leftovers', function (string $path): void {
    expect(file_exists(resource_path($path)))->toBeFalse();
})->with([
    'the second shell' => 'js/components/app-header.tsx',
    'the second shell\'s layout' => 'js/layouts/app/app-header-layout.tsx',
    'the placeholder pattern' => 'js/components/ui/placeholder-pattern.tsx',
    'the starter mark' => 'js/components/app-logo-icon.tsx',
    'the old logo block' => 'js/components/app-logo.tsx',
    'the tab control for light and dark' => 'js/components/appearance-tabs.tsx',
    'the old breadcrumbs' => 'js/components/breadcrumbs.tsx',
    'the sidebar\'s account block' => 'js/components/nav-user.tsx',
    'the sidebar\'s link list' => 'js/components/nav-footer.tsx',
    'the framework\'s welcome page' => 'views/welcome.blade.php',
]);

it('imports none of the removed components and keeps the starter\'s words out of every screen', function (): void {
    $removed = ['components/app-header', 'layouts/app/app-header-layout', 'components/ui/placeholder-pattern', 'components/app-logo-icon', 'components/app-logo', 'components/appearance-tabs', 'components/breadcrumbs', 'components/nav-user', 'components/nav-footer'];

    foreach (uiTsxFiles() as $file) {
        $source = uiComponent($file);

        foreach ($removed as $module) {
            expect($source)->not->toMatch('#from \'@/'.preg_quote($module, '#').'\'#');
        }

        expect($source)->not->toMatch('/\bLog (in|out)\b/')
            ->not->toContain('registration');
    }
});

it('frames Account with one h1, its line and a sub-navigation, and the pages no longer carry their own h1', function (string $page): void {
    expect(uiComponent("pages/account/{$page}.tsx"))->not->toContain('<h1');
})->with([
    'profile' => 'profile',
    'security' => 'security',
    'preferences' => 'preferences',
]);

it('names the account frame\'s list and links', function (): void {
    expect(uiComponent('layouts/account/layout.tsx'))
        ->toContain('<AccountNav />')
        ->not->toContain('Your profile, sign-in and interface preferences')
        ->and(uiComponent('layouts/account/account-nav.tsx'))
        ->toContain("aria-label={__('Account')}")
        ->toContain("title: __('Profile')")
        ->toContain("title: __('Preferences')")
        ->toContain("title: __('Notifications')")
        ->toContain("title: __('Security')");
});

it('offers Appearance as a labelled segmented control in Preferences', function (): void {
    expect(uiComponent('pages/account/preferences.tsx'))
        ->toContain('<Segmented')
        ->toContain('aria-labelledby="appearance-mode-label"')
        ->toContain("{__('Colors')}")
        ->toContain("{__('Match my computer')}")
        ->toContain('Light, dark, or whatever your computer is set to. Only you see it.')
        ->not->toContain('AppearanceTabs');
});
