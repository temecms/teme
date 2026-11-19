# Design system: the admin

Updated 2026-11-13 with decisions I-3398 to I-3420.

The rules every admin screen is built to, from the look approved on 2026-11-13 (reference design D2) and the decisions of that day. This document is normative: where it says **must** or gives a value, the code uses that value. Where it and older documents disagree, this one wins for the new interface; section 12 lists every disagreement found.

| Source | What it settles |
|---|---|
| The approved reference design (D2) | The approved look: tokens, components, every screen drawn |
| The row-actions options, option 4 | Row actions (I-3398) |
| Decisions I-3390, I-3395 to I-3420 | One look in light and dark; Teme's own layout; plugins (I-3396); no toolbar and a status area (I-3397); hybrid row actions (I-3398); the table that holds its shape (I-3399); editor and media (I-3400); Home, a plugin's own area, site status (I-3401, adopted); the accent (I-3402); the four answers: looks, row heights, dependencies, the Publish panel (I-3404); the plugin icon (I-3405); "Soon" (I-3408); people's pictures (I-3410); the editor's Search and social box (I-3413 to I-3415); a plugin's options inside a core box (I-3418) |
| Decision, 2026-11-13 | **Ember is Teme's default accent.** The other accents are for later, but the system has to fit each accent. |
| The interface audit | What went wrong in the built admin and what a check can enforce |
| `docs/architecture/accessibility.md` | WCAG 2.2 AA, the standard this system serves |
| The catalogue | This document drawn live, for looking at |
| The v10 reference design, and its Search and social drawing | The design that replaces v9 (I-3390): every screen, and the parts the joining pass unified into shared components (4.41 to 4.49). Values taken from its stylesheet are marked as such |

**How to use it.** Look the thing up: a value in section 2, a component in section 4, a screen's frame in section 5. If what you need is not here, it is a missing rule: ask, and it is added here before it is built. Never invent a value.

Contents: 1 Principles · 2 Tokens · 3 Mapping from today's tokens (3.1 The looks are removed; more contrast follows the system) · 4 Components (4.1 to 4.40, then 4.41 Fresh-password prompt · 4.42 Settings page layout · 4.43 Account layout · 4.44 Auth card · 4.45 Step bar · 4.46 "Soon" tag · 4.47 Plugin icon · 4.48 Icon tile · 4.49 Person's picture; the editor's Search and social box is in 4.39, the media list view in 4.35) · 5 Page patterns · 6 Plugins (6.3 A plugin's options inside a core box) · 7 Accessibility · 8 Writing in the interface · 9 The check that stops drift · 10 Rollout · 11 Open questions · 12 Disagreements found (12.4 added)

---

## 1. Principles

Six rules that decide arguments. Each says what it forbids.

| # | Principle | Forbids |
|---|---|---|
| P1 | **One look, in light and dark.** The look is Teme's, not a person's choice (I-3395). Light and dark follow the person's appearance setting, and more contrast follows the computer's own "more contrast" setting (I-3404); nothing else changes the look. | Looks, colour schemes, per-person accents, a high-contrast setting of Teme's own, any component that knows which theme or accent is on. An accent choice for a site or a person is a later feature (parked idea I-192), not built now. |
| P2 | **A neutral ground; colour means something.** The page is a near-white with no hue, panels are white, greys are neutral (chroma at most 0.004). Colour comes only from the accent and the status colours, and each colour has one meaning. | Warm, cream or yellowish neutrals; decorative colour; a second accent; a raw colour anywhere; colour as the only carrier of a state. |
| P3 | **Space, surface and weight before lines.** Things are grouped by space and lifted by a soft shadow. A border appears only where a control needs its edge. | Borders around regions, boxes inside boxes, dividers between table rows, heavy lines. |
| P4 | **Teme's own layout, improved; never a copy of another system.** The redesign builds on v9 and the built admin, taking features from Statamic, Ghost and EmDash one at a time (I-3395). | A Post/Block panel pair, an admin toolbar, hover-only actions, Screen options, any screen copied from another system only because that system has it. |
| P5 | **Firm, readable type.** Base text is 15px, nothing is under 12px, weights are 400, 500 and 600 only. | Tiny or slim type, light weights, sizes outside the six, letter-spaced lowercase. |
| P6 | **Plugins fit without clutter.** On shared screens a plugin adds through declared places, as data that core draws; in its own space it may build anything, with the kit (I-3396). | Plugin CSS restyling core, plugin buttons in the editor toolbar, extra sidebar entries, a plugin squeezed to fit (a wall is a missing socket, filed and built). |

Behind all six: **one way to do each thing.** One component per job, one place for Save, one notice. The check in section 9 enforces what can be enforced.

---

## 2. Tokens

Every value lives in `resources/css/app.css`. Raw tokens are CSS custom properties in `:root` (light) and `.dark` (dark), which plugins read; Tailwind exposes each through `@theme inline` under the class name in the table. **The CSS variable names are part of the plugin contract** (section 6.4).

Colours are written in `oklch(L C H)`. Contrast figures are WCAG 2 ratios computed from these values, after clipping to sRGB (the worst case: what an ordinary screen shows), with Ember as the accent. The script that computed them is the same arithmetic the check uses (section 9, R12).

### 2.1 Neutral ramp

Hue 260 (barely cool), chroma at most 0.004. The page itself has chroma 0.

| CSS variable | Class | Light | Dark | Use |
|---|---|---|---|---|
| `--page` | `bg-page` | `oklch(0.9875 0 0)` | `oklch(0.185 0.002 260)` | The ground under everything; the editor's writing area; the top bar |
| `--chrome` | `bg-chrome` | `oklch(0.97 0.002 260)` | `oklch(0.165 0.002 260)` | The sidebar, the editor's rail, pane heads, a workspace's top bar |
| `--surface` | `bg-surface` | `oklch(1 0 0)` | `oklch(0.225 0.003 260)` | Panels, the table frame, menus, popovers, dialogs, inputs |
| `--surface-2` | `bg-surface-2` | `oklch(0.978 0.001 260)` | `oklch(0.252 0.003 260)` | Row hover, inset areas (picker side, menu and dialog footers, collapsed card, featured-image row, upload drop zone) |
| `--surface-3` | `bg-surface-3` | `oklch(0.952 0.002 260)` | `oklch(0.29 0.003 260)` | Neutral button fill, hover on chrome and menu items, the selected tab, term chips, kbd, skeleton |
| `--surface-4` | `bg-surface-4` | `oklch(0.918 0.003 260)` | `oklch(0.33 0.004 260)` | Hover of a `surface-3` button, the switch's off track, the editor's divider |
| `--line` | `border-line` | `oklch(0.922 0.002 260)` | `oklch(0.30 0.003 260)` | The few dividers: menu separator, toolbar separators, tool-group divider, chart axis. Default `border-color` |
| `--edge` | `border-edge` | `oklch(0.64 0.004 260)` | `oklch(0.56 0.004 260)` | A control's boundary: input, select, unchecked checkbox and radio, filter chip, switch track |
| `--ink` | `text-ink` | `oklch(0.22 0.004 260)` | `oklch(0.95 0.002 260)` | Text, titles, values |
| `--ink-2` | `text-ink-2` | `oklch(0.43 0.004 260)` | `oklch(0.80 0.003 260)` | Secondary text: descriptions, field labels, nav entries, icons in menus and toolbars |
| `--ink-3` | `text-ink-3` | `oklch(0.52 0.004 260)` | `oklch(0.70 0.003 260)` | Quiet text: hints, meta lines, counts, placeholders, column headings, group labels |
| `--neutral-dot` | `bg-neutral-dot` | `oklch(0.62 0.004 260)` | `oklch(0.66 0.004 260)` | The dot of a Draft (neutral) pill |
| `--scrim` | `bg-scrim` | `oklch(0.2 0.004 260 / 0.38)` | `oklch(0.08 0.002 260 / 0.62)` | Behind dialogs and sheets (replaces `bg-black/80`) |
| `--scroll-thumb` | (base CSS) | `oklch(0.82 0.002 260)` | `oklch(0.38 0.003 260)` | Scrollbar thumb at rest |
| `--scroll-thumb-hover` | (base CSS) | `oklch(0.70 0.003 260)` | `oklch(0.48 0.004 260)` | Scrollbar thumb while the area is pointed at |

**Content colours** (pictures and the things drawn over them; the same in both themes because they sit on photographs, not on the interface):

| CSS variable | Value (both themes) | Use |
|---|---|---|
| `--img-outline` | light `oklch(0 0 0 / 0.1)`, dark `oklch(1 0 0 / 0.1)` | 1px inner outline on every picture and thumbnail |
| `--photo-scrim` | `oklch(0 0 0 / 0.55)` | Labels over a picture (file type on a tile, importing overlay) |
| `--on-photo` | `oklch(1 0 0)` | Text and marks over a picture (focal marker ring, rule-of-thirds lines at 35%) |
| `--paper` | `oklch(1 0 0)` | Things that must be white whatever the theme: a QR code's ground, a print sheet |

### 2.2 The accent: one pair, everything derived

The accent is **two numbers**, hue and chroma, plus one optional lightness. Every accent token is computed from them in CSS, so a different accent is a change of two values and nothing else.

| CSS variable | Ember (the default) | Meaning |
|---|---|---|
| `--accent-h` | `42` | Hue, in degrees |
| `--accent-c` | `0.16` | Chroma at the solid |
| `--accent-l` | `0.52` light, `0.74` dark | The solid's lightness. The only free lightness; leave the default unless an accent fails section 2.2.2 at it |

**The formula** (light, then dark; `h` = `--accent-h`, `c` = `--accent-c`, `L` = `--accent-l`):

| CSS variable | Class | Light | Dark | Use |
|---|---|---|---|---|
| `--accent` | `bg-accent` | `oklch(L c h)` | `oklch(L c×0.95 h)` | The solid: the primary button, checked checkbox and switch, the brand mark, chart highlight, the "+" between blocks, drop lines |
| `--accent-hover` | `bg-accent-hover` | `L − 0.06` when its text is light, `L + 0.06` when dark; `c` | same rule; `c×0.9` | Hover and press of the solid |
| `--on-accent` | `text-on-accent` | chosen automatically, see below | same | Text and icons on the solid |
| `--accent-ink` | `text-accent-ink` | `oklch(0.47 c h)` | `oklch(0.80 c×0.85 h)` | Accent as text: links, row actions, the current nav icon, a pressed pin |
| `--accent-soft` | `bg-accent-soft` | `oklch(0.955 c×0.28 h)` | `oklch(0.30 c×0.38 h)` | Selection: the current nav entry, a selected row and tile, a pressed toggle, the bulk bar, badges |
| `--accent-soft-2` | `bg-accent-soft-2` | `oklch(0.915 c×0.45 h)` | `oklch(0.36 c×0.5 h)` | A step stronger: the avatar, highlighted words in suggestions, the Customise outline |
| `--on-accent-soft` | `text-on-accent-soft` | `oklch(0.37 c h)` | `oklch(0.88 c×0.6 h)` | Text on either soft step |
| `--ring` | `outline-ring` | `oklch(0.55 c h)` | `oklch(0.78 c h)` | The focus ring |
| `--chart-1` | `fill-chart-1` | `var(--accent)` | `var(--accent)` | The highlighted series |
| `--chart-2` | `fill-chart-2` | `oklch(0.80 0.05 h)` | `oklch(0.45 0.06 h)` | Every other series |

The text on the solid is picked by the solid's own lightness, with CSS relative colour (Chrome 119, Safari 18, Firefox 128):

```css
--on-accent: oklch(from var(--accent) clamp(0.2, (0.58 - l) * 1000, 0.99) 0.02 h);
--accent-hover: oklch(from var(--accent) calc(l - 0.06 + 0.12 * clamp(0, (l - 0.58) * 1000, 1)) c h);
```

A solid darker than L 0.58 gets near-white text (L 0.99) and darkens on hover; a lighter one gets near-black text (L 0.20) and lightens on hover. 0.58 is where white text stops reaching 4.5:1 and near-black starts to (white needs L ≤ 0.568; near-black needs L ≥ 0.595). With Ember the light solid takes white text and the dark solid takes dark text.

Ember's light `--accent` (L 0.52, C 0.16) is just inside sRGB (the sRGB limit at that lightness is 0.157): it shows the same on every screen. Its `--accent-ink` and the dark steps sit slightly outside and are clipped by the browser by less than 0.03 in OKLab distance, which is not visible.

#### 2.2.1 Accent is not a product setting

There is one accent, Ember, in `:root`. Teal, Harbour, Plum and any other accent are **not offered in the product**: offering a choice of accent, to a site or a person, is a later feature (parked idea I-192). The system is built so that day costs nothing: no component, status colour, picture or test may assume Ember's hue (the check's rule R13 forbids a literal accent value or hue number anywhere but the accent token block).

#### 2.2.2 When an accent is acceptable

An accent pair is acceptable when every derived pair below passes, **in light and in dark**, computed after clipping to sRGB. The check (R12) computes this for the accent in `app.css`; the catalogue computes it live for any accent typed into it.

| Pair | Needs | Why |
|---|---|---|
| `--on-accent` on `--accent` and on `--accent-hover` | 4.5:1 | Button text |
| `--accent` on `--surface` and on `--page` | 3:1 | A checked box, a switch, a chart bar must be seen |
| `--ring` on `--surface`, `--page` and `--chrome` | 3:1 | Focus must be seen everywhere |
| `--accent-ink` on `--surface`, `--page`, `--chrome`, `--surface-2`, `--accent-soft` | 4.5:1 | Links and row actions, on every ground they sit on |
| `--on-accent-soft` on `--accent-soft` and `--accent-soft-2` | 4.5:1 | Selected entries, badges, the bulk bar |
| Hue distance to every status hue (section 2.3) | at least 20° | An accent must never be read as a state |
| Gamut: OKLab distance between the written solid and the clipped one | at most 0.04 | Otherwise the colour seen is not the colour chosen |

In plain numbers, with the fixed lightness steps above: the light solid must sit at **L 0.40 to 0.56** (white text) or **L 0.60 to 0.66** (dark text); the dark solid at **L 0.51 to 0.56** (white text) or **at least 0.60** (dark text). Chroma may go up to the sRGB limit for the hue at that lightness. Below L 0.40 the accent turns to near-black; above 0.66 in light, a checked box no longer stands out from white.

**Measured, for the record** (light / dark; each figure is the lowest over the grounds the pair sits on; the catalogue computes the same table live):

| Accent | h, c | Text on solid | Solid on panel or page | Ring on chrome, panel or page | Link on its grounds | Soft text on soft | Nearest status hue | Verdict |
|---|---|---|---|---|---|---|---|---|
| **Ember (default)** | 42, 0.16 | 5.61 / 7.46 | 5.70 / 7.02 | 4.77 / 7.71 | 6.16 / 6.93 | 8.15 / 7.26 | danger 18: 24° | Acceptable |
| Teal (later) | 195, 0.125 | 4.76 / 8.21 | 4.74 / 7.79 | 4.00 / 8.99 | 5.34 / 7.47 | 7.18 / 7.54 | ok 150: 45° | Acceptable (its light solid is clipped by 0.05 on an sRGB screen, so it shows duller than written) |
| Harbour (later) | 262, 0.17 | 5.47 / 7.55 | 5.47 / 7.13 | 4.57 / 8.02 | 6.10 / 7.02 | 8.27 / 7.41 | info 240: 22° | Acceptable |
| Plum (later) | 338, 0.15 | 5.70 / 7.38 | 5.78 / 6.94 | 4.83 / 7.99 | 6.35 / 7.08 | 8.50 / 7.58 | ai 300: 38° | Acceptable |
| A yellow wanting a light solid | 100, 0.17, L 0.86 | 11.91 / 11.91 (dark text, chosen automatically) | **1.46** / 11.26 | 4.36 / 8.63 | 5.90 / 7.33 | 8.04 / 7.57 | warn 85: **15°** | **Refused**: in light, a ticked box and a chart bar vanish on white, and it would read as a warning |

### 2.3 Status colours

Each state has a solid (the dot or a chart mark), a soft background, and an ink (text and icons, on the soft background and on any surface). Status hues were moved on 2026-11-13 so that Ember, an orange, can never be mistaken for a problem or a warning (see 2.3.1).

| State | Tone | Solid | Soft | Ink | Pill word |
|---|---|---|---|---|---|
| Published, fine, saved | `ok` | L `oklch(0.52 0.13 150)` / D `oklch(0.74 0.14 150)` | L `oklch(0.95 0.04 150)` / D `oklch(0.30 0.05 150)` | L `oklch(0.38 0.1 150)` / D `oklch(0.86 0.10 150)` | Published; Fine |
| Draft, private, not scanned | `neutral` | `--neutral-dot` | `--surface-3` | `--ink-2` | Draft; Private |
| Scheduled, information | `info` | L `oklch(0.56 0.12 240)` / D `oklch(0.74 0.11 240)` | L `oklch(0.955 0.025 240)` / D `oklch(0.30 0.04 240)` | L `oklch(0.42 0.11 245)` / D `oklch(0.86 0.07 240)` | Scheduled |
| In review, needs a look, warning | `warn` | L `oklch(0.76 0.15 85)` / D `oklch(0.82 0.15 88)` | L `oklch(0.965 0.05 92)` / D `oklch(0.32 0.05 85)` | L `oklch(0.45 0.10 85)` / D `oklch(0.88 0.12 90)` | In review; Needs a look |
| Problem, failed, broken, in the trash | `danger` | L `oklch(0.53 0.20 18)` / D `oklch(0.70 0.18 18)` | L `oklch(0.955 0.03 18)` / D `oklch(0.31 0.06 18)` | L `oklch(0.45 0.18 18)` / D `oklch(0.85 0.09 18)` | Problem; In the trash |
| Written or suggested by AI | `ai` | L `oklch(0.55 0.17 300)` / D `oklch(0.74 0.13 300)` | L `oklch(0.955 0.03 300)` / D `oklch(0.31 0.06 300)` | L `oklch(0.45 0.15 300)` / D `oklch(0.87 0.08 300)` | (marks only) |

