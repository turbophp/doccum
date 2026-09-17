# doccum shell — design plan

Date: 2026-09-17
Status: Proposed
Covers: the Files three-pane shell (tree, list, detail), the topbar, and the
token system every other page inherits. Implements spec §10 and ledger items
`item/topbar-shell` and `item/files-three-pane`.
Does not cover: Home, Settings and Trash page layouts (they take the tokens and
components from here; their own layouts are separate, smaller plans).

This is a plan, not code. Every value in it is meant to be typed into
`app.css`, a Blade file or a `motion` call without further interpretation.

---

## 0. How I read the brief

doccum is a records system, not a file-sync client. Its users file and
retrieve all day and are paid to get custody right. That changes what "on par
with Drive and Dropbox" means: match their fluency (drag, right-click, keyboard,
instant response), not their mood (friendly colour, roomy rows, illustrated
empty states). Drive is a consumer product wearing a work shirt; doccum is a
work tool and should look like one.

The material that is doccum's alone: a file **belongs to a period**, the period
may be **archived** (read-only, awaiting retention), the file may be under
**legal hold**, it has **versions**, and its text was **extracted** or was not.
None of that exists in Drive. It is where the design should spend its
character, so that the shell explains custody at a glance instead of merely
listing names.

"Colours efficiently" I take literally: the shell is grey, and the three hues
that exist each mean exactly one thing. If a colour appears, it is information.

Principles the rest of this document follows:

1. **Quiet means fine.** A row with no glyphs and no colour is a healthy,
   extracted, open-period file. Only exceptions are marked.
2. **Colour is a signal, never a style.** Blue is selection. Red is hold.
   Amber is "needs attention". Nothing else is coloured. There is no green.
3. **The list wins.** When space is tight, the tree and the detail pane give
   way; the list never shrinks below a usable table.
4. **Motion answers an action or shows a real change.** Nothing moves on page
   load. Nothing moves on hover.
5. **Explain refusals.** A records system says why something is not allowed,
   inline, in the menu, in plain words.

---

## 1. Palette

Two neutral ramps (light and dark) of five values each, and three semantic
hues. That is the entire palette.

### Base values

| Token | Light | Dark | Role |
|---|---|---|---|
| `sheet` | `#FFFFFF` | `#1F2327` | List surface, detail pane, menus, modals, inputs |
| `chrome` | `#EEF0F2` | `#181B1F` | Topbar, tree pane, status bar, period bands, toolbar |
| `rule` | `#D3D8DE` | `#30363D` | Hairlines: pane dividers, column header rule, band rule, `kbd` border |
| `ink-2` | `#5B6470` | `#9AA3AD` | Secondary text and glyphs: size, dates, owner, disabled items, archived lock |
| `ink` | `#1F252B` | `#E8EBEE` | Primary text, primary button fill, icons, focus-free borders on buttons |

Contrast, measured: `ink` on `sheet` 15.5:1 (light) / 13.2:1 (dark); `ink-2`
on `sheet` 6.0:1 / 6.2:1; `ink-2` on `chrome` 5.2:1 / 5.6:1. All pass AA for
the 12px secondary text.

Dark mode is graphite, not black. The darkest value anywhere is `chrome`
`#181B1F`, and it is visibly grey next to a black bezel. Pure black is not used
either. Both themes share the same hue family (a very slight cool cast) so a
screenshot in one theme is recognisably the same product as the other.

### Semantic values

| Token | Light | Dark | Means, and only means |
|---|---|---|---|
| `select` | `#2B5FD9` | `#7AA2FF` | This is selected, focused, or is the current drop target |
| `hold` | `#C42B1F` | `#FF7A70` | This file is under legal hold |
| `attention` | `#A05A00` | `#E8A33C` | Something failed and a person should look: extraction failed, upload failed |

Derived from `select`, used as backgrounds (computed with `color-mix(in oklab,
var(--select) N%, var(--sheet))`; the hex is the approximate result for
mock-ups):

| Use | Light | Dark |
|---|---|---|
| Selected row background (10% / 16%) | `#EAEFFB` | `#2B3446` |
| Drop target row background (12% / 18%) | `#E6ECFA` | `#2D3749` |
| Focus outline | `select` at 100%, 2px | same |

Contrast, measured: `select` on `sheet` 5.6:1 / 6.4:1; `hold` on `sheet`
5.7:1 / 6.2:1; `attention` on `sheet` 5.3:1 / 7.3:1. All three are usable as
text and glyph colour at 12px.

### What each colour may mean

- **`select`** — the selected row background, the focus outline, the checked
  state of a row checkbox, the ring on a drop target, the underline under the
  active tab in the detail pane, the caret and ring on inputs. It is never
  used on a button, a link, an icon or a heading.
- **`hold`** — the hold glyph in the state column, the hold tick on the period
  spine, the hold band at the top of the detail pane, the "Under hold" reason
  in a disabled menu item, the hold count in the status bar. Nothing else.
  Notably: **red is not danger.** "Move to trash" is a plain menu item and a
  plain `ink` button. Destruction in doccum is expressed by words and
  confirmation, not colour, so that the one thing red says is "preserve this".
  This is a deliberate risk and it is right for a records system: a hold is
  the single state that must be impossible to miss, and it is the state
  Drive-shaped tools have no vocabulary for.
- **`attention`** — the extraction-failed glyph, a failed upload's row and
  tray entry, a failed property validation message. Not warnings, not
  "pending", not "unsupported".

### What is not allowed to carry colour

File-type glyphs. Folder glyphs. The tree. Avatars (initials in `ink` on
`rule`). Buttons (primary is `ink` fill with `sheet` text; secondary is `sheet`
with a `rule` border). Links (`ink`, underlined on hover only). Headings. The
version pill and any other badge (`chrome` fill, `rule` border). Toasts (a
successful move is shown by the row leaving, and the toast is grey with an
Undo). Success of any kind. Pending or processing extraction (hollow `ink-2`
glyph). Unsupported extraction (`ink-2` glyph). Archived (lock glyph in
`ink-2`, hatched band, no colour). Trashed (`ink-2` text, no colour). Empty
states. Charts on Home, when they exist (`ink` at graded opacity).

