# Interface part G: People and your account on the new design system

**One part, one branch.** It gives each account a picture (a column on `users`, two Operations, a privacy holder), then moves People and the account pages onto the page patterns, with one account layout and a Two-factor sign-in page of its own. The server work goes first; every screen task builds on it.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** against the integration branch `feat/interface-foundation` at the commit "feat(admin): the design system and the admin's new frame". This plan is lighter (I-569): decisions, schema, names, permissions, tests and the delicate code are written out; the rest is described.

**Goal.** People on `ListPage` with each person's picture and the shared fresh-password prompt inside its dialogs; one account layout headed by the signed-in person; Profile with a picture chosen from Media; Preferences saving each choice as it is made; Security for the password only; Two-factor sign-in on its own page; phone layouts for all of them.

**Architecture.** The picture is the ID of a Media item in a new nullable column, `users.picture_media_id`, written only by `SetPicture` (yourself) and `RemovePicture` (yourself, or someone holding `users.manage`). One query class, `PersonPictures`, turns IDs into the image's square thumbnail address at render time (rule 9). The admin reads the picture through the shared `auth.user` and the People rows. The screens use the patterns already on the integration branch (`ListPage`, `SettingsPage`, `FormPanel`, `InPageNav`, `PersonPicture`, `FreshPasswordInline`, `SaveBar`) and add none.

**Proposal:** `docs/proposals/people-and-account.md` (accepted). Its "Settled" section binds this plan: the picture is its own column, not a field; an administrator may remove another person's picture but never choose one; Passkeys and Sessions join the account list later without a new decision; Suspended keeps its own empty text.

**Spec:** `docs/architecture/design-system.md` ("DS"): 4.11 Field, 4.12 Save bar, 4.19 Dialog, 4.23 Empty state, 4.26 Tables (row actions), 4.27 In-page navigation, 4.33 Top bar (the 32px account circle), 4.41 Fresh-password prompt, 4.43 Account layout, 4.49 Person's picture, section 5 (page patterns), section 7 (accessibility), section 8 (writing).

**Bound by:** I-3410 (people's pictures), I-3439 (every new word in American English), I-3373 (every word through `__()`), I-100 (Fortify's own credential state), I-115 (two-factor enrolment), I-3375 (privacy holders), I-3366 (people and bylines), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies:** none. **Migrations:** one, additive (D2). **Permissions:** none new; `users.manage` gains one use (D4). **Operations:** `SetPicture`, `RemovePicture`. **Routes:** four (D6). **Contract members (`packages/teme-contracts`):** none. **Tokens, `components/ui/**`, existing `components/patterns/**`:** not changed. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree

1. **"A field on the person" (the proposal's "How it fits") against "its own column, not a field" (Settled 1).** Settled wins: a column, never a field-registry field.
2. **Which person.** Since bylines, an account's `people` row holds no name, slug or biography of its own ("whose come from the account"), and exists only once a byline names the account. The account's identity lives on `users`. **The column goes on `users`** (D1).
3. **DS 4.43 names InPageNav for the account list.** `InPageNav` is a single sideways row that puts items past the fifth under More; the account list is a vertical side list of seven entries headed by a person, as the settings frame's side list is. **The account layout draws its own side list** in `layouts/account/`, styled as the settings frame's (current: `bg-accent-soft text-on-accent-soft`), with no change to `InPageNav`.
4. **Each account page's root.** The scanner's rule R7 accepts only named page patterns, and there is no account pattern. `SettingsPage` is a header and the form column inside a frame that owns the page's container, which is exactly what each account page needs inside the account layout. **Account pages render `SettingsPage`** inside the account layout. Adding a new pattern name to R7 would widen the scanner (a STOP), so it is not done.
5. **People's seven views and `InPageNav`'s five.** `InPageNav` shows five and puts the rest under More, so Suspended and Invited sit under More (its trigger shows the current item's name). `InPageNav` is not changed here (open point 1).
6. **Dialogs "full screen" on a phone (the proposal) against DS 4.19** (a `sm` dialog is `100vw − 32px` below 640px; only `xl` goes full screen). The dialog is part 1's; **DS wins** and nothing is changed in `ui/dialog.tsx`.
7. **The "People's pictures" setting (DS 4.49).** The proposal builds initials only; the setting is not built and no Gravatar address is ever made.
8. **"The unlock-form test" (the proposal's "Tests that change").** `tests/Browser/UnlockFormTest.php` covers the public site's protected-item form, which this part does not touch. It is not changed.
9. **Row actions.** DS 4.26's hybrid row actions belong to the content-lists part (`components/list/**`). People uses `RowLinks` and `RowActionsMenu` as they are: "Change role…" and Suspend or Reactivate on the line, and a "More actions for {name}" menu in its own last column holding "Remove picture…" (when there is one) and "Delete…".

## The decisions, written out

**D1. The column is `users.picture_media_id`.** Reasons: an account's name, slug and biography live on `users`, and its `people` row deliberately holds none of them; the `people` row is made lazily by the first byline, so a picture there would force one for every account; every screen that shows a picture now (People, the account layout, the account menu) reads accounts. A person with no account (a guest writer) gets a picture later in `people`, the same split as the biography; that is not built here.

**D2. The migration**, written out. File: `database/migrations/2027_06_20_100000_add_picture_to_users.php` (Task 0 checks it sorts after every migration on the base).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account's picture (I-3410): the ID of an image in Media, never an
 * address, so a new domain changes nothing. Deleting the image for good
 * clears it; until then a trashed image is simply not shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('picture_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->index('picture_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['picture_media_id']);
            $table->dropIndex(['picture_media_id']);
            $table->dropColumn('picture_media_id');
        });
    }
};
```

`App\Models\User` gains `@property string|null $picture_media_id` in its docblock. It is not added to `#[Fillable]`.

**D3. `SetPicture`** (`app/Domain/Users/Operations/SetPicture.php`, input `Input/SetPictureInput.php`: `userId`, `mediaId`, `actorId`).

```php
/**
 * Sets your own picture to an image in Media (I-3410). Only you choose it:
 * an administrator may remove another person's picture, never choose one.
 */
final class SetPicture extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SetPictureInput $input */
        if ($input->actorId() === null || $input->actorId() !== $input->userId) {
            throw new AuthorizationException('You may only change your own picture.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetPictureInput $input */
        Validator::make(
            ['media' => $input->mediaId],
            ['media' => ['required', 'string', Rule::exists('media', 'id')->where('kind', 'image')->whereNull('deleted_at')]],
            ['media.required' => __('Choose an image from Media.'), 'media.exists' => __('Choose an image from Media.')],
        )->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var SetPictureInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $user->picture_media_id = $input->mediaId;
        $user->save();

        return null;
    }
}
```

No cache purge and no hook: the picture is not shown on the public site in this part.

**D4. `RemovePicture`** (`app/Domain/Users/Operations/RemovePicture.php`, input `Input/RemovePictureInput.php`: `userId`, `actorId`). Uses the `ManagesUsers` concern.

