# FluxPos — Phase 4 Requirements

**Theme: production readiness — full offline confidence, traceability and no manual maintenance.**

Status: draft for review (v1.0.12). Bases on phases 1–3 (category tabs, offline catalog
rendering, offline order entry/payment with exactly-once booking) and the fixes of
1.0.5–1.0.11 (placement-independent loading, lazy token/URL, order adoption, cache
busting, background merge of adopted orders).

Reference production context: Dolibarr **22.0.3**, PHP **8.3**, module under
`/custom/fluxpos/`, MySQL prefix `llxos_`.

---

## 1. Goals

1. A cashier never sees a silent failure or an action that "does nothing", online or offline.
2. The owner never has to perform manual maintenance steps after deploy (no disable/re-enable dance, no stale copies, no stale caches).
3. Every capture made offline is provably booked exactly once and verifiable by the cashier (terminal) and by the owner (back office).
4. All user-facing strings follow Dolibarr i18n practice (translatable, currently several hardcoded English alerts in the offline engine).

## 2. Non-goals (out of scope for phase 4)

- Stock reservation/verification while offline.
- Refunds, credit notes and invoices with complex structuring (sub-lines, multi-currency totals beyond current approximations) captured offline.
- Fiscal signature/depth-offline (multi-day) operation.
- Rewriting the UI shell (interface cleanup keeps its own track).

---

## 3. Functional requirements

### FR-1 — Sync status panel on the terminal (was: status pill + browser console)

- The offline status pill becomes clickable and opens a small panel listing, per place and per sale:
  - pending orders (unpaid carts) with place, age and total;
  - pending paid sales with uid, total and age;
  - sales **refused by the server with the server error text displayed inline** (today the error is only in the browser console — unacceptable for a cashier).
- Actions in the panel: **Retry now** (all or per item), **Discard** (confirm dialog; only for refused sales or empty carts), nothing else destructive. Discarding a refused sale must never be possible while the server is unreachable.
- All strings translated (see FR-5).

### FR-2 — Complete the offline capture of adopted orders (raise AND lower AND delete)

- Extension of 1.0.11 adoption: lines already on the server order can now be **lowered and deleted** while offline.
- Server side: the merge gains an absolute mode — each captured line carries its `lineid` (already in the snapshot), the merge sets the quantity of that existing line to the captured value (up **or down** with `updateline`) and **deletes** baseline lines removed offline (only while the invoice is still a draft).
- Deletion safety: a deleted baseline line is only deleted on the server if it still exists on the draft with the same product id; otherwise the sync reports a per-line warning instead of failing the whole merge.
- Replay safety: re-sending the same adopted cart must remain a no-op (absolute quantities, idempotent by definition). Idempotency of *deletions* requires the cart to remain on the terminal until the full merge is acknowledged (current behavior) — the deletion is only replayed while the cart exists.
- Amount recomputation on the server for updateline/deleteline reuses `Facture::update_price` (transaction-safe, same as online actions).

### FR-3 — Free lines and discounts while offline *(stretch goal)*

- Fixed-price/free lines captured offline would need price data not in the cached catalog. Requirement: at minimum a clear translated notice ("not possible offline"), unless a cheap capability is found in the snapshot (e.g. remember the last N free-line entries per terminal). Do not build price guessing.

### FR-4 — Offline payment of an adopted order *(stretch goal)*

- Today an adopted order can only be paid on the server. Requirement if taken up: validating + paying the *existing* draft invoice of the place from the terminal, exactly-once, keyed by cart uid + server invoice id, with the same payment controls as separate orders. Requires the "not a draft anymore" answers of the sync endpoint to be disambiguated ("already validated and paid by a previous attempt" vs "someone else validated it: never pay again").

### FR-5 — Full i18n of the offline engine (Dolibarr practice)

- Every user-facing string of `js/offline.js` (alerts, panel, notices, status pill) goes through the `fluxposlang` mechanism passed by `index.php` (current fallbacks remain as safety net).
- Source strings added to `langs/en_US/fluxpos.lang`; optionally `nl_NL` and `fr_FR` for the production sites.
- The setup pages and new admin screens follow the same rule.

### FR-6 — Setup pre-flight checks panel (removes all manual maintenance steps)

A section on the module setup page that checks and (where possible) repairs in one click:

1. **Duplicate module copies** — warn when a copy of the module is found in more than one root (`htdocs/fluxpos/` *and* `htdocs/custom/fluxpos/`): the stale copy must be removed (this is the current `/fluxpos/` 403 noise on production).
2. **Offline sales table** — verify `llx_fluxpos_sale` exists; if not, offer **Create table** (removes the current "disable/re-enable" requirement).
3. **Legacy constants** — verify the `TAKEPOS_*`/`CASHDESK_*` → `FLUXPOS_*` import ran (currently only done at module enable, unconfirmed on production); offer **Import now**.
4. **Terminal/customer setup** — a terminal without its default thirdparty blocks offline orders; show it.
5. **Browser capabilities** (a self-test link opening a small page): service worker registration state, cache version actually served, IndexedDB availability, current served `offline.js` version (mtime must match disk).
6. Each check states what to do in one sentence; checks must never produce fatals when the underlying tables/files are missing (Dolibarr 22 throws on SQL errors — all guarded).