Flux mapping: keep `--color-accent` bound to `ink` as it is today, so Flux's
primary buttons stay grey. Add the three semantic tokens beside it and override
Flux's focus ring and checkbox-checked colour to `select` in `app.css` (the
project already overrides the control focus ring there).

---

## 2. Type

### Family

**Public Sans**, weights 400, 500, 600, loaded with
`bunny('Public Sans', { weights: [400, 500, 600] })`. One family, no second
face. Verified: Bunny Fonts serves it (18 styles, OFL-1.1) and the USWDS
project that made it names tabular figures as a design principle of the face.

Why change from Instrument Sans: Instrument Sans is a display-leaning grotesque
with no tabular-figure feature, so a column of sizes and dates set in it
wobbles by a pixel or two per row, which is exactly what a file manager cannot
afford. Public Sans was drawn for US government forms and tables — dense
compliance content — and reads as a work face rather than a landing-page face.
It disambiguates `1`, `l` and `I`, and it is not the face I would reach for on
any other project (Inter, Geist, Instrument), which is the point.

Not used: a monospace face. Numbers align because the sans has tabular figures,
so there is no functional reason for mono, and a decorative one would be a
tell.

### Scale

| Step | Size / line | Weight | Used for |
|---|---|---|---|
| 11 | 11 / 16 | 400, 500 for `kbd` | Status bar, band metadata, keyboard hints, disabled-item reasons, version pill |
| 12 | 12 / 16 | 400 | Secondary cells (size, modified, owner, period), detail-pane field labels, column headers |
| 13 | 13 / 20 | 500 names, 400 body | Row names, tree items, menu items, buttons, inputs, tabs. The default UI size |
| 14 | 14 / 20 | 400 | Detail-pane body, extracted-text preview (max 72ch), modal body |
| 16 | 16 / 24 | 600 | Detail-pane title (the file name), modal titles |
| 20 | 20 / 28 | 600 | Page titles on Home / Settings / Trash. Not used inside the shell |

Letter-spacing is 0 at every step except 11, which gets `+0.01em`. No
all-caps anywhere, no tracked labels. Column headers are 12/400 sentence case.

### Numerals

- Every numeric cell and counter has `font-variant-numeric: tabular-nums;`
  (with `font-feature-settings: "tnum"` as the fallback for engines that need
  it). One utility class, `.num`, applied to: size, modified, period, version,
  owner is text so not `.num`, status-bar counts, byte totals in bands.
- Sizes are right-aligned, two significant digits, unit after a thin space:
  `48.2 MB`, `912 KB`, `3.0 GB`. Folders show `—`.
- Dates in columns are fixed-width ISO so digits stack: `2026-09-17 14:02`. At
  narrower widths the column shortens to `09-17 14:02`, then to `09-17`.
  Relative times ("3 minutes ago") appear only in the detail pane and toasts,
  where nothing needs to align.
- Periods are `2026-09` in cells and "September 2026" in band headers.
- Versions are `v3`, right-aligned.

---

## 3. Layout

Full-width, no page container, exactly as spec §10 says. One 44px topbar; below
it the three panes fill the viewport height. Pane widths persist per user
(a small settings write when a resize handle is released).

| Region | Default | Range | Persisted |
|---|---|---|---|
| Topbar | 44px tall | fixed | — |
| Tree pane | 240px | 200–360, drag handle on its right edge | yes |
| List pane | remaining | never below 480px | — |
| Detail pane | 336px | 288–480, drag handle on its left edge | yes |
| List toolbar (breadcrumb row) | 36px tall | fixed | — |
| Column header | 28px tall | fixed | — |
| Status bar | 28px tall | fixed | — |

### Desktop (≥ 1280px)

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ doccum v0.1.0      [ Search names, properties and text            / ]   Home  Files  Settings  (AK) │ 44
├──────────────┬───────────────────────────────────────────────────────────┬───────────┤
│ Home         │ Files › Finance › Contracts          New ▾   Sort ▾   ⋮   │ Master S… │ 36
│ ▾ Shared     │ ▢  Name                 State  Period   Owner   Modified  Size  Ver│ 2026-09   │ 28
│   ▾ Finance  ├─┬─────────────────────────────────────────────────────────┤ v3, hold  │
│     Contracts│ │ September 2026                                  open   │───────────│
│     Invoices │ │ ▢ ▤ Master services agreement.pdf   ⊘   2026-09  A. K  09-17 14:02  1.2 MB  v3│ ┌───────┐ │
│   ▸ Legal    │ │ ▢ ▤ Schedule B.docx                  ⏲  2026-09  A. K  09-16 09:40  312 KB v1│ │ HOLD  │ │
│   ▸ HR       │ │ ▢ ▤ Countersigned (scan).pdf        △   2026-09  M. R  09-15 17:11  9.8 MB v1│ └───────┘ │
│ ▸ Archive    │ ┃ August 2026                    archived 2 Sep, 41 files│ Properties│
│              │ ┃ ▢ ▤ Q2 board minutes.pdf           🔒  2026-08  A. K  08-30 11:00  640 KB v2│ Versions  │
│              │ ┃ ▢ ▤ Vendor list.xlsx               🔒  2026-08  M. R  08-12 16:22  88 KB  v1│ Text      │
│              │ ┃ ▢ ▤ Lease amendment.pdf         ⊘ 🔒  2026-08  A. K  08-03 10:05  2.1 MB v4│ Activity  │
│              │ │                                                         │           │
│              │ │                                                         │ Client    │
│              │ │                                                         │  Acme Ltd │
│              │ │                                                         │ Signed    │
│              │ │                                                         │  Yes      │
│              ├─┴─────────────────────────────────────────────────────────┤           │
│              │ 6 files, 1 under hold, 3 in archived period          14.1 MB   │ [Save]    │ 28
└──────────────┴───────────────────────────────────────────────────────────┴───────────┘
  240                          flex (min 480)                                   336
