# CHANGELOG FLUXPOS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.1.3

- Bug fix: the 1.1 layout still did not appear on the production terminal after the deployment, even after cache-busting the stylesheet URL (1.1.1): **the offline service worker itself was serving an old copy**. Its fetch handler answered every request in the module `css/` and `js/` directories (and any static file) with a pure **cache-first** strategy without any expiry, so `css/pos.css.php` and `js/offline.js` pre-captured at some earlier date were served straight from the IndexedDB cache — before the network, before the browser HTTP cache, and in practice unchanged by a hard refresh. Stylesheets and scripts of the module are now served **network first with a cached fallback only when the server cannot be reached** (which is also what the offline engine needs), so every update reaches the terminal as soon as the connection is up; the cache-first strategy is kept for real static images. The service worker cache is renewed (`fluxpos-offline-v12`), which also deletes every stale URL captured by the previous versions. Module version 1.1.3.

## 1.1.2

- **Category tab bar**: the legacy TakePOS paging is gone. Every category of the terminal is rendered at once in a single scrollable strip that uses the full width of the product area; arrows appear in front of and behind the strip only when it actually overflows (and only on the side with hidden tabs), and the strip can also be scrolled directly on a touch screen. The historical DOM ids (`catdivN`, `catimgN`, `catdescN`) and the `LoadProducts(position)` contract are kept, and `PrintCategories`/`MoreCategories` stay as harmless shims for any external caller.
- **Tile density control (S/M/L)** moves onto the tab row, right after the tabs, sitting a bit lower, so it no longer takes space inside the product area.
- **Offline / synchronization status pill** leaves its fixed position over the top-right corner (where it covered the tabs and the payment controls) and is docked at the top of the cart column, just above the cart lines.
- Service worker cache renewed (`fluxpos-offline-v11`). Module version 1.1.2.

## 1.1.1

- Bug fix: after updating, the new 1.1 layout did not appear and the cart stacked under the product grid instead of standing as a right column: `css/pos.css.php` is served with `Cache-Control: max-age=10800` (copied from stock TakePOS) while the POS screen linked it without any cache-busting parameter, so Firefox (and LiteSpeed in front of it) kept serving the pre-1.1 stylesheet for up to three hours after the files were replaced — the 1.1 HTML rendered without the 1.1 CSS, exactly what the screenshot showed. The stylesheet URL of the POS screen, the payment screen, the floors screen and the reduction popup now carries `?v=<filemtime of pos.css.php>` (the same mechanism the POS javascript files already used), the cart column returns to the right without any manual action, and every future CSS change reaches the terminals immediately. The `$dolibarr_nocache` guard of stock TakePOS was restored inside `css/pos.css.php` for good measure. Module version 1.1.1.

## 1.1.0

- New POS screen layout ("Proposal A" of `docs/LAYOUT_PROPOSAL.md`, designed from Square, Shopify POS, Lightspeed and Loyverse): the product area becomes the hero of the screen with a **fluid CSS-grid** (`#productgrid`) that renders the whole category at once — no 24-tile fixed pool, no page arrows, the area scrolls instead — and the cart becomes a **right column** of the screen (28%, 300–420 px) containing the order lines, a **sticky total**, the **payment modes as a block** and a quick-row of actions. Minimum supported screen is 1280 x 800. On narrow screens the cart stacks under the product area until the dedicated mobile step-through layout lands (next phase).
- The **numberpad is no longer a permanent third of the screen**: it opens as a contextual popup on the line currently edited (tap a cart line), and closes automatically once qty, price or reduction is applied. The constant `FLUXPOS_DISPLAY_NUMBERPAD` selects the mode: `lineedit` (default), `never`, or `always` (docked in the cart column, the escape hatch for terminals that use it all day).
- **Tile density** control (S/M/L, `FLUXPOS_TILE_SIZE`) in the corner of the product grid, remembered per terminal in the browser (`localStorage['fluxposTileSize<terminal>']`), so the same screen fits a counter behind glasses or a back-office at distance.
- **Product ordering**: `FLUXPOS_SORTPRODUCTFIELD` now also accepts `position` (the order of the category-product link, or its position column when present) and `bestsales` (the best sold quantities over a window of `FLUXPOS_BESTSELLER_DAYS` days, 90 by default); both are dedicated SQL because the generic sort field of `getObjectsInCateg` applies to the product table only, and both are guarded so missing data can never break the catalog. The same order is delivered through `getCatalog` so the offline cache keeps it.
- **Search** filters the tiles of the grid in place (limit 500): no popup list; Escape or emptying the search box goes back to the category. The single exact barcode behaviors are kept (third-party switch or product inserted at once).
- **Cart visibility**: order lines get a product thumbnail and +/- quantity steppers (quantity steps go through the server with pre-computed values, so weight products keep their fractional quantities), and the offline cart mirror renders the same thumbnails, steppers and sticky total (service worker cache renewed, `fluxpos-offline-v10`).
- **Payment block**: every payment mode configured for the terminal is directly clickable (`pay.php` opens with this mode preselected via the new `paycode` parameter, highlighted button); "More" opens the full payment screen. The other actions move into a quick-row (New/Place, direct payment when enabled, print receipt) plus an overflow modal (`#ModalActions`) that keeps every hook-registered button working.
- Translation strings for the new interface added to `langs/en_US/fluxpos.lang` (`FluxPosTileSize`, `FluxPosOtherPaymentModes`, `FluxPosLineEditTitle`, `FluxPosMoreActions`); the proposal document stays on its own branch and is not part of the release.