```php
/**
 * Clears a person's picture, so their initials show. You may clear your
 * own; someone who manages users may clear anyone's (proposal, Settled 2).
 * The image stays in Media.
 */
final class RemovePicture extends BaseOperation
{
    use ManagesUsers;

    protected function authorize(OperationInput $input): void
    {
        /** @var RemovePictureInput $input */
        if ($input->actorId() !== null && $input->actorId() === $input->userId) {
            return;
        }

        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void {}

    /** @return array{name: string, removed: bool} */
    protected function perform(OperationInput $input): array
    {
        /** @var RemovePictureInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $removed = $user->picture_media_id !== null;
        $user->picture_media_id = null;
        $user->save();

        return ['name' => $user->name, 'removed' => $removed];
    }
}
```

**Who may do what:**

| Act | Yourself | Someone with `users.manage` | Anyone else | The operator at the shell |
|---|---|---|---|---|
| Choose a picture (`SetPicture`) | yes | only their own | only their own | no |
| Remove a picture (`RemovePicture`) | yes | anyone's | only their own | yes |

Choosing needs the Media picker, which answers those holding `content.create` (all four core roles). A person without it sees no "Choose from Media" button (Task 5); `SetPicture` itself does not ask for `content.create` (open point 4).

**D5. `PersonPictures`** (`app/Domain/Users/Queries/PersonPictures.php`), the one place a picture's address is made:

```php
/**
 * Each account's picture as an address made now from the site's own address
 * (rule 9): the image's square thumbnail, or none while the account has no
 * picture, the image is in the trash, or its thumbnail is not made yet.
 */
final class PersonPictures
{
    /**
     * The picture address of each account that has one, keyed by account ID.
     *
     * @param  list<int>  $userIds
     *
     * @return array<int, string>
     */
    public function for(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $pictures = [];

        foreach (DB::table('users')
            ->join('media', 'media.id', '=', 'users.picture_media_id')
            ->whereIn('users.id', $userIds)
            ->whereNull('media.deleted_at')
            ->where('media.kind', 'image')
            ->get(['users.id', 'media.variants']) as $row) {
            $url = MediaUrls::variant((array) json_decode((string) $row->variants, true, flags: JSON_THROW_ON_ERROR));

            if ($url !== null) {
                $pictures[(int) $row->id] = $url;
            }
        }

        return $pictures;
    }

    /** One account's picture address, or null. */
    public function of(int $userId): ?string
    {
        return $this->for([$userId])[$userId] ?? null;
    }
}
```

The thumbnail is the 300px square crop around the focal point every image gets, enough for the 96px circle on a dense screen.

**D6. Routes.**

| Method and path | Name | Controller | Group |
|---|---|---|---|
| `PUT account/picture` | `profile.picture.update` | `Account\PictureController@update` (body `media`) | `verified` |
| `DELETE account/picture` | `profile.picture.destroy` | `Account\PictureController@destroy` | `verified` |
| `DELETE users/{user}/picture` | `users.picture.destroy` | `Users\UserController@removePicture` | `can:users.manage`, `whereNumber('user')` |
| `GET account/two-factor` | `two-factor.edit` | `Account\TwoFactorController@edit` | `verified`, `RequirePassword` (as Security) |

`PictureController` answers with a toast and `to_route('profile.edit')`: `__('Picture changed.')`, `__('Picture removed. Your initials show instead.')`. `removePicture` answers through the controller's `done()`: `__('Removed the picture of :name.', ['name' => …])`, or `__(':name has no picture.', …)` as `info` when nothing was removed. `two-factor.edit` sits in Fortify's `two-factor.*` name group, which `RequireTwoFactorEnrolment` already lets through.

**D7. What the browser reads.**
- `CurrentUserData::find()` returns `['id', 'name', 'email', 'emailVerified', 'capabilities', 'role', 'picture']`: `role` is the first role's label through `__()` (`Roles::LABELS`, else the role's name as stored), or null; `picture` is `PersonPictures::of()`. Its `@return` shape says so. `types/auth.ts`'s `User` gains `role: string | null` and `picture: string | null`, and loses the unused `avatar?`.
- `UserList`'s `PersonRow` gains `picture: string|null` (one `PersonPictures::for()` call for everyone), null for an invitation.

**D8. The privacy holder.** A new `App\Domain\Users\Privacy\PictureHolder`, key `picture`, label `__('Picture')`, added to `PersonalDataHolders::CORE` **after `PeopleHolder` and before `PrivacyRequestsHolder`** (privacy requests stay last; the account and sign-in holders keep their places, which tests read by index).
- **Export:** nothing without an account or a picture; else one group `__('Your picture')` with `__('Picture') => <the address from PersonPictures>` and `__('File name') => <the media item's original file name>`.
- **Erase** (written out; it runs inside the privacy request's Operation, as the comments holder's erase does):

```php
public function erase(PersonalDataSubject $subject, int $page): ErasePage
{
    if ($subject->accountId === null) {
        return new ErasePage(0, 0, [], true);
    }

    // The account is kept (AccountHolder); its picture is not needed to keep it.
    $cleared = DB::select('UPDATE users SET picture_media_id = NULL WHERE id = ?::bigint AND picture_media_id IS NOT NULL RETURNING id', [$subject->accountId]);

    return $cleared === []
        ? new ErasePage(0, 0, [], true)
        : new ErasePage(1, 0, [__('The picture is no longer shown. The image itself stays in Media, where the site’s administrators can delete it.')], true);
}
```