```

Legend for the ASCII only: `⊘` hold glyph (red), `⏲` extraction pending
(hollow grey), `△` extraction failed (amber), `🔒` archived (grey). The
narrow column left of the rows is the period spine (§7): a hairline for the
open period, a 2px solid line for the archived one, and a red tick beside each
held file.

Alignment: everything is left-aligned except numeric cells (right) and the
topbar's navigation (right). No centred content anywhere in the shell.

The topbar, per spec: lowercase wordmark `doccum` set in 13/600 type followed
by the version pill (11px, `chrome` fill, `rule` border, `ink-2` text); a
search field of at most 360px that `/` focuses; then **Home**, **Files**,
**Settings** (hidden without `users.manage`, `properties.manage` or
`periods.manage`) and the account avatar. Nothing else. The breadcrumb lives in
the list pane's toolbar, not the topbar, so the topbar stays the same on every
page.

### Tablet (768–1279px)

```
┌────────────────────────────────────────────────────────────────┐
│ ☰  doccum v0.1.0   [ Search                 / ]   Files  (AK)  │ 44
├────────────────────────────────────────────────────────────────┤
│ Files › Finance › Contracts              New ▾   Sort ▾   ⋮    │ 36
│ ▢  Name                          State   Modified      Size    │ 28
│ │ September 2026                                        open    │
│ │ ▢ ▤ Master services agreement.pdf   ⊘   09-17 14:02   1.2 MB │
│ │ ▢ ▤ Schedule B.docx                 ⏲   09-16 09:40   312 KB │
│ ┃ August 2026                    archived 2 Sep, 41 files       │
│ ┃ ▢ ▤ Q2 board minutes.pdf          🔒   08-30 11:00   640 KB │
│                                                                │
│ 6 files, 1 under hold                                  14.1 MB │ 28
└────────────────────────────────────────────────────────────────┘
   ☰ opens the tree as a 280px slide-over from the left.
   Selecting a file opens the detail pane as a 360px slide-over from the
   right, over the list, no scrim; Esc or a click in the list closes it.
```

Between 1024 and 1279 the tree stays docked and only the detail pane becomes a
slide-over. Below 1024 both are slide-overs. The list never shares width with
a pane that would push it under 480px; that is the rule that decides when a
pane detaches.

### Phone (< 768px)

```
┌──────────────────────────────┐
│ ☰   Contracts ▾          🔍  │ 48
├──────────────────────────────┤
│ New ▾                 Sort ▾ │ 36
│ September 2026          open │
│ ▤ Master services agreem…  ⋯ │
│   ⊘  1.2 MB   17 Sep         │ 48 (two lines)
│ ▤ Schedule B.docx          ⋯ │
│   ⏲  312 KB   16 Sep         │
│ August 2026   archived 2 Sep │
│ ▤ Q2 board minutes.pdf     ⋯ │
│   🔒 640 KB   30 Aug         │
│                              │
├──────────────────────────────┤
│ 6 files, 1 under hold  14 MB │ 28
└──────────────────────────────┘
  ☰ opens the tree as a full-screen sheet from the left.
  The breadcrumb collapses to the current folder; tapping it opens the
  ancestor path as a menu. Tapping a row opens the detail pane as a
  full-screen page pushed from the right. ⋯ opens the row's actions as a
  bottom sheet (there is no right-click on touch).
```

What collapses, in order, as width shrinks: detail pane detaches → tree
detaches → columns drop (§4) → row goes two-line → period spine gutter drops
(bands stay) → breadcrumb collapses to one segment → topbar nav folds into the
account menu.

What always wins: the list, its toolbar, its status bar, and the search field
(as a field down to 1024px, as an icon below).

---

## 4. Density

### Row height

| Mode | Height | Notes |
|---|---|---|
| Compact | 28px | For operators who file all day; 12px names |
| **Default** | **32px** | 13px names, 20px line, 6px vertical padding |
| Comfortable | 40px | Touch-friendly on desktop touchscreens |
| Phone | 48px | Two-line, fixed; density setting ignored |

Persisted per user. Reachable from the toolbar's `⋮` menu and the empty-space
context menu.

Compared: Finder's list view is about 24px per row with 16px icons and 13px
text — as dense as a table can be before glyphs collide. Drive is 48px with
24px icons, 14px text and 12px of padding — comfortable, and roughly half the
rows per screen. doccum's row carries a state column Finder does not have and
multi-select checkboxes Drive does not have, so 32px is the height at which a
16px glyph, a 16px checkbox and 20px of text sit on one baseline with 6px of
air. It fits about 22 rows in a 768px-tall list against Drive's 14 and
Finder's 30.

### What is in a row (default, ≥ 1280px)

```
 8  24    20   flex (min 160)              96     64      128     128            72    40   28  8
