# Interface part I: the sign-in pages on one card

**One part, one branch.** Every page a person meets before the admin (sign in, set up the site, forgot and reset password, accept an invitation, verify email, two-factor, confirm password) is drawn on one card, `AuthPage`. The error pages people meet outside a theme and the holding page draw the same card in Blade, from the same tokens. When sign-in is paused after too many tries, the page counts the wait down live.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. It builds on the design-system foundation, which is on `feat/interface-foundation` (and on `main` once that branch has merged). Everything this part needs from the foundation is written out below, under "What this part relies on"; Task 0 checks it is there. This plan is lighter (I-569): it writes out the decisions, names, tests and the delicate code, and describes the rest.

**Goal.** The pages before the admin look like the admin the moment someone signs in: one centered card at most 420px wide, the site's own mark at its head, `Field`, `Button` and `Notice` inside, and a phone layout that works at 320px. Nothing about signing in changes but how it is drawn, and the one number the countdown needs.

**Architecture.** A new pattern, `components/patterns/auth-page.tsx`, is the root of every page under `pages/auth/` (the design check's rule R7 already accepts its name); the two auth layouts are deleted and the layout resolver gives `auth/` pages none. The throttled sign-in already says how long to wait in its message; the same number is flashed as a timestamp and handed to the sign-in page as one prop, `retryAfter`. The Blade pages share one anonymous component, `x-teme::standalone`, whose stylesheet is the admin's token block read from `resources/css/app.css` and rewritten as plain CSS (`App\Http\Standalone\StandaloneStyle`), so the card has one source of values with no build step, no script and no file to fetch.

**Proposal:** `docs/proposals/sign-in-pages.md` (accepted; its "Settled" section is binding).

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Laravel Fortify, Inertia 3 (`@inertiajs/react` 3.9), React 19, TypeScript, Tailwind 4, shadcn/ui "new-york" on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1.

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 4.1 Button, 4.3 TextButton, 4.6 Select, 4.11 Field, 4.13 Status pill, 4.22 Notice, 4.44 Auth card, section 5 (page patterns), section 7 (accessibility), section 8 (writing), section 9 (the check). `docs/architecture/security.md` and `SECURITY.md` commitments 10 and 11. Every measure this part needs is also written out below.

**Bound by:** I-3439 (every new word in American English), I-3373 (every word through `__()`), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369, I-100 (Fortify's own credential state), I-1390 (a site icon, later: it will replace the letter mark wherever this part draws it).

**New dependencies, migrations, permissions, operations, routes, settings, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree, and what this plan does

1. **The proposal's "Not in" list** puts the error pages, the holding page and a live countdown outside this part. Its "Settled" section moves all three in. **Settled wins.**
2. **"Server work: none."** A live countdown needs the wait as a number; today it exists only inside a translated sentence. **One small server change** (S1): the throttle flashes when sign-in opens again, and the sign-in page receives it as `retryAfter`. The message, the status, the counters and every refusal stay exactly as they are.
3. **"Forgotten your password?"** in the proposal is British. **Today's "Forgot your password?" stays** (I-3439).
4. **"Who invited you and as what"** on the invitation page: the server sends only the token and the email address. Adding the inviter and the role is server work this part does not do. **The page shows the address it was sent to** (open point 1).
5. **"A clear state for an expired link"** (reset) and the setup code's wrong and expired states: the server says so only when the form is sent, on the field. **They stay on the field**, drawn by `Field`; nothing is checked ahead.
6. **Where the mark sits.** Today it sits above the card; DS 4.44 puts it at the head of the card ("the brand mark, then the title"). **It moves into the card.**
7. **The Blade pages cannot use the React components:** the holding page's policy forbids every file and script (`default-src 'none'; style-src 'unsafe-inline'`), and an error page must render when the app cannot. **They draw the same card in Blade**, from the token block itself (S3), with no script.
8. **"The public error pages."** Errors on the public site are drawn by the theme, and core's fallback template only when a theme fails; a theme owns the site's look. **This part draws Laravel's own error pages**: what anyone meets outside a theme (admin addresses, the sign-in and setup pages, maintenance). Core's fallback template is unchanged (open point 2).
9. **Passkey sign-in** is drawn as "Soon" in the reference design; there is no server support. **Nothing is drawn for it.**
10. **The setup page's time zone** is a native `<select>` (a baseline line). **It becomes `SelectField`** (DS 4.6), its value submitted through the hidden input `SelectField` writes.
11. **The 403 and 404 "inside the admin"** variants in the reference design need the admin to render its own error pages through Inertia. **Not in** (open point 3).

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `ui/` and `patterns/`; it never edits an existing one or a token. A change to them is asked of the maintainers, who make them once on the integration branch. This part adds `patterns/auth-page.tsx` and edits no existing `ui/` or `patterns/` file.
- **The components used, as they are:** `Field` and `FieldInline` (`ui/field.tsx`: props `htmlFor`, `label`, `help?`, `error?`, `required?`, `errorTestId?`, one child control; it writes the help line as `#{id}-help` and the error line as `#{id}-error`, and gives the child `aria-describedby` and `aria-invalid`), `Input`, `Checkbox`, `SelectField` (`options`, `name`, `defaultValue`, `id`), `Button` (variants `primary`, `neutral`, `ghost`; sizes `md`, `sm`, `icon-sm`; `loading`), `TextButton` (`asChild` for a `Link`), `Notice` (`tone: 'info' | 'ok' | 'warn' | 'danger'`, `title?`, `action?`, and any `div` prop such as `role` or `data-test`; its icon is `aria-hidden`), `InputOTP`, `Spinner`; `BrandMark` and `useBrand()`/`brandLine()` from `components/brand.tsx` and `hooks/use-brand.ts`.
- **The values used here** (all tokens in `app.css`): `bg-page`, `bg-surface`, `text-ink`, `text-ink-2`, `text-ink-3`; type `text-caption` 12px, `text-small` 13px, `text-body` 15px, `text-title` 24px; `rounded-xl` 16px; `shadow-float`; `h-(--ctl-h)` and `min-h-(--ctl-h)`: 36px, and 44px on a coarse pointer or under 768px; `max-w-105` (Tailwind's spacing scale, 420px). Under 640px every input takes 16px text already (the token block does it), so a phone does not zoom.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs a Node scanner (`tests/Fixtures/design-system-scan.mjs`) over `resources/js` and `resources/css` (not Blade) and compares what it finds with `tests/Architecture/design-system-baseline.txt` (one line per offense: `rule<TAB>path<TAB>match`, the path relative to `resources/`). The rules met here: **R1** arbitrary `[…]` values; **R2** text sizes other than the six tokens; **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R4** raw colors; **R5** raw `<button>`, `<input>` (not `hidden`), `<select>`, `<textarea>` outside `ui/` and `patterns/`; **R7** a page whose default export does not return a page pattern (`AuthPage` is one), or a page setting `p-*`, `px-*`, `py-*`, `max-w-*`, `mx-auto` on its top two levels; **R10** `outline-none` without a `focus-visible:` outline; **R14** `transition`, `duration-N`, and `animate-*` outside `ui/` without `motion-safe:`. **Lowering the baseline:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()` in the same file. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists, in `tests/Architecture/admin-words-baseline.txt`, every word a person reads that does not yet pass through `__()` or `__n()` from `@/lib/i18n`. New words always go through them. Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its "never lets the baseline grow" case by as many. Blade and PHP words go through Laravel's `__()`.
- **Test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn(User $person)` signs in through the real form (`[data-test="login-button"]`, password `password`) and returns the page at Home; `cmNavigate($page, string $url)` visits in one attempt; `cmWaitUntil($page, string $jsCondition)` polls a condition in the page; `shellSettled($page, string $selector)` waits until those elements have no animation of their own; `cmSmallTargets($page)` returns `['small' => [...], 'checked' => n]` for targets under 24px; `cmAxeViolations($page)` returns axe's violations; `cmSettings(array $values, string $group)` stores settings (`cmSettings(['site_title' => '…'], 'general')`); `uiComponent(string $path)` reads a source under `resources/js`.
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. Select by the attributes the components write (`id`, `name`, `role`, `aria-*`, `data-test`). A page's text is waited for with `cmWaitUntil(…innerText.includes…)`, never a single `assertSee`. Run only the browser files this part touches; GitHub runs the full set after the merge.
- **Security properties that hold** (`SECURITY.md`): **10.** Sign-in and recovery never reveal whether an account exists, not by message, status code, throttling behavior or response time. **11.** No one can lock another person out of their account. The counters are keyed on what was submitted (address and IP, IP, address from a browser that has never signed in to it), never on whether an account exists (`App\Http\Admin\Auth\SignInThrottle`).

---

## The decisions, written out

**S1. The wait reaches the page, revealing nothing.** In `App\Http\Admin\Auth\ThrottleSignIn::handle()`, in the branch that already refuses (after `$seconds = RateLimiter::availableIn(...)` and before the `throw`):

```php
// The countdown on the sign-in page (proposal sign-in-pages, settled 3): the moment the
// message's own number runs out, never more than the refusal already says.
$request->session()->flash(self::RETRY_AT, Date::now()->getTimestamp() + $seconds);
```

with `public const string RETRY_AT = 'teme.sign_in_retry_at';` on the class and `use Illuminate\Support\Facades\Date;`. In `FortifyServiceProvider::configureViews()`, `Fortify::loginView` passes one more prop:

```php
'retryAfter' => self::retryAfter($request),
```

```php
/**
 * Seconds until sign-in opens again, from the moment the throttle flashed for
 * this browser's last refused attempt; null when nothing was refused.
 */
private static function retryAfter(Request $request): ?int
{
    $at = $request->session()->get(ThrottleSignIn::RETRY_AT);

    if (! is_int($at)) {
        return null;
    }

    $seconds = $at - Date::now()->getTimestamp();

    return $seconds > 0 ? $seconds : null;
}
```

**Why it reveals nothing.** The number is the one the refusal already prints (`auth.throttle`: "Too many sign-in attempts. Try again in :seconds seconds."), from the same counter, in the same response. It is written only in the branch every throttled attempt takes, for a real address and an invented one alike, before any account is looked up; it adds no lookup, no hashing and no branch on the account, so the timing floor and the status (a redirect with the same error) are unchanged. It is flashed into the session of the browser that was refused, so it tells that browser only what it was just told. The limits, their keys and their decays are not touched, so commitment 11 holds as before. The button the countdown disables is a convenience; the server's throttle stays the only gate.

**S2. The site's mark.** No site icon exists yet (a later idea, I-1390). **The site's own mark is its letter mark**: the first letter or digit of its title (`brand.mark`, as the sidebar draws it), in the serif on the accent, with the site's name and the product line beside it. **Teme's mark** (`/teme/icon.svg`) and the name "Teme" stand in when the site has none: on the setup page (no site yet), when the title is empty, on the holding page (no site yet), and on an error page that cannot read the title (S4). When I-1390 lands, its picture replaces the letter in `BrandMark` and in `StandalonePage::brand()`, nowhere else.

**S3. The standalone stylesheet.** `app/Http/Standalone/StandaloneStyle.php`, written out (delicate: it is the Blade pages' only source of values):

```php
<?php

declare(strict_types=1);

namespace App\Http\Standalone;

/**
 * The admin's design tokens as plain CSS, for the pages drawn outside the
 * admin's app (the error pages and the holding page): read from the token
 * block of resources/css/app.css, so they never drift from it.
 */
final class StandaloneStyle
{
    private static ?string $tokens = null;

    /**
     * The token block as plain CSS: Tailwind's `@theme` values as `:root`,
     * the dark values under the system's dark scheme; an empty string when
     * the file or its markers are missing, so the page still renders.
     */
    public function tokens(): string
    {
        return self::$tokens ??= $this->build((string) @file_get_contents(resource_path('css/app.css')));
    }

    /**
     * The plain CSS for one source of app.css.
     */
    public function build(string $source): string
    {
        $start = strpos($source, '/* @tokens */');
        $end = strpos($source, '/* @end-tokens */');

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        return $this->rewrite((string) preg_replace('#/\*.*?\*/#s', '', substr($source, $start, $end - $start)));
    }

    /**
     * Each top-level rule, rewritten: `@theme` becomes `:root` holding only
     * its declarations; `@theme inline` (Tailwind's color names) goes;
     * `.dark` becomes `:root` under `prefers-color-scheme: dark`; a media
     * query is rewritten inside; anything else is kept as written.
     */
    private function rewrite(string $css): string
    {
        $out = '';

        foreach ($this->rules($css) as [$prelude, $body]) {
            $out .= match (true) {
                $prelude === '@theme' => ':root{'.$this->declarationsOnly($body).'}',
                str_starts_with($prelude, '@theme') => '',
                $prelude === '.dark' => '@media (prefers-color-scheme: dark){:root{'.$body.'}}',
                str_starts_with($prelude, '@media') => $prelude.'{'.$this->rewrite($body).'}',
                default => $prelude.'{'.$body.'}',
            };
        }

        return $out;
    }

    /**
     * The top-level rules of $css: each prelude and its body, braces matched.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rules(string $css): array
    {
        $rules = [];
        $depth = 0;
        $prelude = '';
        $body = '';

        foreach (str_split($css) as $char) {
            if ($char === '{' && $depth++ === 0) {
                continue;
            }

            if ($char === '}' && --$depth === 0) {
                $rules[] = [trim($prelude), $body];
                $prelude = '';
                $body = '';

                continue;
            }

            if ($depth === 0) {
                $prelude .= $char;
            } else {
                $body .= $char;
            }
        }

        return $rules;
    }

    /**
     * A block's own declarations: nested blocks (the shimmer's keyframes)
     * and Tailwind's `--name-*: initial` resets removed.
     */
    private function declarationsOnly(string $body): string
    {
        $flat = (string) preg_replace('/[^;{}]*\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $body);

        return (string) preg_replace('/--[a-z0-9-]+-\*\s*:\s*initial\s*;/i', '', $flat);
    }
}
```

The memo lasts the process; `app.css` changes only with a deploy. The page's fonts fall back to the system stack inside `--font-sans` (the holding page's policy forbids font files; the Blade pages load none). Dark follows the computer's scheme: a person's own Light or Dark choice is not read here (nothing about a person is).

**S4. `StandalonePage`.** `app/Http/Standalone/StandalonePage.php`, a final readonly class with `SettingsReader` and `ShellBrand` injected:
- `brand(): array{name: string|null, mark: string|null}`: the site's title and `ShellBrand::forViewer(false)['mark']`, inside `try { … } catch (Throwable) { return ['name' => null, 'mark' => null]; }` (an error page must render when the database cannot); `name` null when the title is empty. Null means Teme's mark (S2).
- `temeMark(): string`: `public/teme/icon.svg`'s markup with its `<title>` removed and `aria-hidden="true" focusable="false" width="36" height="36"` added to the `<svg>` tag; `''` when the file cannot be read. Inline, so the holding page's policy (no files) allows it.
- `home(): array{url: string, label: string}`: on an address under the admin's own path (`trim(parse_url(route('dashboard'), PHP_URL_PATH), '/')`, and below it), `route('dashboard')` with "Back to Home"; anywhere else `url('/')` with "Go to the home page".

**S5. The Blade card.** `resources/views/teme/components/standalone.blade.php` (anonymous component, used as `<x-teme::standalone>`). Props: `title` (the tab's title), `holding` (bool), `error` (`int|null`), `refreshSeconds` (`int|null`), `brand` (S4's array, default both null). It writes a whole document: `lang` from the locale, `charset`, `viewport`, `<meta name="robots" content="noindex">`, the refresh meta only when `refreshSeconds` is given, `<title>`, then one `<style>` holding `{!! app(StandaloneStyle::class)->tokens() !!}` followed by the card's own rules, then `<body class="sa-page">` and **exactly** `<main data-holding>` (holding) or `<main data-error="{{ $error }}">`, which is the card: the brand row (`.sa-brand`: the letter mark `.sa-mark` or `temeMark()`, then the name `.sa-name` when there is one), then the slot. **No `<script>`, no `<link>`, no image file.**

The card's own rules read tokens only (a test holds them to it): `.sa-page` is `background: var(--page)`, `color: var(--ink)`, `font: var(--text-body)/1.5 var(--font-sans)`, a grid centering the card with padding 32px 16px, and under 640px (`@media (max-width: 39.99rem)`) the card at the top with padding 20px 16px. `main` is `width: min(420px, 100%)`, `background: var(--surface)`, `border-radius: var(--radius-xl)`, `box-shadow: var(--shadow-float)`, padding 32px (20px under 640), a grid with gap 20px. `.sa-mark` is 36px square, `border-radius: var(--radius-md)`, `background: var(--accent)`, `color: var(--on-accent)`, `font: 600 var(--text-lead)/1 var(--font-serif)`. `h1` is `font-size: var(--text-title)`, weight 600, line height 1.2, margin 0. Quiet text is `var(--ink-2)` at `var(--text-body)`; the foot line `var(--ink-3)` at `var(--text-small)`. `.sa-pill` is a 24px pill (`border-radius: var(--radius-full)`, padding 0 10px, `var(--text-small)` 500), with `.sa-pill-warn` (`--warn-soft` / `--warn-ink`), `.sa-pill-danger` (`--danger-soft` / `--danger-ink`), `.sa-pill-info` (`--info-soft` / `--info-ink`). `.sa-notice` is the Notice drawn in CSS: `background: var(--info-soft)`, `border-radius: var(--radius-md)`, padding 16px 20px. `.sa-button` is a link drawn as the primary button: `min-height: var(--ctl-h)`, padding 0 16px, `border-radius: var(--radius-sm)`, `background: var(--accent)`, `color: var(--on-accent)`, weight 500, hover `var(--accent-hover)`, `:focus-visible` an outline of 2px `var(--ring)` offset 2px. `hr` is a 1px `var(--line)` rule. Every `font-size` reads `var(--text-*)`; no color is written but through `var(--…)`.

**S6. The error pages.** `resources/views/errors/403.blade.php`, `404`, `419`, `429`, `500`, `503`, and `4xx` and `5xx` for any other code (Laravel looks for the code, then its class). Each is `<x-teme::standalone :error="…" :brand="app(StandalonePage::class)->brand()" …>` holding: a pill, the `h1`, the text, the action (S4's `home()` as `.sa-button`), and a foot line. Laravel renders these only for requests a theme does not answer (the public site's errors still go through the theme, `bootstrap/app.php`), so nothing on the public site changes. The words, all through `__()`, `:site` being the site's name or "this site":

| Code | Pill | Heading | Text | Foot |
|---|---|---|---|---|
| 403 | Error 403 (warn) | You can’t open this page | Your account doesn’t have permission to do that. If you need it, ask an administrator of :site to change your role. | |
| 404 | Error 404 (warn) | We can’t find that page | The address may be mistyped, or the item may have moved to the Trash. | |
| 419 | Error 419 (warn) | This page has expired | For your safety, pages stop accepting changes after a while. Nothing you typed was saved. Go back, reload the page and try again. | Drafts in the editor are saved as you type, so they are not affected. |
| 429 | Error 429 (warn) | Too many tries | Wait a minute, then try again. | |
| 500 | Error 500 (danger) | Something went wrong on our side | Teme couldn’t finish that. Drafts you were editing are saved as you type, so nothing is lost. The problem has been written to the log. | If it keeps happening, tell whoever runs the site. |
| 503 | Maintenance (info) | Down for maintenance | :site is being updated. This usually takes a few minutes. Nothing you saved is affected. | This page checks again by itself every 20 seconds. |
| 4xx | Error :code (warn) | This page can’t be shown | Go back and try again, or start from Home. | |
| 5xx | Error :code (danger) | Something went wrong on our side | (as 500) | (as 500) |

The 503 passes `refreshSeconds: 20` only for a `GET` request (a refreshed `POST` address could answer 405), and its foot line only then. The response's status and headers (`Retry-After`) are Laravel's, unchanged.

**S7. The holding page.** `resources/views/teme/holding.blade.php` becomes `<x-teme::standalone :holding="true" :title="…">` with the brand null (Teme's mark: the site has no name yet) and, in order: the address line (`p.hold-addr`, the warn dot before it, "Address: " for screen readers only), the `h1` "This site is being set up and will open soon.", an `hr`, the notice (`div.sa-notice`: "Is this your site?" in 600, "Finish setting it up on the setup page. You’ll need the setup code from the end of the deploy log.", and "Set up the site" as `.sa-button` to `$setupUrl`), and the foot line (new): "Visitors see this page until the first administrator account exists. Search engines are asked to leave it out." `HoldUntilSetUp`, its status, headers and policy are not changed; its view still receives only its two values.

**A1. `AuthPage`.** `resources/js/components/patterns/auth-page.tsx`:

```ts
type AuthPageProps = {
    /** The h1, and the tab's title unless `tabTitle` is given. */
    title: string;
    lead?: ReactNode;
    tabTitle?: string;
    /** 'teme' on the setup page, where the site has no name yet. */
    brand?: 'site' | 'teme';
    /** Above the form: a sent link, a changed password, a paused sign-in. */
    notices?: ReactNode;
    /** Under the form: the other ways on, each a TextButton link 44px tall on touch. */
    links?: ReactNode;
    children: ReactNode;
};
```

It renders `<Head title={tabTitle ?? title} />` and a `main` (`bg-page flex min-h-svh flex-col items-center gap-6 px-4 pt-5 pb-8 sm:justify-center sm:py-8`) holding the card (`data-slot="auth-card"`, `bg-surface shadow-float grid w-full max-w-105 gap-5 rounded-xl p-5 sm:p-8`): `SignInBrand`, then a `grid gap-1` with the `h1` (`text-title text-ink font-semibold`) and the lead (`text-body text-ink-2`), then `notices`, the form (children), and `links` (`grid justify-items-start`). Under the card, the credit, on these pages only: `<p data-test="powered-by" className="text-caption text-ink-3 flex items-center gap-2">` with `<img src="/teme/icon.svg" alt="" aria-hidden="true" width={16} height={16} />` and `__('Powered by Teme')`. Every link in `links` is `<TextButton asChild className="min-h-(--ctl-h)"><Link …>…</Link></TextButton>`.

**A2. `SignInBrand`** (in `components/brand.tsx`; `SidebarBrand` and `BrandMark`'s sidebar tone untouched): a prop `teme?: boolean`. A row at the card's head, start-aligned, a link to the site's home (`home()`) keeping `data-test="sign-in-brand"`: `BrandMark tone="sidebar"` (the 36px serif letter on the accent, `data-test="brand-mark"`), or, when `teme` is set or the shared `name` is empty, `<img src="/teme/icon.svg" alt="" width={36} height={36}>`; then the name (`text-body text-ink font-semibold`; "Teme" in Teme's case) over `brandLine(brand)` (`text-caption text-ink-3`). `BrandMark`'s `screen` tone, used nowhere else, goes.

**A3. The password field.** `components/password-input.tsx` keeps its export and props (its other callers do not change). The raw `<button>` becomes `<Button type="button" variant="ghost" size="icon-sm">` placed at the input's end; it is **reachable by Tab** (no `tabIndex={-1}`), named `__('Show password')` or `__('Hide password')`, with `aria-pressed` and `aria-controls` naming the input's `id`. The input keeps every prop it is given (`id`, `name`, `autoComplete`, `aria-*`).

**A4. The paused sign-in.** `resources/js/components/auth/sign-in-paused.tsx`, written out:

```tsx
import { useEffect, useRef, useState } from 'react';
import { Notice } from '@/components/ui/notice';
import { __, __n } from '@/lib/i18n';

/** The wait in words: minutes while a minute or more is left, then seconds. */
function waitWords(seconds: number): string {
    return seconds >= 60
        ? __n(':count minute|:count minutes', Math.ceil(seconds / 60))
        : __n(':count second|:count seconds', seconds);
}

/**
 * The notice a paused sign-in shows: the wait, counted down each second, then
 * gone, calling `onDone`. Read aloud once, as it first appears; the count
 * that changes is for the eye only.
 */
export function SignInPaused({ seconds, onDone }: { seconds: number; onDone: () => void }) {
    const [endsAt] = useState(() => Date.now() + seconds * 1000);
    const [left, setLeft] = useState(seconds);
    const done = useRef(onDone);
    done.current = onDone;

    useEffect(() => {
        const timer = window.setInterval(() => {
            const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));

            setLeft(remaining);

            if (remaining === 0) {
                window.clearInterval(timer);
                done.current();
            }
        }, 1000);

        return () => window.clearInterval(timer);
    }, [endsAt]);

    if (left === 0) {
        return null;
    }

    return (
        <Notice tone="warn" role="alert" data-test="sign-in-paused">
            <span className="sr-only">
                {__('Too many tries. Wait :time, then try again.', { time: waitWords(seconds) })}
            </span>
            <span aria-hidden="true" data-test="sign-in-paused-wait">
                {__('Too many tries. Wait :time, then try again.', { time: waitWords(left) })}
            </span>
        </Notice>
    );
}
```

**A5. The sign-in page.** `pages/auth/login.tsx` takes `retryAfter: number | null` beside today's props. `const [waiting, setWaiting] = useState(retryAfter !== null)`, reset when the prop changes (a `key={retryAfter ?? 'open'}` on `SignInPaused` and an effect setting `waiting` from the prop). `notices`: `status` as `<Notice tone="ok">`; `firstRun` as `<Notice tone="info" data-test="first-run-notice" action={<TextButton asChild><Link href={FirstRunController.show()}>{__('Set up the site')}</Link></TextButton>}>{__('This site has no accounts yet.')}</Notice>`; `retryAfter !== null` as `<SignInPaused key={…} seconds={retryAfter} onDone={() => setWaiting(false)} />`. While `retryAfter` is set, the email field shows no error of its own (it holds the same sentence as the notice); otherwise a wrong address or password is named on the email field as today. The fields: `Field` "Email address" (`Input id="email" name="email" type="email" autoComplete="email" required autoFocus`), `Field` "Password" (`PasswordInput id="password" name="password" autoComplete="current-password" required`), `FieldInline` "Remember me" (`Checkbox id="remember" name="remember"`, when `canRemember`), then `<Button type="submit" variant="primary" className="w-full" loading={processing} disabled={waiting} data-test="login-button">{__('Sign in')}</Button>`. `links`: "Forgot your password?" (when `canResetPassword`). Title "Sign in", lead "Use your email address and password." The placeholders go (a label says it).

**A6. The other seven pages**, each returning `<AuthPage …>` as its root with its words through `__()`, keeping every `id`, `name` and `data-test` it has today:
- `set-up.tsx`: `brand="teme"`; title "Set up your site", lead "Name your site and make its first administrator account."; today's fields on `Field`, the command in the code field's help as today (`#code-help code`), the time zone on `SelectField` (`id="timezone" name="timezone"`, the browser's zone by default as today); `data-test="set-up-button"`.
- `forgot-password.tsx`: title "Forgot password", lead "Enter your email to receive a password reset link"; `status` as an ok notice; `data-test="email-password-reset-link-button"`; `links`: "Back to sign in".
- `reset-password.tsx`: title "Reset password", lead "Choose a new password."; email (read only, as today), password and confirmation on `Field` with `PasswordInput`; `data-test="reset-password-button"`.
- `accept-invitation.tsx`: title "Choose your password", lead "Choose a password to finish setting up your account."; the email it was sent to (read only), password and confirmation; `data-test="accept-invitation-button"`.
- `verify-email.tsx`: title "Email verification", lead "Click the link we emailed you to confirm your address."; the sent status as an ok notice "Sent. Check your email for the new link."; "Resend verification email" (`variant="neutral"`); `links`: "Sign out" (the logout route, as today).
- `two-factor-challenge.tsx`: `tabTitle` "Two-factor sign-in"; title and lead switch as today ("Authentication code" / "Type the 6-digit code your authenticator app shows."; "Recovery code" / "Type one of your recovery codes."); the code (`InputOTP`) or the recovery code on `Field`; "Continue" (`variant="primary"`), then the other way as its own button under it (`variant="neutral"`); `setLayoutProps` goes.
- `confirm-password.tsx`: title "Confirm password", lead "Confirm your password to continue."; `PasswordInput id="password"` on `Field`; `data-test="confirm-password-button"`.

**A7. The layout resolver.** In `resources/js/app.tsx`, only the `auth/` case changes: it returns no layout (`null`, with the comment `// AuthPage is each sign-in page's own frame.`), so each page's own `AuthPage` is the frame. Every page's `.layout` static goes. `layouts/auth-layout.tsx` and `layouts/auth/auth-simple-layout.tsx` are deleted, and `AuthLayoutProps` leaves `types/ui.ts` (its only user was the deleted layout). If Inertia 3's `layout` option cannot answer "none" (check its documentation for the resolver's return type), STOP.

---

## Owns

**Create:** `resources/js/components/patterns/auth-page.tsx`; `resources/js/components/auth/sign-in-paused.tsx`; `app/Http/Standalone/StandaloneStyle.php`; `app/Http/Standalone/StandalonePage.php`; `resources/views/teme/components/standalone.blade.php`; `resources/views/errors/{403,404,419,429,500,503,4xx,5xx}.blade.php`; `tests/Feature/Auth/SignInPausedTest.php`; `tests/Feature/Admin/StandalonePagesTest.php`; `tests/Browser/SignInPagesTest.php`.

**Modify:** `resources/js/pages/auth/*.tsx` (all eight); `resources/js/components/password-input.tsx`; `resources/js/components/brand.tsx` (`SignInBrand` and `BrandMark`'s `screen` tone only); `resources/js/app.tsx` (the `auth/` case only); `resources/js/types/ui.ts` (`AuthLayoutProps` only); `resources/views/teme/holding.blade.php`; `app/Http/Admin/Auth/ThrottleSignIn.php`; `app/Providers/FortifyServiceProvider.php` (`configureViews()`'s login view only). Tests, meaning unchanged: `tests/Feature/Admin/ShellTest.php` (two cases), `tests/Feature/Admin/WordingPassSettingsTest.php` (three cases), `tests/Feature/Admin/AccessibilityTest.php` (one case), `tests/Feature/Site/HoldingPageTest.php` (one case), `tests/Browser/FirstRunTest.php` (one selector); `tests/Architecture/design-system-baseline.txt` and `DesignSystemTest.php` (lines removed, counts lowered); `tests/Architecture/admin-words-baseline.txt` and `AdminWordsTest.php` (lines removed, count lowered).

**Delete:** `resources/js/layouts/auth-layout.tsx`, `resources/js/layouts/auth/auth-simple-layout.tsx`.

**Not this part's, and not touched:** `components/ui/**` and the token block (the foundation's); `components/input-error.tsx` and `components/text-link.tsx` (the auth pages stop importing them; others still do); the parts running beside this one: `components/ui/combobox.tsx` and `components/term-picker.tsx` (the Combobox); `pages/settings/**`, `layouts/settings/**`, `components/fields/**` (settings); `pages/users/**`, `pages/account/**`, `layouts/account/**` (people and account); `pages/{themes,types,taxonomies,navigation,regions,inbox}/**` (the remaining screens). `PasswordInput` is used by people, account and backups screens: A3 keeps its props, so their files do not change.