**D9. The account layout** (`layouts/account/layout.tsx`, rewritten; `layouts/account/account-nav.tsx`, new).
- `Page`, then a grid `grid items-start gap-8 lg:grid-cols-(--account-cols)` with `style={{ '--account-cols': 'minmax(0, 200px) minmax(0, 1fr)' } as CSSProperties}` (the settings frame's way to set a column the tokens do not name), then the main column `grid min-w-0 gap-4` holding the page.
- **The side list** (`max-lg:hidden`, `lg:sticky lg:top-4`): a `nav` with `aria-label={__('Account')}`; at its head the signed-in person (`PersonPicture` 40 with `nameBeside`, the name `text-small font-semibold text-ink`, the role under it `text-small text-ink-3`); then links Profile, Preferences, Notifications; an `hr` (`border-line mx-3 my-2`); Security, Two-factor sign-in. Each link `min-h-(--ctl-h) max-sm:min-h-11 rounded-md px-3 text-body font-medium text-ink-2 hover:bg-surface-3 hover:text-ink`, current `aria-current="page"` with `bg-accent-soft text-on-accent-soft`, and a `focus-visible:` outline as `InPageNav`'s links have. Passkeys and Sessions are not listed (Settled 3).
- **Under 1024** (`lg:hidden`): one neutral button across the column, `data-test="account-pages"`, holding the 24px picture, the current page's name and a `ChevronDown`, named `__('Account pages: :page', { page })`; it opens a `DropdownMenu` of the same links (Security after a `DropdownMenuSeparator`).
- The list's entries are `{ title, href }` with titles through `__()`; current by `useCurrentUrl().isCurrentOrParentUrl`, as today.

**D10. Preferences save as you choose.** No save bar. The page's `Form` is submitted when a choice changes; a number or text field is submitted when it loses focus, if its value changed since it gained focus. Written out:

```tsx
// Each choice is saved as it is made (proposal: Preferences); a typed value once it is left.
const form = useRef<FormComponentRef | null>(null);
const typedAtFocus = useRef<string | null>(null);
const typed = (target: EventTarget) => target instanceof HTMLInputElement && (target.type === 'number' || target.type === 'text');

<Form
    {...PreferencesController.update.form()}
    ref={form}
    options={{ preserveScroll: true }}
    onChange={(event) => {
        if (!typed(event.target)) {
            form.current?.submit();
        }
    }}
    onFocus={(event) => {
        typedAtFocus.current = typed(event.target) ? (event.target as HTMLInputElement).value : null;
    }}
    onBlur={(event) => {
        if (typed(event.target) && (event.target as HTMLInputElement).value !== typedAtFocus.current) {
            form.current?.submit();
        }
    }}
>
```

React's `onChange` on the form hears the Radix checkbox (its hidden input's click) and the select (its hidden select's change), which `SettingsField` draws; Task 6's browser case proves it for a checkbox. The light-or-dark choice keeps saving through `useAppearance` as today. The success toast stays "Preferences saved.".

**D11. People's password, asked inside the open dialog.** Invite, Change role and Delete each send `headers={{ 'X-Teme-Ask-In-Place': '1' }}`; a 423 answer opens `FreshPasswordInline` under the dialog's fields, and its confirmation submits the dialog's form again:

```tsx
const form = useRef<FormComponentRef | null>(null);
const [asking, setAsking] = useState(false);

<Form
    {...UserController.store.form()}
    ref={form}
    headers={{ 'X-Teme-Ask-In-Place': '1' }}
    onHttpException={(response) => {
        // A lapsed password (bootstrap/app.php): ask here, so nothing typed is lost.
        if (response.status === 423) {
            setAsking(true);

            return false;
        }
    }}
    …
/>
<FreshPasswordInline
    open={asking}
    onOpenChange={setAsking}
    title={__('Confirm your password')}
    text={__('Type your password to carry on. What you typed stays here.')}
    onConfirmed={() => form.current?.submit()}
/>
```

Closing the dialog sets `asking` back to false.

**D12. Where the picture shows.** People's rows and phone cards (40), the dialogs' person head (40), the account side list (40), the phone button (24), Profile's Picture panel (96), the top bar's account menu (its existing 32px `Avatar`, now with `src={auth.user.picture ?? undefined}`). Not in bylines, the content lists or comments (the proposal's "Not in"). An invitation always shows `invited` (the dashed ring).

**D13. Two-factor sign-in on its own page.** `TwoFactorController@edit` takes over the two-factor half of `SecurityController@edit` unchanged (`canManageTwoFactor`, and when it is true `ensureStateIsValid()`, `twoFactorEnabled`, `requiresConfirmation`) and renders `account/two-factor`. `SecurityController@edit` keeps `passwordRules` only. `RequireTwoFactorEnrolment` sends people to `two-factor.edit`; `SafeMode::PATHS` gains `admin/account/two-factor` beside `admin/account/security` (its comment names the Two-factor sign-in page). The page reuses `manage-two-factor.tsx`, `two-factor-setup-modal.tsx` and `two-factor-recovery-codes.tsx`, restyled; with two-factor off for the site it shows a `Notice` (`info`): `__('Two-factor sign-in is turned off for this site.')`. `hooks/use-two-factor-auth.ts` keeps its `OTP_MAX_LENGTH` export (the sign-in pages import it).

---

## Owns

This part changes only these. Everything else is read or imported as it is.

- **Server:** `database/migrations/2027_06_20_100000_add_picture_to_users.php` (new); `app/Models/User.php` (docblock); `app/Domain/Users/Operations/{SetPicture,RemovePicture}.php` and their inputs (new); `app/Domain/Users/Queries/{PersonPictures}.php` (new), `CurrentUserData.php`, `UserList.php`; `app/Domain/Users/Privacy/PictureHolder.php` (new); `app/Domain/Privacy/PersonalDataHolders.php` (one line); `app/Http/Admin/Account/{PictureController,TwoFactorController}.php` (new), `SecurityController.php`; `app/Http/Admin/Users/UserController.php` (one method); `app/Http/Admin/Middleware/RequireTwoFactorEnrolment.php` (its redirect); `app/Domain/Plugins/SafeMode.php` (one path); `routes/admin.php` (the four routes of D6, and nothing else in it).
- **Screens:** `resources/js/pages/users/**`; `resources/js/pages/account/**` (with the new `two-factor.tsx`); `resources/js/layouts/account/**`; `resources/js/components/people/**` (new: the dialogs, the person cell, the phone cards, the row actions); `resources/js/components/account/**` (new: the Picture panel); `resources/js/components/{manage-two-factor,two-factor-setup-modal,two-factor-recovery-codes}.tsx`; `resources/js/hooks/use-two-factor-auth.ts` (words only; `OTP_MAX_LENGTH` unchanged); `resources/js/components/account-menu.tsx` and `resources/js/components/user-info.tsx` (the picture only); `resources/js/types/{auth,users}.ts`.
- **Tests:** the new files named in the tasks; the existing ones in "Existing tests that change"; the two baselines (lines for owned files only, and their counts).

**Not owned (never edited here):** `components/ui/**`, `components/patterns/**`, the token block in `resources/css/app.css`, the scanner; `components/list/**` and `components/media/**` (imported as they are); `components/fields/**`, `pages/settings/**`, `layouts/settings/**` (the settings part's); `components/ui/combobox.tsx`, `components/term-picker.tsx` (the combobox part's); `pages/auth/**`, `layouts/auth*` (the sign-in part's); `pages/{themes,types,taxonomies,navigation,regions,inbox}/**` (the remaining-screens part's); `components/user-menu-content.tsx`; `tests/Pest.php`.

## Global Constraints

- **Rule 1:** every new state change is `SetPicture` or `RemovePicture`, each with a test that it writes nothing when refused; the privacy erase runs inside the privacy request's own Operation. Controllers call Operations and queries only (`LayeringTest`).
- **Rule 3:** PostgreSQL only; feature tests use `RefreshDatabase` as their siblings do.
- **Rule 9:** the column holds the Media ID; addresses are made by `PersonPictures` when asked.
- **Migrations only add.** The one migration adds a nullable column, its key and its index.
- **Every word a person reads goes through `__()` or `__n()`** from `@/lib/i18n` on screens and `__()` in PHP. New words are American English (I-3439). Words already shown keep their wording unless "Every word" below names the change; they move into `__()`.
- **The design check's baseline only shrinks.** `tests/Architecture/DesignSystemTest.php` scans `resources/` and compares with `tests/Architecture/design-system-baseline.txt` (`rule<TAB>path<TAB>match`, one line per occurrence, paths relative to `resources/`). To lower it: fix the offence, run `php artisan test tests/Architecture/DesignSystemTest.php`, remove exactly the lines its first case lists as gone, and lower that rule's number in `dsCounts()`; a rule at zero leaves `dsCounts()`. A moved file changes its path on its lines. **Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.** The rules in short: R1 arbitrary `[…]` values; R2 font sizes but the six (`text-caption`, `-small`, `-body`, `-lead`, `-title`, `-display`); R3 off-scale spacing; R4 raw colors; R5 raw `<button>`, `<input>`, `<select>`, `<textarea>`, `<table>`, `<dialog>`, `<details>` outside `ui/` and `patterns/`; R6 radii, shadows, weights, z-index, leading and tracking not from tokens; R7 a page's root that is not a page pattern, or a page writing its own padding or width; R8 `overflow-auto`/`-scroll` outside `ui/scroll-area.tsx`; R9 home-made notices; R10 an outline removed with no focus ring; R11 unknown variables and colors; R12 contrast; R13 the accent's numbers; R14 motion not from tokens or not `motion-safe:`; R15 style objects setting anything but custom properties and positions.
- **The admin-words baseline only shrinks** (`tests/Architecture/admin-words-baseline.txt`, `path<TAB>word`; the count in `AdminWordsTest`): remove the lines its test lists as gone, lower the count; never add a line or raise the count.
- **Seams:** the tokens, `components/ui/**` and the existing `components/patterns/**` are not changed. If this part needs a change there, STOP: it is made once on the integration branch by the maintainers. New files under `components/people/` and `components/account/` are fine.
- **Tests first:** each test is run red for the reason given before the code. An architecture test is planted red first.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `packages/teme-contracts`, `tests/Pest.php`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public. A code comment cites at most one I-number or one document path.
- `php artisan wayfinder:generate --with-form` after route changes, as the Vite plugin runs it.
- **Browser tests:** run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. Helpers from `tests/Pest.php`: `cmSignIn($user)`, `cmNavigate($page, $url)` (one navigation with the whole timeout), `cmWaitUntil($page, $jsCondition)`, `shellSettled($page, $selector)` (waits until the element's **own** animations end: use it before clicking inside a dialog, sheet or menu, never `getAnimations({ subtree: true })`), `cmSmallTargets($page)` (targets under 24px), `lscrPrefer($user, ['appearance.mode' => $scheme])`, `cmSettings($values, $group)`, `pageImage($author, $width, $height)` (an uploaded image with its variants made). A disabled control is pressed through `assertScript`, not `click()`.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files, one `php artisan test --compact <path>` each.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: `export PATH=/opt/node24/bin:$PATH`; still missing, stop, push and record it. PostgreSQL stopped: `docker compose up -d` and run again before believing a failure. A test red at the base and not this part's: record it, never change it. Out of room: stop at the last green checkpoint, push, record what comes next.

---

## Task 0: Preconditions, then branch

Run in a checkout of the integration branch. If any line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin && git log --oneline origin/feat/interface-foundation | grep -c "feat(admin): the design system and the admin's new frame"` → 1. If that branch is gone, run the same against `origin/main`, and use `origin/main` as the base everywhere below.
- [ ] **Step 2:**
  - `grep -rn "picture_media_id" database/migrations app` → nothing; `ls database/migrations | sort | tail -1` sorts before `2027_06_20_100000_add_picture_to_users.php`;
  - `ls app/Domain/Users/Operations/SetPicture.php app/Domain/Users/Operations/RemovePicture.php app/Domain/Users/Queries/PersonPictures.php app/Domain/Users/Privacy/PictureHolder.php resources/js/pages/account/two-factor.tsx 2>&1` → none exists;
  - `ls resources/js/components/ui/person-picture.tsx resources/js/components/patterns/{fresh-password,in-page-nav,form-page,list-page,settings-page,save-bar,page}.tsx resources/js/hooks/use-shell-width.ts` → all exist; `grep -n "export function FreshPasswordInline\|export function SettingsPage\|export function FormPanel\|export function SaveBar\|export function InPageNav\|export function ListPage" -r resources/js/components/patterns` → six lines;
  - `grep -n "'SettingsPage'" tests/Fixtures/design-system-scan.mjs` → one line (R7 accepts it);
  - `grep -n "ALWAYS_ALLOWED\|two-factor\.\*\|to_route('security.edit')" app/Http/Admin/Middleware/RequireTwoFactorEnrolment.php` → three lines; `grep -n "'admin/account/security'" app/Domain/Plugins/SafeMode.php` → one line;
  - `grep -n "X-Teme-Ask-In-Place" bootstrap/app.php` → one line; `grep -n -e "media/picker" -A2 routes/admin.php` shows the picker's route with `can:content.create`;
  - `grep -n "OTP_MAX_LENGTH" -r resources/js` → `hooks/use-two-factor-auth.ts` and `pages/auth/two-factor-challenge.tsx`;
  - `grep -n "PeopleHolder::class,\|PrivacyRequestsHolder::class," app/Domain/Privacy/PersonalDataHolders.php` → PeopleHolder then PrivacyRequestsHolder, the last two of `CORE`;
  - record: `grep -c . tests/Architecture/design-system-baseline.txt`; for each owned path, `grep -c "	js/pages/users/\|	js/pages/account/\|	js/layouts/account/\|	js/components/\(manage-two-factor\|two-factor-\|account-menu\|user-info\)" tests/Architecture/design-system-baseline.txt` (expected 54 at the time of writing); `grep -c "^pages/users/\|^pages/account/\|^layouts/account/\|^components/\(manage-two-factor\|two-factor-\|account-menu\|user-info\)" tests/Architecture/admin-words-baseline.txt` (expected 118); `dsCounts()` and the `AdminWordsTest` count.
- [ ] **Step 3:** branch `ui/people-and-account` from the base.

## Task 1: The picture's column and its two Operations

**Files:** D2's migration; `app/Models/User.php`; D3 and D4 with their inputs; `tests/Feature/Cms/Users/PictureTest.php` (new).

**Tests, written first** (`PictureTest.php`, `uses(RefreshDatabase::class)`, `RoleSeeder` seeded; images from `pageImage()`):

```php
it('sets your own picture to an image in Media', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);

    app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, (string) $author->id));

    expect($author->refresh()->picture_media_id)->toBe($image->id);
});