│  │ ▢  │ ▤ │ Master services agreement.pdf │ ⊘ 🔒 │ 2026-09 │ A. Khan │ 2026-09-17 14:02 │ 1.2 MB │ v3 │ ⋯ │  │
```

| Cell | Width | Content | Visibility |
|---|---|---|---|
| Checkbox | 24 | 16px box | Shown on row hover, when the row is selected, or when any row is selected (Dropbox behaviour) |
| Type glyph | 20 | 16px monochrome outline: document, spreadsheet, image, folder, archive, other | Always |
| Name | flex, min 160 | 13/500 `ink`; truncates with ellipsis at the end (extension preserved by truncating the stem) | Always |
| State | 96 | Up to three 14px glyphs, fixed order: hold (`hold`), extraction (`attention` if failed; hollow `ink-2` if pending or processing; `ink-2` "no text" if unsupported; **nothing if done**), archived lock (`ink-2`) | Always, narrows to one glyph |
| Period | 64 | `2026-09`, `.num` | ≥ 1280 |
| Owner | 128 | Creator's display name, truncated | ≥ 1440 |
| Modified | 128 | `2026-09-17 14:02`, `.num` | Always, shortens |
| Size | 72 | Right-aligned, `.num`; `—` for folders | Always |
| Version | 40 | `v3`, `.num`; blank for folders | ≥ 1280 |
| Row menu | 28 | `⋯` button | On hover and focus; always on touch |

Folder rows use the same template. The size cell shows `—` rather than an item
count: recursive counts per row are a query per row, and the status bar and
detail pane already carry counts for the thing you selected.

### Degradation by width

| Width | Change |
|---|---|
| < 1440 | Owner cell drops (still in the detail pane) |
| < 1280 | Version and Period cells drop; the period band header carries the period |
| < 1024 | State narrows to one glyph, highest priority wins: hold > failed > archived > pending; Modified shortens to `09-17 14:02` |
| < 900 | Modified shortens to `09-17` |
| < 768 | Two-line row: line 1 name + `⋯`; line 2 state glyph, size, `17 Sep` |

Column visibility is also user-controllable (empty-space context menu → Show),
but the width rules above are the defaults and always apply on top.

---

## 5. Motion language

Twelve named motions. Each is bound to one action or one real change; nothing
else in the shell moves. All springs are `motion` v13 `animate(el, keyframes,
{ type: "spring", ... })` calls; all fixed-duration transitions are CSS. One
helper, `move(el, keyframes, options)`, wraps every call and consults
`matchMedia('(prefers-reduced-motion: reduce)')`.

| # | Name | Trigger | Definition | Why it exists |
|---|---|---|---|---|
| 1 | **Select** | Click, arrow key, Space | **No animation.** Background changes on the same frame | Selection is a hardware-feeling act; a fade makes a fast filer feel the UI is behind them |
| 2 | **Reveal** | Detail pane opens or closes; tree docks or undocks | Width: spring stiffness 420, damping 38, mass 1 (settles ≈ 260ms, no visible overshoot). Content opacity 0→1 over 120ms linear, starting 40ms after the width begins. On close, content opacity → 0 over 80ms first, then width | Shows where the pane came from and where it went |
| 3 | **Unfold** | Tree node expands / collapses; a row appears (upload, restore, new folder); a period band group expands | Height: spring stiffness 520, damping 42 (≈ 200ms), `overflow: hidden`; chevron rotates 0→90° over 120ms `cubic-bezier(0.2, 0, 0, 1)`; text opacity 0→1 over 120ms after height starts | Preserves the reader's place in a list that just changed length |
| 4 | **Fold** | A row leaves: moved, trashed, restored elsewhere; a band group collapses | Height 32→0 and opacity 1→0 over 160ms `cubic-bezier(0.4, 0, 0.2, 1)`. Rows below move up as layout, not as a second animation | Confirms exactly which rows went |
| 5 | **Lift** | Drag begins (after 4px of movement) | A custom ghost (row clone, not the native drag image): scale 1→1.02 and shadow `0 6px 16px rgba(31,37,43,0.18)`, spring stiffness 600, damping 30 (≈ 180ms, slight overshoot). Multi-drag: up to three clones offset 3px each with a count in `ink` on `sheet` | Tells the hand it has picked something up |
| 6 | **Accept** | Ghost enters a valid target | Target row: 2px inset ring in `select`, background to the drop tint, 80ms ease-out; folder glyph swaps to its open variant on the same frame. Leaving: 60ms. Invalid target: nothing changes, cursor `not-allowed`, status bar states the reason | The only feedback that matters in a drag: "here is where it will go" |
| 7 | **Settle** | Drop on a valid target | Ghost springs to the target row's centre, scale → 0.6, opacity → 0: stiffness 700, damping 40 (≈ 160ms). Then the source rows **Fold** | Closes the loop the Lift opened |
| 8 | **Stamp** | Legal hold placed | Hold glyph: scale 1.6→1.0, opacity 0→1, spring stiffness 800, damping 22 — a deliberate overshoot, the loudest motion in the system. Detail-pane hold band: height 0→28px, same spring. **Lifting a hold**: glyph opacity 1→0 over 120ms, no spring | Imposing a hold should feel like an act; releasing one should be quiet. The asymmetry is the meaning |
| 9 | **Menu** | Context menu, dropdown, submenu opens | Opacity 0→1 and scale 0.97→1 with the transform origin at the cursor corner, 90ms `cubic-bezier(0.2, 0, 0, 1)`. Close: opacity only, 60ms | Anchors the menu to the click |
| 10 | **Sheet** | Phone / tablet: tree, detail or actions sheet opens | Translate: spring stiffness 380, damping 36 (≈ 280ms). Backdrop opacity 150ms | Same Reveal idea at a size where width cannot animate |
| 11 | **Progress** | Upload in flight; extraction status changes | 2px bar along the row's bottom edge in `ink` (not `select` — blue would claim the row is selected), width follows real bytes with a 200ms linear tween between progress events. Row sits at 60% opacity, goes to 100% over 120ms on completion; bar fades over 200ms. Extraction glyph swaps (pending → nothing, or → failed) with a 120ms opacity crossfade when the Livewire poll or event lands | Data, not decoration: it shows the actual bytes |
| 12 | **Confirm** | Toast appears (move, trash, hold, restore — each with Undo where reversible) | translateY 8→0 and opacity 0→1 over 140ms ease-out; dismiss after 6s or on action, 100ms opacity | Names what happened in the same words as the menu item that did it |

Explicitly forbidden: row hover transitions (background changes instantly);
staggered entrances on page load; skeleton shimmer (static `rule`-coloured
blocks instead); pulsing on pending glyphs; springs on opacity; parallax;
transitions on colour-scheme change.

### Reduced motion

Under `prefers-reduced-motion: reduce`, `move()` short-circuits: Reveal,
Unfold, Fold, Lift, Settle, Stamp and Sheet become instant state changes; Menu
and Confirm keep an opacity-only 60ms fade; Progress remains, because it is
information; Accept remains, because a drop target with no indication is
unusable. CSS transitions use Tailwind's `motion-reduce:transition-none`.

### Livewire choreography

Alpine drives the motion before the round trip; Livewire reconciles after.
Trash: Alpine folds the rows, then calls `$wire.trash(ids)`; the response
morphs the list, which no longer contains them, so nothing jumps. On failure
the rows Unfold back in and a toast explains. Move, hold and restore follow the
same optimistic pattern. `wire:transition` is not used; it is too coarse for a
table.

---

## 6. Interaction

### Selection

- Click selects one and clears the rest. Ctrl/Cmd-click toggles. Shift-click
  extends a range from the anchor. Clicking a row's checkbox toggles without
  clearing others. Click on empty list space clears. Esc clears.
- Ctrl/Cmd-A selects everything in the current listing.
- Once any row is selected, every row's checkbox is shown, and the toolbar's
  right side is replaced by the selection summary "3 selected" followed by two
  buttons, "Move to…" and "Move to trash".
- Selection survives a sort change and column change, and clears on
  navigation.
- No marquee (drag-select) in v1. Shift-range and Ctrl-A cover the cases.

### Keyboard

The list is `role="grid"` with one row per `role="row"` and a roving
`tabindex`; the tree is `role="tree"`. Focus is a 2px `select` outline drawn
inset (`outline-offset: -2px`) so it never clips on the pane edge. A row can be
focused and not selected, or selected and not focused; the two are visually
distinct (outline vs background).

| Key | List | Tree |
|---|---|---|
| ↑ ↓ | Move focus | Move focus |
| Shift+↑ ↓ | Extend selection | — |
| Space | Toggle selection | — |
| Enter | Folder: open. File: open detail pane and move focus to it | Open folder in list |
| Ctrl/Cmd+Enter | Download | — |
| ← → | — | Collapse / expand; → on a leaf moves to first child |
| Backspace, Alt+↑ | Go to parent folder | — |
| F2 | Rename inline | Rename inline |
| Delete (Cmd+Backspace on mac) | Move to trash, with Undo toast | Move folder to trash |
| Ctrl/Cmd+A | Select all | — |
| Esc | Clear selection / close menu / close detail | Close menu |
| Shift+F10, Menu key | Open context menu at the focused row | Same at the focused node |
| `/` | Focus search (from anywhere) | |
| `?` | Shortcuts overlay | |
| Tab order | Topbar → tree → list toolbar → column header (sort buttons) → list → status bar → detail pane | |

Copy / cut / paste move (Finder-style) is not in v1.

### Right-click

Flux free ships no `flux:context`, so the context menu is hand-built: an
Alpine-controlled popover positioned at the pointer (or at the focused row for
keyboard), containing a stock `flux:menu`, so items, separators, submenus and
arrow-key navigation come from Flux. It flips to stay on screen. It is
`role="menu"`. On touch, the same content renders as a bottom Sheet from the
row's `⋯` button or a 400ms long-press.

Right-clicking a row that is not in the selection selects it alone first.
Right-clicking a row that is in a multi-selection keeps the selection and opens
the multi-selection menu.

Two rules decide what appears, and they map onto doccum's two authorisation
layers:

- **Hidden** if the viewer's *role* can never do it (the Spatie layer). A plain
  member never sees "Place legal hold".
- **Disabled with a reason** if this *directory or file* forbids it right now
  (the `directory_access` layer, or the file's state). The reason is 11px
  `ink-2` text right-aligned in the item: "No edit access here", "Period
  archived", "Under hold". A records system shows what it is refusing.

Keyboard hints sit right-aligned in `kbd` (11/500, `rule` border, 3px radius).

**File row**

```
Open                              Enter
Download                    Ctrl+Enter
Rename                               F2
Move to…
──────────────────────────────────────
Replace with new version…        (disabled: Period archived)
Versions (3)
Properties
──────────────────────────────────────
Place legal hold                 (periods.manage only; or "Lift legal hold")
──────────────────────────────────────
Move to trash                       Del
```

**Folder row**

```
Open                              Enter
Rename                               F2
Move to…
New folder inside
Upload here…                     (disabled: No upload access here)
──────────────────────────────────────
Properties
──────────────────────────────────────
Move to trash                       Del
```

**Empty space**

```
New folder
Upload files…
──────────────────────────────────────
Select all                       Ctrl+A
Sort by                             ▸   Name / Modified / Size / Period (radio)
Show                                ▸   Period / Owner / Version (checkboxes)
Density                             ▸   Compact / Default / Comfortable (radio)
```

**Multi-selection** (header line: "3 files, 1 folder")

```
Move to…
Place legal hold on 3 files      (periods.manage only)
──────────────────────────────────────
Move 4 items to trash               Del
Clear selection                     Esc
```

### Drag and drop

Sources: list rows (single or multi), tree nodes. Targets: folder rows, tree
nodes, breadcrumb segments (Finder allows this; it is how you move something
up two levels without opening the tree), and the list background when the
source is the desktop.

**Row onto folder.** Native HTML5 drag events with `setDragImage` pointed at
an empty element, so the custom ghost (Lift) is the only thing that moves.
Hovering a valid folder row for 600ms while dragging opens it in the list
(spring-loaded folders); hovering a collapsed tree node for 600ms expands it.
Dragging within 24px of the list's top or bottom edge auto-scrolls. Invalid
targets: the item itself, its own descendants (cycle), a folder where the
viewer lacks edit access, and any target when the file's own period is
archived if the backend refuses that write — the menu and the drop follow the
same rule, so a user never discovers a refusal only on drop.

**What the drop target looks like.** A 2px inset ring in `select` around the
row (or tree node, or breadcrumb segment), the row background at the drop
tint, the folder glyph in its open variant, and the status bar reading "Move
3 items to Contracts". Nothing dashed, nothing pulsing, no scale.

**Files from the desktop.** On `dragenter` of the list pane, the whole pane
becomes the target: a 2px inset ring in `select` around the list, the list
under it dimmed to 96% via a `sheet` overlay, and a centred label in 14/500
`ink`: "Drop to upload into Contracts", with a 12/400 `ink-2` second line
"Or drop on a folder to upload there". Folder rows under the cursor still
Accept individually and win over the pane. If the current folder is in an
archived period, the overlay label becomes "This period is archived. Uploads
are rejected." in `ink-2`, and the drop is a no-op. Same for a folder the
viewer cannot upload into: "No upload access here".

On drop, each file gets a row immediately (Unfold, 60% opacity) with a
Progress bar; uploads go through Livewire's `WithFileUploads`. An Upload tray
(320px, bottom right of the list pane, `sheet` on `rule` border) lists
in-flight items with a cancel each and collapses to a one-line summary when
everything finishes; failed uploads stay in the tray in `attention` with the
reason ("Larger than 100 MB", "Period archived") and a Retry.

Touch has no drag-and-drop. "Move to…" in the actions sheet opens a Move
picker (modal with the tree) instead.

---

## 7. The one bold move: the period spine

Every file in doccum belongs to a period that was fixed at creation and never
moves. Periods close, become read-only, age through a retention window, and
are purged as a unit. Holds live inside them. That structure is the product,
and no file manager shows it. So the list shows it.

**What it is.** The default sort is by period, then modified, newest first.
Rows are grouped under **period bands**: 28px rows in `chrome`, sticky within
their group as you scroll, reading "September 2026" in 12/500 `ink` at left
and, at right in 11/400 `ink-2`, the period's status — nothing for an open
period; for an archived one, "Archived 2 Sep 2026" with a lock glyph, and the
`file_count` and `byte_count` the closer recorded ("41 files, 2.3 GB"). The
band for an archived period carries a hatched 1px pattern along its bottom
rule, the only texture in the whole interface.

Left of the rows runs a **20px gutter, the spine**. Down it, one vertical line
per period spans exactly that period's rows: a 1px `rule` hairline for an open
period, a 2px solid `ink-2` line for an archived one. Beside every file under
legal hold the spine gets a 2px-wide, row-height tick in `hold` red. Scrolling
a long folder, the spine reads as a minimap of custody: how much of this
folder is still open, how much is locked, where the holds sit. Clicking a band
folds its group; the spine segment collapses with it.

When the user sorts by name or size, bands and the spine's period lines go
away — grouping would fight the sort — but the hold ticks stay, because a hold
must be visible in every ordering. Below 1024px the gutter drops and the bands
remain.

**Why this and not something else.** The brief's distinctive material is
state that lives on a time axis: period, archive, retention, hold. A colour
scheme or a typeface cannot carry that; a structural device can. Grouping is
within the genre (Finder groups by date; Drive's Recent view groups by "Earlier
this week"), so a filer will read it without instruction, but doccum groups by
the unit its storage, archiving and purging actually operate on, which makes
the grouping true rather than cosmetic. And it turns "this period is archived"
from a tooltip somebody might hover into a shape you cannot scroll past.

**What was kept plain to pay for it.** The topbar is type only. The tree has
no icons except chevrons. File-type glyphs are a single-weight monochrome set;
there are no thumbnails in rows (the detail pane shows one). No hover
elevation, no card, one shadow in the whole system (the drag ghost and menus
share it). Buttons are 4px-radius rectangles in two variants. Empty states are
one sentence and one button, no illustration. The detail pane is flat
sections separated by hairlines, not stacked cards.

**Risk.** Bands cost 28px per group. In a folder spanning many months of
sparse files, half the screen could be band. Mitigation: groups with a single
file render the band at 24px, and the toolbar's Sort menu has "Modified (no
groups)" one click away, persisted. If usage shows most folders hold one
period, the default flips to ungrouped and the spine keeps working from the
period column. The spine's red ticks and the archived line survive that flip,
which is the part that matters.

---

## 8. Component inventory

### From Flux free (v2.19, verified in `vendor/livewire/flux/stubs`)

| Component | Where it is used |
|---|---|
| `flux:button`, `flux:button.group` | Toolbar (New, Sort), detail-pane Save, modal actions, tray Retry |
| `flux:dropdown` + `flux:menu`, `menu.item`, `menu.separator`, `menu.submenu`, `menu.checkbox`, `menu.radio.group` | Toolbar menus, account menu, and the *contents* of the context menu |
| `flux:modal`, `modal.trigger`, `modal.close` | Move picker, Rename (when inline fails), Shortcuts overlay, Replace-version confirm |
| `flux:tooltip` | Glyph meanings ("Under legal hold since 3 Sep"), truncated names, icon buttons |
| `flux:toast`, `toast.group` | Confirm (already persisted in the layout) |
| `flux:checkbox`, `flux:checkbox.all` | Row checkboxes, column-header select-all, column visibility |
| `flux:input`, `input.file` | Search field, rename field, hidden file input the "Upload files…" item clicks |
| `flux:badge` | The version pill only, grey |
| `flux:breadcrumbs`, `breadcrumbs.item` | List toolbar path (with drop-target behaviour added by a hand-built wrapper) |
| `flux:avatar`, `flux:profile` | Account menu |
| `flux:heading`, `flux:text`, `flux:separator` | Detail pane |
| `flux:field`, `label`, `select`, `textarea`, `radio.group variant="segmented"` | Property forms (exist), density switch, appearance switch |
| `flux:skeleton`, `skeleton.line` | Static loading blocks for list and detail (no shimmer) |
| `flux:icon` (Heroicons) | All glyphs; custom SVGs go in `resources/views/flux/icon/` as the three there today |

Not used: `flux:table`. It cannot host the period spine or bands, gives no
control over row height or drag attributes, and would fight a virtualised list
later. `flux:sidebar` is not used for the tree either; it is navigation
chrome, not a resizable tree with drop targets.

Not in Flux free and therefore hand-built: context menu positioning, tabs,
`kbd`, sheets, command/search results popover, resizable panes.

### Hand-built (Blade + Alpine + `motion`)

| Component | Notes |
|---|---|
| `Topbar` | Replaces both starter layouts; wordmark, pill, search, nav, account. Settings hidden by the three admin permissions |
| `PaneLayout` + `PaneResizer` | CSS grid with two persisted widths; resizer is a 6px hit area over a 1px `rule`; keyboard: focusable, ←/→ nudge 16px |
| `TreePane` + `TreeNode` | Recursive Alpine tree, lazy-loads children from Livewire, Home pinned above Shared per spec; drop target; spring-loaded expand |
| `ListPane` | Hosts Toolbar, ColumnHeader, PeriodSpine, Rows, StatusBar, DropOverlay, UploadTray |
| `Toolbar` | Breadcrumb (drop-target segments), New ▾, Sort ▾, `⋮`; swaps to selection actions when anything is selected |
| `ColumnHeader` | Sort buttons with a `select`-coloured direction caret, `aria-sort` |
| `Row` | The template in §4; `role="row"`, `aria-selected`, `draggable` |
| `PeriodBand` | Sticky group header; archived variant with lock, counts, hatched rule |
| `PeriodSpine` | Gutter lines and hold ticks, positioned from row offsets; recomputed on morph |
| `StateGlyphs` | Hold / extraction / archived, with tooltips and `aria-label`s |
| `StatusBar` | Counts, selection summary, drag reason; is the `aria-live="polite"` region for announcements ("Moved 3 items to Contracts") |
| `SelectionStore` | Alpine store: ids, anchor, focused id; the single source of truth for both list and toolbar |
| `ContextMenu` | Pointer-anchored popover wrapping `flux:menu`; three content variants; flips to stay on screen; Shift+F10 |
| `DragController` + `DragGhost` + `DropOverlay` | Native DnD events, custom ghost, validity rules mirrored from the menu, desktop-file overlay |
| `UploadTray` + `ProgressBar` | Per-file progress, cancel, retry, failure reason |
| `DetailPane` | Header (name 16/600, type, period, `v3`), HoldBand (28px `hold` bar, "Under legal hold since 3 Sep by A. Khan", Lift button for `periods.manage`), Tabs, and the four tab panels |
| `Tabs` | Properties / Versions / Text / Activity; `select` underline; `role="tablist"` |
| `VersionsList` | Rows: `v3`, uploaded by, date, size, Download, "Make current" if that action exists; Replace button |
| `TextPreview` | Extracted text at 14/20, max 72ch, with the extraction status and error message when failed |
| `RenameInline` | Replaces the name cell with an input that selects the stem, not the extension |
| `Kbd` | 11/500, `rule` border, 3px radius, no mono |
| `ShortcutsOverlay` | Modal listing §6's table |
| `MovePicker` | Modal with the tree; the touch and keyboard route to Move |
| `Sheet` | Left / right / bottom sheets for phone and tablet |
| `EmptyState` | One sentence, one action: "Nothing here yet. Drop files or choose Upload." / "No results." (identical for no-match and no-access, as the search view already does) |
| `move()` | The one motion helper; owns reduced-motion |

Where things live: Livewire components under `app/Livewire/Files/` (Browser
stays the root and keeps filtering in the query, never in the view); Alpine
under `resources/js/shell/` (`selection.js`, `dnd.js`, `menu.js`,
`motion.js`); tokens in `resources/css/app.css` under `@theme`. Every action
the shell triggers already exists or is planned as an `app/Actions/*` class
and is authorised by the Livewire component through a Policy, never by the
view or the action.

---

## 9. Second pass: reviewing the plan against the brief

I asked what I would have produced for "a dense file manager, dark and light,
monochrome, springy" without reading the subject, and compared. These parts of
the first draft were that default, and were changed:

1. **Bold move was going to be the red hold.** A monochrome UI where one thing
   is red is the near-black-plus-one-accent pattern with the accent renamed.
   The hold colour stays as a *rule*, but the spend is now the period spine —
   structural, specific to time-partitioned archives, and something Drive
   cannot copy without doccum's data model.
2. **Grouping was "Today / Yesterday / Earlier this month".** That is Drive's
   Recent view. doccum's real partition is the period, so the groups are
   periods and the band carries the period's archive status and counts.
3. **The typeface was IBM Plex Sans.** Excellent tabular figures, but it is
   the reflex for anything that looks like a dev tool. Public Sans has the same
   virtues, is on Bunny, and its provenance (government forms and tables) is
   this product's world.
4. **Sizes and dates were going to be monospace.** That is on the brief's
   reject list and it is unnecessary: tabular figures do the aligning.
5. **The dropzone had a dashed border.** Dashed is the universal dropzone
   glyph and reads as a stock upload widget. It is now a solid 2px inset ring
   in `select`, the same language as every other drop target, plus a sentence
   that names the folder.
6. **Success was going to be a green toast.** Green would have been a fourth
   hue meaning nothing the list did not already show. Removed; success is the
   row leaving and a grey toast with Undo.
7. **The detail pane was three stacked cards** (Properties, Versions, Text).
   That is the SaaS-card kit. It is now one flat pane with tabs and hairlines.
8. **The dark theme's darkest value was `#111`.** Replaced with graphite
   `#181B1F` / `#1F2327`, visibly grey, so dark mode is a choice rather than a
   black that is not quite black.
9. **Extraction "done" had a tick glyph.** A tick on every healthy row is
   noise, and it is the kind of reassurance a consumer product adds. Now a
   healthy row shows nothing: quiet means fine.
10. **Motion had an "enter" animation on the list when navigating folders.**
    That is the scattered fade-and-slide-up the skill calls out. Navigating is
    a full content swap; it now happens on the same frame, and only the rows
    that change *within* a listing move.

Kept after review, deliberately: 32px rows (denser than Drive because the
users are professionals, looser than Finder because the row carries more);
ISO dates in columns (a records system should sort by eye); explaining
disabled menu items instead of hiding them (the two-layer authorisation model
makes the hidden/disabled split principled rather than arbitrary).

---

## 10. Build notes

### Tokens (Tailwind 4 `@theme`, replaces the zinc block in `app.css`)

```css
@theme {
    --font-sans: 'Public Sans', ui-sans-serif, system-ui, sans-serif;

    --color-sheet:     #ffffff;
    --color-chrome:    #eef0f2;
    --color-rule:      #d3d8de;
    --color-ink-2:     #5b6470;
    --color-ink:       #1f252b;

    --color-select:    #2b5fd9;
    --color-hold:      #c42b1f;
    --color-attention: #a05a00;

    /* Flux: primary buttons stay ink; selection is a separate token. */
    --color-accent:            var(--color-ink);
    --color-accent-content:    var(--color-ink);
    --color-accent-foreground: var(--color-sheet);
}