CSS variables: `--ok`, `--ok-soft`, `--ok-ink`; `--info`, `--info-soft`, `--info-ink`; `--warn`, `--warn-soft`, `--warn-ink`; `--danger`, `--danger-soft`, `--danger-ink`; `--ai`, `--ai-soft`, `--ai-ink`. For the one filled danger button: `--danger` (fill), `--danger-hover` (L 0.47 light / 0.76 dark), `--on-danger` (L `oklch(0.99 0.005 18)` light, `oklch(0.20 0.03 18)` dark).

**`--warn` (the solid) is decorative.** It reaches only 2.16:1 on white, so it is used only as a dot beside a word. Anything that carries warning meaning by itself (an icon, a mark) uses `--warn-ink`.

**Every state is a word plus a shape** (section 4.13): a dot for ok, neutral and info; a triangle icon for warning; an octagon icon for problem. Colour is never the only carrier.

#### 2.3.1 Ember against the status colours (measured)

| Pair | Before (reference-design values) | After (this system) |
|---|---|---|
| Ember solid hue vs problem hue | 41° vs 27°: **14° apart**, OKLab distance 0.061 | 41° vs 18°: **23°**, distance 0.083 |
| Ember link text vs problem text | distance 0.039 | 0.065 |
| Ember link text vs warning text | warning ink at hue 60: **19° apart**, distance 0.085 | warning ink at hue 85: **43°**, distance 0.114 |
| Ember solid vs warning solid | 28°, distance 0.196 | 43°, distance 0.266 |
| Ember soft vs problem soft (selected row vs problem pill ground) | distance 0.016 | 0.023 (still close: told apart by treatment below) |

Hue alone cannot keep an orange accent apart from red and amber, so treatment does the rest, and these rules hold for **any** accent:

1. A primary button is a **solid fill with light or dark text, 36px tall, 8px radius, no dot**. A status pill is **24px tall, fully rounded, a soft ground, a coloured icon or dot, and a word**. They never share a shape.
2. The problem pill carries an **octagon icon**, the warning pill a **triangle icon**; only fine, draft and info pills carry a plain dot.
3. The filled danger button appears only in a confirmation dialog, **in place of** the primary button, never beside one.
4. Selection (`--accent-soft`) is never drawn as a pill and never carries a word that names a state.

### 2.4 Text colours, borders and where they may sit

| Text | On | Light | Dark |
|---|---|---|---|
| `--ink` | `--page` | 16.70 | 16.10 |
| `--ink` | `--surface` | 17.31 | 14.78 |
| `--ink-2` | `--surface` | 8.10 | 9.15 |
| `--ink-2` | `--surface-3` | 7.04 | 7.55 |
| `--ink-3` | `--surface` | 5.50 | 6.40 |
| `--ink-3` | `--page` | 5.31 | 6.97 |
| `--ink-3` | `--chrome` | 5.05 | 7.21 |
| `--ink-3` | `--surface-2` | 5.16 | 5.95 |
| `--ink-3` | `--surface-3` | 4.78 | 5.28 |
| `--ink-3` | `--accent-soft` (selected row meta) | 4.64 | 5.21 |
| `--accent-ink` | `--surface` / `--surface-2` / `--accent-soft` | 7.31 / 6.86 / 6.16 | 8.51 / 7.91 / 6.92 |
| `--page` (toast text) | `--ink` (toast ground) | 16.70 | 16.10 |
| status inks on their softs | ok / info / warn / danger / ai | 8.38 / 7.36 / 6.77 / 7.04 / 6.94 | 9.05 / 8.95 / 8.85 / 8.13 / 8.84 |

| Control boundary | On | Light | Dark |
|---|---|---|---|
| `--edge` | `--surface` | 3.36 | 3.67 |
| `--edge` | `--page` | 3.24 | 4.00 |
| `--edge` | `--surface-2` | 3.15 | 3.41 |
| `--edge` | `--chrome` | 3.08 | 4.14 |

These match the reference design's report (text on page 16.7 / 16.1; quiet text on panel 5.5 / 6.4; control edge 3.36 / 3.67).

**Forbidden placements** (they fail): `--ink-3` on `--surface-4` (4.32); an edged control on `--surface-3` (2.92) or `--accent-soft` (2.83). So quiet text never sits on `--surface-4`, and inputs, unchecked boxes and filter chips sit only on `--page`, `--surface`, `--surface-2` or `--chrome`. A checkbox in a selected row is checked by definition, so it is filled, not edged.

**More contrast.** When the computer asks for it (`prefers-contrast: more`), a block in `app.css` re-values tokens only, as 3.1 lists. It is not a setting of Teme's (I-3404).

### 2.5 Elevation

| Level | CSS variable / class | Light | Dark | Use |
|---|---|---|---|---|
| 0 Flat | none | none | none | Page, chrome, the writing area, a workspace |
| 1 Raised | `--shadow-raised` / `shadow-raised` | `0 1px 2px oklch(0.25 0 0 / 0.06), 0 2px 10px oklch(0.25 0 0 / 0.045)` | `0 1px 2px oklch(0 0 0 / 0.35), 0 0 0 1px oklch(1 0 0 / 0.035)` | Panels, the table frame, figure tiles, the media detail panel |
| 2 Floating | `--shadow-float` / `shadow-float` | `0 2px 6px oklch(0.2 0 0 / 0.08), 0 12px 32px oklch(0.2 0 0 / 0.12)` | `0 4px 12px oklch(0 0 0 / 0.40), 0 16px 40px oklch(0 0 0 / 0.45), 0 0 0 1px oklch(1 0 0 / 0.06)` | Menus, popovers, the bubble, the block picker, toasts, tooltips |
| 3 Modal | `--shadow-modal` / `shadow-modal` | `0 8px 24px oklch(0.2 0 0 / 0.14), 0 32px 72px oklch(0.2 0 0 / 0.18)` | `0 12px 32px oklch(0 0 0 / 0.5), 0 40px 80px oklch(0 0 0 / 0.55), 0 0 0 1px oklch(1 0 0 / 0.07)` | Dialogs, sheets, the picker, the larger modal |

Dark mode adds a faint light ring to each shadow, because a shadow alone is invisible on a dark ground.

### 2.6 Radii

| Token | Value | Use |
|---|---|---|
| `--radius-xs` / `rounded-xs` | 4px | Checkbox box |
| `--radius-sm` / `rounded-sm` | 6px | Kbd, tooltip, term chips, gutter and card-head buttons, the segmented control's buttons, Link profile pills |
| `--radius-md` / `rounded-md` | 8px | Buttons, inputs, selects, menu items, nav entries, tabs, thumbnails, pictures, callouts, notices |
| `--radius-lg` / `rounded-lg` | 12px | Panels, the table frame, menus, popovers, toasts, the bulk bar, tiles, editor cards, the block picker |
| `--radius-xl` / `rounded-xl` | 16px | Dialogs, sheets (inner corner only) |
| `--radius-full` / `rounded-full` | 9999px | Pills, filter chips, badges, avatar, switch, the "+" between blocks |

Nested corners follow outer = inner + padding: a menu (12) with 4px padding holds items of 8.

### 2.7 Spacing

A 4px grid. Tailwind's `--spacing` stays 4px, so `p-3` is 12px.