it('writes nothing when an administrator chooses another person’s picture', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($admin, 400, 400);

    expect(fn () => app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, (string) $admin->id)))
        ->toThrow(AuthorizationException::class);

    expect($author->refresh()->picture_media_id)->toBeNull();
});

it('writes nothing when no one is signed in, even at the shell', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);

    expect(fn () => app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, null)))
        ->toThrow(AuthorizationException::class);

    expect($author->refresh()->picture_media_id)->toBeNull();
});
```

Further cases, described: refuses a document, a trashed image and an unknown ID with "Choose an image from Media." under `media`, writing nothing; removes your own picture; an administrator removes another person's; **writes nothing when an author removes another person's picture** (`AuthorizationException`, the column unchanged); removing when there is none answers `removed: false`; deleting the image for good (`TrashMedia`, then `DeleteMediaPermanently`, each with `MediaActionInput($id, $adminId)`) leaves the column null.

- [ ] **Step 1:** the cases; run: they fail on the missing classes (record the first line).
- [ ] **Step 2:** the migration, the docblock line, the two Operations and inputs as D2 to D4. Run until green.
- [ ] **Step 3:** quick check; commit `feat(people): a picture for each account, chosen from Media (I-3410)`; push; the Records line.

## Task 2: Reading the picture, and the privacy holder

**Files:** D5; `CurrentUserData.php`, `UserList.php` (D7); D8 and `PersonalDataHolders.php`; `tests/Feature/Cms/Users/PersonPicturesTest.php`, `tests/Feature/Privacy/PictureHolderTest.php` (new); the pins named below.

**Tests, written first:**
- `PersonPicturesTest`: "gives the image’s square thumbnail address, made from the site’s address" (the address ends with the thumbnail's recorded path; after `config(['app.url' => …])` and the media disk's URL changed, the address follows); "gives none while the image is in the trash, and gives it again once restored"; "gives none for an account without a picture"; "reads every account's picture in one query" (`DB::enableQueryLog()`, three accounts, one query).
- `PictureHolderTest`: "exports the picture’s address and file name"; "exports nothing for an account without a picture, or an address without an account"; "clears the picture on erasure, says the image stays in Media, and leaves another account’s picture"; "is listed after Bylines and before Privacy requests".
- `CurrentUserDataTest`'s first case and `AccountTest`'s "shares only the documented user fields" now expect `['id', 'name', 'email', 'emailVerified', 'capabilities', 'role', 'picture']`; a new case in `CurrentUserDataTest`: "shares the role's label and the picture's address" (an author with a picture: `role` "Author", `picture` the thumbnail address).
- `UsersPageTest` gains: "shows each person's picture, and none for an invitation".

- [ ] **Step 1:** run them: they fail on the missing classes and keys.
- [ ] **Step 2:** build as D5, D7, D8. `PluginHoldersTest`'s list of keys gains `'picture'` between `'people'` and `'privacy-requests'`.
- [ ] **Step 3:** run the new files, `tests/Feature/Privacy`, `tests/Feature/Cms/Users`, `tests/Feature/Admin/AccountTest.php`, `tests/Feature/Admin/UsersPageTest.php`. Quick check; commit `feat(people): the picture in what the admin reads, and in privacy requests`; push; the Records line.

## Task 3: The routes: the picture, and Two-factor sign-in's page

**Files:** D6's controllers and routes; `SecurityController.php`; `RequireTwoFactorEnrolment.php`; `SafeMode.php`; `tests/Feature/Admin/AccountPictureTest.php`, `tests/Feature/Account/TwoFactorPageTest.php` (new); the pins below. Then `php artisan wayfinder:generate --with-form`.

**Tests, written first:**
- `AccountPictureTest`: "changes your own picture and says so" (PUT `/admin/account/picture` with `media`; redirect to Profile; toast "Picture changed."); "removes your own picture"; "refuses a file that is not an image, keeping the picture" (session error under `media`); **"lets someone who manages users remove another person’s picture, and no one else, writing nothing when refused"** (an editor's DELETE `/admin/users/{author}/picture` is 403 and the author's column is unchanged; an administrator's succeeds with "Removed the picture of Bea Author."); "offers no way to choose another person’s picture" (`PUT /admin/users/{id}/picture` is 405 or 404, nothing written).
- `TwoFactorPageTest`: the two Security cases that read two-factor props move here unchanged in meaning (`two-factor.edit` renders `account/two-factor` with `canManageTwoFactor`, `twoFactorEnabled`, `requiresConfirmation`; with the feature off, `canManageTwoFactor` false and the others missing); "asks for the password again before showing it" (redirect to `password.confirm`, as Security does).
- `tests/Feature/Account/SecurityTest.php`: those two cases leave it (they are now in `TwoFactorPageTest`); a case states that Security renders `passwordRules` and no two-factor props.
- `AccountEnforcementTest`: "sends a member of a required role to set up two-factor, and nowhere else" now expects `route('two-factor.edit')` and opens `/admin/account/two-factor`. `SafeModeTest`: its redirect expects `route('two-factor.edit')`, and its dataset gains `'the Two-factor sign-in page' => ['admin/account/two-factor', false]`.

- [ ] **Step 1:** the cases; run: red on the missing routes (404) and the old redirect.
- [ ] **Step 2:** build D6 and D13's server half; wayfinder.
- [ ] **Step 3:** run the new files, `tests/Feature/Account`, `tests/Feature/Security`, `tests/Feature/Plugins/SafeModeTest.php`, `tests/Feature/Plugins/LockOutTest.php`, `tests/Feature/Admin/UsersPageTest.php`, `tests/Architecture/LayeringTest.php`. Quick check; commit `feat(account): routes for your picture, and two-factor sign-in on its own page`; push; the Records line.

## Task 4: The account layout, and the picture in the account menu

**Files:** `layouts/account/layout.tsx`, `layouts/account/account-nav.tsx` (D9); `components/account-menu.tsx`, `components/user-info.tsx` (`picture` in place of `avatar`); `types/auth.ts`; `tests/Browser/AccountPagesTest.php` (new).

**Tests, written first** (`AccountPagesTest.php`, set up as `PeopleScreenTest` is: `withVite()`, `RoleSeeder`, the request-reset listener; Ada an administrator with a picture from `pageImage()` set through `SetPicture`):

```php
it('heads the account list with your picture, name and role, and marks the page you are on', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/notifications');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null');

    $page->assertScript('document.querySelector(\'nav[aria-label="Account"] img\') !== null')
        ->assertScript('document.querySelector(\'nav[aria-label="Account"]\').innerText.includes("Administrator")')
        ->assertScript('[...document.querySelectorAll(\'nav[aria-label="Account"] a\')].map((link) => link.textContent.trim()).join("|")', 'Profile|Preferences|Notifications|Security|Two-factor sign-in')
        ->assertAttribute('nav[aria-label="Account"] a[href$="/admin/account/notifications"]', 'aria-current', 'page')
        ->assertScript('document.querySelectorAll("h1").length === 1');
});
```

Further cases: "shows your picture in the account menu" (`[data-test="account-menu-button"] img` has the picture's address); "opens the account pages from one button under 1024 pixels, with nothing scrolling sideways at 375" (resize 375×812; the side list is hidden; `[data-test="account-pages"]` named "Account pages: Notifications"; click, `shellSettled($page, '[role="menu"]')`, five links; `scrollWidth <= clientWidth`); "passes axe on Account › Profile with every target 24 pixels or more, at 1280 and 375, in both schemes" (`assertNoAccessibilityIssues(3)`, `cmSmallTargets` empty).

- [ ] **Step 1:** the cases; `npm run build`; run: red (no `nav[aria-label="Account"]` head, no `img`).
- [ ] **Step 2:** build D9 and D12's account menu line. The layout's words through `__()`.
- [ ] **Step 3:** `npm run build`; run `AccountPagesTest`, then `tests/Browser/ShellTest.php` and `tests/Browser/ShellResponsiveTest.php`. Quick check; commit `feat(account): one account layout headed by you, and your picture in the account menu`; push; the Records line.

## Task 5: Profile, Security and Notifications

**Files:** `pages/account/{profile,security,notifications}.tsx`; `components/account/picture-panel.tsx` (new); `AccountPagesTest`; the pins below.

What each page is (every root `SettingsPage`, panels `FormPanel`, fields `Field` with the control's own `id` kept in the source, one `SaveBar` as the form's last child with `dirty` from the form's `isDirty`, `onDiscard` its `reset`):
- **Profile:** title "Profile", line `__('Your name, email address and biography.')`. Panel "Picture" (outside the form; `components/account/picture-panel.tsx`): `PersonPicture` 96 with `nameBeside={false}`; "Choose from Media" (neutral, `sm`, `data-test="picture-choose"`) opens `MediaModal` with `kinds={['image']}`, `action={__('Use as picture')}`, and `onChoose` PUTs `{ media: ids[0] }` to `PictureController.update` with `preserveScroll`; "Remove" (ghost, `sm`, `data-test="picture-remove"`, disabled without a picture) DELETEs `PictureController.destroy`; help `__('With none, your initials show.')`. The choose button shows only when `auth.user.capabilities` holds `content.create` and the email is verified; otherwise the help says `__('Your role can’t choose from Media. Ask an administrator to add your picture.')` or `__('Confirm your email address to choose a picture.')`. Panel "Your details" (the form): Name, Email address (with today's unconfirmed-address line and "Resend confirmation email" button), Bio (`Textarea`, `maxLength` 1000, help "Shown on your author page and under your posts." kept word for word); `SaveBar` `what={__('your profile')}`, `testId="update-profile-button"`.
- **Security:** title "Security", line `__('The password you sign in with.')`; panel "Update password" with today's three fields and rules; help `__('Updating it signs you out on your other devices.')`; `SaveBar` `what={__('your password')}`, `testId="update-password-button"`. Two-factor leaves this page.
- **Notifications:** title "Notifications"; today's panels and words; `SaveBar` `saveLabel={__('Save notifications')}`, `what={__('your notifications')}`. "Open inbox" stays a button beside the Inbox heading.

**Tests, written first** (in `AccountPagesTest`):

```php
it('chooses a picture from Media on Profile, shows it at once, and removes it again', function (): void {
    $writer = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    pageImage($writer, 400, 400);

    $page = cmNavigate(cmSignIn($writer), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="picture-choose"]\') !== null');
    $page->assertScript('document.querySelector(\'[data-slot="form-panel"] [data-slot="person-picture"] img\') === null')
        ->click('[data-test="picture-choose"]');
    shellSettled($page, '[role="dialog"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] [role="option"]\') !== null');
    $page->click('[role="dialog"] [role="option"]')
        ->click('[role="dialog"] >> text="Use as picture"');
    cmWaitUntil($page, 'document.body.innerText.includes("Picture changed.") && document.querySelector(\'nav[aria-label="Account"] img\') !== null');

    expect($writer->refresh()->picture_media_id)->not->toBeNull();

    $page->click('[data-test="picture-remove"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Picture removed. Your initials show instead.") && document.querySelector(\'nav[aria-label="Account"] img\') === null');

    expect($writer->refresh()->picture_media_id)->toBeNull();
});
```

Further case: "shows the save bar once a detail changes on Profile, and saves it" (type a new name: `[data-test="save-bar"]` shown; click `[data-test="update-profile-button"]`; "Profile updated."; the bar hidden; the side list's head shows the new name).

**Pins that follow the new markup, meaning unchanged:**
- `WordingPassSettingsTest` "says where the bio shows in a hint under the box": the hint still follows the box and comes before the error; it now reads `Field`'s ids (`bio-help`, `bio-error`) in place of `bio-hint`. "offers Resend confirmation email as a button" and "offers Open inbox as a button" keep reading the same words in the same order.
- `AccessibilityTest`: a file of these three pages that no longer holds `<InputError` leaves the first dataset (`Field` ties each error to its control); the "labels every select and every input" rows stay, since each control keeps its `id` and its `Field`'s `htmlFor`.
- `tests/Browser/AccountNotificationsTest.php`: unchanged; it must pass as it is (`button[type=submit]` is the save bar's Save, shown once the box is unticked).

- [ ] **Step 1:** the cases; `npm run build`; run: red (no `picture-choose`; no save bar).
- [ ] **Step 2:** build the three pages and the panel.
- [ ] **Step 3:** `npm run build`; `AccountPagesTest`, `AccountNotificationsTest`; the PHP files above. `DesignSystemTest` and `AdminWordsTest`: remove these files' lines, lower the counts. Quick check; commit `feat(account): Profile with your picture, Security and Notifications on the design system`; push; the Records line.

## Task 6: Preferences, and Two-factor sign-in's page

**Files:** `pages/account/preferences.tsx`; `pages/account/two-factor.tsx` (new); `components/{manage-two-factor,two-factor-setup-modal,two-factor-recovery-codes}.tsx`; `hooks/use-two-factor-auth.ts` (words); `AccountPagesTest`; `tests/Browser/ShellTest.php` (two cases, below).

- **Preferences:** title "Preferences", line `__('How the admin looks and behaves for you. Each choice is saved as you make it.')`. Panels: "Appearance" (the light-or-dark select, as today), "Lists" (`lists.*`), "Writing" (`editor.*`), "Home" (`dashboard.*`), then "More" for any other key a plugin registers, in the registry's order; a panel with no field is not drawn. No save bar (D10).
- **Two-factor sign-in:** title "Two-factor sign-in", line `__('A second step when you sign in: a code from an authenticator app.')`. One panel: on or off with its words; "Turn on two-factor sign-in" opening today's setup dialog; "Turn off…" asking first (`ConfirmAction`, title `__('Turn off two-factor sign-in?')`); the recovery codes with "Regenerate codes" and its warning, as today. With the feature off: D13's `Notice`. The three components keep their props and `data-test` hooks; they are restyled with the tokens and their words go through `__()`.

**Tests, written first** (in `AccountPagesTest`): "saves a choice on Preferences the moment it is made, and keeps it after a reload" (click the Smart quotes control drawn by `SettingsField`; "Preferences saved."; reload; still unticked; `PreferencesFor::user()` agrees); "keeps a typed number until the field is left" (type in Settings panel width: no request until Tab, then "Preferences saved."); "opens Two-factor sign-in from the account list after the password, with Turn on two-factor sign-in" (Ada with the password confirmed; the page's `h1`; the button present); "passes axe on Preferences and Two-factor sign-in, every target 24 pixels or more, in both schemes".

**`ShellTest` cases that follow, meaning unchanged:** "shows a result as a toast…" provokes the toast by ticking Smart quotes on Preferences in place of pressing "Save preferences"; "frames Account with one h1 and an Appearance select…" reads Preferences' new line ("Each choice is saved as you make it.") in place of the old layout's "Each panel saves on its own.".

- [ ] **Step 1:** the cases; `npm run build`; run: red.
- [ ] **Step 2:** build both pages and restyle the three components. Then plant: remove the `onBlur` branch of D10; see "keeps a typed number…" red; restore. Record its first line.
- [ ] **Step 3:** `npm run build`; `AccountPagesTest`, `ShellTest`; `WordingPassSettingsTest` (the recovery codes' warning keeps its place), `AccessibilityTest`. Baselines lowered for these files. Quick check; commit `feat(account): Preferences save as you choose, and two-factor sign-in on its own page`; push; the Records line.

## Task 7: People

**Files:** `pages/users/index.tsx`; `components/people/{invite-dialog,role-dialog,delete-dialog,person-cell,people-cards,row-actions}.tsx` (new); `types/users.ts` (`picture`); `tests/Browser/PeopleScreenTest.php`; `tests/Feature/Admin/PeoplePageTest.php`; `tests/Architecture/SharedControlsTest.php` (rows that name the page).

What the screen is:
- **Root `ListPage`:** title "People", line "Everyone who can sign in to this site. People you invite choose their own password.", action "Invite someone" (`data-test="people-invite"`).
- **Views:** `InPageNav` `label={__('Views')}`, an item per tab with its count, `current` the view; the address as today (`usersIndex()` for All, `?view=` for the rest).
- **Accounts (wide and compact):** `DataTable` with columns Name (`person-cell.tsx`: `PersonPicture` 40 `nameBeside`, the name, "You" as a flat neutral `StatusPill` on your own row, the email, then `RowLinks` labelled "Actions for {name}" with "Change role…" and Suspend or Reactivate, or `OwnRowLock` on your own row, unchanged in words and hooks), Role, Status (`StatusPill` `ok` "Active" or `danger` "Suspended", and a flat "Two-factor"), Last sign-in, and a last column headed by `sr-only` "More actions" holding `RowActionsMenu` labelled "More actions for {name}" with "Remove picture…" (only when `picture` is not null; a form to `UserController.removePicture` wrapped in `ConfirmAction`: title `__('Remove the picture of :name?')`, text `__('Their initials show instead. The image stays in Media.')`, confirm `__('Remove picture')`) and "Delete…" (destructive). No menu on your own row.
- **Invitations:** as today (caption "Pending invitations"; Name, Role, Invited, Link), the picture `invited`; the line holds "Send again" and "Revoke invitation" (with its confirmation, unchanged); the More menu holds "Change role…".
- **Under 768** (`useShellWidth() === 'small'`): `people-cards.tsx` draws a list of cards (`ul` labelled "People" or "Pending invitations"), each a `Panel`-like `bg-surface rounded-lg shadow-raised p-4` with the picture, the name, the role, the status pills, then the same actions; no table.
- **Empty views:** Invited: "No pending invitations", `__('People you invite get an email with a link that works for :days.')`, the invite button. Suspended (Settled 4): "Nobody is suspended", `__('Someone you suspend can’t sign in, and everything they wrote stays as it is.')`, no button. A role: `` `No ${tabLabel.toLowerCase()} yet` `` through `__('No :role yet', …)`, `__('Invite someone and give them this role.')`, the invite button.
- **Dialogs** (`sm`, each with the person's head: picture 40, name, email): Invite, "Change the role of {name}", "Delete {name}" as today in fields, ids, hints and `data-test`s, each asking for the password in place (D11). Delete keeps "Give their content to" with `emptyLabel="No one — they wrote nothing"` and "Their name stays on what they wrote.".
- Every word through `__()`; the constants keep their wording.

**`PeopleScreenTest`, meaning unchanged:**
- "shows every view with its count…": reads the five shown views (`All3|Administrators1|Editors1|Authors1|Contributors0`), then opens More and reads `Suspended0|Invited1`; the invitations part is unchanged.
- "invites someone from the header's dialog, asking for the password in the dialog": types into `[data-test="invite-dialog"] [data-test="fresh-password-input"]` and clicks `[data-test="fresh-password-confirm"]` in place of `#invite-password` and `invite-password-confirm`; the same in "shows the error under the email field…".
- "asks in the delete dialog who should own their content": opens `[aria-label="More actions for Bea Author"]`, waits for `[role="menu"]`, clicks "Delete…".
- The rest unchanged.

