# Deposito with FluxPos

[Deposito](https://github.com/zimcoding/deposito) charges the deposit on a
returnable bottle or crate (statiegeld) and records the refund of the empty.
FluxPos is compatible with it since FluxPos 1.5.0 and Deposito 0.2.5.

## How it works

Deposito is triggered by the Dolibarr line events of the draft invoice. FluxPos
creates and edits its receipts through the standard invoice class
(`Facture::addline / updateline / deleteline / validate`), so the Deposito
trigger sees every change made at the till and does its work on the server:

- **Deposit line added automatically.** Selling a product that names a deposit
  product adds the deposit line right after it, with the same quantity (the
  product field *Deposit units per unit* is honoured). The line follows every
  quantity change and disappears with the product line. The FluxPos cart
  re-renders from the server after every action, so the deposit line appears
  immediately.
- **Returned empties are a product.** The cashier adds the leeggoed product
  (Deposito field *Leeggoed* ticked) like any other product; Deposito stores
  the line as a refund (−qty × price) on the receipt, so validation puts the
  empties back into stock, into the warehouse of the terminal.
- **No VAT, no discount** on deposit and leeggoed lines, even after an
  invoice-level discount on the whole sale.
- **Only-empties sale** (negative total): FluxPos turns the receipt into a
  credit note of the last validated invoice of the customer (same behaviour as
  TakePOS) and pays the refund out over the terminal's account for the chosen
  payment mode. Without a previous validated receipt or invoice of that
  customer FluxPos stops with *No previous bill for customer* — work on it in
  the back office then, same as with TakePOS.

FluxPos marks its receipts with `module_source = 'fluxpos'`; Deposito (0.2.5
and newer) treats them exactly like TakePOS receipts, including the stock
movement rules.

## The stock guard

With the terminal option **FLUXPOS_QTY_IN_STOCK** enabled, FluxPos refuses to
add more units of a product than the terminal's warehouse holds. Returned
empties would put empties *into* stock, so leeggoed products are exempt from
that guard (that exemption is the FluxPos-side change of 1.5.0). Normal sale
products keep the guard as usual.

## Offline

The offline cart lives on the terminal and does not contain the deposit lines
the server will add. When the Deposito module is enabled, the offline view of
FluxPos therefore shows a notice that deposit charges and returned empties are
applied when the sale is booked. The booked payment is capped to the booked
total on the server, so money is recorded correctly either way; only the total
shown at the till can differ from the booked total for such carts.

A sale of only empties still needs a previous validated receipt or invoice of
the customer on the server, so make those sales online.

## Setup

1. Enable and configure Deposito (see its README: product fields *Deposit
   product*, *Deposit units per unit*, the *Leeggoed* checkbox, and the
   warnings shown on its setup page).
2. FluxPos needs no specific setup. The deposit and leeggoed products appear
   in the product tiles and in the cart like any other product.
3. Outstanding deposits (Deposito report) live under *Billing > Customer
   invoices > Outstanding deposits*, independent of the POS module used.
