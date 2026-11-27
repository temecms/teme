---
title: Overriding core's components
type: how-to
audience: theme
summary: How a theme replaces one of the six pieces core draws on every page, which data each one hands its view, what a comment-form override must keep, and how the contract number keeps an override in step with core.
rests_on: [app/Domain/Themes/ComponentOverrides.php, app/Domain/Themes/ManifestValidator.php, app/Domain/Themes/Manifest.php, app/Console/Commands/CheckTheme.php, app/View/Components/Teme, resources/views/teme/components, packages/teme-contracts/schema/teme-theme.schema.json]
---

# Overriding core's components

Core draws six pieces of every page with a view of its own: the site's regions, the navigation menus, the language links, images, the lines that plugins place between a list's items, and the comment form. A theme can draw any of them itself. It ships a view for the piece and declares that it does, and core uses the theme's view in place of its own.

The document, which holds the page's head, its policy and the sign-in marker, is never on the list, and neither are the two script helpers. A theme's views of those names are never drawn.

## Declaring an override

In `teme-theme.json`, add an `overrides` key. Name the component, and give the contract number you wrote the override against:

```json
"overrides": {
    "navigation": { "contract": 1 }
}
```

Then ship the view at `views/components/navigation.blade.php`. The file's name is the component's name.

Core uses the theme's view only when both are there. A view the manifest doesn't declare is ignored, and `teme:theme:check` warns about it. These are errors, and the theme isn't used until they are fixed:

- a declared name that isn't one of the six components;
- a declared override whose view is missing;
- a contract number higher than core's own.

## The components and their data

Each view receives the variables below. They are the component's public properties, so the view reads them as core's view does.

| Component | Name in `overrides` | What the view receives |
|---|---|---|
| `<x-teme::region>` | `region` | `$name`, the region's slug; `$html`, its content or null; `$undeclared`, whether the theme's manifest doesn't declare the region; `$minWidth`, the width it waits for, or null; `$source`, where the page fetches it when it waits; `$before` and `$after`, the cleaned markup plugins place around it |
| `<x-teme::navigation>` | `navigation` | `$slotKey`, the slot's name; `$slotLabel`, its label in the manifest; `$label`, the landmark's name for screen readers; `$menuId`, an id unique on the page; `$items`, the menu as a tree of items, each with `label`, `url`, `current`, `opensNewTab`, `heading` and `children`, or null; `$showUnbound`, whether to note items that aren't placed outside production |
| `<x-teme::languages>` | `languages` | `$links`, the list of language links; each has `label`, `url`, `current` and `language` (its language tag) |
| `<x-teme::image>` | `image` | `$id`, the media item; `$context`, how the image is used (such as `content`); `$priority`, whether it loads first; `$alt`, the alt text for this use, or null for the media item's own; `$decorative`, whether it is decoration; `$markup()`, a method that draws the `<img>` with the tag's class attribute passed through |
| `<x-teme::between-items>` | `between-items` | `$html`, a list of cleaned markup, one for each item plugins placed after the item, drawn in turn |
| `<x-teme::comment-form>` | `comment-form` | `$form`, the form's state: `action` (where it posts), `path` (the item's address), `parentId` and `parentName` (the comment replied to, or null), `askWebsite`, `signedInOnly`, `keepIpDays`, `values` (what the reader wrote, to show again after an error), `errors` and `standalone`; `$level`, the heading level the form uses; `$closedToGuests`, whether guests may no longer comment |

The attributes an author passes to the component (for example `label` on a navigation, or `showNames` on the language links) reach the component's constructor, and the values the constructor settles are what the view gets.

## What an override receives, and what it doesn't

An override is drawn from its own file, so it gets the component's variables and nothing else. The theme's view composers don't run on it, just as they don't run on core's own views. A composer that adds a variable for the theme's templates won't add it here, so an override that needs a value must take it from the component's variables above.

## The comment form

Comments can only be read from a form core's way, so a comment-form override must keep three things. `teme:theme:check` refuses an override that lacks any of them:

1. It includes `<x-teme::comment-form-fields :form="$form" />` inside its form. That component carries the hidden `path`, `parent`, `token` and `_token` fields, the `subject` honeypot that catches bots, and the thread script. Core's own form includes the same component.
2. Its form posts to `{{ $form->action }}`.
3. Its form carries `data-teme-form`, which core's script finds the form by.

The rest of the form (the text box, the name and email fields, the privacy line) is yours to lay out. Keep the fields' `name` attributes as core's: `text`, `name`, `email`, `url` and `remember`.

## Contract numbers

Every overridable component is at contract 1. An override states the contract it was written against, and it keeps working while core stays at that number.

When core changes what a view receives in a way that would break an override, core raises the number. From then on, `teme:theme:check` warns that the override is behind, and asks you to check the view against this page and then raise the declared number to core's. An override written against a number higher than core's is an error, because the theme needs a newer Teme.

## Rules

An override is a theme view like any other, so it follows the same rules. It has no inline `<script>` and no `on…` attributes, because the content security policy stops both. Put the theme's scripts in its build, as a module. `teme:theme:check` refuses a view that breaks the rule.

Run the check after you change an override:

```bash
php artisan teme:theme:check your-theme-code
```
