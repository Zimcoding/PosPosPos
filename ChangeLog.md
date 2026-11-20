# CHANGELOG FLUXPOS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.3

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