**New cases:**

```php
it('shows each person’s picture, and lets an administrator remove another’s after asking', function (): void {
    app(SetPicture::class)->execute(new SetPictureInput((string) $this->author->id, pageImage($this->author, 400, 400)->id, (string) $this->author->id));

    $page = cmNavigate(cmSignIn($this->admin), '/admin/users?view=author');
    cmWaitUntil($page, 'document.querySelector(\'tbody [data-slot="person-picture"] img\') !== null');

    $page->click('[aria-label="More actions for Bea Author"]');
    shellSettled($page, '[role="menu"]');
    $page->click('[role="menu"] >> text="Remove picture…"');
    ($this->seen)($page, 'Remove the picture of Bea Author?');
    shellSettled($page, '[role="alertdialog"], [role="dialog"]');
    $page->click('[role="dialog"] >> text="Remove picture"');
    ($this->seen)($page, 'Removed the picture of Bea Author.');

    expect($this->author->refresh()->picture_media_id)->toBeNull();
});
```

and "asks for the password inside the change-role dialog, keeping the role chosen" (signed in without a confirmed password; choose Editor; Change role; the fresh-password fields appear in the dialog; the select still says Editor; the password; "Role changed. Bea Author is now an editor…"), "draws people as cards at 375 pixels, passing axe with every target 24 pixels or more, in both schemes" (no `table`; one card per person; `scrollWidth <= clientWidth`).