## 1.0.15

- Bug fix: the terminal setup page (admin/terminal.php) ended in a Dolibarr technical error page, `SQL syntax ... near ':=:1)` on llxos_bank_account`, when opening it. The terminal setup fills the bank accounts with `$form->select_comptes(...)` and passed the account-type filter in the Dolibarr REST API filter syntax (`(courant:=:1)`), but `select_comptes` appends its filter raw to the SQL (unlike the third-party select and the category product searches, which convert their filters through the universal search criteria) so MariaDB refused the page. The five filters now use the raw SQL syntax that TakePOS core itself passes (`courant=2` for the cash account, `courant=1` for cheque, card and SumUp). Module version 1.0.15.

## 1.0.14

- All messages of the offline engine are translatable: every user-facing string (the notices of the offline status indicator, the messages shown when a product is added, quantities are changed or the cart is validated while offline, and the notices of the cart display) goes through the translation of the module (`langs/en_US/fluxpos.lang`, keys `FluxPosOffline*`), delivered to the terminal with the `fluxposlang` object of the POS screen and resolved at call time with an English fallback. The console messages stay technical English on purpose (they are for debugging, not for the cashier). A translation into Dutch or French can now simply be added as `langs/nl_NL/fluxpos.lang` or `langs/fr_FR/fluxpos.lang`.
- The service worker cache is renewed (`fluxpos-offline-v9`) so terminals load the updated offline.js.

## 1.0.13

- Bug fix: when the server of a terminal that was working offline became reachable again, the recovery could end in the console error `self.getCart is not a function` (markServerUp): the function used the local variable `self` inside its callbacks without declaring it, so JavaScript fell back on the global `self` (the browser window) and the display was not given back to the server after a synchronization. The missing local variable is declared, and in the same way as the other engine functions already did. The service worker cache is renewed (`fluxpos-offline-v8`) so terminals load the fixed offline.js.

## 1.0.12

- Phase 4 requirements draft added: `docs/PHASE4_REQUIREMENTS.md` collects the requirements for the next development phase (production readiness) — terminal synchronization panel with explicit error text and retry/discard, completion of the adopted-order capture (lowering/deleting lines of a server order while offline via the line identifiers), setup pre-flight checks (duplicate module copies, missing `llx_fluxpos_sale`, legacy constants, terminal/customer setup, browser self-test), translation of the offline engine through `fluxpos.lang` and `fluxposlang`, server-side synchronization traceability, catalog size guard, and the conflict policy for adopted orders, with an acceptance test matrix and a suggested delivery order. No code change in this version (documentation only); module version 1.0.12.

## 1.0.11

- A place whose order is already registered on the server can finally be captured while offline: the order is adopted into the local cart of the place at the first product tap (this used to be the "Press New" notice). The terminal keeps a snapshot of the order of each place while the connection is up (stored on the terminal like the catalog and the carts), so going offline keeps the lines of the order on display, the new product taps keep working (the quantities of lines already on the server can only be raised while offline — the merge raises, never duplicates), and a background synchronization merges the whole cart into the existing draft invoice of the place as soon as the connection is back, with the same endpoint and the same rules as an order started offline. The payment of such an order stays on the server as today (only an order started offline can be paid whole from the terminal). When no snapshot of the order is available (the order was never shown on this screen), the cashier is guided to "New" as before. Discount lines and sub-lines are excluded, like the server merge already does.
- The service worker cache is renewed (`fluxpos-offline-v7`) so terminals load the new offline.js.
(Capture offline on a place whose order exists on the server (adopt the server order))

## 1.0.10

- Deployment hardening: the browser of a terminal can keep serving its own cached old `offline.js` long after a deployment (its service worker cache only renews when the engine itself changes, but the plain HTTP cache never expires reliably), and an old engine calls the server on the obsolete URL with an obsolete token: catalog synchronization refused 403 again although the fixed code was on the server. The module scripts of the POS screen are now loaded with the modification time of the file on disk as a version (`offline.js?v=...`), so any terminal drops its cached copy as soon as the files are updated on the server.

## 1.0.9

- Since the custom-placement rework, the offline engine resolved the root URL of the module and the security token into its own properties while the file loaded from the `<head>` of the page, that is before the POS page defines them: every offline-engine call (catalog synchronization, ping, sale/order synchronization) went to `/fluxpos/ajax/ajax.php` instead of `/custom/fluxpos/ajax/ajax.php`, with an empty token, and was refused (`HTTP 403`, `token=` empty in the console). The product catalog was therefore never cached on the terminals: offline product taps found no product and offline category switching showed nothing (products are not added, with or without a visible notice). The module URL and the token are now resolved at call time (and fall back on the location of offline.js itself), so the engine reaches the endpoints with the current token on any placement. This also restores correct amounts and translations in the offline cart display.
- The service worker cache is renewed (`fluxpos-offline-v6`) so terminals load the fixed offline.js.

## 1.0.8

- The moment the server becomes unreachable (network gone, but the terminal still thinks it is online), the first product taps went through the server call only: the addline fragment load had no failure handling, so the tap hung or failed without any visible result ("failing silently"). A failed line-entry load now falls back on the terminal: on a network failure the product goes into the local cart of the place exactly like a direct offline tap; if the place already has an order on the server, the cashier gets the "press New" notice. A failed refresh of the order switches the display to the local cart as soon as the connection drops, instead of leaving stale lines on screen.
- The home (backoffice) button of the POS header opened the Dolibarr home without a security token, so the CSRF check refused it (the repeated "GET /index.php refused by CSRF protection - Token not provided" in the log). The link now carries the token.
- The service worker cache is renewed (`fluxpos-offline-v5`) so terminals load the new offline.js.

## 1.0.7

- After a deployment, a terminal whose service worker still serves the offline.js of the previous version ran the new POS page with an older offline engine: tapping a product while offline threw "FluxPosOffline.notifyBlockedCapture is not a function" and stopped the sale entry. Every call to the offline engine from the POS page is now checked before use, so an out-of-date script can only log a hint asking for a reload instead of breaking the page.

## 1.0.6

- On the server, the CSRF protection refused every call of a POS screen opened before the last update (`HTTP/2 403`, `token=` empty in the console). A screen that stays open for days keeps the token it was born with; when Dolibarr refuses it, every call and every product tap silently did nothing, and the catalog could not be synchronized into the offline storage. The offline engine now detects the refused token (HTTP 403 CSRF check) and reloads the POS screen once by itself to get a fresh token (orders captured offline live in IndexedDB and survive the reload); if the fresh screen is refused too (server problem), an alert asks for a manual reload instead of looping.
- Tapping a product while offline on a place whose order already exists on the server did nothing (such an order cannot be modified from a terminal offline). The cashier now gets a notice explaining it and inviting to press New, which starts a separate order on this terminal (already supported).
- The service worker cache is renewed (`fluxpos-offline-v4`) so terminals load the new offline.js.

## 1.0.5

- The main POS screen (index.php) called `isALNERunningVersion()` from the core blockedlog library. That function only exists in Dolibarr 23; on Dolibarr 22 it threw a "Call to undefined function" fatal error with no SQL or log entry, and the page stopped rendering after the numpad: the action buttons, the category tabs and the payment area never appeared. The call is now guarded so the page renders on Dolibarr 22 the same way the core TakePOS screen does.
- The same ALNE helpers are called by receipt.php, pay.php, the receipt setup pages and the receipt printer class. Missing shims return "false" on Dolibarr lower than 23, which matches the behaviour of the core TakePOS screens of those versions.
- The printer name lookup `dol_getIdFromCode(... 'printer_receipt' ...)` in index.php and invoice.php ran even when the core "Receipt Printer" module is not enabled (so the `llx_printer_receipt` table does not exist). Since Dolibarr 22 a query on a missing table throws and aborts the invoice fragment. The lookup is now skipped when the module is disabled and no printer is configured, and the print button falls back to browser printing.
- phone.php used `getDolCurrency()`, a helper that does not exist in Dolibarr 22, when displaying a product price; the currency is now taken from `$conf->currency` as the core TakePOS phone page does.

## 1.0.4

- The dialogs of the POS (Customer, Contact, History, cash fence create/detail/report) linked to the Dolibarr pages with a relative path ("../societe/list.php") that only resolved when the module was installed in htdocs/fluxpos. From htdocs/custom/fluxpos the request went to /custom/societe/... and the dialog showed a 404 or the home dashboard instead of the list. All these links now use DOL_URL_ROOT.

## 1.0.3

- Fixed a PHP syntax error (stray quote) in the "back to module list" link on every module setup page (admin/setup.php, appearance.php, bar.php, receipt.php, terminal.php). The setup screens returned a blank HTTP 500 because the page did not compile.
- A GitHub Actions workflow now runs `php -l` on every PHP file and a syntax check on every JS file on each push and pull request, so a compile error can no longer reach main unnoticed.
- The base URL of the module (used by the offline engine to reach ajax/ajax.php and the service worker) is now derived from the actual location of the running page instead of `dol_buildpath('/fluxpos/', 1)`. `dol_buildpath()` cannot resolve the URL of a directory, so it returned the url of the core location and the catalog synchronization was sent to `/fluxpos/ajax/ajax.php` and refused (HTTP 403) on a custom installation.
- The service worker cache is renewed (`fluxpos-offline-v3`), so terminals drop their cached pages and scripts after the update.

## 1.0.2

- The module can be installed in any directory (`htdocs/fluxpos/`, `htdocs/custom/fluxpos/`, ...). A shared loader `mainenv.php` finds the Dolibarr environment instead of relative includes, which gave a blank page (HTTP 500) on every screen in the custom placement.
- The module setup pages no longer call `dolBuildUrl()`, which only exists in recent Dolibarr versions.
- CSS and JS files are linked with `dol_buildpath()`, so styles and scripts load in the custom placement too.

## 1.0.1

- A sale paid offline is booked exactly once. Each paid sale gets its own id on the terminal; the server stores it with a unique key (new table `llx_fluxpos_sale`) in the same transaction as the invoice and payments. A sale sent again (lost answer, two tabs) returns the first invoice instead of a second one.
- After paying offline, the order of the place is emptied at once: the next customer can be served while the paid sale waits for the connection.
- Offline mode also starts when Wi-Fi is up but the server cannot be reached (no answer, timeout, gateway error). A product tap that fails goes into the local order, or the cashier is told it was not added: never lost silently. A light check every 15 seconds finds out when the server is back.
- Offline, "New" on an order stored on the server starts a separate order on the terminal. It is kept apart and never merged into the order on the server.
- Calls to a local print server no longer switch the terminal offline or online.
- A sale whose products were all deleted is refused instead of being booked as an empty invoice.
- A refused sale no longer blocks the others; the status shows how many were refused.
- The service worker cache is renewed, so terminals load the new files.

**After updating:** disable and enable the module once, so the table `llx_fluxpos_sale` is created. Until then offline sales are kept on the terminals and shown as refused by the server.

**Known limits:**
- Invoice date, payment date and the cash closing check use the moment of synchronization, not the moment of sale.
- A receipt is printed by the server when the sale is synchronized, not when it is paid offline.
- A separate order started offline and not paid stays on the terminal; it is not shown again once the connection is back.