## Global Constraints

- **Rule 1:** nothing here changes state through a new path. Sign-in, setup, reset, invitation, verification and confirmation post where they post today; the flash in S1 is session state the throttle already owns (Fortify's credential flow, I-100). No operation is added, so no authorization-failure test applies.
- **Rule 3:** PostgreSQL only; feature tests use `RefreshDatabase` as their siblings do.
- **Rule 5:** `packages/teme-contracts` is not touched.
- **Commitments 10 and 11** (above) hold: no message, status, header, timing or counter changes. The security tests below stay green, unchanged.
- **No new dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **No token and no change to an existing `ui/` or `patterns/` file** (the seams). If a page needs one, STOP.
- **Every word a person reads goes through `__()` or `__n()`** (`AdminWordsTest`); new words are American English (I-3439). Never raise the admin-words count.
- **The design-system baseline only shrinks.** At the end, no line names `js/pages/auth/`, `js/layouts/auth`, `js/components/password-input.tsx`, `js/components/brand.tsx`, `js/components/patterns/auth-page.tsx` or `js/components/auth/`. Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.
- **The Blade pages load nothing:** no `<script>`, `<link>`, image file or font; every value through `var(--…)` from S3.
- **Tests first:** each test below is run red for the reason given before the code. An architecture-style source test is planted red first.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `SECURITY.md`, `lang/en/auth.php`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public. A code comment cites at most one I-number or one document path (I-797). Every new class in `app/` opens with a one-to-three-sentence docblock; every public query method says what it returns.
- Helpers used by one test file stay in it with its prefix (`sp` for sign-in pages, `sa` for standalone); `tests/Pest.php` is not edited.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: `export PATH=/opt/node24/bin:$PATH`; if still missing, stop, push and record it. PostgreSQL stopped: `docker compose up -d`, then run the check again before believing a failure. A test red at the base and not this part's: record it, never change it. A restarted session reads Records first, treats anything after the last checkpoint as untested, and never rewrites history.

---

## Task 0: Preconditions, then branch

Run in a checkout of the integration branch. If any line differs **by its text**, STOP and write why under Records. `<base>` is `origin/feat/interface-foundation`, or `origin/main` once the foundation has merged there.

- [ ] **Step 1:** `git fetch origin && git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" <base>` → one commit. If it is missing, STOP. Record the base's head.
- [ ] **Step 2:**
  - `php -v` shows 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers;
  - `ls resources/js/components/patterns/auth-page.tsx resources/js/components/auth app/Http/Standalone resources/views/errors 2>&1` → none exists;
  - `ls resources/js/components/ui/{field,notice,text-button,select-field,button,checkbox,input,input-otp}.tsx` → all exist; `grep -n "'AuthPage'" tests/Fixtures/design-system-scan.mjs` → one line (R7 accepts it);
  - `grep -n "case name.startsWith('auth/'):" -A1 resources/js/app.tsx` → the case, then `return AuthLayout;`; `grep -n "export function SignInBrand" resources/js/components/brand.tsx` → one line; `grep -rn "tone=\"screen\"\|BrandMark" resources/js --include=*.tsx` lists only `components/brand.tsx`; `grep -rn "AuthLayoutProps" resources/js` → `types/ui.ts` and `layouts/auth/auth-simple-layout.tsx` only;
  - `grep -n "tabIndex={-1}" resources/js/components/password-input.tsx` → one line;
  - `grep -n "RateLimiter::availableIn" app/Http/Admin/Auth/ThrottleSignIn.php` → one line; `grep -n "Fortify::loginView" app/Providers/FortifyServiceProvider.php` → one line; `grep -n "'throttle' =>" lang/en/auth.php` → `'Too many sign-in attempts. Try again in :seconds seconds.'`;
  - `grep -n "view('teme::holding'" app/Http/Middleware/HoldUntilSetUp.php` → one line; `grep -n "style-src 'unsafe-inline'" app/Http/Middleware/HoldUntilSetUp.php` → one line; `grep -n "PublicPage::error" bootstrap/app.php` → one line;
  - `grep -c "/\* @tokens \*/\|/\* @end-tokens \*/" resources/css/app.css` → 2; `grep -n "^@theme {\|^@theme inline {\|^\.dark {" resources/css/app.css` → one `@theme {`, one `@theme inline {`, two `.dark {`;
  - `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmSmallTargets|cmAxeViolations|cmSettings|uiComponent)\(" tests/Pest.php` → 8;
  - `php artisan test tests/Architecture/DesignSystemTest.php` and `php artisan test tests/Architecture/AdminWordsTest.php` → green; record `grep -cP "\tjs/(pages/auth/|layouts/auth|components/password-input|components/brand\.tsx)" tests/Architecture/design-system-baseline.txt` (33 where this plan was written: R1 2, R2 16, R3 4, R5 2, R7 8, R14 1), `grep -cP "^(pages/auth/|layouts/auth|components/password-input)" tests/Architecture/admin-words-baseline.txt` (59), and `AdminWordsTest`'s count (`grep -n "toHaveCount(" tests/Architecture/AdminWordsTest.php`).
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records (Laravel, Fortify, Inertia both sides, React, Tailwind, Vite, Pest, the browser plugin). `git checkout -b ui/sign-in-pages <base>`.

## Task 1: The wait reaches the sign-in page (S1)

**Files:** `app/Http/Admin/Auth/ThrottleSignIn.php`, `app/Providers/FortifyServiceProvider.php`, `tests/Feature/Auth/SignInPausedTest.php`.

**Tests, written first** (`tests/Feature/Auth/SignInPausedTest.php`, `uses(RefreshDatabase::class)`; a file-local `spFail(string $email, string $ip): TestResponse` posts a wrong password from `$ip` and calls `Auth::forgetGuards()`):

| Case | What it proves |
|---|---|
| "gives the sign-in page no wait when nothing was refused" | `get('/admin/login')` → `->where('retryAfter', null)`; after one wrong password from a fresh IP, the same |
| "gives the sign-in page the wait the refusal names, after a throttled try" | `freezeTime()`; five failures for `editor@example.com` from `198.51.100.20`, a sixth refused (`assertSessionHasErrors('email')`, the message contains "Too many"); `get('/admin/login')` → `retryAfter` is the number in the message (parsed with `/(\d+) seconds/`), and 900 under the default settings |
| "gives the same wait for a real address and an invented one" | `freezeTime()`; a real account `known@example.com` and `nobody@example.com`, each failed five times then refused, each from its own IP; the two sign-in pages' `retryAfter` are equal, and the two refusals' status codes and error bags are equal (as `NoEnumerationTest` compares them) |
| "counts down from the moment of the refusal" | `freezeTime()`, refused; `travel(100)->seconds()`; the page's `retryAfter` is 800; `travel(800)->seconds()`; it is null |
| "forgets the wait after the page has shown it once" | refused; the first `get('/admin/login')` has a number, the second has null (a flash) |

- [ ] **Step 1:** run the file: the first case fails on a missing `retryAfter` prop; record each first line.
- [ ] **Step 2:** S1 as written. Run the file until green. Plant `+ 1` on the flashed timestamp: the second case red; remove it; record the line.
- [ ] **Step 3:** run unchanged, all green: `tests/Feature/Security/NoEnumerationTest.php`, `SignInThrottlingTest.php`, `PasswordUpgradeOnSignInTest.php`, `SecurityLogTest.php`, `tests/Feature/Auth/` (whole folder), `tests/Feature/Admin/ShellBrandTest.php`. A red one is a STOP.
- [ ] **Step 4:** quick check; commit `feat(auth): the sign-in page learns how long sign-in is paused`; push; the Records line.

## Task 2: The Blade card, the error pages and the holding page (S3 to S7)

**Files:** `app/Http/Standalone/{StandaloneStyle,StandalonePage}.php`; `resources/views/teme/components/standalone.blade.php`; `resources/views/errors/*.blade.php`; `resources/views/teme/holding.blade.php`; `tests/Feature/Admin/StandalonePagesTest.php`; `tests/Feature/Site/HoldingPageTest.php` (one case).

**Tests, written first** (`tests/Feature/Admin/StandalonePagesTest.php`, `uses(RefreshDatabase::class)`; `beforeEach` registers, inside the `web` middleware group, `Route::get('/admin/_sa-error/{code}', fn (int $code) => abort($code))` and a `POST` twin, and stores `cmSettings(['site_title' => 'The Garden City Ledger'], 'general')`):

| Case | What it proves |
|---|---|
| "turns the admin's token block into plain CSS" | `app(StandaloneStyle::class)->tokens()` contains `--page:`, `--text-body: 0.9375rem`, `--radius-xl: 16px`, `--accent-l:`, and `@media (prefers-color-scheme: dark){:root{`; contains none of `@theme`, `.dark`, `--color-*`, `@keyframes`, `/*` |
| "declares every value the card reads" | every `var(--name)` in `standalone.blade.php`'s own rules is declared (`--name:`) in `tokens()` |
| "writes no color and no size of its own in the card's rules" | the component file's `<style>` text after `!!}` has no `#` followed by 3 to 8 hex digits, no `rgb(`, `hsl(`, `oklch(`, `white`, `black`; every `font-size:` and `font:` names a `var(--text-` |
| "keeps an empty stylesheet, not an error, when the markers are gone" | `build('body{}')` is `''` |
| "draws each error on the card with the site's mark and its own words" (dataset: 403, 404, 419, 429, 500, 503) | the status is the code; one `<main data-error="{code}">`; one `h1` with S6's heading; the site's name and the letter `T` in `.sa-mark`; a `.sa-button` to `route('dashboard')` saying "Back to Home"; no `<script`, `<link`, `<img`; no "Powered by Teme" |
| "keeps Laravel's status and Retry-After, and refreshes a GET only" | `abort(503, '', ['Retry-After' => '60'])` via a test route: the header is `60`, the page has the refresh meta of 20; the `POST` twin has no refresh meta |
| "draws any other code by its class" | 405 and 502 (test routes) render `data-error="405"` with "This page can’t be shown" and `data-error="502"` with "Something went wrong on our side" |
| "draws Teme's mark when the site's name cannot be read" | `$this->mock(SettingsReader::class)` whose `string()` throws; the 500 page still renders, holds the inline `<svg` with `aria-hidden="true"`, and no `.sa-name` |
| "leaves the public site's errors to the theme" | `get('/no-such-page-here')` → 404 without `data-error` |

**`HoldingPageTest`'s "draws the approved picture…" is rewritten to the new picture** (its meaning, the approved picture in its order, kept): `main[data-holding]`'s children are `div.sa-brand`, `p.hold-addr`, `h1`, `hr`, `div.sa-notice`, `p` (the foot), and the notice's are `p`, `p`, `a.sa-button`; the `style` contains `width: min(420px, 100%)` and `@media (prefers-color-scheme: dark){:root{`. Its other cases, and `assertSee('<main data-holding>', false)`, are not changed and must stay green.

- [ ] **Step 1:** run the new file and the rewritten case: they fail first on the missing class and views; record the first lines.
- [ ] **Step 2:** build S3 to S7. Run until green. Plant a `#fff` in the card's rules and remove `/* @end-tokens */` from a copy passed to `build()`: the two cases red; remove both; record the lines.
- [ ] **Step 3:** run unchanged, all green: the rest of `HoldingPageTest`, `tests/Feature/Security/AdminHeadersTest.php`, every file under `tests/Feature` naming `assertStatus(404)` or `assertForbidden` in `/admin` (`grep -rl -E "assertNotFound|assertStatus\(40[34]\)|assertForbidden" tests/Feature/Admin tests/Feature/Auth`), and `tests/Feature/Site` (the theme's error pages).
- [ ] **Step 4:** quick check; commit `feat(site): the error pages and the holding page on the one card`; push; the Records line.

## Task 3: `AuthPage` and the sign-in page (A1 to A5, A7)

**Files:** `patterns/auth-page.tsx`, `components/auth/sign-in-paused.tsx`, `components/brand.tsx`, `components/password-input.tsx`, `pages/auth/login.tsx`, `app.tsx`, `types/ui.ts`; delete the two layouts; `tests/Browser/SignInPagesTest.php`; `tests/Feature/Admin/ShellTest.php` (two cases).

**Tests, written first** (`tests/Browser/SignInPagesTest.php`, `uses(RefreshDatabase::class)`; `beforeEach` stores the site title "The Garden City Ledger" and makes `$this->editor` with the password `password`; a file-local `spNoSidewaysScroll($page): bool` returns `document.documentElement.scrollWidth <= window.innerWidth`):

| Case | What it proves |
|---|---|
| "draws sign-in on one card at most 420 pixels wide, the site's mark at its head, the credit under it" | At 1280×900: `[data-slot="auth-card"]`'s width is 420; `[data-test="sign-in-brand"]` is inside the card and before the `h1` "Sign in"; it shows "The Garden City Ledger" and `[data-test="brand-mark"]` "T"; `[data-test="powered-by"]` follows the card |
| "fits a 320 pixel screen: no sideways scroll, the card at the top, 16 pixel inputs and 44 pixel links" | `resize(320, 640)`: `spNoSidewaysScroll` true; the card's top under 24px; `#email`'s computed font size `16px`; the "Forgot your password?" link at least 44 tall |
| "names the password's show and hide button, reaches it by Tab, and shows what is typed" | Type in `#password`; Tab from it lands on the button named "Show password", `aria-pressed="false"`; Enter: the input's `type` is `text`, the button "Hide password", `aria-pressed="true"` |
| "names a wrong email or password on the field" | Sign in with a wrong password: `#email-error` holds the refusal's words; `#email` has `aria-invalid="true"` |
| "counts a paused sign-in down to zero, then lets the person try again" | Before visiting, `RateLimiter::hit(SignInThrottle::accountKey($this->editor->email), 3)` as many times as `login_untrusted_attempts_per_account` (10); sign in with the right password: `[data-test="sign-in-paused"]` appears with `role="alert"`, its count says 3 or 2 seconds, the login button is disabled and `#email-error` is absent; `cmWaitUntil` the notice is gone and the button is enabled (within 6 seconds); signing in then reaches `/admin` |
| "passes axe on sign-in in both schemes, at 1280 and 320 pixels, every target 24 pixels or more" | `cmAxeViolations` empty, `cmSmallTargets` empty with `checked > 0`, for each of the four |

If the seeded limiter is not seen by the page (the browser's server does not share the test's cache), replace that case's seeding with ten wrong passwords typed through the form, keep its assertions but the "3 or 2 seconds" and the wait to zero, and record it as `B-?? (draft)`.

**`ShellTest`'s two cases, meaning unchanged:** "says sign in on the sign-in screens, in plain words" pins the same words in their new form (`title={__('Sign in')}`, `lead={__('Use your email address and password.')}`, `data-test="login-button"`, no "Log in"; `{__('Back to sign in')}` in `forgot-password.tsx`; "Sign out" and "Sent. Check your email for the new link." in `verify-email.tsx`; `title={__('Confirm password')}` and "Confirm your password to continue." in `confirm-password.tsx`). "frames the sign-in screens with the site's mark, one main and one h1" reads `components/patterns/auth-page.tsx`: `<SignInBrand`, `<main`, `<h1`, no `AppLogoIcon`. (The four other pages' pins move with Task 4; run this file again then.)

- [ ] **Step 1:** write the browser cases; `npm run build`; run the file: the first case fails on `[data-slot="auth-card"]`; record. Rewrite the two `ShellTest` cases; run them: red on the old sources; record.
- [ ] **Step 2:** build A1 to A5 and A7. `npm run build`; run the browser file until green, then `ShellTest`'s two cases. Plant `tabIndex={-1}` back on the password button: the third case red; remove; record.
- [ ] **Step 3:** run `tests/Feature/Admin/ShellTest.php` (whole), `tests/Feature/Admin/ShellBrandTest.php`, `tests/Feature/Auth/`; `DesignSystemTest` and `AdminWordsTest`: remove the lines they list as gone for the files touched so far, lower the counts.
- [ ] **Step 4:** quick check; commit `feat(admin): the sign-in page on the one card, with a live wait when sign-in is paused`; push; the Records line.

## Task 4: The other seven pages (A6)

**Files:** `pages/auth/{set-up,forgot-password,reset-password,accept-invitation,verify-email,two-factor-challenge,confirm-password}.tsx`; `tests/Browser/SignInPagesTest.php` (one case); `tests/Feature/Admin/WordingPassSettingsTest.php` (three cases), `AccessibilityTest.php` (one case), `tests/Browser/FirstRunTest.php` (one selector).

**Test, written first:** "draws every sign-in page on the one card, with no sideways scroll at 320 pixels and no axe violation in either scheme" (dataset of how each page is reached): forgot password (`/admin/forgot-password`); reset password (`route('password.reset', ['token' => 'a-token', 'email' => $this->editor->email])`); accept an invitation (`route('invitation.show', ['token' => 'a-token', 'email' => 'new@example.com'])`); two-factor (a `User::factory()->withTwoFactor()` person signs in through the form); verify email (a `->unverified()` person signs in through the form, then `route('verification.notice')`); confirm password (`cmSignIn`, then `cmNavigate($page, route('password.confirm', [], false))`). For each, at 1280×900 and 320×640, light and dark: one `[data-slot="auth-card"]` holding the one `h1`; `spNoSidewaysScroll` true; `cmAxeViolations` empty; `cmSmallTargets` empty with `checked > 0`. The setup page is covered by `FirstRunTest`.

**Existing tests that change, meaning unchanged:**
- `WordingPassSettingsTest`: "says why the reader is on the sign-in screen above the form" finds `notices=` before `<Form` in `login.tsx`; "offers Back to sign in on its own, not inside a sentence" pins `{__('Back to sign in')}` inside a `TextButton` and still no "Or, return to"; "offers the other way to sign in as a button of its own under Continue" pins `variant="neutral"` after "Continue" and before `{authConfigContent.toggleText}`.
- `AccessibilityTest`'s "ties every field error to its field": an error drawn by `Field`'s `error` prop is tied by `Field` itself (`ui/field.tsx` writes `#{id}-error` and the control's `aria-describedby`). The case counts `<InputError` elements and `<Field … error=` props together, requires at least one per file, checks `id=` and the `aria-describedby` count against the `InputError`s only. The six auth rows stay in its dataset. Planted red: an auth page with its errors removed fails the "at least one" check.
- `FirstRunTest`: the hint's selector `#code-hint code` becomes `#code-help code` (`Field`'s help id); the case otherwise unchanged.

- [ ] **Step 1:** write the case; `npm run build`; run: red on the first page's card; record. Change the four tests above; run them: red on today's sources (the `AccessibilityTest` change green, the plant red); record.
- [ ] **Step 2:** move the seven pages as A6. `npm run build`; run `SignInPagesTest`, `FirstRunTest`, then the three feature files and `ShellTest` again, until green.
- [ ] **Step 3:** `DesignSystemTest` and `AdminWordsTest`: remove the rest of the owned files' lines, lower the counts. `grep -cP "\tjs/(pages/auth/|layouts/auth|components/password-input|components/brand\.tsx|components/patterns/auth-page|components/auth/)" tests/Architecture/design-system-baseline.txt` → 0; `grep -cP "^(pages/auth/|layouts/auth|components/password-input|components/patterns/auth-page|components/auth/)" tests/Architecture/admin-words-baseline.txt` → 0.
- [ ] **Step 4:** quick check; commit `feat(admin): every sign-in page on the one card`; push; the Records line.

## Task 5: The error pages in the browser

**File:** `tests/Browser/SignInPagesTest.php` (one case).

**Test:** "draws the admin's 404 and 403 on the card, passing axe in both schemes with nothing small" — `visit('/admin/no-such-screen')` (404) and, as `cmSignIn` of a person with the `editor` role, `cmNavigate` to an administrators-only screen (pick one whose route the editor's role is refused, recorded) (403). For each, light and dark: `main[data-error]` present, `cmAxeViolations` empty, `cmSmallTargets` empty with `checked > 0`, `spNoSidewaysScroll` true at 320×640, and no `[data-test="powered-by"]`.

- [ ] **Step 1:** write it; run: it passes or fails only on a fault in Task 2's card; fix the card, never the case. Record the result.
- [ ] **Step 2:** quick check; commit `test(site): the error pages pass axe on the card`; push; the Records line.

## Task 6: Gate and records

- [ ] The full `composer check` once.
- [ ] After `npm run build`, these browser files one at a time, each to a file, read: `SignInPagesTest`, `FirstRunTest`, `ShellTest`, `ShellResponsiveTest`, `TwoEditorsTest` (signs in through the form), `PeopleScreenTest` and `SaveBarTest` (the confirm-password page), `BackupsScreenTest` and `DesignCatalogueTest` (password fields that now have a reachable button). GitHub runs the rest after the merge. **A browser assertion that fails only because it measured the old look** (the card's width, a link's size) may be updated to the value written here and recorded as `B-?? (draft)`; **an assertion about behavior** (an order, focus, a word, a count, nothing scrolling sideways) that fails is a fault in this part: fix the page.
- [ ] Records: the commits; each test seen red (task, file, case, first line); the design-system counts per rule and the admin-words count before and after (expected, from this plan's base: R1 −2, R2 −16, R3 −4, R5 −2, R7 −8, R14 −1; admin words −59); every `B-?? (draft)`; and how to try it: sign out; sign in at 1280 and at 320 wide; press the eye; get the password wrong five times, try a sixth, and watch the wait count down (from 15 minutes under the default settings); visit `/admin/no-such-screen`; on a site with no accounts, visit `/`. Commit `docs(plans): interface part I records`; push.

---

## Security tests that must keep passing, unchanged

`tests/Feature/Security/NoEnumerationTest.php` (sign-in and reset answers identical for known and unknown addresses; the response-time floor on every credential endpoint), `SignInThrottlingTest.php` (made-up addresses throttled exactly as real ones; one address trying many accounts stopped; the account holder's own browser never held back; the per-account counter cleared on reset; forwarded headers ignored), `PasswordUpgradeOnSignInTest.php`, `SecurityLogTest.php` (`signin.throttled`), `AdminHeadersTest.php`, `tests/Feature/Auth/SignInWordsTest.php` (the throttle's words unchanged), and the rest of `tests/Feature/Auth/`. `SignInPausedTest` adds: the same wait for a real address and an invented one, and the wait never outliving the refusal.

## Every word

**Moved into `__()` unchanged:** every word the admin-words baseline lists today for `pages/auth/**`, `layouts/auth/**` and `components/password-input.tsx` (59 lines where this plan was written), "Powered by Teme" among them. The email placeholders (`email@example.com`) and the password placeholder go: a label says what each field holds.

**New, through `__()` or `__n()`** (American English): "Too many tries. Wait :time, then try again.", ":count second|:count seconds", ":count minute|:count minutes" (read at N = 1: "Too many tries. Wait 1 second, then try again."); the error pages' words in S6's table, "Back to Home", "Go to the home page", "this site", "Maintenance", "Error :code"; the holding page's foot line, "Visitors see this page until the first administrator account exists. Search engines are asked to leave it out.", and "Address: " (for screen readers).

## What must not be done

- Change a route, a status, a header, a message in `lang/`, a throttle's key, limit or decay, or anything a refused sign-in answers.
- Draw a passkey button, a "sign in with…" provider, or the admin's in-shell error pages.
- Change the theme's error pages, core's fallback templates (`resources/views/teme/fallback/**`), or `PublicPage`.
- Edit an existing `ui/` or `patterns/` file, a token, the scanner, or `tests/Pest.php`.
- Put a `<script>`, a `<link>`, an image file or a font in the Blade pages; widen the holding page's policy.
- Delete a test, or change a test's meaning; change a test not named here.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- the foundation commit is missing from the base, or a Task 0 line differs by its text;
- any security test above turns red, or keeping one green would need a change to it;
- a page needs a token or a change to an existing `ui/` or `patterns/` component;
- a design-system or admin-words line would have to be added or a count raised, or the scanner's allowed places widened;
- Inertia 3's layout resolver cannot give `auth/` pages no layout (A7);
- the token block's shape differs from Task 0's greps so that S3 would need another rewrite rule;
- a browser test not named here would have to change.

## Security

- **No account is revealed.** S1 flashes the number the refusal already prints, in the branch every throttled attempt takes, before any account is looked up; `SignInPausedTest` proves real and invented addresses get the same wait, and `NoEnumerationTest` proves the answers and the timing floor are unchanged.
- **No one is locked out by this part.** The counters, keys and decays are untouched; the countdown and the disabled button live in the refused browser only, and the server's throttle stays the only gate.
- **The Blade pages** inline only Teme's own files (the token block, Teme's icon), escape the site's name with `{{ }}`, load nothing, and keep the holding page's policy (`default-src 'none'; style-src 'unsafe-inline'`). An error page reads the site's title inside a `try`, so a failing database cannot turn a 500 into a blank page.
- **The password's eye** changes the input's `type` only; nothing typed is stored or sent anywhere new.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md` 4.44: the brand row at the card's head; the credit on the sign-in pages only; Teme's mark when the site has none; the Blade pages drawn on the same card from the token block (`StandaloneStyle`); `AuthPage`'s props.
- `docs/architecture/security.md`, sign-in throttling: the sign-in page receives the wait as `retryAfter`, flashed by `ThrottleSignIn` from the refusal's own number.
- Changelog line, at the release: "The sign-in pages, the error pages and the holding page share one new design, work on a 320-pixel phone, and count down the wait when sign-in is paused after too many tries."
- Baseline conflicts with the parts running beside this one: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.
- `resources/js/app.tsx` is touched here in one line (the `auth/` case); a part that changes another case merges beside it.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | The invitation page saying who invited you and as what | A small server change in the people part: `InvitationController::show` sends the inviter's name and the role's label for a valid token; the page shows them in its lead |
| 2 | Core's fallback error template (when a theme's own fails) stays bare | Leave it to the theme work: a public visitor should meet the site's look, not the admin's card |
| 3 | The admin's 403 and 404 inside its own shell, as the reference design draws them | Inertia 3's custom exception handling renders an admin error page in the shell for signed-in people; a later part, after the shell settles |
| 4 | A 419 during an Inertia visit opens Laravel's page in Inertia's modal (now the card) | Reload the page on 419 through Inertia's exception handling, in the same later part as 3 |
| 5 | The setup page's time zone on `SelectField` (about 420 zones in a list) | The Combobox replaces it once the Combobox has merged |
| 6 | The Blade pages follow the computer's dark scheme, not a person's own Light or Dark choice | Keep: they render without reading a person; the sign-in pages already follow the system for guests |

---

## Records

<!-- Filled in while building: commits; each test seen red (task, file, case, the failure's first line); the design-system counts per rule and the admin-words count before and after; the 403 screen chosen in Task 5; differences from the plan as B-?? (draft); changelog lines; any STOP. -->

**Setup.** `/tmp/teme-cloud-setup.log`: "2026-11-14T14:50:11Z ran in /home/user/teme as root", "2026-11-14T15:01:59Z finished"; working directory `/home/user/teme`. PHP 8.4.27, PostgreSQL 18 on 5433, Valkey on 6380, Node 24.22.1. Base `origin/main` at `e77848d`, holding the foundation commit `e7f2af7`.

**Checkpoints.**
- Done to Task 1 at `77456b5`, the quick check green.
- Done to Task 2, the quick check green.