@layer theme {
    .dark {
        --color-sheet:     #1f2327;
        --color-chrome:    #181b1f;
        --color-rule:      #30363d;
        --color-ink-2:     #9aa3ad;
        --color-ink:       #e8ebee;
        --color-select:    #7aa2ff;
        --color-hold:      #ff7a70;
        --color-attention: #e8a33c;
        --color-accent-foreground: var(--color-sheet);
    }
}
```

Dark mode keys off the project's existing `.dark` custom variant, because the
Flux appearance toggle sets the class. Note that both starter layouts currently
hardcode `class="dark"` on `<html>`; the Topbar layout must drop that and let
`@fluxAppearance` decide.

Selection tint and drop tint are computed, not stored:
`bg-[color-mix(in_oklab,var(--color-select)_10%,var(--color-sheet))]` (16% in
dark; 12% / 18% for drop). Focus: `outline: 2px solid var(--color-select);
outline-offset: -2px` on rows and tree nodes, `outline-offset: 2px` on
buttons and inputs, on `:focus-visible` only.

### Fonts

`vite.config.js`: replace `bunny('Instrument Sans', { weights: [400, 500, 600] })`
with `bunny('Public Sans', { weights: [400, 500, 600] })`. Keep `@fonts` in
`partials/head.blade.php`. Add `.num { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }`
and verify once in a browser that `1111` and `0000` render at equal width;
if they do not, the Bunny subset is missing the feature and the fallback is
`Source Sans 3`, which also ships `tnum` and is on Bunny.

### Motion

`npm i motion` (v13). `resources/js/shell/motion.js` exports `move()`:

```js
import { animate } from 'motion';