### FR-7 — Server-side sync traceability

- Extend the offline booking with a minimal sync log (`llx_fluxpos_synclog` or extra columns on `llx_fluxpos_sale`): uid, terminal, kind (order/sale), result (booked/duplicate/error), error text, attempt count, timestamps.
- A small admin page ("Offline sync") lists the recent attempts per terminal with status, and the pending references waiting on any terminal (from the sale table).
- Purpose: the owner can verify "was that sale booked?" without touching the database, and can spot a terminal that has stopped syncing.

### FR-8 — Catalog size guard

- `syncCatalog` refuses to cache more than `FLUXPOS_OFFLINE_CATALOG_MAXPRODUCTS` products (default e.g. 5000) and reports it to the console/panel, so a huge catalog can never freeze the terminal. No truncation of per-category arrays below the limit.

### FR-9 — Conflict policy for adopted orders

- If the draft invoice of the place changed on the server since the snapshot was taken (`tms` newer than snapshot time, or line ids disappeared), the merge still applies the raise-only/absolute rules and returns a `conflict` flag; the terminal panel shows "the order on the server changed meanwhile, verify the lines" and the order is refreshed from the server after the merge.
- The merge must never overwrite a modification made online (no line price/description changes from the terminal — only quantities/additions as captured).

---

## 4. Non-functional requirements

- **NFR-1** Zero regression of online capture speed; the offline engine must stay load-order independent (no head-time capture of page globals — the 1.0.9 rule) and must degrade gracefully on browsers without service worker/IndexedDB (clear message, online only).
- **NFR-2** Data on the terminal (catalog, orders, sales) contains only what the POS screen already shows; clearing site data clears everything; nothing is sent except the replay endpoints.
- **NFR-3** Dolibarr coding standards everywhere: version bump + ChangeLog entry per PR; service worker cache bump whenever `offline.js`/`serviceworker.js` change; `function_exists`/`isModEnabled` guards for version-dependent core calls; no query on possibly-missing tables without a guard (PHP 8.3 + DOL 22 throw).
- **NFR-4** CI green on every PR (`php -l` all files, `node --check` all JS); workflow may get a PHP version matrix (8.2/8.3) — optional.
- **NFR-5** One PR per feature, merge order respected; a follow-up commit to a merged PR is never stranded (rebase onto `main` and re-PR if needed).

---

## 5. Acceptance test matrix

| # | Scenario | Expected |
|---|----------|----------|
| A1 | Fresh deploy, close all tabs, reload twice, console | `getCatalog` 200 on `/custom/fluxpos/`, log "catalog synchronized", pre-flight all green |
| A2 | Offline tap, new order | Tile animates, line appears in local cart instantly, no alert |
| A3 | Offline tap on existing order (1.0.11 adoption) | Order adopted, lines + new tap visible, total correct |
| A4 | Offline lower/delete of an existing line (FR-2) | Allowed; on reconnect line qty lowered / line removed on the draft |
| A5 | Reconnect after offline captures | Background merge within ~4 s, cart cleared, server order refreshed, exactly one booking per product line |
| A6 | Two tabs of the same terminal syncing at once | Second sync is a no-op (duplicate detection), no double lines |
| A7 | Session token expired while offline for hours | Screen auto-reloads once on 403; carts/sales survive (IndexedDB) |
| A8 | Wi-Fi up, server down; first tap | Quick fallback (no 30 s hang), tap captured locally, status shows offline |
| A9 | Refused sale (product deleted on server) | Error text visible in the terminal panel, retry works after fixing online |
| A10 | Stale module copy present | Pre-flight check 1 flags it with an actionable message |
| A11 | Catalog over the limit | Warning shown, catalog of the limit products still cached, no freeze |
| A12 | Day/night: sale paid offline at 23:59, synced next day | Books with sync-day date; cash fence rules same as online; documented limit stays |

---

## 6. Suggested delivery order

1. **PR 1**: FR-5 (i18n sweep — mechanical, unlocks everything else, translations carried in the same PR as the features that need them afterwards).
2. **PR 2**: FR-6 (pre-flight checks — removes the standing manual maintenance items).
3. **PR 3**: FR-1 + FR-7 (terminal panel + server log — traceability).
4. **PR 4**: FR-2 + FR-9 (complete adopted-order capture with lineid merge).
5. **PR 5**: FR-8 (+ FR-3/FR-4 stretch decisions).

Each PR: version bump, ChangeLog entry, SW cache bump when `offline.js` changed, ready-for-review.