| Step | px | Allowed where |
|---|---|---|
| `0.5` | 2 | **Only** as the gap between touching controls (toolbar buttons, sub-entries in the sidebar, the segmented control's inset and gap). Never as padding or margin |
| `1` | 4 | Inside a control (icon to label in small things), label to control, title to meta line |
| `2` | 8 | Icon to label, gap between buttons in a group, between chips, tile padding |
| `3` | 12 | Control padding (inline), table cell padding, between rows of a list, filter row gap within a group |
| `4` | 16 | Between related blocks: field groups, panels in a grid, page-header gap, filter row to table |
| `5` | 20 | Panel inline padding, gap between fields in a form panel |
| `6` | 24 | Panel padding in forms, dialog inline padding, between a page header and its content, top-bar inline padding |
| `8` | 32 | Page inline padding, between sections of a page |
| `10` | 40 | Between Home's sections |
| `12` | 48 | Empty-state vertical padding |
| `16` | 64 | Page bottom padding |

Forbidden: `1.5`, `2.5`, `3.5` (6, 10, 14px), `7`, `9`, `11`, `14`, `15`, and any `[Npx]` spacing. The gap between groups is at least twice the gap within a group.

### 2.8 Type

**Fonts.** IBM Plex, self-hosted (I-196), with the system stack behind each family, as today.

| Token | Family | Use |
|---|---|---|
| `--font-sans` | IBM Plex Sans Variable | The whole interface, the editor's excerpt and headings |
| `--font-serif` | IBM Plex Serif 400, 400 italic, 600 | The post title and the body text in the editor; the brand initial |
| `--font-mono` | IBM Plex Mono 400, 500 | Code, identifiers, addresses where a character matters |

**The six sizes.** Tailwind's own sizes (`text-xs` to `text-9xl`) are removed (`--text-*: initial`), so only these exist.

| Class | Size | Line height | Weights | Use, exactly |
|---|---|---|---|---|
| `text-caption` | 12px | 1.5 (18px) | 400, 500, 600 | **Help text** under a field (400, ink-3); kbd; badges and counts in badges (600); tile facts; the plugin mark; sidebar group headings, menu group labels and the card's type name (600, uppercase, +0.04em, the only uppercase allowed); chart labels. Nothing is smaller |
| `text-small` | 13px | 1.5 (19.5px) | 400, 500 | **Field labels** (500, ink-2); table column headings (500, ink-3); meta lines; pills and chips (500); row actions (500); small buttons; secondary text in menus, the rail's summary rows, tooltips |
| `text-body` | 15px | 1.5 (22.5px) | 400, 500, 600 | **Body text** and the default: **table cells**; controls and button labels (500); menu items; nav entries (500); **panel titles** (600, in dashboard panels, rail sections and panes); the title in a list row (600, at line-height 1.35) |
| `text-lead` | 18px | 1.35 (UI) | 400, 600 | **Section headings** in a page, form-panel titles, dialog titles, empty-state titles (600); the editor's excerpt (400, line-height 1.55); the editor's subheading (600, sans); **the editor's reading text** (Plex Serif 400, line-height 1.7) |
| `text-title` | 24px | 1.2 | 600 | **Page titles** (`h1`, −0.01em); figure values; the editor's heading (sans, line-height 1.3) |
| `text-display` | 34px | 1.18 | 600 | **The post title** in the editor (Plex Serif, −0.015em, `text-wrap: balance`); big numbers in panels (sans, line-height 1.1, −0.02em) |

Inputs use `text-body`, and 16px below 640px wide so a phone does not zoom on focus. Numbers that change or line up use `tabular-nums`. Headings use `text-wrap: balance`; descriptions `text-wrap: pretty`. Weights other than 400, 500 and 600 are forbidden.

### 2.9 Sizes

| Token | Value | Use |
|---|---|---|
| `--ctl-h` | 36px | Buttons, inputs, selects, tabs, filter chips, menu items, the sidebar's search |
| `--ctl-h-sm` | 32px | Small buttons (bulk bar, pager, dialog-head actions, rail action bar, editor toolbar and bubble buttons), crumbs, rail summary selects, the avatar |
| `--ctl-h-xs` | 28px | Only inside dense frames: the segmented control's buttons, card-head buttons, the block gutter, the Tools launcher's pin |
| `--row-h` | 92px (72px where the title is one line) | A content-list row (section 4.26): two heights, I-3404 |
| `--top-h` | 60px | The top bar, a workspace's top bar, the command palette's input row |
| `--side-w` | 264px | The sidebar |
| `--side-w-rail` | 68px | The sidebar folded to icons |
| `--rail-w` | 344px | The editor's rail (resizable 320 to 480, as built) |
| `--measure` | 704px | The editor's reading measure |
| `--page-max` | 1280px | Every page's container |
| `--form-max` | 760px | A form's column |
| `--detail-w` | 360px (320 below 1360px wide) | The media detail panel, the image-editing side panel |
| `--dialog-sm` / `-md` / `-lg` | 480 / 640 / 860px | Dialog widths (4.19) |
| `--dialog-xl-w` / `-h` | `min(1120px, 100vw − 80px)` / `min(740px, 100vh − 48px)` | The large fixed dialog |
| `--sheet-w` | 480px | Sheets |
| Icons | 20px default, 16px in small controls and menus' end marks, 14px inside marks and pills, 32px in empty states; stroke 1.75 | Lucide only, through `ui/icon.tsx` |

### 2.10 Motion

| Token | Value | What uses it |
|---|---|---|
| `--ease` | `cubic-bezier(0.2, 0, 0, 1)` | Everything. One curve |
| `--dur-1` | 120ms | Hover, press, colour, opacity of row tools and gutters |
| `--dur-2` | 180ms | Menus and popovers appearing (opacity, scale 0.97 to 1), switch, chevron rotation, crop previews |
| `--dur-3` | 240ms | Dialogs (opacity, 8px rise, scale 0.985), toasts (8px rise), the sidebar folding |
| `--press` | 0.96 | Buttons scale on press |

**May animate:** `opacity`, `scale`, `translate`, `rotate`, `background-color`, `color`, `box-shadow`, the shell's `grid-template-columns`. **Never:** width, height, top or left (except the focal marker following a click), `transition: all`. Name the properties. **No transition on a theme switch**: the switch adds `transition: none` to everything for one frame. **Reduced motion** (`prefers-reduced-motion: reduce`): durations become 1ms, the skeleton stops shimmering (a still `--surface-3`), the press scale is off; the save spinner and job spinners keep turning, because they are the only sign of work going on.

### 2.11 Layers

| CSS variable | Value | What |
|---|---|---|
| `--z-sticky` | 10 | Sticky table header, pinned columns (their own 1 to 3 inside the table), the rail's action bar, pane heads |
| `--z-toolbar` | 20 | The editor's sticky toolbar |
| `--z-workspace` | 100 | A full-screen workspace |
| `--z-overlay` | 200 | Scrim, dialogs, sheets |
| `--z-float` | 300 | Menus, popovers, the bubble, the block picker (above dialogs, so a menu in a dialog shows) |
| `--z-tooltip` | 400 | Tooltips |
| `--z-toast` | 500 | Toasts |

Used as `z-(--z-overlay)`. No other z-index number may be written.

### 2.12 Breakpoints

| Name | Min width | What changes at it |
|---|---|---|
| (phone) | 0 | Page inline padding 16px; the sidebar is a drawer opened from the top bar's menu button; filters fold to search plus a Filters button; dialogs nearly full width; the picker full screen; Home one column |
| `sm` | 640px | Inputs back to 15px text; dialogs at their widths |
| `md` | 768px | Page inline padding 32px; the sidebar is the icon rail, with the full menu opening over the page; Home's small and medium panels side by side |
| `lg` | 1024px | The full sidebar is in the page, foldable to the rail by choice; the editor's rail is in the page (below it, a sheet); the media detail panel is beside the grid (below it, a sheet) |
| `xl` | 1360px | The media and image-editing side panels at 360px (320 below); the editor's page padding 80px (64 below) |

Tailwind's `2xl` is removed. The admin is checked at 320, 375, 1280 and 1440px wide. On a coarse pointer (touch), every control is at least 44px.

### 2.13 Focus ring

`outline: 2px solid var(--ring); outline-offset: 2px`, radius following the element. Inputs, selects and textareas: offset 0 and their edge turns transparent, so the ring replaces the border. The ring is never removed without this replacement (check R10). Contrast of the ring: 4.76 on chrome, 5.01 on page, 5.20 on surface in light; 8.40 or more in dark.

### 2.14 Scrollbars

Every scrolling area uses the **ScrollArea** component (4.40), which sets `scrollbar-width: thin`, `scrollbar-color: var(--scroll-thumb) transparent`, and the hover colour while pointed at. The page view keeps `scrollbar-gutter: stable`. No new dependency: this is CSS, honoured by Chrome, Edge and Firefox; Safari on macOS shows its own overlay bars.

---

## 3. Mapping from today's tokens

Every variable in `origin/main:resources/css/app.css` and what it becomes. **`--accent` changes meaning** (today: the grey hover; new: the brand accent), so the codemod renames in two passes: first every old `accent` use to `surface-3`, then every `primary` use to `accent`.

| Today | Becomes | What happens |
|---|---|---|
| `--background` `#F6F6F7` | `--page` | Renamed, value changed (oklch 0.9875 0 0) |
| `--foreground` | `--ink` | Renamed |
| `--card` | `--surface` | Renamed |
| `--card-foreground`, `--popover-foreground`, `--secondary-foreground`, `--accent-foreground` | `--ink` | Removed: text on a surface is `ink` |
| `--popover` | `--surface` | Removed; popovers use `surface` with `shadow-float` |
| `--secondary` | `--surface-3` | Removed |
| `--muted` | `--surface-2` (insets, disabled fields) | Removed |
| `--muted-foreground` (558 uses) | `--ink-2`; meta lines become `--ink-3` | Removed. The codemod writes `ink-2`; each section's rebuild moves meta to `ink-3` |
| `--faint` (3.45:1, failing) | `--ink-3` (5.50:1) | Removed |
| `--border` | `--line` | Renamed; still the default `border-color` |
| `--input` (1.56:1) | `--edge` (3.36:1) | Renamed, value changed: controls get a visible edge |
| `--primary` | `--accent` | Renamed; derived from the pair |
| `--primary-foreground` | `--on-accent` | Renamed; chosen automatically |
| `--ring` | `--ring` | Kept; derived from the pair |
| `--accent` (grey hover) | `--surface-3` | **Meaning moves**: the name now means the brand accent |
| `--selected` (blue) | `--accent-soft` | Removed: selection takes the accent |
| `--link` (blue) | `--accent-ink` | Removed: one accent, links included |
| `--success` / `--success-muted` | `--ok-ink` (text), `--ok` (dot) / `--ok-soft` | Renamed |
| `--warning` / `--warning-muted` | `--warn-ink`, `--warn` / `--warn-soft` | Renamed, hue moved to 85 |
| `--destructive` / `-foreground` / `-muted` | `--danger` (fill), `--danger-ink` (text) / `--on-danger` / `--danger-soft` | Renamed, hue moved to 18 |
| `--chart-1` to `--chart-5` (unused) | `--chart-1`, `--chart-2` | Three removed |
| `--radius` 4px; `--radius-sm` 2, `-md` 4, `-lg` 6 | `--radius-xs` 4, `-sm` 6, `-md` 8, `-lg` 12, `-xl` 16, `-full` | Values changed; `--radius` removed |
| `--shadow` | `--shadow-float`; adds `--shadow-raised`, `--shadow-modal` | Renamed; now defined for dark too |
| `--sidebar` (dark chrome `#1D1D1F`) | `--chrome` (light) | The dark sidebar ends |
| `--sidebar-foreground` / `-muted` | `--ink-2` / `--ink-3` | Removed |
| `--sidebar-primary` / `-primary-foreground` | `--accent-ink` / removed | Removed |
| `--sidebar-accent` / `-accent-foreground` | `--accent-soft` / `--on-accent-soft` | Removed |
| `--sidebar-border` | none (no border) | Removed |
| `--sidebar-ring` | `--ring` | Removed |
| `--ui-size` 13px | `text-body` 15px on `body` | Removed |
| `--h1-size`, `--section-size` (unread) | `text-title`, `text-lead` | Removed |
| `--page-pad` (unread) | Owned by the Page component (section 5) | Removed as a token; the value lives in Page |
| `--cell-y`, `--list-y`, `--divider-soft` (unread) | Owned by DataTable and Panel | Removed |
| `--sidebar-width` 240 / `-icon` 56 (inline in `app-shell.tsx`) | `--side-w` 264 / `--side-w-rail` 68 | Moved into `app.css` |
| `--editor-measure` 692px | `--measure` 704px | Renamed |
| `.teme-prose` 16px serif at 1.6, headings serif | 18px serif at 1.7; H2 24px sans, H3 18px sans | Values changed |
| Tailwind `text-xs` … `text-3xl`, `2xl` breakpoint | Six named sizes; `xl` = 1360px | Removed |
| The three looks in `app.css` (Dark chrome, Standard, Quiet); `appearance.look` and `appearance.high_contrast` (Preferences, I-198) | None | **Removed** (I-3404): the blocks, `applyLook`, the two preferences (a migration drops the stored values) and the Look and High contrast controls. See 3.1 |
| The high-contrast set in `app.css` | A `@media (prefers-contrast: more)` block | Replaced: re-values tokens, follows the computer's setting. See 3.1 |
| (none) | `--chrome`, `--surface-2/3/4`, `--ink-3`, `--accent-*`, `--on-accent-soft`, `--info-*`, `--ai-*`, `--scrim`, `--scroll-thumb*`, `--img-outline`, `--photo-scrim`, `--on-photo`, `--paper`, sizes, motion, layers | New |

### 3.1 The looks are removed; more contrast follows the system

Decided (I-3404). One look, as I-3395 asks.

| What | Rule |
|---|---|
| The three looks and the high-contrast setting | Removed: the look and contrast blocks in `app.css`, `applyLook`, the two preferences (a migration drops the stored values), the Look and High contrast controls on Preferences. Anyone who picked Quiet or high contrast gets the one look |
| Tests | `PreferencesLooksTest` and the parts of `PreferencesPageTest`, `PreferencesTest` and `DesignTokensTest` that cover the looks are deleted with them. Approval for deleting these tests is given in I-3404 |
| More contrast | One `@media (prefers-contrast: more)` block in `app.css` that **only re-values tokens**, so no component knows it is on |
| Forced colours | The admin is checked in `forced-colors: active` (Windows high contrast): see section 7 |

**The token mapping under more contrast:**

| Tokens | Under `prefers-contrast: more` |
|---|---|
| `--ink-2`, `--ink-3` | Darker in light, lighter in dark; the exact values are set when the block is written, and R12 holds them to the same pair thresholds as 2.4 |
| `--edge`, `--line` | At least 4.5:1 on the grounds they sit on (today 3:1 for `--edge`) |
| Soft grounds under text (`--accent-soft`, the status softs) | Not used under text: the text sits on `--surface` and the soft ground becomes an edge or a mark |
| Accent, status colours, `--ring` | Unchanged: they already meet 3:1 and 4.5:1 (2.2.2, 2.3) |

---

## 4. Components

Built on shadcn/ui "new-york" and Radix, which stay: **restyle, never rebuild by hand.** Each lives in `resources/js/components/ui/` (or `components/patterns/` for composites), takes its look only from tokens, and is the only way to draw its thing (the check enforces it, section 9).

**The reference design's "States" control** ("Show this screen as", `Teme.states`) is a design-review device for looking at a screen's empty, loading and failed states. It is never built into the product; the catalogue (9.3) shows the states instead.

States, once for all components unless a component says otherwise:

| State | Treatment |
|---|---|
| Rest | As specified |
| Hover | One step up the neutral ramp (`surface-3` → `surface-4`, transparent → `surface-3`), ink to `--ink`, 120ms |
| Focus | The focus ring (2.13), on `:focus-visible` only |
| Active (pressed) | `scale: 0.96` for buttons, 120ms |
| Disabled | Opacity 0.55, `cursor: not-allowed`, no hover, no press; keeps its place; a tooltip says why when the reason is not obvious |
| Loading | The control keeps its width; a spinner (16px) replaces its icon or sits before the label; `aria-busy="true"`; clicks ignored |
| Error | Danger edge (`--danger`) on edged controls, `aria-invalid`, message in the Field |
| Selected / pressed | `--accent-soft` ground, `--on-accent-soft` text (`aria-pressed`, `aria-selected`, `aria-current`) |

### 4.1 Button

**Built on** `ui/button.tsx` (Slot + cva), restyled. **Purpose:** an action.

| Variant | Look | Exactly when |
|---|---|---|
| `primary` | `--accent` fill, `--on-accent` text, hover `--accent-hover` | **One per view**: the page's main action (New post, Upload), a dialog's or sheet's confirm, the rail's Publish, a form's Save, a workspace's result (Insert 5 images). Never two |
| `neutral` (default) | `--surface-3` fill, `--ink`, hover `--surface-4` | Every other action that has a label: Replace, Add key, Save draft, Cancel, Keep it, Review, Try again |
| `ghost` | Transparent, `--ink-2`, hover `--surface-3` + `--ink` | Actions in bars and headers that should not compete: Customise, Clear filters, Columns, Export CSV, the pager, top-bar controls, Back to the article |
| `danger` | `--danger` fill, `--on-danger`, hover `--danger-hover` | **Only** the confirm button of an irreversible action, in a confirmation dialog, where it replaces the primary (Delete permanently) |
| `danger-quiet` | Transparent, `--danger-ink`, hover `--danger-soft` | Entry points to a destructive action that is reversible or confirmed later: Move to trash in the rail, Trash in the media detail panel |
| `link` (TextButton, 4.3) | — | See 4.3 |

| Size | Height | Padding | Text | When |
|---|---|---|---|---|
| `md` (default) | 36 | 0 16 | `text-body` 500 | Page header, forms, dialogs, filter row, empty states |
| `sm` | 32 | 0 12 | `text-small` 500 | Inside panels and rows, bulk bar, pager, rail action bar, dialog heads, cards' featured-image row |
| `xs` | 28 | 0 8 | `text-small` 500 | Only in dense frames: card tools, suggestion rows in a pane |

Anatomy: optional leading icon (20 in md, 16 in sm and xs), label, optional trailing chevron for a menu. Gap 8. Radius `md`. Whitespace nowrap. **Split button** (Publish ▾): a primary button and a 32px-wide primary chevron button 1px apart, outer corners rounded only; the chevron opens a menu of the other choices. **Toggle**: any variant with `aria-pressed`; pressed takes the selected treatment (Live preview, the rail toggle).

Do: a verb that says what happens ("Publish", "Insert image"). Don't: two primaries in a view; a primary on a destructive action; an icon-only button with a label hidden in `title`.

### 4.2 Icon button

**Built on** `Button` with `size="icon-md|icon-sm|icon-xs"`: square 36, 32 or 28, ghost by default, icon 20 (16 in xs). **Must** have an `aria-label` and a Tooltip (4.16) with the same words plus a shortcut if one exists. Pressed state for toggles. A dot badge (8px `--accent` with a 2px `--page` ring, top right) marks unseen items. On phones the hit area grows to 44px (`max-sm:min-h-11 min-w-11`, kept from today).

### 4.3 Link and TextButton

A **link** goes somewhere (`<Link>` from Inertia, or `<a>`); a **TextButton** acts in place and looks like a link. Both: `--accent-ink`, 500, underline transparent at rest and `currentColor` on hover (offset 3px), focus ring. In running text, links are always underlined. Row actions (4.26), "Open the link report", "12 revisions" are TextButtons or links. **Height at least 24px** when standing alone (row actions are 24px tall with 4px padding). Danger TextButton: `--danger-ink`. Don't draw a link with `Button variant="link"`, an underlined `<button>`, or `<Link className="underline">`: one component (`ui/text-button.tsx`).

### 4.4 Input

**Built on** `ui/input.tsx`. 36 tall (32 in the rail's summary rows and the alt-text row), `--surface` ground, 1px `--edge`, radius `md`, padding 0 12, `text-body`, placeholder `--ink-3`. Focus: 2px ring, offset 0, edge transparent. Error: `--danger` edge. Disabled: `--surface-2` ground, `--ink-3` text. Read-only: `--surface-2`, no edge, text selectable. **Search** input: magnifier 18px at 10px from the start, padding-start 36px, `type="search"`, a visible or `sr-only` label. Width comes from its container (fields fill their column); in filter rows the search is 280px (full width on phones). Number fields with a unit: 120px plus the unit as text after.

### 4.5 Textarea

**New** `ui/textarea.tsx` (there is none today; 21 raw ones exist). Input's look; padding 8 12; line-height 1.5; `resize: vertical`; minimum 3 rows. An **auto-growing** variant (no edge, no ground, grows with content) exists only for the editor's title and excerpt.

### 4.6 Select

**Built on** Radix Select (`ui/select.tsx`, `ui/select-field.tsx`). The trigger looks like an input with a 16px chevron at the end (padding-end 32). The list is a Menu (4.16) popover. Use when there are 2 to about 15 fixed choices. More than 15, or choices people type to find: Combobox. Raw `<select>` is forbidden.

### 4.7 Combobox

**Built on** Popover plus a filterable listbox (`cmdk`, approved: I-3404). A trigger like Select; opening it shows a search field (focused) above a list; typing filters; ↑↓ move, Enter chooses, Esc closes. Multi-select shows chosen items first, ticked. Used for: categories and tags with many terms, author, parent page, internal-link search, the Tools launcher's search, the command palette (same listbox inside a dialog).

### 4.8 Checkbox

**Built on** Radix Checkbox (`ui/checkbox.tsx`). Box 18px, radius `xs` (4), 1.5px `--edge` on `--surface`; checked: `--accent` fill, `--on-accent` tick (stroke 2.5); indeterminate: a dash. Label beside it, gap 8, the whole label clicks; target at least 24px tall. A checkbox **waits for Save**; something that acts at once is a Switch.

### 4.9 Radio group

**Built on** Radix RadioGroup (new dependency, approved: I-3404). Circle 18px, `--edge`; checked: `--accent` fill with an 8px `--on-accent` dot in the centre. Vertical list, gap 8; horizontal only for up to three short choices. Arrow keys move and choose; Tab enters and leaves the group. A choice with a description puts it under the label in `text-small` `--ink-3`.

### 4.10 Switch

**Built on** Radix Switch (new dependency, approved: I-3404). Track 36×20, fully rounded; off: `--surface-4` with a 1px inset `--edge`; on: `--accent`. Thumb 14px, `--surface` (on: `--on-accent`), moves 16px in 180ms. Label before it ("Show the author's name") or after it, never both. **A switch acts at once** and the result is announced (toast or the row's own words change); it never waits for Save. **The exception is a settings page** (4.42): the approved v10 design draws every on/off setting there as a switch, and those switches wait for the page's one save bar like the page's other fields.

### 4.11 Field

**New** `ui/field.tsx`: the only way to put a label, a control, help and an error together. **One way:**

1. **Label** above the control: `text-small` 500 `--ink-2`, gap 4 to the control.
2. **Control.**
3. **Help** under the control: `text-caption` `--ink-3`, line-height 1.5, gap 4.
4. **Error** under the help: `text-small` `--danger-ink` with an octagon icon 16px, gap 4. Tied by `aria-describedby` (help and error), `aria-invalid` on the control.

Required fields add "Required" in `--ink-3` after the label; optional is the default and unmarked. Checkboxes and switches put the label beside the control (4.8, 4.10); the help still goes under. **Label to the left** exists only in the editor rail's summary rows (a 96px key column in `text-small` `--ink-3`, value beside), nowhere else.

### 4.12 Form layout, and the one place Save lives

**New** `patterns/form.tsx` (`Form`, `FormPanel`, `FormFooter`).

- The form's column is 760px (`--form-max`), left-aligned in the page container.
- Each group is a **FormPanel**: `--surface`, radius `lg`, `shadow-raised`, padding 24, gap 20 between fields; title in `text-lead` 600; an optional one-line description in `text-body` `--ink-2`. Panels stack with 16px between.
- Two fields share a row only when they are a pair (width and height, across and down): two equal columns, gap 16.
- **Save lives in the footer of whatever holds the form, and only there.** A page's form: the **FormFooter**, a bar under the last panel, Save (primary) first and left-aligned with the fields, then Cancel or Reset (ghost), then "Unsaved changes" in `text-small` `--ink-3` while dirty; when the form is taller than the window, the footer sticks to the bottom of the view (`--page` ground, `shadow-raised` above it) so Save is always in reach. A dialog's or sheet's form: its footer (4.19), actions at the end, Cancel before the primary. A panel that is its own small form (Home's Quick draft): its footer inside the panel.
- **Never** a Save beside a field, in the page header, or in a panel's header. One form per page, so one Save per page.
- The editor is the exception that proves the rule: it saves itself, and the rail's pinned action bar (Save draft, Publish) is its footer.
- Leaving with unsaved changes asks first (the existing leave dialog). **One save bar and one leave guard for every form** (the reference design's `Teme.saveBar` with its guard, `Teme.leaveGuard`; the joining pass folded four versions into it): the bar appears on the first change and says what is unsaved, and the guard names it ("Leave without saving {what}?").

### 4.13 Status pill

**New** `ui/status-pill.tsx`, one definition for lists, the editor, site status and plugins. Height 24, fully rounded, padding 0 10, gap 6, `text-small` 500, nowrap; ground and ink from the tone (2.3). Before the word: a 7px dot (`ok`, `neutral`, `info`), a 14px triangle (`warn`), a 14px octagon (`danger`). A `flat` variant (no mark) is a removable tag in the rail (with a 20px round × button). **Always a word.**

### 4.14 Chips and the "+N" overflow

Two kinds, never mixed.

| Kind | Look | Use |
|---|---|---|
| **Filter chip** | 36 tall, fully rounded, 1px `--edge`, `--surface`, padding 0 12, `text-small` 500 `--ink-2`, optional 16px icon and a count; pressed: `--accent-soft`, `--on-accent-soft`, edge transparent | A toggle filter (Missing alt text, Unused, Imported) |
| **Term chip** | 24 tall, radius `sm`, `--surface-3`, padding 0 8, `text-small` `--ink-2`, at most 132px with an ellipsis and the full name in a tooltip | Categories and tags in a list cell |

**In a list cell** (I-3399): show the first two term chips if both fit the column's width, else the first one; then a **"+N" chip** (transparent, 1px inset `--edge`, 500) that is a button opening a Popover listing the rest, with an `aria-label` naming them ("3 more tags: Ferries, Port, Weather"). One line, never wrapping. An empty cell is an em dash in `--ink-3` with an `aria-label` ("No tags").

### 4.15 Badge and count

| Kind | Look | Use |
|---|---|---|
| **Badge** | Min 22×22, fully rounded, padding 0 7, `--accent-soft`, `--on-accent-soft`, `text-caption` 600, tabular | A count that needs action: Comments 7 in the sidebar. With `sr-only` words ("7 waiting") |
| **Count** | Plain number in `--ink-3`, tabular, after the label with a space | A count for information: tabs (All 24), panel titles, the Columns button |
| **Dot badge** | 8px `--accent`, 2px `--page` ring | New items behind an icon (folded sidebar, an icon button) |
| **Top-bar number** | The count as `text-small` 600 after the icon inside a ghost sm button | Jobs running, things to look at, new notifications |

### 4.16 Tooltip

**Built on** Radix Tooltip (`ui/tooltip.tsx`, installed, used today only in the folded sidebar). `--ink` ground, `--page` text, `text-small` 500, padding 4 8, radius `sm`, `shadow-float`, nowrap. Shows after 400ms of pointing **and at once on keyboard focus**; Esc hides it; it hides when the pointer leaves. Below the trigger (right of it in the folded sidebar). On every icon-only button, every truncated title, every mark. It never holds the only copy of information or anything clickable. The `title` attribute is not a tooltip.

### 4.17 Menu and the "…" menu

**Built on** Radix DropdownMenu (`ui/dropdown-menu.tsx`). Popover of 220 to 340px, `--surface`, radius `lg`, `shadow-float`, padding 4, appearing in 180ms. **Item**: 36 tall, radius `md`, padding 0 12, gap 12, 20px icon in `--ink-2`, label `text-body`; a shortcut in `kbd` or an end note in `text-small` `--ink-3` at the end; hover and keyboard highlight `--surface-3`. **Group label**: `text-caption` 600 uppercase `--ink-3`. **Separator**: 1px `--line`, 4px 8px margins. **Danger item**: `--danger-ink` text and icon, always last, after a separator. **Plugin item**: the plugin mark (4.40) after the label. Checkable items show a 16px tick at the end. Disabled items keep their place.

**The "…" menu** is an icon button (ghost, sm, `MoreHorizontal`) with `aria-label` "More actions for {name}". Its order: the state action, other core actions, separator, plugin actions in their declared order, separator, Trash. Keyboard: ↑↓, Home, End, type-ahead, Enter; Esc and Tab close and focus returns to the trigger.

### 4.18 Popover

**Built on** Radix Popover (`ui/popover.tsx`). `--surface`, radius `lg`, `shadow-float`, padding 16 (4 when it holds a list), width set by its content up to 400 (the site status panel 400, the column chooser 380, the Tools launcher 448). Placed below its trigger, flipped when there is no room, 6px away. Focus moves into it; Esc closes it and returns focus. Used for: site status, jobs, the link editor, the column chooser, "+N" lists, the date filter, the address editor.

### 4.19 Dialog

**Built on** Radix Dialog (`ui/dialog.tsx`), scrim `--scrim` (not `bg-black/80`).

| Size | Width | Use |
|---|---|---|
| `sm` | 480 | Confirmations |
| `md` | 640 | One task with a few fields; the command palette (max height 560) |
| `lg` | 860 | Reading: long explanations, "What's new" |
| `xl` (fixed) | `min(1120px, 100vw − 80px)` × `min(740px, 100vh − 48px)` | **The large fixed dialog**: the media picker and every "Open larger". Its size does not change with its content; its body scrolls inside |

Anatomy: `--surface`, radius `xl`, `shadow-modal`, enters in 240ms. **Head**: padding 16 16 12 24, title `text-lead` 600, optional plugin mark, optional head actions (sm ghost), close icon button at the end. **Body**: padding 0 24 24, scrolls inside (ScrollArea). **Footer**: padding 12 16 12 24, `--surface` (a tray may sit at its start), actions at the end, Cancel (neutral) then the primary (or danger).

Focus: on open, the first field, or in a confirmation the safe button ("Keep it"); trapped inside; on close back to the opener. Esc and a click on the scrim close it, except while it holds unsaved input, when it asks first. **At most one dialog over another**, and only a confirmation (`sm`). Below 640px a dialog is `100vw − 32px` wide; the `xl` dialog becomes full screen.

### 4.20 Sheet

**Built on** `ui/sheet.tsx` (Radix Dialog). From the end, 480 wide (full width below 640), `--surface`, `shadow-modal`, inner corners radius `xl`, head and footer like a dialog. **Allowed for:** Quick edit; filters on a phone; the editor's rail below 1024px; the media detail panel below 1024px; the sidebar below 1024px (from the start, 264 wide). **Never** over a dialog, never two at once. A dialog may open from a sheet (the picker from Quick edit); it closes back to the sheet.

### 4.21 Toast

**Built on** sonner (installed) through `flash-toast.tsx`, restyled. Bottom centre, 24px from the bottom; `--ink` ground, `--page` text, radius `lg`, `shadow-float`, min height 44, padding 8 8 8 16, `text-body` 500; enters with an 8px rise in 240ms. At most three; the oldest goes. **At most one action** (a ghost sm button in the inverse colours) and a Dismiss icon button. Stays 6 seconds, 10 with an action (today's values). `role="status"`; a failure is `role="alert"` and leads with the octagon icon.

**What it says:** the result, in the past tense, in two or three words, then the action. The decided pairs: **"Published" · Undo**, **"Moved to Trash" · Undo**, "Reverted to draft" · Undo, "Approved" · Undo, "Restored" · View. Several items: "3 posts moved to Trash" · Undo. A toast never holds the only way to do something.

### 4.22 Notice (banner and notice, one way)

**New** `ui/notice.tsx`, replacing the 17 hand-made `role="note"` boxes, the three tinted strips and the `Alert` component. Tones `info`, `ok`, `warn`, `danger`. Anatomy: the tone's soft ground, radius `md`, padding 16 20, gap 12; the tone's icon (20px, in its ink); optional title in `text-body` 600; text in `text-body` `--ink`; at most one action (neutral sm button or a link) after the text; optional dismiss icon button at the end.

| Where | What it is for |
|---|---|
| In the page flow, under the page header or at the top of a panel | Something about this screen to know before acting ("Keys are stored encrypted…" stays a help line; a notice is for "AI is unavailable until the new month") |
| The page banner (one slot, under the top bar, the page container's width) | What blocks work site-wide (the existing `StatusBanner`, restyled as a Notice) |
| Inside the editor (a card): callout | A content block, styled the same way but part of the article |

A result goes in a toast, a background result in the notifications inbox, a field problem in the Field. Nothing prints at the top of every screen.

### 4.23 Empty state

**Built on** `list/empty-state.tsx`, restyled. Centred, padding 48 24, gap 12: icon 32 `--ink-3`, title `text-lead` 600 `--ink`, one line `text-body` `--ink-2`, one action. Two kinds: **Nothing yet** ("No posts yet", the primary action "New post"); **Nothing matches** ("No posts match", "Try fewer filters.", neutral "Clear filters"). Inside the table frame or panel it replaces, never a bare page. A plugin's or a panel's empty state is one line in `--ink-3` inside the panel.

### 4.24 Loading and skeleton

| Situation | Show |
|---|---|
| A panel or list loading its data | **Skeleton**: rows of 14px bars, radius `sm`, `--surface-3` with a `--surface-2` shimmer (1.3s, stops under reduced motion), varied widths (60 to 90%), shaped like what will come; the region gets `aria-busy="true"` and a label ("Loading top posts") |
| A button's action running | The button's loading state |
| A page visit | Inertia's top progress bar, 2px `--accent` |
| A background job | The jobs indicator in the top bar (a turning icon and a count) |
| Saving in the editor | The save state: "Saving…" with a turning 16px icon, then "Saved just now" with a check in `--ok` |

Skeletons appear only after 300ms, so fast loads do not flash.

### 4.25 Error state

| Scope | Look |
|---|---|
| A field | The Field's error (4.11) |
| A panel or a plugin's area | Padding 8 20 20, the octagon icon in `--danger-ink`, one sentence that names what failed and says the rest is fine ("Couldn't load submissions. The Forms plugin didn't answer in time; the rest of Home is unaffected."), a neutral sm "Try again". `role="alert"`. Every plugin area has its own error boundary that draws this |
| A whole page | The error page: the page header pattern with a title, one line, and a way back |
| An action | A failure toast, plus the Field or the Notice where the problem is |

### 4.26 Table (the content lists)

**Built on** `list/data-table.tsx`, restyled; no table engine (I-3404: the existing component is enough; TanStack Table is not needed now). Covers I-3398, I-3399 and the row heights of I-3404 in full.

**Frame.** A Panel (`--surface`, radius `lg`, `shadow-raised`) with `overflow: hidden`; inside, a horizontal ScrollArea holding the table, then the pager. Above the frame, in order: tabs (4.27), the filter row, the bulk bar when rows are selected.

**Header.** 44 tall, `--surface`, sticky to the top of the view, `text-small` 500 `--ink-3`, nowrap. A sortable heading is a button: label plus a 14px icon (up-down at rest, an arrow when sorted), and `aria-sort` on the `th`. A plugin's column shows its plug mark after the label.

**Rows** (I-3404). A row is **72px where the title is one line and 92px where it wraps to two** (`--row-h` is 92): 12px padding, the title (1 or 2 lines of 20px), 4px, the row-action line (24px), 12px. The reference design's stylesheet sets no fixed height for these rows: they are their padding plus their content, so the two figures are that sum. I-3399's "one even height" is superseded. No borders between rows. Hover: every cell `--surface-2`. Selected: every cell `--accent-soft`. Cells other than the title are top-aligned with the title's first line (padding-top 22px).

**Columns, from the start:**

| Column | Width | Pinned | Content |
|---|---|---|---|
| Tick box | 48 (padding-start 16) | Left, at 0 | Checkbox, `aria-label` "Select {title}"; the header's box selects the page |
| Title | Minimum 320 (240 below 640px), takes all spare room | Left, at 48 | Thumbnail 48×32 (radius `md`, `--img-outline`; an image icon on `--surface-3` when none) beside: the title (`text-body` 600 `--ink`, line-height 1.35, **wraps to two lines, then an ellipsis**, `text-wrap: pretty`, the full title in a tooltip), and under it the row-action line |
| Other columns | Declared width each (author 128, categories 146, tags 168, status 120, date 116, numbers 100 to 116) | No | **Never wrap.** Numbers right-aligned, tabular. Dates: the date, and a fixed second line in `text-small` `--ink-3` ("Published", "Last edited") |
| "…" | 56 | Right, at 0 | The row's "…" menu (4.17) |

**Row actions** (I-3398, the hybrid): under the title, always visible, one line of TextButtons in `text-small` 500, 24px tall, gap 4: **Edit, Quick edit, View** (Preview when not published). Clicking the title also edits. Everything else is in the **"…" menu**: the one state action that applies (Publish on an unpublished item; Approve on an item in review, for someone who may decide; Revert to draft on a published one), then plugin row actions, then **Trash** last in red (at most one of Publish, Approve and Revert to draft is shown). In the Trash view the line holds **Restore**, and the menu holds **Delete permanently** (which confirms in a `sm` dialog with a `danger` button). Publishing from the menu answers "Published" · Undo; trashing answers "Moved to Trash" · Undo. On touch and by keyboard nothing changes: there is no hover state to reveal.

**Scrolling sideways.** Extra columns never squeeze the title: the table's minimum width is the sum of its columns, and the ScrollArea scrolls. The tick box and title stay pinned at the left (a soft shadow on the title's trailing edge once scrolled), the "…" column stays pinned at the right (a soft shadow on its leading edge while more is underneath). **The "…" column is a real column at the end of the row, not an overlay**, so scrolling to the end always brings the last data column (Date) fully into view beside it (the fix I-3398 asks for). When the table is wider than the frame, the pager's line says "More columns: scroll sideways".

**Bulk bar.** Appears above the frame when a row is ticked: `--accent-soft`, `--on-accent-soft`, radius `lg`, min height 52, padding 8 8 8 20. "{n} posts selected" in 600, then up to three actions as neutral sm buttons on `--surface` (Publish, Edit categories, Move to trash), then "More ▾", then at the end "Clear selection" (ghost). When the whole page is ticked: "Select all 240 posts" (TextButton). It is a labelled region; ticking announces the count.

**Column chooser.** "Columns {n}" (ghost, sliders icon) at the end of the filter row opens a 380px Popover: "Columns, in order" (caption); Title fixed (ticked, disabled, "always shown"); each column a checkbox with its name, its plug mark, and Move earlier / Move later icon buttons (the keyboard and single-pointer way to reorder; dragging may be added). A line under it says either "The title takes the room that is left…" or "The table is now wider than the screen. It scrolls sideways, with the tick box and title kept in view." Choices are the person's own (preferences).

**Sorting.** One column at a time; its heading toggles direction; the first click uses the column's default (text ascending, dates and numbers descending). Only columns backed by an index sort (a plugin's column declares its index to be sortable).

**Filter row.** Search (280) and up to four filters (Select or Combobox, each with an `sr-only` label and an "All …" first choice): category, author, date, and one "From plugins" filter holding every plugin's filters labelled with their plugin ("Orphaned (a link plugin)"). A choice applies at once. "Clear filters" (ghost) appears once any filter is on. Then space, then Columns. Gap 8, wraps on narrow screens; below 640px: the search, and a "Filters ({n})" button opening a sheet.

**Pager.** Inside the frame, padding 16 20: "1 to 10 of 240" (`text-small` `--ink-3`) at the start; at the end, Previous (icon), page numbers (sm ghost; the current one selected; an ellipsis beyond seven), Next (icon).

**Empty, loading, error** replace the table inside the frame (4.23 to 4.25).

**Keyboard.** Tab order per row: tick box, title, Edit, Quick edit, View, "…". Space ticks. Shift+click ticks a range.

### 4.27 Tabs and in-page navigation

One look, two meanings. Look: a row of 36px items, radius `md`, padding 0 12, gap 4, `text-body` 500 `--ink-2`; hover `--surface-3`; **selected `--surface-3` with `--ink`**; a count after the label (4.15). One row, never wrapping: **the first five show, the rest go under "More ▾"** (when the selected one is in More, More shows its name). 16px below the row.

| Component | Built on | Meaning | Keyboard |
|---|---|---|---|
| **Tabs** | Radix Tabs (new dependency, approved: I-3404) | Panels of one screen that does not change address: the picker's Library / Upload / plugin sources, a dialog's sections | ←→ move and show; Tab leaves |
| **InPageNav** | Links, `aria-current="page"`, inside `<nav aria-label>` | Views that change the address: Posts' status views (All 24, Published, Drafts…), a plugin area's pages (Overview, Link report…) | Tab through; Enter follows |

### 4.28 Card and panel

**Panel** (`ui/panel.tsx`, restyled): `--surface`, radius `lg`, `shadow-raised`, no border. **Head** (optional): min height 52, padding 12 16 0 20, title `text-body` 600 (a count after it in `--ink-3`), the plugin mark, then actions at the end. **Body**: padding 8 20 20. **List body** (rows): padding 4 8 12; each row padding 8 12, radius `md`, hover `--surface-2`; a title (`text-body` 500, one line, ellipsis) over meta (`text-small` `--ink-3`), optional thumbnail 48×32 at the start and one sm action at the end.

**Clickable card** (the Tools hub): a Panel that is a button or link, padding 20, gap 16: an icon tile (4.48), the name (600), one line (`text-small` `--ink-2`), the plugin mark if any; hover `--surface-2`.

**Figure tile**: a Panel, padding 16 20, gap 4: the value (`text-title` 600, tabular), the label (`text-small` `--ink-2`) after an 8px dot of its state's colour; links to the matching view.

### 4.29 Dashboard panel

A Panel in Home's 12-column grid (gap 16).

| Size | Columns at ≥1024 | 768–1023 | <768 |
|---|---|---|---|
| `s` | 4 | 6 | 12 |
| `m` | 6 | 6 | 12 |
| `l` | 8 | 12 | 12 |
| `full` | 12 | 12 | 12 |

Head: title, count, and for a plugin's panel the **plugin mark** (plug icon and the plugin's name, `text-caption` 500 `--ink-3`). Core draws the frame, the loading skeleton (3 to 5 bars), the failed state (4.25, naming the plugin) and the empty line; the plugin supplies data or its own body inside the frame. **Customise mode**: a bar at the top (`--accent-soft`: "Customising your Home. Move, resize or hide panels. Only you see this." then Add a panel, Reset (ghost), Done (primary)); each panel gets a 2px `--accent-soft-2` outline and, in its head, Move earlier and Move later (icon buttons), a segmented size control (S M L F) and Hide; the plugin mark hides while customising to make room. Core panels are in "Your work"; plugins' in "From plugins", a section with its own heading and a one-line hint.

### 4.30 Page header

**New** `patterns/page-header.tsx` (replacing `list/page-header.tsx` and `heading.tsx` as page titles). Flex, items aligned to the end, gap 16, wraps; margin 8 0 24. Start: the `h1` (`text-title` 600, −0.01em; a plugin's page adds its plugin mark after it) and one line of description (`text-body` `--ink-2`, 4px below). End: actions, at most one primary, the primary first in reading order (Home: "+ Post" primary, then "+ Page", "Upload media", ghost "Customise"). One `h1` per page. No breadcrumb here (4.31).

### 4.31 Breadcrumb

In the top bar, at its start (`patterns/crumbs.tsx`, built on `ui/breadcrumb.tsx`). Crumbs are 32px ghost buttons (`text-body` 500 `--ink-2`, padding 0 8, radius `sm`); "/" separators in `--ink-3`; the current page in `text-body` 600 `--ink`, at most 420px with an ellipsis (at least 160 in the editor). A crumb may be a **switcher** (an up-down icon after its name) opening a menu of its siblings: "Content ⇅" lists Posts, Pages, Media…; "Posts ⇅" lists recent posts; "Settings ⇅" lists the settings pages. Never more than section / page / item. Inside `<nav aria-label="Where you are">`.

### 4.32 The sidebar

**Built on** `ui/sidebar.tsx`, restyled. 264 wide (`--side-w`), `--chrome`, no border, its own ScrollArea.

| Part | Spec |
|---|---|
| Brand | Min height 68, padding 16 16 12, gap 12: the site's mark (36 square, radius `md`, `--accent`, its initial in Plex Serif 600 18px `--on-accent`), the site's name (`text-body` 600, ellipsis) over "Teme 0.6 · production" (`text-caption` `--ink-3`) |
| Search | A 36px button, `--surface-3`, margin 0 12 8: magnifier, "Search or jump to" (`--ink-3`), `kbd` Ctrl K. Opens the command palette |
| Group | Heading 28 tall: `text-caption` 600 uppercase +0.05em `--ink-3` with a chevron; folds its entries. 16px above each group |
| Entry | Min 38 tall, padding 0 12, radius `md`, gap 12: icon 20, label `text-body` 500 `--ink-2` (ellipsis), then a plug mark (14px icon, `--ink-3`; its plugin named in an `sr-only` text), a Badge, a hub chevron. Hover `--surface-3` + `--ink`; current: `--accent-soft`, `--on-accent-soft`, icon `--accent-ink`, `aria-current="page"` |
| Sub-entry | 32 tall, `text-small` 400, indented 32, gap 2 between. Shown under the current entry, or under an opened hub (Tools, Settings) |
| Foot | View site, Customise menu, Fold the menu, as entries, padding 8 12 |
| Folded (rail) | 68 wide: icons only, centred; group headings become a 20×2 line in `--surface-4`; badges become a dot badge; sub-entries hidden (a hub opens its page); every entry has a tooltip to its right |

**"Soon" is a real tag in the product (I-3408, 2026-11-13; this replaces the earlier "reference-design-only").** A planned feature may show where it will live (a menu entry, a hub card, a tab, a field) with a quiet "Soon" tag; it cannot be opened or used. It is marked in one declared way in the code (a `soon` flag on the entry in the registry that draws it), and nothing is tagged without a roadmap entry. The tag's look and rules are in 4.46. The disabled "Later" tags on Crop, Rotate and Flip stay in the reference design only. Hubs take growth before the top level; each person may hide entries (Customise menu: "Only you see these changes. The command palette still finds hidden entries."). Below 1024px the sidebar is a start-side sheet opened by a menu button at the start of the top bar. In the editor the sidebar starts folded (I-3400), and the person's choice there is remembered.

### 4.33 The top bar and its status area

60 tall (`--top-h`), `--page`, no border, padding 0 24, gap 12: crumbs at the start; at the end, gap 4:

| Control | Look | Opens |
|---|---|---|
| Jobs | Ghost sm: a refresh icon (16) turning while jobs run, then their number | A popover: each job's name (`text-small`), a 6px progress bar (`--surface-4` track, `--accent` fill), "64% done" (caption); then "Open Tools › Jobs" |
| Site status | Ghost sm: a check-circle when all is fine; an alert icon and the number when something needs a look. `aria-label` "Site status: 2 need a look" | The site status panel (below) |
| Notifications | Ghost sm: a bell and the number of new ones | The inbox popover (built) |
| Help | Icon button | Help |
| Account | 32px circle, `--accent-soft-2`, initials in `text-caption` 600 `--on-accent-soft` | Account menu: name and role, Your account, Preferences, Sign out |

**The site status panel** (I-3397), a 400px popover: "Site status" (600) with "2 to look at" or "All is well" (`text-small` `--ink-3`); then one row per entry, padding 8, radius `md`, a two-column grid: the label (500) with its plugin mark | its Status pill (Fine / Needs a look / Problem); its one line (`text-small` `--ink-2`) | at most one action (neutral sm). A footnote line in `text-caption`. Rows come from core and plugins as data (6.2). Every action is also a command.

No admin toolbar exists, and nothing prints at the top of every screen.

### 4.34 Command palette

**Built on** Dialog `md` (640, max height 560), opened by Ctrl K / ⌘K or the sidebar's search, and the existing `palette/command-palette.tsx`. Input row 60 tall, padding 0 20: magnifier, an input in `text-lead` with no edge (it is a combobox; the dialog is its frame), placeholder "Search posts, screens and commands". List (listbox) in groups with caption labels: Go to, Actions, Tools, From plugins, then matching Posts (four without a query); items are menu items with an end note (a post's status) and plug marks. Footer (`--surface-2`, `text-caption` `--ink-3`): ↑ ↓ move, Enter open, Esc close. Nothing matches: "Nothing matches."

### 4.35 Media tile

`ui/media-tile.tsx`. Grid: `repeat(auto-fill, minmax(var(--tile), 1fr))`, gap 16; tile width S 136, M 172 (default), L 228, chosen by a segmented control; 160 in the picker. A tile: padding 8, radius `lg`, gap 8; hover `--surface-3`; selected `--accent-soft` with a 2px `--accent` inner outline on the picture; open in the detail panel `--surface-3`. Picture square, radius `md`, `--img-outline`. Name `text-small` 500, one line. Facts `text-caption` `--ink-3`: "Used 3×", "Unused" or "Imported · Unsplash", then "No alt" (`--warn-ink`, 14px triangle) when an image has no alt text. A file that is not an image shows its type ("PDF") at the top end over the picture (`--photo-scrim`, `--on-photo`, `text-caption` 600). **Tick**: a 24px square at the top start, radius `sm`, white with an inset `--edge`, shown on hover, focus, when selected and while anything is selected; **always shown on touch screens**; ticked: `--accent` with an `--on-accent` tick. The grid is a listbox (`aria-multiselectable` in the library): arrows move, Space ticks, Enter opens the details.

**List view** (drawn into the reference design after this document was written; issue 450). A **Grid / List** segmented control (4.40) in the filter row switches the library between the grid and a list; the choice is remembered (browser storage, a per-viewer convenience). The list is the shared table (4.26): tick boxes and the bulk bar; the file with a small thumbnail (the name keeps one line); Type; Alt text with the "Missing" flag; Credit; Used in (a count that opens the list of posts); Uploaded. The same filters apply, and the detail panel (4.36) opens from either view. The picker (4.38) stays a grid.

### 4.36 Media detail panel

360 wide (`--detail-w`), sticky beside the grid, `--surface`, radius `lg`, `shadow-raised`, its own scroll (height up to the view's). Head: "3 of 24" (`text-small` `--ink-3`), Previous and Next (icon buttons, also ← →), Close. Body padding 0 20 20, gap 16: the picture (3:2), the file name (600, breaks anywhere), facts as a two-column list (`text-small`: Type, Size, Uploaded, Source); fields: **Alt text** (with "missing" in `--warn-ink` and a triangle when empty; help: "Used wherever an article does not give its own." or a plugin's mark "Alt text written by Smart Image Matcher"), Caption, Credit; "Used in": links to each post with "Featured image" or "In the body" under it; actions: Edit image (primary sm), Download original (neutral sm), Trash (danger-quiet sm). Fields save as you leave them (a toast confirms). Below 1024px, a sheet.

### 4.37 Focal-point tool

The Edit image page (detail-with-side-panel pattern). The **stage**: the picture at 3:2, radius `lg`, `--img-outline`, crosshair cursor, a rule-of-thirds overlay (`--on-photo` at 35%), and the **marker**: a 36px circle with a 2px `--on-photo` ring, a dark outer ring (`--photo-scrim`), a 6px centre dot. Click to place; the stage is focusable (`role="application"`, labelled): arrow keys move 1%, Shift+arrows 10%. Side panel: "Focal point" with Across (%) and Down (%) number fields; "How it will be cropped": live previews at 16:9 Lead story, 1:1 Lists, 4:5 Portrait, 1.91:1 Social card, moving in 180ms; Alt text. Header actions: Previous / Next image, Back to media, Save (primary). Crop, rotate and flip need server work and are a separate decision (I-3400): they are **not drawn** until built (the reference design's disabled "Later" buttons are not shipped).

### 4.38 The picker

The `xl` fixed dialog, one picker for every image (I-3400). **Head**: the title by caller ("Insert an image", "Replace the image", "Choose the featured image", "Add images to the gallery"), then Tabs: Library, Upload, and each plugin source with its plug mark (an image plugin); close. **Body**: a two-column grid, main | side 300 (`--surface-2`, padding 20, gap 16). Main (padding 8 24 24): the media filter row (search, date, filter chips), a hint line ("Only images can be used here. Double-click to insert at once." / "…they go in the order you choose them."), the grid (tiles 160). Side: the focused picture's preview, name, size, Alt text, Caption, Credit (edits the library's record). Upload tab: a drop zone (`--surface-2`, 2px inset `--surface-4`, min height 320, centred: icon, "Drop pictures here", formats and size limit, "Choose files" primary); dragging over: `--accent-soft` and a 2px inset `--accent`; an upload list with a 6px progress bar each; finished uploads are chosen. **Footer**: the tray "Selected ({n})" with 48px thumbnails, each with a 20px round remove button (`--ink` ground, `--page` icon), then Cancel and the primary, labelled by caller ("Insert image", "Replace", "Set featured image", "Add 3 images"), disabled until something is chosen. **No add-by-URL.** Below 768px: full screen, the side panel under the grid.

### 4.39 The editor's parts

**Writing surface.** The writing column scrolls on its own over `--page`. The sheet: max width `--measure` + 160 (864), centred, padding 24 80 120 (64 inline below 1360px and in live preview). In order: Title (auto-growing textarea, Plex Serif `text-display` 600, −0.015em, balance, placeholder "Title"); Excerpt (Plex Sans `text-lead` 400, line-height 1.55, `--ink-2`, placeholder "Add an excerpt: one or two sentences that sell the story"); both have real labels ("Title", "Excerpt") shown only while focused (`text-caption` 600 uppercase `--ink-3`), and show focus as a 2px `--accent` bar 14px before their start. Featured image row (`--surface-2`, radius `lg`, padding 12, gap 16: thumbnail 96×64, name 500 over "Featured image · 2400 × 1600 · 412 KB · alt text set" in `text-small` `--ink-3`, then Replace (neutral sm), Edit, Remove (ghost sm)); empty: one full-width button "Add a featured image" on `--surface-2`. The toolbar. The body: Plex Serif `text-lead` (18px) at line-height 1.7, measure 704; H2 sans `text-title` 600 line-height 1.3; H3 sans `text-lead` 600; quote with a 3px `--accent` start border, italic, `--ink-2`; code in Plex Mono `text-small` on `--surface-2`, radius `md`; links `--accent-ink` underlined 1px; divider 96×2 `--surface-4` centred. Empty line placeholder: "Write, or type / for a block". Under the body (32px above, 16px padding): Words, Characters, Reading time (`text-small` `--ink-3`, values `--ink-2` 500, tabular). Figures may be Column, Wide (−40px each side) or Full (−80px).

**Toolbar.** Sticky at the top of the writing column (`--z-toolbar`), one row that never wraps, gap 2, padding 4 8, radius `lg`, `--page` at 86% with an 8px backdrop blur. Buttons 32, ghost, `--ink-2`: block type ("Paragraph ▾", 132 wide, `text-small`); separator (1×20 `--line`); Bold, Italic, Underline, Strikethrough (letter glyphs); separator; bulleted list, numbered list, quote, link; separator; "+ Insert"; then, at the end, Undo, Redo. **Core formats only.** When short of room, controls fold into a More menu from the end (Bold, Italic, Link and Insert never fold; EditorControlsTest). The toolbar is at **full strength at all times**: the reference design's resting opacity of 0.72 drops its labels to 3.83:1 (section 12).

**Bubble menu.** On a text selection, above it (below when there is no room): `--surface`, radius `lg`, `shadow-float`, padding 4, gap 2; 32px buttons: Bold, Italic, Strikethrough, Inline code | Link | More (Underline, Superscript, Subscript, Clear formatting; then "From plugins", where plugin buttons for selected text live; a person may pin one to the bubble). **The link editor** replaces the bubble's buttons with a 360px panel: a search field over internal content (results stored by ID, never by URL; rule 9), or a pasted outside address.

**Block handle.** In a 64px gutter before every block (text blocks and cards): "+" (add a block below) and a grip, both 28px, `--ink-3`, hover `--surface-3`. Visible on hover, on the current block, on the selected card and while focus is inside; on touch, always for the current block. Dragging the grip moves the block: the dragged block at 40% opacity, a 3px `--accent` line where it will land. Clicking the grip opens the **block menu**: "Turn into" (Paragraph, Heading, Subheading, Quote, Bulleted list, Numbered list) for text; Duplicate (Ctrl D), Move up (Alt Shift ↑), Move down (Alt Shift ↓), Collapse to one line (cards), plugin entries (marked), separator, Delete (Del, red). Move up and down are the dragging alternative (WCAG 2.5.7).

**Between blocks.** A 12px gap between blocks; on hover or focus a 24px round `--accent` "+" appears in its middle over a 2px `--accent` line at 35%. It opens the picker.

**The one picker** (the block picker; I-3400), opened from "+", from "/" on an empty line, and from the toolbar's Insert. A 400px floating panel (max height 480), `--surface`, radius `lg`, `shadow-float`. Head: what it acts on ("Insert after: Image", "Insert at the start", "Turn this empty line into…") and a List / Grid segmented control. A search field (from "+" and Insert; from "/" the typed text filters, shown as "Filtering by "/ima": keep typing to narrow"). The list (listbox): groups Text, Media, Cards, From plugins (marked); an item is 44 tall: a 32px icon square (`--surface-2`), name (500) over a description (`text-small` `--ink-3`), and its typing shortcut in `kbd` (`##`, `/image`). Grid: three columns of small previews 56 tall. Footer (`--surface-2`, caption): ↑ ↓ move, Enter insert, Esc close. Nothing matches: "No block matches. Esc closes this."

**Card frame.** A card sits in the text with 8px above and below, radius `lg`. Its **header** (40 tall): the card's type name (`text-caption` 600 uppercase `--ink-3`), its tools when selected (for an image: Replace | Width Column / Wide / Full | Focal point), Collapse, "…" (28px buttons). The header shows on hover, when selected, when collapsed and **while focus is inside**. Hover: a 1px `--line` ring; selected: a 2px `--accent` ring. Collapsed: `--surface-2`, one line: the name and a preview of its content. Caption typed in place under the picture (`text-small` `--ink-2`, placeholder "Add a caption"); alt text as a row under it (label and a 32px input), shown when the card is selected or when alt text is missing ("Alt text missing" in `--warn-ink` with a triangle).

**Search and social** (I-3415, with I-3413 and I-3414; the drawing is approved; it replaces the built panel, issue 298). The name stays "Search and social", as on the Settings page. A **full-width box under the article** (the reference design draws it at most 760 wide, centred, `--surface`, radius `lg`, `shadow-raised`), in this order:

| Part | Rule |
|---|---|
| **Header strip** | `--chrome`, min height 52, padding 0 16 0 24, with the box's name and a summary; it **folds the box** (a Disclosure, 4.40). It sets the box apart from the article |
| **Tabs** | Tabs (4.27), **one on screen at a time**: Search first, then Social, Schema, Advanced. Core's first tab is always first. A plugin's tab sits where its author declared it relative to core's tabs (6.3); the SEO plugin's Analysis tab is drawn between Search and Social |
| **Rows** | Each row has its **label and length guidance in a left column** (300 wide on `--surface-2`, in the reference design) and **the field at full width** on the right |
| **Search tab** | The **result preview** at true size with a **Desktop / Mobile** segmented switch; the SEO title (from the site's template until overridden); the meta description (a Textarea, 4.5). **The address shows in the preview only** and is changed only in Publish › Address (the rail's Address row): one value, never a field of its own here |
| **Social tab** | Inherits from Search: "Same as search" until changed, with **one card preview** |
| **Schema tab** | The type and its summary |
| **Checks** | Core's plain factual checks, **after the fields, never above them** |

What is core's and what is the SEO plugin's is I-3413. A plugin adds to the box only through the declared places (6.3).

**The rail.** 344 wide (resizable 320 to 480), `--chrome`, its own scroll. **Action bar** pinned at the top (padding 16, gap 8): Preview (ghost sm, eye), Save draft (neutral sm; "Save" when published), then at the end the split primary: Publish (or Schedule, or Update) ▾. **Publish section, never collapsing**: it holds the Publish button only, with no "Live on the site" switch (I-3404). Summary rows (96px key column): Visibility, Discussion, Publish (Immediately / At a set time…), At (date and time, with the site's time zone as help), Address (a link opening its editor: the one place the address is changed), Template, Author, History ("12 revisions", opening the History pane), then "Stick to the top of the blog". **Sections**, each collapsible: a 44px header button (title `text-body` 600, a plugin's mark, a summary at the end in `text-small` `--ink-3`, a chevron), body padding 4 12 16, gap 12: Categories and tags (checkbox list scrolling past 176px, a primary category select when more than one, tags as removable flat pills over an "Add a tag and press Enter" input), Outline, Checks, then plugin sections (collapsed by default, summarising and linking out). **Move to trash** (danger-quiet sm) at the foot.

**The card inspector.** Selecting a card replaces the rail's sections with its pane (same head as any pane: "← Back to the post settings", the card's name, close), holding its settings (for an image: preview, Replace, Focal point, Open in library; Alt text with help; Decorative; Caption; Width; facts). Deselecting returns the sections. There is no Post / Block panel pair.

**The Tools launcher** (I-3400). In the editor's top bar: Teme's own Outline and History (icon buttons), a divider, **up to three pinned plugin tools** (icon buttons with a 12px plug badge at the bottom end, labelled "Link suggestions, from a link plugin"), then **"Tools {n}"** (ghost sm, plug icon). It opens a 448px popover: a search field (combobox; focused), then one list in groups "Pinned to the bar", "Teme", "Plugins" (A to Z), nothing listed twice; a row is 40 tall: icon, the tool's name (500), its plugin (`text-caption` `--ink-3`, right-aligned), its size as a small tag (Pane, Window, Full screen, Split view), and a pin toggle (28px, filled `--accent-ink` when pinned, `aria-pressed`). Pinning a fourth says, in a live line, "Up to three tools can be pinned to the bar. Unpin one to pin this one." Footer (`--surface-2`): "{n} tools from {m} plugins" and "Manage plugins". When the bar is short of room, the pinned icons give way first. Every tool is also a command.

**Panes.** One at a time, replacing the rail's sections. Head (56 tall, sticky, `--chrome`): Back (icon, "Back to the post settings"), the title (`text-body` 600, focusable), the plugin mark, then "Open larger" (ghost sm) when the pane offers it, and Close. Body padding 0 16 24, gap 16.

**The larger modal.** "Open larger" shows the same pane, **with its state kept**, in the `xl` fixed dialog: head with the title, the plugin mark, "Back to the pane" (ghost sm) and Close. Closing it returns to the pane and puts focus on "Open larger".

**Workspace frame.** A full-screen layer over the admin (`--z-workspace`), `--page`, opened from the document, returning to it. Top bar (60, `--chrome`, padding 0 24): "← Back to the article" (ghost), the title (600), the plugin mark, then its controls and at the end its one primary result action ("Insert 5 images"). Body scrolls, padding 32, content up to 960 wide. The result reaches the document as **one undoable change**. It is a modal region (`role="dialog"`, `aria-modal`); leaving with unapplied work asks.

**Live preview.** A split view: the writing column and a preview pane side by side; the rail hides. The pane: a bar (`--chrome`, padding 8 16): "Live preview" (600) "updates as you type" (`--ink-3`), a segmented Desktop / Tablet / Phone (1040, 768, 390px), Pop out, Close; under it a stage (`--surface-3`, padding 24) holding the site's own rendering in a frame. **The preview is the theme, not the admin**: admin tokens do not apply inside it.

**Focus mode.** A toggle in the top bar: the sidebar folds away and the top bar fades to 50% until pointed at or focused. Esc leaves.

### 4.40 Smaller parts

| Part | Spec |
|---|---|
| **Segmented control** | Built on Radix ToggleGroup (installed, unused today). `--surface-3` ground, radius `md`, padding 2, gap 2; buttons 28 tall, radius `sm`, padding 0 12, `text-small` 500 `--ink-2`; pressed: `--surface`, `--ink`, `shadow-raised`. For 2 to 4 mutually exclusive view choices (Today / 7 days / 30 days, List / Grid, S M L) |
| **Kbd** | `ui/kbd.tsx`: `text-caption` 500 `--ink-3`, `--surface-3`, radius `sm`, padding 0 4, min width 20, line-height 20px |
| **Plugin mark** | `ui/plugin-mark.tsx`: a 14px plug icon and the plugin's name, `text-caption` 500 `--ink-3`, gap 4; in tight places the icon alone, with the name in a tooltip and `sr-only` text. On everything a plugin adds to a shared screen |
| **Progress bar** | `ui/progress.tsx`: 6px, fully rounded, `--surface-4` track, `--accent` fill, `role="progressbar"` with its value. No dependency |
| **Spinner** | `ui/spinner.tsx` (exists): a 16px turning icon, `--ink-3`, 1s per turn |
| **Avatar** | The top bar's 32px account circle, for the person signed in. A person's picture elsewhere is the component in 4.49. **No pictures in dense lists** (I-3399): authors are names |
| **Disclosure** | Radix Collapsible (installed): a button with a chevron rotating 90° and its region; replaces `<details>` |
| **Chart** | Plain SVG drawn by core from data: bars in `--chart-2` with the current one in `--chart-1`, a 1px `--line` axis, labels in `text-caption` `--ink-3`; sparklines 2px `--accent` over `--accent-soft`. Every chart has a text alternative (`role="img"` and a sentence). No chart library: charts are undecided until a screen draws one (I-3404) |
| **ScrollArea** | `ui/scroll-area.tsx`: a `div` with the scrollbar style (2.14), `overflow` on the axis asked for, `tabIndex=0` and a label when it holds no focusable content (so the keyboard can scroll it). No dependency |

### 4.41 Fresh-password prompt

**One prompt** (`Teme.askPassword({ title, text, confirm, onConfirm })`), replacing the four the groups drew. A `sm` dialog (4.19) asking for the person's password before a sensitive act (two-factor, passkeys, keys, backups). It is **asked once per page**; the settings pages keep a 15-minute memory of a successful answer. A wrong answer says so in the Field (4.11) and keeps what was typed. People's screens may ask **inside an open dialog** (the reference design's in-place form), so nothing typed is lost; that second form is the same component.

### 4.42 Settings page layout and "Find a setting"

**One layout for every settings page** (`Teme.settings.define`, the reference design's whole settings kit; a plugin's page uses it too; built as `layouts/settings/layout.tsx` with `SettingsPage`):

- The page's title and line across the full width, "Saved just now" at their end after a save.
- Under them, a **200px side column** (gap 32 to the form): **"Find a setting"** at its top, then the Settings pages (`text-body` 500, 36px), core's with no heading and plugins' under "From plugins", each with the plug mark. The current page opens to its **section links** (`text-small`, 32px, under a 2px `--surface-4` rule), read from the page's titled form panels; the one in view is marked as the page scrolls, and a click jumps to it. Under 1024px the column's list opens from one button.
- "Find a setting" searches every settings page the person may change and shows up to eight results as a list under the box, the typed words marked; ↑↓ and Enter choose, Escape closes, "N more: type more words to narrow them." and "No setting matches “…”. Try fewer words." say the rest; "/" anywhere on the page focuses it. A result opens its page and marks the setting.
- The form's sections, one titled panel each, then **the one save bar with its leave guard** (4.12), saying "1 unsaved change" or "N unsaved changes" with "Save", and after a refused save "N settings need attention before you can save".
- A field whose change asks for the password says "Asks for your password to change"; Save asks once for all of them ("You changed X and Y…").
- Someone who holds `settings.view` but not a group's own permission sees its page **view only**: a banner naming the permission that changes it, fields disabled, no save bar.
- The crumb reads "Settings / {page}"; Settings is the hub of cards (5, Hub).

Rules for what a section holds: one purpose per section; settings are never grouped only because they sit next to each other in the registry.

### 4.43 Account layout

**One layout for Your account** (`Teme.accountNav`, `.account-main`): an in-page navigation (InPageNav, 4.27) of the account's pages (Profile, Preferences, Notifications, Security, Passkeys, Sessions, Two-factor), headed by the signed-in person (their picture, 4.49, with name and role), then a rule, and the main column (gap 16) holding the page's form panels. Every account page uses it.

### 4.44 Auth card

**One card for the pages outside the admin** (`Teme.authCard`; sign-in, set-up, forgot and reset password, verify email, two-factor, confirm password). Centred, `--surface`, radius `xl`, floating shadow, padding 32, gap 20, **at most 420 wide** (`min(420px, 100%)` in the reference design's stylesheet; the earlier text said 400). The brand mark, then the title (`text-title`), a lead line, the Fields and one primary button. A password field has a show / hide eye button (labelled). A "Soon" tag inside a disabled button sits 4px after its label. Replaces section 5's earlier note that auth pages were not designed.

### 4.45 Step bar

`Teme.steps(labels, n)` for a task in several steps (Import, Backups set-up, first run). A row of steps, gap 8, `<ol>`; each step: padding 8 12, radius `md`, `--surface-2`, `text-small` 500 `--ink-2`, with a 24px round number at its start. **Current**: `--accent-soft` ground, `--on-accent-soft` text, the number on `--accent` (`aria-current="step"`). **Done**: a check on `--ok-soft`. Under it, the Progress bar (4.40) for a step that runs; it is the same `progress` element in every screen (tones: fine, problem, quiet). Folded from the five progress and step versions of the groups.

### 4.46 "Soon" tag

**A real product tag** (I-3408). A planned feature may show where it will live (a menu entry, a hub card, a tab, a field) with this tag; the entry **cannot be opened or used** and says in a word what it will do (a click may answer with a short toast, `soonToast`).

| Part | Rule |
|---|---|
| Look | A pill 20 tall, padding 0 8, fully rounded, `--surface-3`, `--ink-3`, `text-caption` 500 (the values of the reference design's "Soon" tag). Quiet: it is not a status pill (4.13) and never carries a state's colour |
| Where | After the label of a sidebar entry (pushed to the end), a hub card, an InPageNav item, a tab, a heading, a field label, a table heading, a button (the one tag, with one spacing rule: 8px after its label). Hidden in the folded sidebar |
| In the code | **One declared way**: a `soon` flag on the entry in the registry that draws it, so that the list of what is promised is one query and the tag is removed in one place when the feature ships. **Nothing is tagged without a roadmap entry** |
| Not this | The disabled "Later" tags on Crop, Rotate and Flip are a design-review device and never ship (4.37). "Proposal" in the reference design is the same tag in another word and is also a design-review device |
| To settle when built | Who sees the tags (everyone or administrators), and whether a site can hide them (I-3408; section 11) |

### 4.47 Plugin icon

**Every plugin has its own icon** (I-3405): a small square picture the plugin ships. The generic plug mark (4.40) keeps its separate job of marking what came from a plugin inside core's screens; the plugin icon is the plugin's identity.

| Size | Radius | Initials | Where |
|---|---|---|---|
| 32 | `md` (8) | `text-small` | Lists: the Plugins screen's rows, a hub card's heading, a search result |
| 56 | `lg` (12) | `text-lead` | The plugin's card and dialogs |
| 64 | `lg` (12) | `text-title` | The head of the plugin's own area and its settings |
| 96 | `xl` (16) | `text-display` | A plugin's detail page, and later its card in a plugin directory |

Sizes and radii are from the reference design's stylesheet. A 1px `--img-outline` inner outline; the file is shown contained at 60% of the square. **No icon file: the plugin's initials on a neutral square** (`--surface-3`, `--ink-2`, 600), **never the accent**. The reference design's tinted squares stand in for the pictures plugins will ship. The file is never trusted as code: an SVG is sanitised or rasterised at install and served by core (I-3405). It appears on the Plugins screen, in the plugin's dialogs, at the head of its own area, and later in the directory.

### 4.48 Icon tile

**A thing's identity in a list** (a hub card, a type, a setting group, a Tools row): a square holding one icon. `--surface-3`, icon in `--ink-2`.

| Size | Radius | Icon |
|---|---|---|
| 36 (default) | `md` (8) | 20 |
| 48 | `lg` (12) | 24 |
| 24 | `sm` (6) | 16 |

Sizes are from the reference design's stylesheet. It carries no state colour and is decorative (`aria-hidden`); the name beside it is the label. A plugin's own picture is the Plugin icon (4.47), not an icon tile.

### 4.49 Person's picture

**A circle** (`avatar(name, { pic, size, invited })`) for a person on the screens about people (I-3410): People, a profile, Bylines, the account navigation. **Not in dense lists** such as the posts list (I-3399).

| Part | Rule |
|---|---|
| What it shows | The picture the person uploaded (from Media; they may remove it again). With none, **the site's default**, chosen in Settings › People › People's pictures: **initials** (the default) or **Gravatar**. How Gravatar is fetched is settled when it is built (I-3410) |
| Initials | `--surface-3` ground (a second step, `--surface-4`, for variety), `--ink-2`, 600, centred |
| Invited, not joined | A dashed 1px `--edge` ring on a transparent ground, a person icon (18) in `--ink-3`: no initials, since no one has joined |
| Sizes | 24 (small), 40 (default), 96 (large: a profile's head), from the reference design's stylesheet. The top bar's account circle is the 32px one in 4.33 |
| Always | `overflow: hidden`, `border-radius` full, the picture covering it, with the person's name as its text alternative (empty when the name is beside it) |

---

## 5. Page patterns

No page sets its own padding, width or header: it renders one **Page** pattern, and the pattern owns them (check R7). Every pattern sits in the shell: the sidebar, then a column of the top bar (60) and the **view**, which scrolls (the window does not).

| Pattern | Component | Container | Header | Primary action | Destructive action |
|---|---|---|---|---|---|
| **List** | `ListPage` | `--page-max` 1280, centred; padding 8 32 64 (8 16 64 below 768) | Page header; then InPageNav (status views), filter row, bulk bar, table frame | Page header's end ("New post") | In the "…" menu and the bulk bar; permanent deletion only in the Trash view, confirmed |
| **Form (edit)** | `FormPage` | 1280 container; the form column 760, left-aligned | Page header (title is the item's name or "New category") | Save, in the form footer (4.12) | "Delete {thing}" as a danger-quiet sm button at the foot of the last panel, confirmed |
| **Settings** | `SettingsPage` in the Settings frame | 1280; header across the width, then the 200px side column and the form column 760 (4.42) | Page header; the crumb "Settings ⇅" switches between settings pages; the side column with "Find a setting", the pages and the current page's sections (4.42) | Save, in the form footer | As Form |
| **Hub** (Tools, Settings) | `HubPage` | 1280 | Page header; a grid of clickable cards (`auto-fill, minmax(300px, 1fr)`, gap 16), or the cards under headings (Settings: "The site", "Writing and discussion", "People and services"); then "From plugins" with its hint ("Each names its plugin.") and its cards | None | None |
| **Dashboard** (Home) | `DashboardPage` | 1280 | Page header (greeting, date, quick actions: + Post primary, + Page, Upload media, Customise); figure tiles (5 across, gap 12; 3 then 2 on narrower screens); "Your work" panels; "From plugins" panels | "+ Post" | None |
| **Detail with side panel** | `DetailPage` | 1280; grid `minmax(0, 1fr) 360px`, gap 24; the panel sticky | Page header | Header's end (Upload; Save on Edit image) | In the side panel's actions (Trash) |
| **Editor** | `EditorPage` | Full height: grid writing column \| rail 344; no page padding; the sidebar folded | The editor's top bar (4.39) instead of a page header; the `h1` is the post title field | The rail's Publish | Move to trash at the rail's foot |
| **A plugin's own area** | `PluginAreaPage` | As List | Page header with the plugin mark after the `h1`; InPageNav under it (first five pages, the rest under More) | As its pages need, under these rules | As its pages need |
| **Full-screen workspace** | `Workspace` | Full screen; body padding 32; content up to 960 | Workspace top bar (4.39) | The top bar's end | Inside, with Undo |

**Responsive behaviour**, for all patterns (section 2.12):

- **≥1360:** as drawn.
- **1024–1359:** side panels 320; the editor's sheet padding 64.
- **768–1023:** the sidebar is a sheet behind a menu button; the editor's rail is a sheet behind the rail toggle; the media detail panel a sheet; dashboard `l` panels go full width.
- **<768:** page inline padding 16; the page header's actions wrap under the title (primary first); filters fold to the search and "Filters ({n})"; tables keep the tick box and title pinned (title minimum 240) and scroll; Home is one column, figure tiles two across; the top bar keeps the current page's name and one status button that opens a panel with jobs, site status and notifications; the picker is full screen.
- **320px wide:** everything reachable, nothing clipped (WCAG 1.4.10); this width is tested.

**Auth pages** (sign-in, set-up, two-factor and the rest) use the one auth card (4.44) in `AuthPage`.

**Phone and tablet.** The phone and tablet behaviour of each pattern is being settled in the reference design's responsive pass and will be written here when it is approved. The list above is today's and is not extended until then.

---

## 6. Plugins

The rule above everything (I-3396): **a plugin can build anything in its own space**; on the screens everyone shares, it adds through declared places with budgets, so many plugins do not crowd the admin. The budgets order shared screens; they are never a reason to refuse what a plugin needs. When a real plugin cannot be built, the socket is missing: it is filed and built.

### 6.1 Drawn by core from a plugin's data

The plugin supplies data (declared through `teme/contracts`); core draws it with its own components, so it looks native and survives upgrades. Every entry also declares when it applies and the permission it needs (plugins.md).

| Surface | Data a plugin supplies | Core draws |
|---|---|---|
| **List cell** (a column) | `{ label, width, sortableBy?: indexed value, cell: Text \| Number \| Status \| Marks }` | The column, its heading with the plug mark, the cell |
| **Row of marks** (a link plugin's Link profile) | `{ dot?: { tone, label }, pills: up to 4 × { icon, count, tone: 'info'\|'ok'\|'danger'\|'warn'\|'ai'\|'quiet', label, opens? }, warning?: { label }, whole?: { kind: 'notScanned'\|'notIncluded', label, action? } }` | One line, gap 3: an 8px state dot, then pills 24 tall, radius `sm`, padding 0 6, gap 3, a 14px icon and the count (`text-caption` 600, tabular) in the tone's soft and ink (`quiet` = `--surface-3` / `--ink-3`, used at zero), each a button with a tooltip and an `aria-label` ("Inbound internal links: 4"); a warning mark (triangle in `--warn-ink` on `--warn-soft`); or one whole-cell state ("Scan" as a quiet pill button; "Not included" as `text-small` `--ink-3` with a tooltip) |
| **Row action** | `{ label, icon, order, when, permission, danger? }` | An item in the row's "…" menu, after core's, with the plug mark |
| **Bulk action** | Same | An item under the bulk bar's "More" |
| **Filter** | `{ label, choices }` | A choice in the list's one "From plugins" filter, labelled with the plugin |
| **Dashboard panel** | `{ title, size: 's'\|'m'\|'l'\|'full', count?, body: Rows \| Figures \| Chart \| Text \| Component }` | The panel frame, its plug mark, loading, failed and empty states; a `Component` body is the plugin's React inside core's frame |
| **Status row** | `{ label, state: 'fine'\|'attention'\|'problem', detail: one line, action?: { label, command } }` | A row in the site status panel; its action is also a command |
| **Menu entry** (sidebar) | `{ label, icon, section, order, badge? }` (at most one per plugin, in a core section, within the site-wide cap) | A sidebar entry with the plug mark |
| **Hub page** | `{ hub: 'tools'\|'settings', label, description, icon }` | A card in the hub and a sub-entry under it |
| **Command** | `{ label, icon, group, keywords, run }` | A palette item, under "From plugins" or its group, with the plug mark |
| **Rail section** | `{ title, summary, fields?, actions? }` | A collapsed rail section with the mark; it summarises and links out, never holds a workflow |
| **Tool** (editor) | `{ label, icon, size: 'pane'\|'dialog'\|'workspace', openLarger?: boolean }` | A row in the Tools launcher, pinnable; the pane, dialog or workspace frame |
| **Block** | `{ label, description, icon, shortcut, group: 'From plugins' }` | An entry in the one picker |
| **Picker source** | `{ label, component }` | A tab in the picker, with the mark |
| **Check** | `{ label, state, fact }` | A line in the editor's Checks |
| **Selection button** | `{ label, icon, command }` | In the bubble's More, under "From plugins" |
| **Plugin icon** | An image file (an SVG is sanitised or rasterised at install and served by core) | The plugin icon (4.47); the initials when none |
| **Tab in a core box** | `{ label, position relative to core's tabs, body }` | A tab in the box (the editor's Search and social), marked as the plugin's; core's first tab stays first (6.3) |

### 6.2 The kit

A plugin's own screens (its pages, panes, dialogs, workspaces) look native by **building with Teme's component kit**: the same components core uses, taken from the admin at run time (the Vite preset externalises them; a plugin never bundles its own copy).

| Kind | In the kit |
|---|---|
| Tokens | Every CSS variable in section 2 (colours, elevation, radii, sizes, motion, layers); the six text classes through the plugin's own Tailwind preset |
| Page patterns | `PluginAreaPage`, `ListPage`, `FormPage`, `DetailPage`, `Workspace`, `PageHeader`, `InPageNav` |
| Controls | `Button`, `IconButton`, `TextButton`, `Input`, `Textarea`, `Select`, `Combobox`, `Checkbox`, `RadioGroup`, `Switch`, `Segmented`, `Field`, `Form`, `FormPanel`, `FormFooter` |
| Feedback | `toast()`, `Notice`, `EmptyState`, `Skeleton`, `Spinner`, `ErrorState`, `Progress` |
| Overlays | `Menu`, `Popover`, `Tooltip`, `Dialog` (all sizes), `Sheet` |
| Data | `DataTable` (with `BulkBar`, `ColumnChooser`, `FilterRow`, `Pager`), `StatusPill`, `TermChips`, `Badge`, `Kbd`, `Panel`, `PluginMark`, `Chart` |
| Media | `openMediaPicker()` (the one picker), `MediaTile` |
| Icons | `Icon` (Lucide at the system's stroke) |

**Versioning.** The kit is part of the admin API: its major version is the plugin contract's major, checked at enable (`adminApi`, plugins.md). Within a major, components and token names are only added; a removal or a changed meaning waits for the next major, and a deprecation names the version that removes it (logged against the plugin that uses it). The catalogue (9.3) is the kit's living reference.

### 6.3 A plugin's options inside a core box (I-3418)

Where a core box (the editor's Search and social is the first) holds a plugin's options, the same rules hold for every plugin, the Teme team's own included: **no private doors**. Full text: `docs/architecture/plugins.md` › A plugin's options inside a core box.

| Rule | Meaning |
|---|---|
| Its own work | In **its own tab**, placed where its author declared it relative to core's tabs. Core's first tab is always first |
| Small additions | Beside core's fields **through declared places only**: a suggestion or advice next to a field, an extra row in a core tab, a state on the box's header. Core draws them and marks them as the plugin's (4.40, plug mark) |
| What core owns | **Never moved, hidden or replaced** by a plugin |
| Two plugins, one place | Where a place holds one, the site's administrator chooses |

**The rule (I-3396).** The kit is how a plugin looks native, **never a limit on what it may build**. In its own space a plugin may draw anything the kit does not have, using the tokens (never raw colours, never restyling core's components, its CSS in a layer below core's). When a real plugin needs something on a shared screen that no socket offers, that is a missing socket: filed and built, not refused.

---

## 7. Accessibility

WCAG 2.2 AA, as `accessibility.md` sets it, applied to this system.

| Area | Rule here |
|---|---|
| **Contrast** | Text 4.5:1, controls, icons, focus and boundaries 3:1, in light and dark, for every pair in 2.4 and every accent pair in 2.2.2, computed by the check (R12). Decorative only: `--warn` dots, scrollbars (the thumb is 1.75:1 at rest and 2.67:1 pointed at, as approved; the area also scrolls by wheel, keys and touch, and its overflow shows), `--line` dividers |
| **Target size** | At least 24×24 (2.5.8): the smallest controls are 24 (row actions, ticks, the "+" between blocks, Link profile pills); dense controls are 28; ordinary ones 32 and 36. On phones icon buttons grow to 44 |
| **Focus** | The ring on every focusable thing (2.13); never hidden under a sticky header or the pinned columns (sticky regions add `scroll-margin`); focus moves into overlays and back to their opener; removing a row moves focus to the next row or the list's heading |
| **Keyboard paths** | Menus: ↑↓ Home End, type-ahead, Esc. Tabs: ←→. InPageNav and the sidebar: Tab. Table: Tab through each row's controls, Space ticks. The media grid: arrows, Space, Enter. The editor's handle: Tab reaches it on the current block; Enter opens the block menu; Alt Shift ↑↓ move a block; Ctrl D duplicates; the "+" and "/" open the picker, ↑↓ Enter Esc inside it. The Tools launcher, palette and pickers: a combobox with arrows and Enter. The focal point: arrows. Every screen's main task has a keyboard-only browser test |
| **No hover-only actions** | Row actions are always visible (I-3398). Card headers and the block gutter show on focus as well as hover. Media ticks show on focus and always on touch. Tooltips also show on focus |
| **Touch** | `(hover: none)` shows ticks and gutters; the dragging of blocks and columns has buttons as alternatives (2.5.7) |
| **Reduced motion** | 2.10: durations 1ms, no shimmer, no press scale; a browser test emulates it |
| **Never colour alone** | Pills carry a word and a shape; "No alt" carries an icon and words; Link profile pills carry an icon, a number and a tooltip; required fields carry a word; errors carry an icon and text; the current nav entry also carries `aria-current` |
| **Names** | Every icon button has an `aria-label` and a tooltip; every mark has an accessible name; each repeated landmark has its own name ("Main", "Where you are", "Post settings", "Bulk actions") |
| **Announcements** | Toasts are live regions; the save state is `role="status"`; selection counts and the launcher's pin note are announced |
| **Forced colours and more contrast** | Controls keep visible boundaries in Windows high contrast (`forced-colors: active`): borders, not only grounds, mark selection and the switch. Under the computer's "more contrast" (`prefers-contrast: more`) the token block in 3.1 applies; Teme has no contrast setting of its own (I-3404) |

---

## 8. Writing in the interface

The few rules visible in the approved reference design. **A full voice and tone guide is a separate, later item (issue 357).** Every word still goes through `__()` (AdminWordsTest).

1. **Sentence case** everywhere: "Move to trash", "Search or jump to", "Customise your Home". Names of screens and products keep their capitals (Posts, Media, Trash).
2. **Buttons are verbs** that say what happens: "Publish", "Insert image", "Set featured image", "Clear filters", "Try again". Not "OK", not "Submit". A confirmation's buttons name both outcomes: "Delete permanently" and "Keep it".
3. **Toasts state the result** in the past tense, short, and offer one way back: "Published" · Undo; "Moved to Trash" · Undo; "Page cache cleared: 1,240 pages are rebuilt as readers visit".
4. **Empty states** say what is missing and what to do: "No posts match" / "Try fewer filters." / Clear filters; "No broken links" / "Every internal link opens."
5. **Errors say what failed, who failed, and what still works**: "Couldn't load submissions. The Forms plugin didn't answer in time; the rest of Home is unaffected."
6. **Plain words over jargon**: "Needs a look", "Across (%)" and "Down (%)" rather than X and Y.
7. **Help text is one sentence** in the person's terms: "For people who can't see it."; "Only you see these changes."
8. **Counts are numbers with their noun**: "3 posts selected", "2 to look at", "Used 3×".
9. Typography: curly quotes and apostrophes, the ellipsis character "…" for an action that asks more ("At a set time…"), an en dash for ranges, "·" between facts.

---

## 9. The check that stops drift

### 9.1 How it is built

The same way as `AdminWordsTest`: a Node scanner parses the admin's code with Vite's own parser (`rolldown/parseAst`), a Pest test compares what it finds with a **baseline of today's offences that may only shrink**, and a second test pins each rule's count.

| File | What |
|---|---|
| `tests/Fixtures/design-system-scan.mjs` | Reads `{ root, css }`; walks `resources/js/**/*.ts(x)` (skipping Wayfinder's `actions`, `routes`, `wayfinder` and `.d.ts`), parses each file, collects every class string (JSX `className`, `cn()`, `cva()` and `clsx()` arguments, template literals), every JSX element name and its props, every `style` prop; reads `resources/css/app.css` and any CSS under `resources/css` except `cards/` (public-site theme fallbacks, out of scope); prints `{ offences: [[rule, path, match]], unreadable }` |
| `tests/Architecture/DesignSystemTest.php` | Calls `guardNode('design-system-scan.mjs', …)`; fails on any offence not in the baseline and on any baseline line no longer found ("remove it and lower the count"); pins each rule's count; a planted-file test proves every rule catches what it should |
| `tests/Architecture/design-system-baseline.txt` | `rule<TAB>path<TAB>match`, one line per offence when the check arrives |

**Allowed places.** `resources/js/components/ui/**` and `resources/js/components/patterns/**` are where the system is implemented: rules R5 (raw controls), R7 (page frame), R8 (scroll) and R9 (notices) do not apply inside them; the token rules (R1 to R4, R6, R11 to R14) apply everywhere. Lowering a count is a normal change; **raising a count or widening an allow-list is a stop**: it needs a maintainer's decision.

### 9.2 The rules

Starting sizes are estimates from the audit's counts on `origin/main` (2026-11-13) and a count of today's code; the real baseline is whatever the scanner writes on its first run, after step 1 of the rollout.

| Rule | Catches (pattern) | Allowed | Start | How it reaches zero |
|---|---|---|---|---|
| **R1 Arbitrary values** | Any `[…]` value in a utility: `text-[13px]`, `w-[420px]`, `max-w-[640px]`, `h-[34px]`, `grid-cols-[200px_1fr]`, `ring-[3px]`, `tracking-[-0.015em]`, `rounded-[…]`, `shadow-[…]`, `z-[…]` (regex `\b[a-z-]+-\[[^\]]+\]` on class strings, excluding variant brackets `data-[…]:`, `aria-[…]:`, `has-[…]:`, `group-[…]:`) | `grid-cols-[minmax(0,1fr)]` and `grid-cols-[minmax(0,1fr)_auto]`; `aspect-[N/M]` ratios; `var(--token)` shorthands `(--token)` | ≈ 200 (590 minus the 389 font sizes, which R2 counts) | Each value either names a token (`h-(--ctl-h)`, `w-(--rail-w)`) or a pattern owns it; falls section by section |
| **R2 Font sizes off the scale** | `text-xs`, `text-sm`, `text-base`, `text-lg`, `text-xl`, `text-2xl`…, `text-[Npx]`, `font-size:` in `style` or CSS outside the token block | `text-caption`, `text-small`, `text-body`, `text-lead`, `text-title`, `text-display` | ≈ 897 (every size class today; 29 of them in `ui/`) | `ui/` in step 1, then each section's rebuild |
| **R3 Spacing off the grid** | `(p|px|py|pt|pr|pb|pl|ps|pe|m|mx|my|mt|mr|mb|ml|ms|me|gap|gap-x|gap-y|space-x|space-y|inset|top|right|bottom|left|translate-x|translate-y)-(1\.5|2\.5|3\.5|7|9|11|14|15)` and any `0.5` outside `gap`; `[Npx]` spacing (also R1) | `gap-0.5`, `gap-x-0.5`, `gap-y-0.5`; the segmented control's `p-0.5` inside `ui/` | ≈ 255 | Section rebuilds |
| **R4 Raw colours** (extends `NoRawColoursTest`) | Today's patterns (palette classes, hex, `oklch(`/`rgb(`/`hsl(`, `text-white`/`text-black`) **plus** `bg-`, `border-`, `ring-`, `outline-`, `fill-`, `stroke-`, `from-`, `to-`, `shadow-` with `white`, `black` or `transparent`-mixes (`bg-black/80`, `bg-white`, `border-white`), CSS colour keywords in `style`, and in CSS any colour literal outside the `:root`, `.dark` and `@theme` token blocks | `transparent`, `currentColor`, `inherit` | **5** (`ui/dialog.tsx:39`, `ui/sheet.tsx:37` scrims; `media-drawer.tsx:370` focal marker; `two-factor-setup-modal.tsx:80` QR ground; `backups/key-sheet.tsx:10` print sheet) | All five fixed in step 1 with `--scrim`, `--on-photo`/`--photo-scrim` and `--paper`; then **no baseline**: the rule stays at zero, as `NoRawColoursTest` does today |
| **R5 Raw controls outside `ui/`** | JSX `<button>`, `<select>`, `<textarea>`, `<table>`, `<details>`, `<input>` (except `type="hidden"` and `type="file"` inside an upload component), `<dialog>` | Inside `components/ui/**` and `components/patterns/**` | ≈ 154 (50 buttons, 21 textareas, 5 selects, 6 tables, 4 details, ≈ 55 inputs and 13 radios among them) | Replaced by the components in each section's rebuild; the editor's own widgets become `ui/` parts in the editor step |
| **R6 Off-scale shape and weight** | `rounded`, `rounded-(none|2xl|3xl)`, `shadow`, `shadow-(sm|md|lg|xl|2xl)`, `font-(thin|extralight|light|bold|extrabold|black)`, `z-\d+`, `leading-\d`, `tracking-(tighter|tight|wide|wider|widest)` | `rounded-(xs|sm|md|lg|xl|full)`, `shadow-(raised|float|modal)`, `font-(normal|medium|semibold)`, `z-(--z-*)`; `uppercase tracking-[…]` only inside the three caption parts in `ui/` | ≈ 60 (bare `rounded` 29, `shadow`/`shadow-sm` 2, `z-` numbers 30 incl. `ui/`) | Step 1 (`ui/`) then sections |
| **R7 Page frame** | In `resources/js/pages/**`: a default export whose returned root is not a page pattern (`ListPage`, `FormPage`, `SettingsPage`, `HubPage`, `DashboardPage`, `DetailPage`, `EditorPage`, `PluginAreaPage`, `Workspace`, `AuthPage`); or any element in a page file carrying `p-*`, `px-*`, `py-*`, `max-w-*`, `mx-auto`, `container` on the page's top two levels | — | ≈ 55 (every page file: 34 write `p-6`, 34 `max-w-*` uses) | One line per page, removed as the page moves onto its pattern |
| **R8 Scrolling without ScrollArea** | `overflow(-x|-y)?-(auto|scroll)` in a class string, `overflow: auto/scroll` in `style` or CSS | Inside `ui/scroll-area.tsx` | **42** | Step 1 does the sidebar, menus, popovers, selects, dialogs and sheets in `ui/` (≈ 12); sections do the rest |
| **R9 Hand-made notices and pills** | `role="note"`; an element whose classes combine a status soft ground with a border or padding (`bg-(ok|warn|danger|info|warning|success|destructive)-(soft|muted)`) outside `ui/` | — | **≈ 26** (17 `role="note"`, 3 tinted strips, 6 hand-made chips) | Replaced by `Notice` and `StatusPill` section by section |
| **R10 Focus removed** | `outline-none` or `outline-hidden` without a `focus-visible:` outline or ring utility in the same class string | — | ≈ 5 (audit §5: search panel, palette input, 2FA input, ProseMirror, `main`) | Each given a ring or its replacement rule |
| **R11 Tokens and classes that do not match** | A custom property defined in `app.css` that no file reads (`var(--x)`, a `@theme inline` class used somewhere); a `var(--x)` or token class (`bg-popover`, `text-muted-foreground`) whose token is not defined | Tokens listed as public for plugins (section 2) count as used | 0 after step 1 (today: 6 dead structure tokens, 3 dead chart tokens, `--scrim` used but undefined: all gone in the rewrite) | Held at zero |
| **R12 Contrast** | Computes every pair in sections 2.2.2 and 2.4 from `app.css` (oklch → sRGB with clipping → WCAG ratio), in light and dark, for the accent in `:root`; fails below the threshold; also fails when the written accent clips by more than 0.04 or sits within 20° of a status hue | The decorative list in section 7 | 0 | Held at zero; a new token pair joins the list when it is introduced |
| **R13 Accent literal** | Outside the accent token block in `app.css`: any use of a hue number with a colour function, `--accent-h`/`--accent-c` read by a component, a literal colour equal to a derived accent step, a class or `style` that names an accent by name (`ember`, `teal`…) | The accent block; the catalogue page's accent lab (not shipped) | 0 | Held at zero |
| **R14 Motion** | `animate-*` outside `ui/` without `motion-safe:`; `transition` or `transition-all` without named properties; a CSS `transition`/`animation` with a duration or curve not from the tokens; no `prefers-reduced-motion` block in `app.css` | `transition-[colors,opacity,scale,translate,box-shadow]` named; the tokens | ≈ 20 (`animate-` outside `ui/`) | Section rebuilds |
| **R15 Inline style** | A JSX `style` prop that sets anything but a CSS custom property or a computed position (`left`, `top`, `transform` from runtime values) | `style={{ '--tile': … }}`; the focal marker's and popovers' positions | ≈ 11 | Converted to variables or classes |

### 9.3 The catalogue route and its screenshots

A second line of defence for what code reading cannot see.

- **Route:** `/admin/_catalogue`, registered only when `app()->environment(['local', 'testing'])`, behind the admin login, rendering `pages/dev/catalogue.tsx`. Never in a production build's routes (a feature test asserts 404 in production).
- **What it shows:** every `ui/` and `patterns/` component in every state of section 4 (rest, hover forced with a `data-state` attribute, focus, pressed, disabled, loading, error, selected), every token as a swatch, the type scale, the page patterns at small size, the plugin marks and a sample row of marks, the editor's parts with sample content. Sections are anchors (`#button`, `#table`…). The page is the code version of the catalogue drawing, and the kit's reference for plugin authors.
- **Test:** `tests/Browser/DesignCatalogueTest.php` visits each anchor at 1280 and 375 wide, in light and dark, with reduced motion, and compares a screenshot with the stored one (Pest's browser screenshot assertion); a difference fails with the diff image. It also runs axe with the `wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa` tags and `target-size` on. Updating a stored screenshot is part of a deliberate design change and is named in its commit.
- **Runs:** in CI's browser shares (a change runs only the browser tests it touches; CI runs the full set).

---

## 10. Rollout

Order, with the editor and media first (I-3400):

| Step | What | Ships how | Must not break |
|---|---|---|---|
| **0** | Answers to section 11 | Given for four (I-3404); what is left is in section 11 | — |
| **1a. Rename** | A codemod renames today's token names to the new names **with today's values** (accent → surface-3 first, then primary → accent, and the rest of section 3). Tailwind classes follow. | Ships alone: nothing looks different | Every test; AdminWordsTest untouched (no words move) |
| **1b. Tokens and `ui/`** | `app.css` rewritten (section 2, Ember); the five raw colours fixed; `components/ui/**` restyled and the new parts added (Textarea, RadioGroup, Switch, Tabs, ScrollArea, Field, Form, Notice, StatusPill, chips, Kbd, PluginMark, Progress, Segmented, MediaTile, TextButton); the catalogue route and its screenshot test; **DesignSystemTest** with its baseline; `DesignTokensTest`, `SharedControlsTest` and `ContrastTest` rewritten for the new values (they encode the old design) | **Cannot ship behind the old look**: tokens are global, so this is the switch. Every screen takes the new colours, type and shapes at once, in its old layout. It merges only when the shell (step 2) is ready with it | AdminWordsTest's count (new components' words go through `__()`; a word that only moves file updates its path line); existing browser tests' selectors: keep `data-slot`, roles, accessible names and `data-testid`; when markup must change, the test changes in the same commit; **no test is deleted without approval** (given for the looks' tests, I-3404) |
| **2. Shell and patterns** | Sidebar (light, 264, folding to 68), top bar with crumbs and the status area (jobs, site status from data, notifications), command palette, Page patterns, toasts | With 1b, as one release | The skip link, landmarks and the editor workspace layout (`html.editor-workspace`) |
| **3. Editor** | Writing surface as a page, toolbar, bubble, handles and gutter (Tiptap's drag handle, approved: I-3404), the one block picker, card frame, rail, inspector, Tools launcher, panes and the larger modal, workspace frame, live preview | Section by section after the switch; the editor may land in parts behind its own route (the old editor stays until the new is complete) | `EditorControlsTest` (toolbar rules hold), the editor's browser tests (focus mode, rail, cards), stored Tiptap JSON (no schema change is part of the look) |
| **4. Media** | Library grid and filters, tiles, detail panel, focal point, the picker | After the editor's picker hooks exist | Media browser tests; uploads |
| **5. Lists** | Posts first (the table of I-3398 and I-3399, the column chooser, bulk bar), then Pages, Comments, Users, Terms, Redirects, the 404 log, Jobs, the rest of `DataTable`'s 16 screens | Screen by screen | Row-action tests (they change: hover links to the hybrid); selection and bulk tests |
| **6. Home** | The panel grid, figures, Customise, the plugin panel frame | — | Dashboard tests |
| **7. Settings and hubs** | Settings pages on the form pattern with the crumb switcher; the Tools hub; plugin hub pages | — | Settings save tests (Save moves to the form footer: tests that click "Save {page} settings" change) |
| **8. People and the rest** | Users and roles, Account and Preferences (looks removed, I-3404), Themes, Plugins, Types, Taxonomies, Import, AI activity, Backups, Updates, auth pages | — | Their tests |

Each step lowers DesignSystemTest's counts; a step is done when its files have no baseline lines left. The v10 reference design that replaces v9 (I-3390) is drawn from the catalogue and the rebuilt screens, not before them.

---

## 11. Open questions

**Decided, no longer open** (2026-11-13):

| Was | Answer | Where it went |
|---|---|---|
| The accent | Ember is the default; the others are later (I-3402) | 2.2, 2.2.1 |
| The three looks and high contrast | Removed; Teme follows the computer's "more contrast" (I-3404) | 3.1, section 7 |
| Content-list row heights | Shorter where the title is one line, taller where it wraps to two: 72 and 92px (I-3404) | 2.9, 4.26 |
| New dependencies | Radix tabs, radio group and switch; `cmdk`; Tiptap's drag handle. No table engine. Each one's current version and licence is checked on the day it is added (I-3404) | 4.7, 4.9, 4.10, 4.26, 4.27, 4.39 |
| The "Live on the site" switch | Dropped: the Publish panel has the Publish button only (I-3404) | 4.39 |

| # | Question | Options | Recommendation |
|---|---|---|---|
| **Q1** | On a phone, do the content lists become cards, or keep the table and scroll sideways? | A. Cards: each row a card with the title, the status and the actions. B. The table scrolls sideways with the tick box and title pinned (what section 5 says today) | Open: being drawn in the reference design's responsive pass |
| **Q2** | Who sees the "Soon" tags, and may a site hide them? | A. Everyone. B. Administrators only. C. Everyone, and a site may hide them | Left for when it is built (I-3408) |
| **Q3** | Charts | A plain SVG drawn by core (as 4.40 says now), or a chart library | Undecided until a screen draws a chart (I-3404) |

---

## 12. Disagreements found

Between the approved reference design, the decisions, the code on `main`, and the older documents. The rule applied: **a decision wins over the reference design; the reference design wins over older documents and today's code**, unless it breaks a rule this system must keep (contrast, keyboard, consistency), noted as such.

### 12.1 Reference design against the decisions

| # | Reference design D2 draws | Decision | This system |
|---|---|---|---|
| 1 | Row actions by the old I-3130 split: Edit, Quick edit, View plus Trash on the line, the rest under a "More" link | I-3398: Edit, Quick edit, View on the line; a pinned "…" with the state action, plugin actions, Trash | I-3398 (4.26). **Resolved** (adopted, I-3398) |
| 2 | Toasts "Moved to the trash: "{title}"", "Published: "{title}"" | I-3398: "Published · Undo", "Moved to Trash · Undo" | The decided words (4.21). **Resolved** (I-3398) |
| 3 | Rows 66 or 86px tall depending on the title | I-3399: rows stay one even height | 72px for a one-line title and 92px for two, as drawn. **Resolved** (I-3404 supersedes I-3399's even height) |
| 4 | The accent switch with Teal default | Ember is the default; no accent setting | Ember in `:root`; others only in the catalogue (2.2). **Resolved** (I-3402) |
| 5 | Danger at hue 27 and warning at hue 70 (14° and 29° from Ember) | One accent with a meaning of its own (P2) | Danger moved to 18, warning to 85, plus shape rules (2.3.1). **Resolved** (I-3402: no status colour may be mistaken for the accent) |

### 12.2 Reference design against rules this system must keep

| # | Reference design | Problem | This system |
|---|---|---|---|
| 6 | Editor toolbar at 72% opacity at rest | Its labels fall to 3.83:1 (needs 4.5) | Full strength always |
| 7 | Tooltips appear on mouse-over only | Keyboard users never see them | Radix Tooltip, on focus too |
| 8 | Card headers show on hover and selection only | Not on keyboard focus inside the card | Also on `focus-within` |
| 9 | "Delete permanently" confirmed with the primary (accent) button | Accent on a destructive act | The `danger` variant |
| 10 | Two large dialog sizes (picker 1120×740, "Open larger" 1040×720) | Two sizes for one job | One `xl` fixed size |
| 11 | Off-grid sizes: card tools 30px, Link profile pills 26px, table thumbnails 48×34, Link profile font written as `12px` literal, chart labels 11px | Off the 4px grid; 11px is below the 12px floor | 28px, 24px, 48×32, `text-caption`, 12px |
| 12 | Hint line-height 1.45 | A seventh line-height | 1.5 |
| 13 | Native `<select>` and `<input type=checkbox>` with `accent-color` | Differs per browser; today's code uses Radix | Radix Select and Checkbox |
| 14 | Monospace from the system stack | Plex Mono is installed and used | Plex Mono |
| 15 | Form pages: one plugin's 760px column centred, another's left-aligned; a Save in each panel | Two layouts, Saves per panel | One form column, left-aligned; Save in the form footer (4.12). **Resolved** in the joining pass: one save bar, the plugin's own Save removed |
| 16 | "Soon" tags in the sidebar, disabled "Later" Crop/Rotate/Flip | "Soon" is a product tag (I-3408); "Later" is a design-review device | "Soon" ships as 4.32 and 4.46 say; "Later" never ships (4.37). **Resolved** (I-3408) |
| 17 | Hard-coded colours on the tile type label, the tick, the focal marker, the embed play button, the preview site | Raw colours | Tokens `--photo-scrim`, `--on-photo`, `--paper`; the preview is the theme's own rendering |
| 18 | `data-theme` on `<html>` | Today's code uses the `.dark` class from the appearance cookie | Keep the code's mechanism |
| 19 | Warning dots at 2.75:1 (2.16 after the hue move) used beside icons in Checks | An icon carrying meaning needs 3:1 | Warning icons use `--warn-ink`; the solid is dots only |
| 20 | The Tools launcher's footer counts "{n} tools from {n} plugins" with one number | The two counts differ | "{n} tools from {m} plugins" |

### 12.3 Reference design against older documents and today's code

| # | Older document or code | Reference design (approved) | This system |
|---|---|---|---|
| 21 | `admin-design.md` rule 3 and 5: panels have no radius and no shadow | Panels are white cards lifted by a soft shadow, radius 12 | The reference design; `admin-design.md` to be superseded by this file for the new interface |
| 22 | `admin-design.md` rule 2: the accent does two jobs only | The accent also marks selection, the current entry, badges, charts | The reference design |
| 23 | `admin-design.md`: links blue (`--link`), selection blue (`--selected`) | One accent for links and selection | The reference design |
| 24 | `admin-design.md`: 13px base, 11px labels, five sizes; controls 34/28; rows 44; sidebar 240/56; top bar 52; rail 360 | 15px base, 12px floor, six sizes; controls 36/32; rows per I-3399 and I-3404; sidebar 264/68; top bar 60; rail 344 | The reference design (the rail stays resizable 320 to 480, as built) |
| 25 | `admin-design.md`: the editor is a labelled form field; serif headings in the body | The editor is a page; headings in sans | The reference design |
| 26 | `admin-design.md`: labels sentence case, never tracked or uppercase | Uppercase tracked captions for sidebar groups, menu group labels, the card's type name | Allowed for those three only (2.8) |
| 27 | `admin-design.md` and the built pager: "Newer" / "Older" | Numbered pages | Numbered pages |
| 28 | Built `list/screen-options.tsx` "Screen options" | "Columns" chooser with order | "Columns" (a name borrowed from another system is dropped, P4) |
| 29 | Built Settings layout: its own side menu and a sticky "Save {page} settings" bar | No second menu (hub sub-entries and a crumb switcher); Save after the fields | Crumb switcher; Save in the form footer, which sticks when the form is long (4.12). Partly resolved by the joining pass (one save bar, Find a setting, 4.42); the side list is in 12.4 |
| 30 | `plugins.md`: dashboard panel sizes "figure, half, full" | S, M, L, Full (4, 6, 8, 12 of 12) | S, M, L, Full; `plugins.md` to be updated |
| 31 | Built default look: Dark chrome sidebar (I-198) | A light sidebar on `--chrome` | Light; looks removed. **Resolved** (I-3404) |
| 32 | `accessibility.md` rule 9: "row actions go in the Actions menu, never small always-shown buttons" | Always-shown text links under the title (I-3398) | The decision: the links are 24px tall text actions, which meet 2.5.8; rule 9 to be re-worded with I-3398 |
| 33 | Built toast durations 6s / 10s with an action | 4.2s / 9s | The built values (they were decided for reading time) |
| 34 | Built `bg-black/80` scrim | `--scrim` at 38% (62% dark) | `--scrim` |
| 35 | `SharedControlsTest` asserts 44px rows, 34px buttons, flat panels | Different values | The test is rewritten in step 1b, as it encodes the old design |

### 12.4 Found while updating (2026-11-13)

Between this document and the reference design's joining pass. Not resolved: each needs a decision on which side wins.

| # | This document | The reference design | To decide |
|---|---|---|---|
| 36 | 4.12: Save is a footer bar on `--page` with `shadow-raised` | The shared save bar is a sticky inverse (ink) bar, radius 12, 16px above the bottom of the view, appearing on the first change | Which look is Teme's |
| 37 | 4.40: the Progress bar is 6px tall | `.progress` is 8px (`--s-2`) | 6 or 8 |
| 38 | 4.44 (written now) takes 420 from the reference design's stylesheet; the old text said 400 | 420 | Confirm 420 |
| 39 | 5 and 12.3 #29: no second side menu in Settings | The shared settings layout has a side list and "Find a setting" | **Resolved** by the approved v10 design: the side list stays, with the current page's sections (4.42) |
| 40 | 4.39: Checks is a rail section; 6.1: a plugin's Check is "a line in the editor's Checks" | I-3415: checks sit inside the Search and social box, after the fields | Whether Checks is one list, or two (the article's and the box's) |
| 41 | 4.26: the reference design sets table rows by padding, not a fixed height | Rows are 72 or 92 only by arithmetic | Whether `--row-h` is enforced as a min height or left as content |