**`PeoplePageTest`** keeps each case's meaning and reads the files where the code now lives (`components/people/*.tsx` and the page): views through `<InPageNav` with `label={__('Views')}` in place of `<TabStrip`; the invitation's actions with "Send again", "Change role…" and "Revoke invitation" and its confirmation; the role dialog's labelled select and two hints; the own-row lock; the delete dialog's options; the empty views (Suspended with its own text). Words are matched inside `__('…')`. **`SharedControlsTest`**'s two rows that name `pages/users/index.tsx` name the file that now holds the select field (`components/people/delete-dialog.tsx`, and for the select-field import the role dialog too).

- [ ] **Step 1:** the new and changed cases; `npm run build`; run `PeopleScreenTest`: red where expected (record each first line).
- [ ] **Step 2:** build the screen and the six components.
- [ ] **Step 3:** `npm run build`; `PeopleScreenTest`, `FirstRunTest` (it passes through People's empty site), then `PeoplePageTest`, `SharedControlsTest`, `AccessibilityTest`, `UsersPageTest`, `ListScreenTest`. Baselines lowered for these files. Quick check; commit `feat(people): People on the list page with pictures, cards on phones and the password asked in place`; push; the Records line.

## Task 8: Gate and records

- [ ] The full `composer check` once. The browser files this part added or changed, after `npm run build`, one at a time: `AccountPagesTest`, `PeopleScreenTest`, `AccountNotificationsTest`, `ShellTest`, `ShellResponsiveTest`, `FirstRunTest`. GitHub runs the rest after the merge.
- [ ] `grep -c -E '	js/(pages/(users|account)/|layouts/account/|components/(people/|account/|manage-two-factor|two-factor-|account-menu|user-info))' tests/Architecture/design-system-baseline.txt` → 0, and the same paths without `js/` at the start of a line in `admin-words-baseline.txt` → 0. Any line that could not go is recorded with its reason.
- [ ] `git diff --stat origin/<base> -- resources/js/components/ui resources/js/components/patterns resources/css tests/Fixtures/design-system-scan.mjs tests/Pest.php` → nothing.
- [ ] Records: the commits; the totals; both baselines per rule before and after; every `B-?? (draft)`; and how to try it: on Your account › Profile, choose a picture from Media; see it in the account list, the account menu and on People; as an administrator, remove someone else's from their row's More menu; open Two-factor sign-in from the account list; on a phone-sized window, open the account pages from the one button and see People as cards. Commit `docs(plans): people and account records`; push.

---

## Existing tests that change (meaning unchanged)

| File | What changes |
|---|---|
| `tests/Feature/Cms/Users/CurrentUserDataTest.php`, `tests/Feature/Admin/AccountTest.php` | The documented keys gain `role` and `picture` |
| `tests/Feature/Plugins/PluginHoldersTest.php` | The keys gain `picture` before `privacy-requests` |
| `tests/Feature/Account/SecurityTest.php` | Its two-factor cases move to `TwoFactorPageTest` |
| `tests/Feature/Security/AccountEnforcementTest.php`, `tests/Feature/Plugins/SafeModeTest.php` | Sent to `two-factor.edit`; the new page is a safe page |
| `tests/Feature/Admin/PeoplePageTest.php`, `tests/Architecture/SharedControlsTest.php` | Read the files the code moved to, words inside `__()` |
| `tests/Feature/Admin/WordingPassSettingsTest.php`, `tests/Feature/Admin/AccessibilityTest.php` | Follow `Field`'s ids; rows for files without `<InputError` leave the first dataset |
| `tests/Browser/PeopleScreenTest.php` | Views past five under More; the shared password fields; Delete from the More menu |
| `tests/Browser/ShellTest.php` | The toast from a Preferences choice; Preferences' new line |

No test is deleted. A test not in this table that would have to change is a STOP.

## Every word

**New**, through `__()`, American English: "Choose an image from Media.", "You may only change your own picture." (exception text), "Picture changed.", "Picture removed. Your initials show instead.", "Removed the picture of :name.", ":name has no picture.", "Your picture", "Picture", "File name", "The picture is no longer shown. The image itself stays in Media, where the site’s administrators can delete it.", "Account pages: :page", "Two-factor sign-in", "Your name, email address and biography.", "Choose from Media", "Use as picture", "Remove", "With none, your initials show.", "Your role can’t choose from Media. Ask an administrator to add your picture.", "Confirm your email address to choose a picture.", "Your details", "your profile", "The password you sign in with.", "Updating it signs you out on your other devices.", "your password", "your notifications", "How the admin looks and behaves for you. Each choice is saved as you make it.", "Appearance", "Lists", "Writing", "Home", "More", "A second step when you sign in: a code from an authenticator app.", "Turn off two-factor sign-in?", "Two-factor sign-in is turned off for this site.", "More actions", "Remove picture…", "Remove the picture of :name?", "Their initials show instead. The image stays in Media.", "Remove picture", "Confirm your password", "Type your password to carry on. What you typed stays here.", "People you invite get an email with a link that works for :days.", "Someone you suspend can’t sign in, and everything they wrote stays as it is.", "No :role yet", "Invite someone and give them this role.".

**Gone:** the old layout's heading "Account" and its line "Signed in as :name. Each panel saves on its own."; "Save preferences"; "Update your name and email address".

**Moved into `__()` unchanged:** every other word in the owned files that the admin-words baseline lists.

## What must not be done

- Change `components/ui/**`, the existing `components/patterns/**`, a token, the scanner, `components/list/**`, `components/media/**`, `components/fields/**`, `tests/Pest.php`.
- Let anyone choose another person's picture; store an address in the column; make a Gravatar address.
- Show the picture on the public site, in bylines, the content lists or comments.
- Build Passkeys, Sessions, the role-permissions page or the "People's pictures" setting.
- Add a dependency; change `composer.json`, `composer.lock`, `package.json` or the lock.
- Delete a test, or change a test's meaning.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- the integration branch lacks the named commit, or a Task 0 line differs by its text;
- a screen needs a change to `components/ui/**`, an existing pattern, a token, or `components/list/**` / `components/media/**` (the media picker cannot be opened from Profile as it is, for instance);
- a design-system or admin-words line would have to be added or a count raised, or the scanner's allowed places or R7's names widened;
- a test outside "Existing tests that change" would have to change, or one there would change its meaning;
- the Form's `onChange` does not hear a `SettingsField` control (D10), so Preferences could only save through a change to `components/fields/**`;
- the migration cannot be applied to a site with accounts without rewriting `users` beyond adding the column.

## Security

- `SetPicture` is yours alone; `RemovePicture` is yours or a user manager's; both are refused before anything is written, and the HTTP routes add `can:users.manage` for another person's.
- The picture is an image already in the site's public Media; its address is made from the site's own address when asked. No address is stored, and nothing is fetched from another site.
- `SetPicture` accepts any live image ID without asking whether the chooser could see it in the picker: every image in Media is already public (open point 4).
- Erasure clears the picture; the image stays in Media for the administrators to delete.
- The fresh-password prompt posts to Fortify's own confirmation with the session's CSRF token, as today.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md` 4.43: the account list is the layout's own vertical side list in the settings frame's look; each account page is a `SettingsPage`; under 1024 one button opens it. 4.49: the picture is the Media image's square thumbnail; initials only until the setting is built.
- `docs/architecture/media.md`: an account's picture is a use of an image that `media_usages` does not record (open point 3).
- Changelog line, at the release: "Each person can choose a picture from Media for their account; People shows everyone's picture and becomes cards on a phone; your account pages share one layout, and two-factor sign-in has its own page."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds. `AccessibilityTest` and `SharedControlsTest` rows: keep both sides' edits.

## Conflicts with the other parts of this wave

- **Settings:** owns `components/fields/**`; Preferences imports `SettingsField` with its props `field`, `name`, `value`, `error`. If that part changes them, the merge fixes Preferences' call. It also edits `AccessibilityTest`'s rows for its files.
- **Sign-in:** imports `OTP_MAX_LENGTH` from `hooks/use-two-factor-auth.ts` (kept). Both parts may edit `AccessibilityTest` rows (different lines).
- **Combobox and remaining screens:** no shared file but the two baselines and their counts.
- **The shell's tests:** this part changes two cases of `tests/Browser/ShellTest.php`; no other part of this wave is expected to.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | People's Suspended and Invited sit under More (`InPageNav` shows five) | Keep for now; if it reads badly, the maintainers decide between six shown in `InPageNav` and a different order |
| 2 | The picture in bylines (the Bylines panel and author pages) and on the public site | A later part, with the guest writer's picture on `people` |
| 3 | Media does not know an image is someone's picture: "Unused" lists it, and trashing it shows no warning | Record the use when Media's usages are widened beyond content |
| 4 | `SetPicture` does not ask for the picker's `content.create` | Fine while every image in Media is public; revisit if private media arrives |
| 5 | Telling a person when an administrator removed their picture | Not now; the security log could record it later |
| 6 | The People's pictures setting and Gravatar (DS 4.49) | Not built (the proposal); initials only |

---

## Records

<!-- Filled in while building: commits; each test seen red (task, file, case, the failure's first line); both baselines per rule before and after; differences from the plan as B-?? (draft); changelog lines; any STOP. -->
