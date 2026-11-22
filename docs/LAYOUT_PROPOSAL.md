# FluxPos layout proposal — more product space, better product ordering

Proposal for the interface round of Phase 4. Written against the current
layout code (`index.php` rows/divs + `css/pos.css.php`) and inspired by how
the mainstream POS systems solve the same problem (Square POS, Shopify POS,
Lightspeed Retail/Restaurant, Loyverse). Nothing here is implemented yet —
this document is the menu to choose from.

## 1. The screen today (landscape, 1920×1083)

```
+------------------------------------------------------------------------+
| topnav (terminal number, New, customer, search, home ...)              |
+-----------------------------+------------------------------------------+
| #poslines  (div1, 34% wide) | div2: numberpad (33%) | div3: action     |
|  cart lines, scroll         |  always visible       |  buttons (33%)   |
+-----------------------------+------------------------------------------+
| div4: category tabs strip (48 px)                                      |
+------------------------------------------------------------------------+
| div5: product grid — fixed pool of 24 tiles (FLUXPOS_NB_MAXPRODUCT),   |
|       paged with arrows; tiles = image + label + price                 |
+------------------------------------------------------------------------+
```

Consequences measured on the production screen:

- The **products live in the bottom half** (~55% of the viewport height) although
  selecting products is 80% of a cashier's work.
- The **numberpad occupies 33% × 45% of the screen permanently**, but is only
  needed when a line is edited or a price/reduction is typed.
- The **cart gets 34% × 45%**, scrolls in a small box, and its total is only
  visible by scrolling.
- The grid is a **fixed pool of 24 DOM cells** refilled per page: adding products
  means paging, and every page change is an ajax round trip.

## 2. What other POS systems do (patterns worth stealing)

| System | What it does differently |
|---|---|
| **Square POS** | Products are the hero: full-height item grid, cart docked as a right column; the numberpad only appears on the payment screen and when editing a line; customizable item grid with shortcut/favorite tiles and drag-and-drop setup. |
| **Shopify POS** | "Smart grid" with draggable tiles and pages, fixed cart panel; tile size is fixed, layout is content-driven. |
| **Lightspeed Retail / Restaurant (O-Series)** | Grid **or list** view per screen, drag-and-drop product arrangement from the POS itself, editable tile colors, dark mode. |
| **Loyverse** | Grid/list toggle, custom home pages, favorites row; on phones the cart is an overlay, not a column. |
| Common denominator | All products are visible with **infinite scroll, no page arrows**; the numberpad is **contextual, never permanent**; the cart is a **persistent column with a pinned total**, not a top-left box. |

## 3. Proposal A (recommended): right cart column + contextual numberpad + fluid grid

New skeleton (one CSS change per block, little HTML restructuring):

```
+------------------------------------------------------------------------+
| topnav (same as today)                                                 |
+---------------------------------------------------+--------------------+
| div4: category tabs (scrollable, with thumbnails and counts)   |  cart |
+---------------------------------------------------|  div1 as right     |
| div5: product grid — full remaining height and     |  column (28% wide, |
|       full width of the left area                  |  full height)      |
|       CSS grid, auto-fill, infinite scroll         |  lines scroll      |
|       [search result mode: tiles filtered while    |  TOTAL pinned at   |
|        typing, Esc = back to category]             |  bottom + payment  |
|                                                    |  button always     |
| [optional compact entry bar: qty - | + | line edit] |  reachable         |
+---------------------------------------------------+--------------------+
```

1. **#poslines becomes a right column** (28% width, full height). Lines get
   more room, the total and the payment button are pinned at the bottom. This
   is the Square/Shopify ergonomics that every cashier knows.
2. **The numberpad (div2) disappears from the base screen.** It reappears:
   - as a **modal over the cart** when a cart line is tapped (qty / price /
     reduction per line, with the on-screen keys it has today), and
   - on the **payment screen** (pay.php already has its own pad).
   Free-line / open-price products then open the same modal — no permanent
   pad needed. A per-terminal option `FLUXPOS_DISPLAY_NUMBERPAD`
   (`never` / `lineedit` / `always`, default `lineedit`) keeps an escape
   hatch for terminals that use it constantly.