const reduce = matchMedia('(prefers-reduced-motion: reduce)');

export function move(el, keyframes, options = {}) {
    if (reduce.matches && !options.keepUnderReducedMotion) {
        Object.assign(el.style, Object.fromEntries(
            Object.entries(keyframes).map(([k, v]) => [k, Array.isArray(v) ? v.at(-1) : v]),
        ));
        return Promise.resolve();
    }
    return animate(el, keyframes, options).finished;
}

export const springs = {
    reveal: { type: 'spring', stiffness: 420, damping: 38, mass: 1 },
    unfold: { type: 'spring', stiffness: 520, damping: 42, mass: 1 },
    lift:   { type: 'spring', stiffness: 600, damping: 30, mass: 1 },
    settle: { type: 'spring', stiffness: 700, damping: 40, mass: 1 },
    stamp:  { type: 'spring', stiffness: 800, damping: 22, mass: 1 },
    sheet:  { type: 'spring', stiffness: 380, damping: 36, mass: 1 },
};
```

Fixed durations (Fold 160ms, Menu 90/60ms, Accept 80/60ms, Confirm 140ms,
Progress 200ms tween) are CSS transitions with the beziers in §5, guarded by
`motion-reduce:` variants.

### Accessibility floor

Contrast figures in §1. Focus visible everywhere (§10 above). `role="grid"`
list, `role="tree"` tree, `role="menu"` menus, `role="tablist"` tabs. Status
bar is `aria-live="polite"` and announces moves, trashes, holds and drag
reasons. Every glyph has an `aria-label` and a tooltip with the same text.
Touch targets are at least 44px on phone (rows are 48). Nothing is conveyed by
colour alone: hold has a glyph and a word in the detail pane; selection has a
checkbox state; drop targets have the status-bar sentence.

### Copy

Sentence case throughout. The verb on a menu item is the verb in its toast:
"Move to trash" produces "Moved 3 items to trash. Undo". Refusals name the rule:
"Period archived", "Under hold", "No upload access here". Empty states are one
sentence and one action. No exclamation marks, no apologies.

---

## 11. Risks and open questions

| Risk | Why accepted | Fallback |
|---|---|---|
| Red means hold, not danger; users expect a red Delete | In a records system the hold is the state that must never be missed; trash is reversible and has Undo. Purge is an operator CLI action, not a shell button | If confusion shows up in use, destructive confirms can adopt `ink`-filled buttons with the noun spelled out; red still stays reserved |
| Period bands cost vertical space | The period is the true partition; grouping is honest | "Modified (no groups)" sort, one click, persisted; §7 |
| Hand-built context menu, tabs, tree and DnD are more surface than Flux Pro would need | Flux free is a hard constraint; Flux Pro's `context`, `tabs` and `command` are not available | Each is a small Alpine component wrapping Flux free primitives, so a later Flux Pro adoption can swap them one at a time |
| Custom drag ghost via `setDragImage` on an empty element has quirks in Safari | The native ghost image cannot be springed and cannot stack for multi-drag | Feature-detect; fall back to the native ghost with Lift disabled |
| Public Sans `tnum` in the Bunny subset | Verified the family and its stated tabular-figure design; the served subset is not verified | Runtime width check in dev; `Source Sans 3` fallback (§10) |
| Whether rename and move are permitted on files in an archived period | Spec says archived periods "reject writes" and uploads; it does not say metadata | Confirm against `MoveDirectory` and `StoreFileVersion` before building the menu rules; the menu and the drop share one rule table so the answer changes one place |
| Whether a held file may be moved to trash | `TrashFile` exists and trash keeps objects; purge is what a hold blocks | If `TrashFile` refuses held files, the menu item is disabled with the reason "Under hold" |

The shell is done, for this plan's purposes, when `FileBrowserTest` keeps its
unreachable-directory guarantees and the ledger item's `doneWhen` holds —
column sort, panel on select, version list, replace adds a version, bulk trash
of exactly the selected rows — and when a person can file and retrieve for a
day without the interface once asking for their attention it did not need.
