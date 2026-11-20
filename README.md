# FluxPos

## Features
Add a Touch Screen POS (Point Of Sale) to your ERP.

FluxPos is an independent, self-contained Dolibarr module (it is not the core
TakePOS module) built on the official external-module template layout, with:
- Products displayed as category tabs in the sale screen.
- Full offline operation: once loaded, catalog, order entry, payments,
  validation, numbering and receipt printing keep working without internet
  access, and orders synchronise asynchronously in the background without
  interrupting the entry of new orders.
- Migration path from an existing TakePOS install (legacy `TAKEPOS_*` /
  `CASHDESK_*` settings and floor-plan data are imported on activation).

<!--
![Screenshot fluxpos](img/screenshot_fluxpos.png?raw=true "FluxPos"){imgmd}
-->

## Installation
- Copy (or clone) this directory into your Dolibarr `htdocs/fluxpos/` folder.
- In Dolibarr go to **Home > Setup > Modules/Applications**, find **FluxPos** and enable it.
- On activation the module imports settings and floor tables from a previous
  TakePOS installation if one exists, then configure your terminals under
  **Home > Setup > Modules > FluxPos (setup tab)**.
- Menu entry: **Point of sale**: `htdocs/fluxpos/index.php`.

Note: FluxPos conflicts with the core TakePOS module; disable TakePOS before
enabling FluxPos.

## Layout (Dolibarr external-module guidelines)
- `core/modules/modFluxPos.class.php` - module descriptor (id 94410, permissions 94411-94413)
- `core/modules/fluxpos/` - invoice reference numbering models
- `sql/` - table installation scripts ('Floor tables' plan)
- `langs/en_US/fluxpos.lang` - module language file
- `lib/fluxpos.lib.php` - admin tabs helper
- `admin/` - setup pages, `ajax/` - background endpoints, `js/` + `css/` - frontend assets
- `modulebuilder.txt` - keeps the module editable with Dolibarr ModuleBuilder

## Licenses
GPL v3 - See LICENSE notes in source file headers.
Icon and image files and their licenses: see the *img* subdirectory: images
`gplv3.png` and `gfdl.png` file are under respective free licenses.