3. **div3 (action buttons) collapses** into the cart column footer
   (Payment, New, Scale/Weight if enabled) plus a small overflow "…" menu.
   All hook-registered extra action buttons keep working — they move into
   the "…" menu, organized by group.
4. **The product grid becomes a CSS grid with infinite scroll**:
   `repeat(auto-fill, minmax(var(--fluxpos-tile-w), 1fr))`.
   - `FLUXPOS_NB_MAXPRODUCT` / the 24-cell pool and the page arrows go away;
     the full category is rendered once (the offline path already receives
     whole categories for its cache, so online and offline can share one
     renderer eventually).
   - **Tile density** setting `FLUXPOS_TILE_SIZE` = `S` (≈120 px, up to ~10
     columns) / `M` (≈160 px) / `L` (≈220 px, ~5 columns), changeable live
     from a small control in the grid corner and remembered per terminal
     (localStorage). This is Lightspeed's grid/list toggle generalized.
5. **Product ordering** (`FLUXPOS_SORTPRODUCTFIELD` gains options):
   - `ref` (today's default), `label`, and `position` (multi-level
     subcategory position, as stored on `llxos_categorie_societe`-style
     links; subcategories already render before products in the tabs
     Phase-1 work),
   - `bestsales`: product order by the number of invoice lines of the last
     30/90 days (per terminal context), computed server-side and **cached
     with the offline catalog** so it also works offline,
   - the top 8 of "bestsales" can additionally be shown as a **favorites
     strip pinned above the grid inside a category** (iPad-stand pattern),
     option `FLUXPOS_FAVORITES_STRIP` per terminal.
6. **Search bar** (already in topnav) becomes mode-aware while the product
   area is the hero: typing filters tiles in place; when exact, the first
   hit can auto-add (same barcode-reader integration as today).

## 4. Proposal B (conservative): keep the geometry, remove only the pad

If a full re-skeletton is too invasive for one step:

- Keep div1/div3 where they are; give the numberpad's third to the **product
  area by moving the tab strip and grid up one row**: the grid jumps from
  ~55% to ~80% of the viewport height.
- Catalog line-editing moves into the same modal as in Proposal A (the
  modal is the reusable piece of both proposals).

This is roughly half a day of CSS/HTML moving and immediately delivers
+45% product space; Proposal A can later be applied on top of it.

## 5. Constraints from the module rules (both proposals)

- The **offline renderer in `js/offline.js` draws the same product tiles and
  cart** — any grid change must be mirrored there or the offline screen
  will drift from the online one (and vice versa). This is why the shared
  tile CSS classes (`wrapper2`, `productprice`, …) must stay the styling
  contract between both renderers.
- **Service worker cache** must be bumped (offline.js and pos.css.php change).
- **All new strings** through `fluxpos.lang` (in place since 1.0.14) and the
  `fluxposlang` deliveries in the page.
- No CSS framework, no build step: plain CSS (Dolibarr style) and jQuery as
  today; hooks (`completeJSProductDisplay`, action-button hook) keep working.
- `phone.php` keeps its own minimal layout (out of scope here, next round).
- Every proposal step = its own PR with version bump + ChangeLog
  (house rules).

## 6. Suggested delivery as PRs

| PR | Scope | Notes |
|---|---|---|
| L1 | Numberpad → line-edit modal + payment screen (Proposal B step) | Biggest space win for least risk |
| L2 | Product grid: CSS grid + density + infinite scroll (online first, then shared offline renderer) | Second biggest win |
| L3 | Cart as right column (or keep, per decision) + action buttons into cart footer / "…" menu | Skeleton change |
| L4 | Product ordering `position`/`bestsales` + favorites strip (+ offline cache of the order) | Needs the sales-history query |
| L5 | Search-in-grid behavior + polish (thumbnails in tabs, counts) | Optional round 2 |

Versions 1.0.16+; L1–L2 are the candidates for the next terminal deploy.

## 7. Open decisions

1. Numberpad: remove to modal (A/B) or keep per-terminal option only?
2. Cart: move to a right column now (A) or later (B first)?
3. Bestsellers window: 30 or 90 days, and favorites strip on by default?
4. Minimum supported screen: is 1280×800 (small tablets) a requirement?
