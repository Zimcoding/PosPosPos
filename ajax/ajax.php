<?php
/* Copyright (C) 2001-2004	Andreu Bisquerra	<jove@bisquerra.com>
 * Copyright (C) 2020		Thibault FOUCART	<support@ptibogxiv.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/fluxpos/ajax/ajax.php
 *	\brief      Ajax search component for FluxPos. It search products of a category.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// Load Dolibarr environment
require_once __DIR__.'/../mainenv.php'; // Load $user and permissions
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT."/product/class/product.class.php";
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

$category = GETPOST('category', 'alphanohtml');	// Can be id of category or 'supplements'
$action = GETPOST('action', 'aZ09');
$term = GETPOST('term', 'alpha');
$search_term = GETPOST('search_term', 'alpha');
$id = GETPOSTINT('id');
$search_start = GETPOSTINT('search_start');
$search_limit = GETPOSTINT('search_limit');

if (!$user->hasRight('fluxpos', 'run')) {
	accessforbidden();
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('fluxposproductsearch')); // new context for product search hooks

$pricelevel = 1;	// default price level if PRODUIT_MULTIPRICES. TODO Get price level from thirdparty.


/**
 * Custom product ordering for the FLUXPOS_SORTPRODUCTFIELD values 'position' and
 * 'bestsales'. The $sortfield of Categorie::getObjectsInCateg() is applied with the
 * order() of the database layer to the product table alias (o), while 'position'
 * lives on the category-product link table (when the column exists there) and
 * 'bestsales' needs an aggregate over the invoices of a time window, so both need
 * dedicated SQL.
 *
 * @param  int    $categid  ID of category
 * @param  string $sortmode 'position' or 'bestsales'
 * @return null|array<int>  null when the sort mode is unavailable (the caller keeps
 *                          its default order), else the ordered list of product ids
 */
function fluxposCustomSortedProductIds($categid, $sortmode)
{
	global $db, $conf;

	$categid = (int) $categid;
	if ($categid <= 0) {
		return null;
	}

	if ($sortmode == 'position') {
		// llx_categorie_product has no 'position' column in Dolibarr 22, so the linked
		// order falls back to the link insertion order (rowid) unless the column exists.
		$resql = $db->query("SHOW COLUMNS FROM ".$db->prefix()."categorie_product LIKE 'position'");
		$hasposition = ($resql && $db->num_rows($resql) > 0);
		if ($resql) {
			$db->free($resql);
		}
		$sql = "SELECT cp.fk_product as pid FROM ".$db->prefix()."categorie_product as cp";
		$sql .= " WHERE cp.fk_categorie = ".$categid;
		$sql .= " ORDER BY ".($hasposition ? "cp.position, " : "")."cp.rowid";
	} elseif ($sortmode == 'bestsales') {
		$days = getDolGlobalInt('FLUXPOS_BESTSELLER_DAYS', 90);
		if ($days <= 0) {
			$days = 90;
		}
		$datelimit = $db->idate(dol_time_plus_duree(dol_now(), -$days, 'd'));
		$sql = "SELECT cp.fk_product as pid, COALESCE(SUM(fd.qty), 0) as nbqty";
		$sql .= " FROM ".$db->prefix()."categorie_product as cp";
		$sql .= " LEFT JOIN ".$db->prefix()."facturedet as fd ON fd.fk_product = cp.fk_product";
		$sql .= " LEFT JOIN ".$db->prefix()."facture as f ON f.rowid = fd.fk_facture";
		$sql .= " WHERE cp.fk_categorie = ".$categid;
		$sql .= " AND (f.rowid IS NULL OR (f.entity IN (".getEntity('invoice').") AND f.fk_statut >= 1 AND f.datef >= '".$datelimit."'))";
		$sql .= " GROUP BY cp.fk_product ORDER BY nbqty DESC, cp.rowid ASC";
	} else {
		return null;
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return null;	// guard: a missing table or column must never break the catalog
	}
	$ids = array();
	while ($obj = $db->fetch_object($resql)) {
		$ids[] = (int) $obj->pid;
	}
	$db->free($resql);
	return count($ids) ? $ids : null;
}

/**
 * Reorder fetched products following an external list of ids (custom sort of the
 * fluid grid). Products whose id is not in the list keep their relative order at
 * the end, so nothing is ever dropped.
 *
 * @param  array           $products    Array of Product
 * @param  array<int,int>  $orderedids  Product ids in the wanted order
 * @return array           Reordered products
 */
function fluxposOrderProductsBy($products, $orderedids)
{
	if (!is_array($products) || !is_array($orderedids) || count($orderedids) == 0) {
		return $products;
	}
	$order = array();
	foreach ($orderedids as $i => $pid) {
		$order[(int) $pid] = $i;
	}
	usort($products, function ($a, $b) use ($order) {
		$aid = (int) (isset($a->id) ? $a->id : 0);
		$bid = (int) (isset($b->id) ? $b->id : 0);
		if (!isset($order[$aid]) && !isset($order[$bid])) {
			return 0;
		}
		if (!isset($order[$aid])) {
			return 1;
		}
		if (!isset($order[$bid])) {
			return -1;
		}
		return ($order[$aid] < $order[$bid]) ? -1 : (($order[$aid] > $order[$bid]) ? 1 : 0);
	});
	return $products;
}

/**
 * Products for sale of a category, with the custom sort of the fluid grid when
 * FLUXPOS_SORTPRODUCTFIELD is 'position' or 'bestsales'.
 *
 * @param  Categorie $object Fetched category
 * @param  string    $filter Universal search filter of getObjectsInCateg()
 * @param  int       $limit  See getObjectsInCateg()
 * @param  int       $offset See getObjectsInCateg()
 * @return array|int         Array of Product, or -1 on error
 */
function fluxposGetProductsOfCategory($object, $filter, $limit, $offset)
{
	$sortfield = getDolGlobalString('FLUXPOS_SORTPRODUCTFIELD');
	if ($sortfield != 'position' && $sortfield != 'bestsales') {
		return $object->getObjectsInCateg("product", 0, $limit, $offset, $sortfield, 'ASC', $filter);
	}

	// Custom order: the ids of the custom SQL drive the final order, so the fetch
	// runs without limit and the historical pagination is applied afterwards.
	$orderedids = fluxposCustomSortedProductIds($object->id, $sortfield);
	$prods = $object->getObjectsInCateg("product", 0, 0, 0, '', 'ASC', $filter);
	if ($prods == -1 || !is_array($prods)) {
		return $prods;
	}
	if (is_array($orderedids)) {
		$prods = fluxposOrderProductsBy($prods, $orderedids);
	}
	if ($offset > 0) {
		$prods = array_slice($prods, $offset);
	}
	if ($limit > 0) {
		$prods = array_slice($prods, 0, $limit);
	}
	return $prods;
}

/**
 * Return the products for sale of a category, trimmed to the fields used by the POS grid and search.
 * Used to build the catalog synchronized into IndexedDB by js/offline11.js.
 *
 * @param  int $categid    ID of category
 * @param  int $pricelevel Price level to use for prices
 * @return array           Array of products
 */
function getFluxPosProductsForCategory($categid, $pricelevel)
{
	global $conf, $db, $langs;

	$object = new Categorie($db);
	$result = $object->fetch($categid);
	if ($result <= 0) {
		return array();
	}

	$prods = fluxposGetProductsOfCategory($object, '(o.tosell:=:1)', 0, 0);

	$res = array();
	if (is_array($prods) && count($prods) > 0) {
		foreach ($prods as $prod) {
			'@phan-var-force Product $prod';
			$res[] = array(
				'id' => $prod->id,
				'rowid' => $prod->id,
				'ref' => $prod->ref,
				'label' => $prod->label,
				'barcode' => $prod->barcode,
				'price' => $prod->price,
				'price_ttc' => $prod->price_ttc,
				'price_formated' => price(price2num(empty($prod->multiprices[$pricelevel]) ? $prod->price : $prod->multiprices[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency),
				'price_ttc_formated' => price(price2num(empty($prod->multiprices_ttc[$pricelevel]) ? $prod->price_ttc : $prod->multiprices_ttc[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency)
			);
		}
	}

	return $res;
}

/**
 * Send the receipt of an invoice to the receipt printer of a terminal.
 * Same behavior as the printinvoiceticket action, usable from other actions, for
 * example to print automatically the receipt of a sale validated offline, at the
 * moment its synchronization reaches the server. Does nothing when no receipt
 * printer is configured for the terminal.
 *
 * @param  int    $id   ID of invoice to print
 * @param  string $term Terminal number
 * @param  User   $user User asking the printing
 * @return void
 */
function fluxposPrintInvoiceTicket($id, $term, $user)
{
	global $db;

	if (empty($id) || $id <= 0) {
		return;
	}

	require_once dol_buildpath('/fluxpos/class/dolreceiptprinter.class.php');
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	// check printer for terminal
	if (getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term) <= 0 && getDolGlobalString('FLUXPOS_PRINT_METHOD') != "takeposconnector") {
		return;
	}

	$object = new Facture($db);
	$object->fetch($id);

	$resultinccounter = 0;
	$templateidtouse = 0;

	// Increase of counter is managed by the file that generate the ticket, so "receipt.php"

	// Call trigger to log the $action 'DOC_PREVIEW' or 'DOC_DOWNLOAD'
	$result = $object->call_trigger('DOC_PREVIEW', $user);

	if ($result >= 0) {
		$resultinccounter++;
		$object->pos_print_counter++;	// increase counter to match the change in database

		$templateidtouse = getDolGlobalInt('FLUXPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term);	// Note that this may not be ignored by the sendToPrinter()
	}

	if ($resultinccounter) {
		// Send to printer
		$printer = new dolReceiptPrinter($db);
		$printer->sendToPrinter($object, $templateidtouse, getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term));
	} else {
		print 'Failed to update print counter for object ID='.$object->id.' so we refuse to print.';
	}
}

/**
 * Add the captured product lines to a draft invoice. A product already on the draft is
 * not added twice: its quantity is only raised to the captured quantity, never lowered
 * (same rule as invoice.php action=addline), so replaying the same capture is harmless.
 * The caller owns the database transaction.
 *
 * @param  Facture $invoice  Draft invoice (with its lines loaded)
 * @param  Societe $customer Customer of the invoice
 * @param  array   $lines    Captured lines: array('id' => product id, 'qty' => qty)
 * @return int               0 if OK, -1 if error (message in $invoice->error)
 */
function fluxposReconcileLines($invoice, $customer, $lines)
{
	global $db, $mysoc;

	foreach ($lines as $linetarget) {
		$idproduct = (int) $linetarget['id'];
		$qty = (float) $linetarget['qty'];
		if ($idproduct <= 0 || $qty <= 0) {
			continue;
		}

		$prod = new Product($db);
		if ($prod->fetch($idproduct) <= 0) {
			// Product does not exist anymore: keep the other lines instead of failing the whole capture
			continue;
		}

		$datapriceofproduct = $prod->getSellPrice($mysoc, $customer, 0);
		$price = $datapriceofproduct['pu_ht'];
		$price_ttc = $datapriceofproduct['pu_ttc'];
		$price_base_type = empty($datapriceofproduct['price_base_type']) ? 'HT' : $datapriceofproduct['price_base_type'];
		$tva_tx = $datapriceofproduct['tva_tx'];
		$tva_npr = (int) $datapriceofproduct['tva_npr'];
		$localtax1_tx = get_localtax($tva_tx, 1, $customer, $mysoc, $tva_npr);
		$localtax2_tx = get_localtax($tva_tx, 2, $customer, $mysoc, $tva_npr);

		// Search an existing line for the same product, to update it instead of adding a duplicate
		$existingline = null;
		foreach ($invoice->lines as $line) {
			if ($line->fk_product == $idproduct && $line->fk_parent_line <= 0 && $line->special_code != 4) {
				$existingline = $line;
				break;
			}
		}

		if ($existingline !== null && $qty > $existingline->qty + 0.0001) {
			$vatratecode = $existingline->tva_tx;
			if ($existingline->vat_src_code) {
				$vatratecode .= ' ('.$existingline->vat_src_code.')';
			}
			$result = $invoice->updateline($existingline->id, $existingline->desc, $existingline->subprice, $qty, $existingline->remise_percent, $existingline->date_start, $existingline->date_end, $vatratecode, $existingline->localtax1_tx, $existingline->localtax2_tx, 'HT', $existingline->info_bits, $existingline->product_type, $existingline->fk_parent_line, 0, $existingline->fk_fournprice, $existingline->pa_ht, $existingline->label, $existingline->special_code, $existingline->array_options, $existingline->situation_percent, $existingline->fk_unit);
			if ($result < 0) {
				return -1;
			}
		} elseif ($existingline === null) {
			$line = array('description' => $prod->description, 'price' => $price, 'tva_tx' => $tva_tx, 'localtax1_tx' => $localtax1_tx, 'localtax2_tx' => $localtax2_tx, 'remise_percent' => $customer->remise_percent, 'price_ttc' => $price_ttc, 'array_options' => array());

			/* setup of margin calculation */
			if (getDolGlobalString('MARGIN_TYPE')) {
				if (getDolGlobalString('MARGIN_TYPE') == 'pmp' && !empty($prod->pmp)) {
					$line['fk_fournprice'] = null;
					$line['pa_ht'] = $prod->pmp;
				} elseif (getDolGlobalString('MARGIN_TYPE') == 'costprice' && !empty($prod->cost_price)) {
					$line['fk_fournprice'] = null;
					$line['pa_ht'] = $prod->cost_price;
				} else {
					// default is fournprice
					require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
					$pf = new ProductFournisseur($db);
					if ($pf->find_min_price_product_fournisseur($idproduct, $qty) > 0) {
						$line['fk_fournprice'] = $pf->product_fourn_price_id;
						$line['pa_ht'] = $pf->fourn_unitprice_with_discount;
						if (getDolGlobalString('PRODUCT_CHARGES') && $pf->fourn_charges > 0) {
							$line['pa_ht'] += (float) $pf->fourn_charges / $pf->fourn_qty;
						}
					}
				}
			}

			$idoflineadded = $invoice->addline($line['description'], $line['price'], $qty, $line['tva_tx'], $line['localtax1_tx'], $line['localtax2_tx'], $idproduct, (float) $line['remise_percent'], '', 0, 0, 0, 0, $price_base_type, $line['price_ttc'], $prod->type, -1, 0, '', 0, '', (empty($line['fk_fournprice']) ? 0 : $line['fk_fournprice']), (empty($line['pa_ht']) ? '' : $line['pa_ht']), '', $line['array_options'], 100, 0, $prod->fk_unit, 0);
			if ($idoflineadded < 0) {
				return -1;
			}
		}
	}

	return 0;
}

/**
 * Validate a draft invoice and record the payments captured on the terminal, exactly like
 * invoice.php action=valid does (validation with or without stock move, cash fence check,
 * payment recorded into the bank account of the terminal for its payment mode, credit
 * note conversion of a negative sale, change given for cash). The invoice number is
 * attributed here by the numbering module of Dolibarr.
 * The caller owns the database transaction and must roll back when an error is returned.
 *
 * @param  Facture $invoice        Draft invoice with its lines
 * @param  array   $payments       Payments: array('pay' => 'cash'|'card'|'cheque'|'LIQ'|..., 'amount', 'excess', 'accountid')
 * @param  int     $fluxposterminal Terminal number
 * @param  bool    $validatedhere  Set to true when the invoice was validated by this call
 * @return string                  '' if OK, else the error message
 */
function fluxposValidateAndPay($invoice, $payments, $fluxposterminal, &$validatedhere)
{
	global $conf, $db, $langs, $user;

	$validatedhere = false;

	// Reload the invoice so its lines and totals include the lines just added
	if ($invoice->fetch($invoice->id) <= 0) {
		return ($invoice->error ? $invoice->error : 'Invoice not found');
	}

	// Test that the period is not close: a cash control closed for today on this
	// terminal blocks any sale (same as invoice.php)
	$tmpcurrentday = dol_getdate(dol_now());
	$sql = "SELECT MIN(ref) as firstref FROM ".MAIN_DB_PREFIX."pos_cash_fence";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND posnumber = ".((int) $fluxposterminal);
	$sql .= " AND year_close = ".((int) $tmpcurrentday['year']);
	$sql .= " AND (";
	$sql .= " (month_close IS NULL AND day_close IS NULL)";
	$sql .= " OR (month_close = ".((int) $tmpcurrentday['mon'])." AND day_close IS NULL)";
	$sql .= " OR (month_close = ".((int) $tmpcurrentday['mon'])." AND day_close = ".((int) $tmpcurrentday['mday']).")";
	$sql .= ")";
	$sql .= " AND status = 1";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && $obj->firstref) {
			return $langs->trans("ACashControlHasBeenclosedForCurrentDay", $obj->firstref);
		}
	}

	// Draft invoice with a negative total: transform into a credit note of the last
	// validated invoice of the customer (same as invoice.php)
	if ($invoice->status == Facture::STATUS_DRAFT && $invoice->total_ttc < 0) {
		$invoice->type = $invoice::TYPE_CREDIT_NOTE;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture";
		$sql .= " WHERE entity IN (".getEntity('invoice').")";
		$sql .= " AND fk_soc = ".((int) $invoice->socid);
		$sql .= " AND type <> ".Facture::TYPE_CREDIT_NOTE;
		$sql .= " AND fk_statut >= ".$invoice::STATUS_VALIDATED;
		$sql .= " ORDER BY rowid DESC";

		$fk_source = 0;
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj) {
				$fk_source = $obj->rowid;
			}
		}
		if ($fk_source == 0) {
			return $langs->trans("NoPreviousBillForCustomer");
		}
		$invoice->fk_facture_source = $fk_source;
		$invoice->update($user);
	}

	// Validation of the draft invoice: the invoice number is attributed here by the
	// numbering module (same rules as invoice.php for the stock changes)
	if ($invoice->status == Facture::STATUS_DRAFT) {
		$constantforkey = 'FLUXPOS_NO_DECREASE_STOCK'.$fluxposterminal;
		$allowstockchange = (getDolGlobalString($constantforkey) != "1");

		if (isModEnabled('stock') && !isModEnabled('productbatch') && $allowstockchange) {
			// Validation with a stock change into the warehouse of the terminal
			$savconst = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
			$conf->global->STOCK_CALCULATE_ON_BILL = 1;	// To force the change of stock during invoice validation
			$constantforkey = 'FLUXPOS_ID_WAREHOUSE'.$fluxposterminal;
			$res = $invoice->validate($user, '', getDolGlobalInt($constantforkey), 0, 0);
			$conf->global->STOCK_CALCULATE_ON_BILL = $savconst;
		} else {
			// Validation with no change into stock (same as invoice.php)
			$res = $invoice->validate($user);
		}
		if ($res < 0) {
			return ($invoice->error == 'NotConfigured' ? $langs->trans("NotConfigured").' (FluxPos numbering module)' : $invoice->error);
		}
		$validatedhere = true;
	}

	// Record the payments captured on the terminal (same rules as invoice.php)
	if (is_array($payments) && count($payments) > 0) {
		$now = dol_now();
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

		$lastpaiementid = 0;
		foreach ($payments as $paymentinfo) {
			if (!is_array($paymentinfo)) {
				continue;
			}
			$pay = isset($paymentinfo['pay']) ? (string) $paymentinfo['pay'] : '';
			$paycode = $pay;
			if ($pay == 'cash') {
				$paycode = 'LIQ';	// For backward compatibility
			}
			if ($pay == 'card') {
				$paycode = 'CB';	// For backward compatibility
			}
			if ($pay == 'cheque') {
				$paycode = 'CHQ';	// For backward compatibility
			}

			if ($paycode == 'delayed') {
				// Payment will be made outside the POS
				if (getDolGlobalInt("FLUXPOS_DELAYED_TERMS")) {
					$invoice->setPaymentTerms(getDolGlobalInt("FLUXPOS_DELAYED_TERMS"));
				}
				continue;
			}

			// Search the payment mode id from its code (same as invoice.php)
			$paiementid = 0;
			if ($paycode) {
				$sql = "SELECT id, code FROM ".MAIN_DB_PREFIX."c_paiement";
				$sql .= " WHERE active = 1 AND code = '".$db->escape($paycode)."'";
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj) {
						$paiementid = $obj->id;
					}
				}
			}

			// Bank account of the terminal for this payment mode (same as invoice.php)
			$bankaccount = 0;
			if (!empty($paymentinfo['accountid'])) {
				$bankaccount = (int) $paymentinfo['accountid'];
			} elseif ($paycode == 'LIQ') {
				$bankaccount = getDolGlobalInt('FLUXPOS_ID_BANKACCOUNT_CASH'.$fluxposterminal);	// For backward compatibility
			} elseif ($paycode == 'CHQ') {
				$bankaccount = getDolGlobalInt('FLUXPOS_ID_BANKACCOUNT_CHEQUE'.$fluxposterminal);	// For backward compatibility
			} else {
				$bankaccount = getDolGlobalInt('FLUXPOS_ID_BANKACCOUNT_'.$paycode.$fluxposterminal);
			}
			if ($bankaccount <= 0 && isModEnabled("bank")) {
				return $langs->trans("ErrorModuleSetupNotComplete", "FluxPos").' (bank account of terminal '.$fluxposterminal.' for payment mode '.$paycode.')';
			}

			$remaintopay = $invoice->getRemainToPay();
			if (!(($remaintopay > 0 && $invoice->type != Facture::TYPE_CREDIT_NOTE) || ($remaintopay < 0 && $invoice->type == Facture::TYPE_CREDIT_NOTE))) {
				continue;	// Nothing more to pay
			}

			$amountofpayment = (float) (isset($paymentinfo['amount']) ? $paymentinfo['amount'] : 0);
			// Same rule as invoice.php: a missing amount, or an amount higher than the
			// remain to pay (money received including the change), is capped to the remain to pay
			if ($amountofpayment <= 0 || $amountofpayment > $remaintopay) {
				$amountofpayment = $remaintopay;
			}

			$payment = new Paiement($db);
			$payment->datepaye = $now;
			$payment->fk_account = $bankaccount;
			if ($paycode == 'LIQ') {
				$payment->pos_change = !empty($paymentinfo['excess']) ? (float) $paymentinfo['excess'] : 0;
			}
			$payment->amounts[$invoice->id] = $amountofpayment;
			// We do not set $payment->multicurrency_amounts because we want payment to be in main currency
			$payment->paiementid = $paiementid;
			$payment->paiementcode = $paycode;
			$payment->num_payment = '';

			$res = $payment->create($user);		// This record payment and regenerate the PDF
			if ($res < 0) {
				return ($payment->error ? $payment->error : $db->lasterror());
			}
			$res = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankaccount, '', '');
			if ($res < 0) {
				return ($payment->error ? $payment->error : $db->lasterror());
			}
			$lastpaiementid = $paiementid;
		}

		// Recalculate the remain to pay after the payments have been recorded
		$remaintopay = $invoice->getRemainToPay();
		if ($remaintopay == 0 && $lastpaiementid > 0) {
			$result = $invoice->setPaid($user);
			if ($result > 0) {
				$invoice->paye = 1;
				$invoice->status = $invoice::STATUS_CLOSED;
				$invoice->close_code = '';
			}
			// set payment method
			$invoice->setPaymentMethods($lastpaiementid);
		}
	}

	return '';
}

/**
 * Synchronize an offline order (not paid yet) into the draft invoice of a place.
 * The draft invoice of the place is created if it does not exist yet (same rules as
 * invoice.php action=addline), then its product lines are reconciled with the line
 * list captured on the terminal (see fluxposReconcileLines()): nothing is ever deleted
 * or lowered, so the call can be replayed at any time without duplicating lines.
 *
 * Paid sales are not sent here but to fluxposSyncOfflineSale(), which books each sale
 * exactly once. The $valid mode is kept only for terminals still running an older
 * offline.js that locks the cart of the place instead of creating a sale.
 *
 * @param  string $place    Place (table) of the order
 * @param  array  $lines    Array of captured lines: array('id' => product id, 'qty' => qty)
 * @param  int    $socid    Thirdparty id to use if the invoice must be created
 * @param  array  $payments Payments captured on the terminal (only with $valid)
 * @param  bool   $valid    Validate the sale and record the payments (older terminals only)
 * @return array            Result info: array('placeid', 'socid', 'total_ttc', 'ref', 'paid') or array('error' => ...)
 */
function fluxposSyncOfflineOrder($place, $lines, $socid, $payments = array(), $valid = false)
{
	global $conf, $db, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

	$langs->loadLangs(array("bills", "errors", "admin", "fluxpos@fluxpos"));

	$fluxposterminal = empty($_SESSION['fluxposterminal']) ? 1 : $_SESSION['fluxposterminal'];
	$constforcompanyid = 'FLUXPOS_ID_THIRDPARTY'.$fluxposterminal;

	$invoice = new Facture($db);

	// Resolve the draft invoice of the place. An empty capture (order cleared while
	// offline) must never create an empty draft invoice.
	$haslines = false;
	foreach ($lines as $linetarget) {
		if ((int) $linetarget['id'] > 0 && (float) $linetarget['qty'] > 0) {
			$haslines = true;
			break;
		}
	}
	$ret = $invoice->fetch(0, '(PROV-POS'.$fluxposterminal.'-'.$place.')');
	if ($ret <= 0) {
		if (!$haslines) {
			return array('place' => $place, 'socid' => 0, 'total_ttc' => 0, 'created' => 0);
		}
		$invoice->socid = ($socid > 0) ? $socid : getDolGlobalInt($constforcompanyid);
		if ($invoice->socid <= 0) {
			return array('error' => $langs->trans("ErrorModuleSetupNotComplete", "FluxPos"));
		}

		$dolnowtzuserrel = dol_now('tzuserrel');	// If user is 02 january 22:00, we want to store '02 january'
		$monthuser = dol_print_date($dolnowtzuserrel, '%m', 'gmt');
		$dayuser = dol_print_date($dolnowtzuserrel, '%d', 'gmt');
		$yearuser = dol_print_date($dolnowtzuserrel, '%Y', 'gmt');
		$invoice->date = dol_mktime(0, 0, 0, (int) $monthuser, (int) $dayuser, (int) $yearuser, 'tzserver');

		$invoice->module_source = 'fluxpos';
		$invoice->pos_source = $fluxposterminal;
		$invoice->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;

		$db->begin();
		$placeid = $invoice->create($user);
		if ($placeid < 0) {
			$db->rollback();
			return array('error' => $invoice->error);
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."facture";
		$sql .= " SET ref='(PROV-POS".$fluxposterminal."-".$place.")'";
		$sql .= " WHERE rowid = ".((int) $placeid);
		if (!$db->query($sql)) {
			$db->rollback();
			return array('error' => $db->lasterror());
		}
		$db->commit();

		$invoice->fetch($placeid);
	}

	if ($invoice->status != $invoice::STATUS_DRAFT) {
		return array('error' => 'Invoice of place '.$place.' is not a draft anymore');
	}

	$customer = new Societe($db);
	$customer->fetch($invoice->socid);

	$db->begin();

	if (fluxposReconcileLines($invoice, $customer, $lines) < 0) {
		$errmsg = ($invoice->error ? $invoice->error : $db->lasterror());
		$db->rollback();
		return array('error' => $errmsg);
	}

	$validatedhere = false;
	if ($valid) {
		if (!$haslines) {
			$db->rollback();
			return array('error' => $langs->trans("NoLinesToBill", "FluxPos"));
		}
		$errmsg = fluxposValidateAndPay($invoice, $payments, $fluxposterminal, $validatedhere);
		if ($errmsg !== '') {
			$db->rollback();
			return array('error' => $errmsg);
		}
	}

	$db->commit();

	$invoice->fetch($invoice->id);

	// The receipt of a sale validated by this synchronization is printed on the receipt
	// printer of the terminal when there is one configured
	if ($validatedhere) {
		fluxposPrintInvoiceTicket($invoice->id, $fluxposterminal, $user);
	}

	return array('placeid' => $invoice->id, 'place' => $place, 'socid' => $invoice->socid, 'total_ttc' => $invoice->total_ttc, 'ref' => $invoice->ref, 'paid' => (int) $invoice->paye);
}

/**
 * Return the result of a sale already booked by fluxposSyncOfflineSale().
 *
 * @param  string     $saleuid Unique id of the sale, made by the terminal
 * @return array|null          Result info (with 'duplicate' => 1), or null when the sale is not booked yet
 */
function fluxposFindBookedSale($saleuid)
{
	global $conf, $db;

	$sql = "SELECT s.fk_facture, f.ref, f.total_ttc, f.paye";
	$sql .= " FROM ".MAIN_DB_PREFIX."fluxpos_sale as s";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = s.fk_facture";
	$sql .= " WHERE s.entity = ".((int) $conf->entity);
	$sql .= " AND s.sale_uid = '".$db->escape($saleuid)."'";
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		return null;
	}

	return array('uid' => $saleuid, 'placeid' => (int) $obj->fk_facture, 'ref' => $obj->ref, 'total_ttc' => $obj->total_ttc, 'paid' => (int) $obj->paye, 'duplicate' => 1);
}

/**
 * Book one sale paid on a terminal while it was offline: a new invoice with its lines,
 * validated (invoice number from the numbering module) and paid, in ONE database
 * transaction. The sale id made by the terminal is stored with a unique key in the
 * same transaction, so a sale sent twice (the terminal did not get the first answer,
 * two tabs syncing at once) is booked only once: the second call just returns the
 * invoice of the first one.
 *
 * @param  string $saleuid  Unique id of the sale, made by the terminal
 * @param  string $place    Place the sale was made at (for information only)
 * @param  array  $lines    Lines: array('id' => product id, 'qty' => qty)
 * @param  int    $socid    Thirdparty id (0 = default customer of the terminal)
 * @param  array  $payments Payments: array('pay', 'amount', 'excess', 'accountid')
 * @param  int    $datesale Time of the sale on the terminal (timestamp, 0 if unknown)
 * @return array            Result info: array('uid', 'placeid', 'ref', 'total_ttc', 'paid', 'duplicate') or array('error' => ...)
 */
function fluxposSyncOfflineSale($saleuid, $place, $lines, $socid, $payments, $datesale)
{
	global $conf, $db, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

	$langs->loadLangs(array("bills", "errors", "admin", "fluxpos@fluxpos"));

	if (!preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $saleuid)) {
		return array('error' => 'Bad request: invalid sale id');
	}

	// Already booked: the terminal did not get the first answer, give it again
	$booked = fluxposFindBookedSale($saleuid);
	if ($booked !== null) {
		return $booked;
	}

	$haslines = false;
	foreach ($lines as $linetarget) {
		if ((int) $linetarget['id'] > 0 && (float) $linetarget['qty'] > 0) {
			$haslines = true;
			break;
		}
	}
	if (!$haslines) {
		return array('error' => $langs->trans("NoLinesToBill", "FluxPos"));
	}

	$fluxposterminal = empty($_SESSION['fluxposterminal']) ? 1 : $_SESSION['fluxposterminal'];
	$constforcompanyid = 'FLUXPOS_ID_THIRDPARTY'.$fluxposterminal;

	$db->begin();

	// Claim the sale id first: the unique key makes a second, concurrent call for the
	// same sale fail here (it waits for the first one, then sees it booked)
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."fluxpos_sale (entity, sale_uid, terminal, place, date_sale, date_creation, fk_user_creat)";
	$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($saleuid)."', '".$db->escape((string) $fluxposterminal)."', '".$db->escape(dol_substr((string) $place, 0, 64))."',";
	$sql .= " ".($datesale > 0 ? "'".$db->idate($datesale)."'" : "NULL").", '".$db->idate(dol_now())."', ".((int) $user->id).")";
	if (!$db->query($sql)) {
		$errno = $db->lasterrno();
		$errmsg = $db->lasterror();
		$db->rollback();
		if ($errno == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			$booked = fluxposFindBookedSale($saleuid);
			if ($booked !== null) {
				return $booked;
			}
		}
		return array('error' => $errmsg);
	}
	$saleid = $db->last_insert_id(MAIN_DB_PREFIX."fluxpos_sale");

	$invoice = new Facture($db);
	$invoice->socid = ($socid > 0) ? $socid : getDolGlobalInt($constforcompanyid);
	if ($invoice->socid <= 0) {
		$db->rollback();
		return array('error' => $langs->trans("ErrorModuleSetupNotComplete", "FluxPos"));
	}

	$dolnowtzuserrel = dol_now('tzuserrel');	// If user is 02 january 22:00, we want to store '02 january'
	$monthuser = dol_print_date($dolnowtzuserrel, '%m', 'gmt');
	$dayuser = dol_print_date($dolnowtzuserrel, '%d', 'gmt');
	$yearuser = dol_print_date($dolnowtzuserrel, '%Y', 'gmt');
	$invoice->date = dol_mktime(0, 0, 0, (int) $monthuser, (int) $dayuser, (int) $yearuser, 'tzserver');

	$invoice->module_source = 'fluxpos';
	$invoice->pos_source = $fluxposterminal;
	$invoice->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;

	if ($invoice->create($user) < 0) {
		$errmsg = ($invoice->error ? $invoice->error : $db->lasterror());
		$db->rollback();
		return array('error' => $errmsg);
	}
	$invoice->fetch($invoice->id);

	$customer = new Societe($db);
	$customer->fetch($invoice->socid);

	if (fluxposReconcileLines($invoice, $customer, $lines) < 0) {
		$errmsg = ($invoice->error ? $invoice->error : $db->lasterror());
		$db->rollback();
		return array('error' => $errmsg);
	}

	// Products deleted since the sale are skipped: a sale with none left must not be
	// booked as an empty invoice (the money taken would be recorded nowhere)
	$invoice->fetch($invoice->id);
	if (empty($invoice->lines)) {
		$db->rollback();
		return array('error' => 'None of the products of this sale exist anymore: book it by hand');
	}

	$validatedhere = false;
	$errmsg = fluxposValidateAndPay($invoice, $payments, $fluxposterminal, $validatedhere);
	if ($errmsg !== '') {
		$db->rollback();
		return array('error' => $errmsg);
	}

	$sql = "UPDATE ".MAIN_DB_PREFIX."fluxpos_sale SET fk_facture = ".((int) $invoice->id)." WHERE rowid = ".((int) $saleid);
	if (!$db->query($sql)) {
		$errmsg = $db->lasterror();
		$db->rollback();
		return array('error' => $errmsg);
	}

	$db->commit();

	$invoice->fetch($invoice->id);

	// The receipt is printed on the receipt printer of the terminal when one is configured
	if ($validatedhere) {
		fluxposPrintInvoiceTicket($invoice->id, $fluxposterminal, $user);
	}

	return array('uid' => $saleuid, 'placeid' => $invoice->id, 'ref' => $invoice->ref, 'total_ttc' => $invoice->total_ttc, 'paid' => (int) $invoice->paye, 'duplicate' => 0);
}



/*
 * View
 */

$thirdparty = new Societe($db);

if ($action == 'getProducts' && $user->hasRight('fluxpos', 'run')) {
	$tosell = GETPOSTISSET('tosell') ? GETPOSTINT('tosell') : '';
	$limit = GETPOSTISSET('limit') ? GETPOSTINT('limit') : 0;
	$offset = GETPOSTISSET('offset') ? GETPOSTINT('offset') : 0;

	top_httphead('application/json');

	// Search
	if (GETPOSTINT('thirdpartyid') > 0) {
		$result = $thirdparty->fetch(GETPOSTINT('thirdpartyid'));
		if ($result > 0) {
			$pricelevel = $thirdparty->price_level;
		}
	}

	$object = new Categorie($db);
	if ($category == "supplements") {
		$category = getDolGlobalInt('FLUXPOS_SUPPLEMENTS_CATEGORY');
		if (empty($category)) {
			echo 'Error, the category to use for supplements is not defined. Go into setup of module FluxPos.';
			exit;
		}
	}

	$result = $object->fetch($category);
	if ($result > 0) {
		$filter = '';
		if ($tosell != '') {
			$filter = '(o.tosell:=:'.((int) $tosell).')';
		}
		$prods = fluxposGetProductsOfCategory($object, $filter, $limit, $offset);
		// Removed properties we don't need
		$res = array();
		if (is_array($prods) && count($prods) > 0) {
			$productChildrenNb = 0;
			foreach ($prods as $prod) {
				'@phan-var-force Product $prod';
				if (getDolGlobalInt('FLUXPOS_PRODUCT_IN_STOCK') == 1) {
					if (getDolGlobalInt('PRODUIT_SOUSPRODUITS')) {
						$productChildrenNb = $prod->hasFatherOrChild(1);
					}
					// always show virtual products (don't manage stock)
					if ($productChildrenNb == 0) {
						// remove products without stock
						$prod->load_stock('nobatch,novirtual');
						if ($prod->stock_warehouse[getDolGlobalString('FLUXPOS_ID_WAREHOUSE'.$_SESSION['fluxposterminal'])]->real <= 0) {
							continue;
						}
					}
				}
				unset($prod->fields);
				unset($prod->db);

				$prod->price_formated = price(price2num(empty($prod->multiprices[$pricelevel]) ? $prod->price : $prod->multiprices[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency);
				$prod->price_ttc_formated = price(price2num(empty($prod->multiprices_ttc[$pricelevel]) ? $prod->price_ttc : $prod->multiprices_ttc[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency);

				$res[] = $prod;
			}
		}
		echo json_encode($res);
	} else {
		echo 'Failed to load category with id='.dol_escape_htmltag($category);
	}
} elseif ($action == 'search' && $search_term != '' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/json');

	// Search barcode into third parties. If found, it means we want to change third parties.
	$result = $thirdparty->fetch(0, '', '', $search_term);

	if ($result && $thirdparty->id > 0) {
		$rows = array();
		$rows[] = array(
				'rowid' => $thirdparty->id,
				'name' => $thirdparty->name,
				'barcode' => $thirdparty->barcode,
				'object' => 'thirdparty'
			);
		echo json_encode($rows);
		exit;
	}

	// Search
	if (GETPOSTINT('thirdpartyid') > 0) {
		$result = $thirdparty->fetch(GETPOSTINT('thirdpartyid'));
		if ($result > 0) {
			$pricelevel = $thirdparty->price_level;
		}
	}

	// Define $filteroncategids, the filter on category ID if there is a Root category defined.
	$filteroncategids = '';
	if (getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID') > 0) {	// A root category is defined, we must filter on products inside this category tree
		$object = new Categorie($db);
		//$result = $object->fetch($conf->global->FLUXPOS_ROOT_CATEGORY_ID);
		$arrayofcateg = $object->get_full_arbo('product', getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID'), 1);
		if (is_array($arrayofcateg) && count($arrayofcateg) > 0) {
			foreach ($arrayofcateg as $val) {
				$filteroncategids .= ($filteroncategids ? ', ' : '').$val['id'];
			}
		}
	}

	$barcode_rules = getDolGlobalString('FLUXPOS_BARCODE_RULE_TO_INSERT_PRODUCT');
	if (isModEnabled('barcode') && !empty($barcode_rules)) {
		$barcode_rules_list = array();

		// get barcode rules
		$barcode_char_nb = 0;
		$barcode_rules_arr = explode('+', $barcode_rules);
		foreach ($barcode_rules_arr as $barcode_rules_values) {
			$barcode_rules_values_arr = explode(':', $barcode_rules_values);
			if (count($barcode_rules_values_arr) == 2) {
				$char_nb = intval($barcode_rules_values_arr[1]);
				$barcode_rules_list[] = array('code' => $barcode_rules_values_arr[0], 'char_nb' => $char_nb);
				$barcode_char_nb += $char_nb;
			}
		}

		$barcode_value_list = array();
		$barcode_offset = 0;
		$barcode_length = dol_strlen($search_term);
		if ($barcode_length == $barcode_char_nb) {
			$rows = array();

			// split term with barcode rules
			foreach ($barcode_rules_list as $barcode_rule_arr) {
				$code = $barcode_rule_arr['code'];
				$char_nb = $barcode_rule_arr['char_nb'];
				$barcode_value_list[$code] = substr($search_term, $barcode_offset, $char_nb);
				$barcode_offset += $char_nb;
			}

			if (isset($barcode_value_list['ref'])) {
				// search product from reference
				$sql  = "SELECT rowid, ref, label, tosell, tobuy, barcode, price, price_ttc";
				$sql .= " FROM " . $db->prefix() . "product as p";
				$sql .= " WHERE entity IN (" . getEntity('product') . ")";
				$sql .= " AND ref = '" . $db->escape($barcode_value_list['ref']) . "'";
				if ($filteroncategids) {
					$sql .= " AND EXISTS (SELECT cp.fk_product FROM " . $db->prefix() . "categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN (".$db->sanitize($filteroncategids)."))";
				}
				$sql .= " AND tosell = 1";
				$sql .= " AND (barcode IS NULL OR barcode <> '" . $db->escape($search_term) . "')";

				$resql = $db->query($sql);
				if ($resql && $db->num_rows($resql) == 1) {
					if ($obj = $db->fetch_object($resql)) {
						$qty = 1;
						if (isset($barcode_value_list['qu'])) {
							$qty_str = $barcode_value_list['qu'];
							if (isset($barcode_value_list['qd'])) {
								$qty_str .= '.' . $barcode_value_list['qd'];
							}
							$qty = (float) $qty_str;
						}

						$objProd = new Product($db);
						$objProd->fetch($obj->rowid);

						$ig = '../public/theme/common/nophoto.png';
						if (!getDolGlobalString('FLUXPOS_HIDE_PRODUCT_IMAGES')) {
							$image = $objProd->show_photos('product', $conf->product->multidir_output[(int) $objProd->entity], 'small', 1);

							$match = array();
							preg_match('@src="([^"]+)"@', $image, $match);
							$file = array_pop($match);

							if ($file != '') {
								if (!defined('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE')) {
									$ig = $file.'&cache=1';
								} else {
									$ig = $file.'&cache=1&publicfluxpos=1&modulepart=product';
								}
							}
						}

						$rows[] = array(
							'rowid' => $obj->rowid,
							'ref' => $obj->ref,
							'label' => $obj->label,
							'tosell' => $obj->tosell,
							'tobuy' => $obj->tobuy,
							'barcode' => $search_term, // there is only one product matches the barcode rule and so the term is considered as the barcode of this product
							'price' => empty($objProd->multiprices[$pricelevel]) ? $obj->price : $objProd->multiprices[$pricelevel],
							'price_ttc' => empty($objProd->multiprices_ttc[$pricelevel]) ? $obj->price_ttc : $objProd->multiprices_ttc[$pricelevel],
							'object' => 'product',
							'img' => $ig,
							'qty' => $qty,
						);
					}
					$db->free($resql);
				}
			}

			if (count($rows) == 1) {
				echo json_encode($rows);
				exit();
			}
		}
	}

	$sql = 'SELECT p.rowid, p.ref, p.label, p.tosell, p.tobuy, p.barcode, p.price, p.price_ttc' ;
	if (getDolGlobalInt('FLUXPOS_PRODUCT_IN_STOCK') == 1) {
		if (getDolGlobalInt('FLUXPOS_ID_WAREHOUSE'.$_SESSION['fluxposterminal'])) {
			$sql .= ', ps.reel';
		} else {
			$sql .= ', SUM(ps.reel) as reel';
		}
	}
	/* this will be possible when field archive will be supported into llx_product_price
	if (getDolGlobalString('PRODUIT_MULTIPRICES')) {
		$sql .= ', pp.price_level, pp.price as multiprice_ht, pp.price_ttc as multiprice_ttc';
	}*/
	// Add fields from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters);
	if ($reshook >= 0) {
		$sql .= $hookmanager->resPrint;
	}

	$sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';
	/* this will be possible when field archive will be supported into llx_product_price
	if (getDolGlobalString('PRODUIT_MULTIPRICES')) {
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_price as pp ON pp.fk_product = p.rowid AND pp.entity = ".((int) $conf->entity)." AND pp.price_level = ".((int) $pricelevel);
		$sql .= " AND archive = 0";
	}*/
	if (getDolGlobalInt('FLUXPOS_PRODUCT_IN_STOCK') == 1) {
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_stock as ps';
		$sql .= ' ON (p.rowid = ps.fk_product';
		if (getDolGlobalString('FLUXPOS_ID_WAREHOUSE'.$_SESSION['fluxposterminal'])) {
			$sql .= " AND ps.fk_entrepot = ".((int) getDolGlobalInt("FLUXPOS_ID_WAREHOUSE".$_SESSION['fluxposterminal']));
		}
		$sql .= ')';
	}

	// Add tables from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListTables', $parameters);
	if ($reshook >= 0) {
		$sql .= $hookmanager->resPrint;
	}

	$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
	if ($filteroncategids) {
		$sql .= ' AND EXISTS (SELECT cp.fk_product FROM '.MAIN_DB_PREFIX.'categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ('.$db->sanitize($filteroncategids).'))';
	}
	$sql .= ' AND p.tosell = 1';
	if (getDolGlobalInt('FLUXPOS_PRODUCT_IN_STOCK') == 1 && getDolGlobalInt('FLUXPOS_ID_WAREHOUSE'.$_SESSION['fluxposterminal'])) {
		$sql .= ' AND ps.reel > 0';
	}
	$sql .= natural_search(array('ref', 'label', 'barcode'), $search_term);
	// Add where from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters);
	if ($reshook >= 0) {
		$sql .= $hookmanager->resPrint;
	}

	if (getDolGlobalInt('FLUXPOS_PRODUCT_IN_STOCK') == 1 && !getDolGlobalInt('FLUXPOS_ID_WAREHOUSE'.$_SESSION['fluxposterminal'])) {
		$sql .= ' GROUP BY p.rowid, p.ref, p.label, p.tosell, p.tobuy, p.barcode, p.price, p.price_ttc';
		// Add fields from hooks
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters);
		if ($reshook >= 0) {
			$sql .= $hookmanager->resPrint;
		}
		$sql .= ' HAVING SUM(ps.reel) > 0';
	}

	// load only one page of products
	$sql .= $db->plimit($search_limit, $search_start);

	$resql = $db->query($sql);
	if ($resql) {
		$rows = array();

		while ($obj = $db->fetch_object($resql)) {
			$objProd = new Product($db);
			$objProd->fetch($obj->rowid);
			$image = $objProd->show_photos('product', $conf->product->multidir_output[(int) $objProd->entity], 'small', 1);

			$match = array();
			preg_match('@src="([^"]+)"@', $image, $match);
			$file = array_pop($match);

			if ($file == "") {
				$ig = '../public/theme/common/nophoto.png';
			} else {
				if (!defined('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE')) {
					$ig = $file.'&cache=1';
				} else {
					$ig = $file.'&cache=1&publicfluxpos=1&modulepart=product';
				}
			}

			$row = array(
				'rowid' => $obj->rowid,
				'ref' => $obj->ref,
				'label' => $obj->label,
				'tosell' => $obj->tosell,
				'tobuy' => $obj->tobuy,
				'barcode' => $obj->barcode,
				'price' => empty($objProd->multiprices[$pricelevel]) ? $obj->price : $objProd->multiprices[$pricelevel],
				'price_ttc' => empty($objProd->multiprices_ttc[$pricelevel]) ? $obj->price_ttc : $objProd->multiprices_ttc[$pricelevel],
				'object' => 'product',
				'img' => $ig,
				'qty' => 1,
				'price_formated' => price(price2num(empty($objProd->multiprices[$pricelevel]) ? $obj->price : $objProd->multiprices[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency),
				'price_ttc_formated' => price(price2num(empty($objProd->multiprices_ttc[$pricelevel]) ? $obj->price_ttc : $objProd->multiprices_ttc[$pricelevel], 'MT'), 1, $langs, 1, -1, -1, $conf->currency)
			);
			// Add entries to row from hooks
			$parameters = array();
			$parameters['row'] = $row;
			$parameters['obj'] = $obj;
			$reshook = $hookmanager->executeHooks('completeAjaxReturnArray', $parameters);
			if ($reshook > 0) {
				// replace
				if (count($hookmanager->resArray)) {
					$row = $hookmanager->resArray;
				} else {
					$row = array();
				}
				$rows[] = $row;
			} else {
				// add
				if (count($hookmanager->resArray)) {
					$rows[] = $hookmanager->resArray;
				}
				$rows[] = $row;
			}
		}

		echo json_encode($rows);
	} else {
		echo 'Failed to search product : '.$db->lasterror();
	}
} elseif ($action == 'getCatalog' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/json');

	// Price level of the thirdparty (or default level)
	if (GETPOSTINT('thirdpartyid') > 0) {
		$result = $thirdparty->fetch(GETPOSTINT('thirdpartyid'));
		if ($result > 0) {
			$pricelevel = $thirdparty->price_level;
		}
	}

	// Categories tree of the POS products (same split as index.php)
	$object = new Categorie($db);
	$fulltree = $object->get_full_arbo('product', getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID'), 1);

	$levelofrootcategory = 0;
	if (getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID') > 0) {
		foreach ($fulltree as $key => $categorycursor) {
			if ($categorycursor['id'] == getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID')) {
				$levelofrootcategory = $categorycursor['level'];
				break;
			}
		}
	}
	$levelofmaincategories = $levelofrootcategory + 1;	// Main categories are categories just under the root categorie of POS products.

	$maincategories = array();
	$subcategories = array();
	foreach ($fulltree as $key => $categorycursor) {
		if ($categorycursor['level'] == $levelofmaincategories) {
			$maincategories[$key] = $categorycursor;
		} elseif ($categorycursor['level'] > $levelofmaincategories) {
			$subcategories[$key] = $categorycursor;	// If category level 2 or 3 or ...
		}
	}
	$maincategories = dol_sort_array($maincategories, 'label');
	$subcategories = dol_sort_array($subcategories, 'label');

	// Load the products for sale of each category (main, sub and the supplements category)
	$products = array();
	$categidstoload = array();
	foreach ($maincategories as $categorycursor) {
		$categidstoload[] = $categorycursor['id'];
	}
	foreach ($subcategories as $categorycursor) {
		$categidstoload[] = $categorycursor['id'];
	}
	$supplementscategoryid = getDolGlobalInt('FLUXPOS_SUPPLEMENTS_CATEGORY');
	if ($supplementscategoryid > 0) {
		$categidstoload[] = $supplementscategoryid;
	}

	foreach ($categidstoload as $categid) {
		$catalogkey = ($categid == $supplementscategoryid) ? 'supplements' : (string) $categid;
		if (!isset($products[$catalogkey])) {
			$products[$catalogkey] = getFluxPosProductsForCategory($categid, $pricelevel);
		}
	}

	echo json_encode(array('maincategories' => $maincategories, 'subcategories' => $subcategories, 'products' => $products));
} elseif ($action == 'syncOfflineOrder' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/json');

	// Write operation: only POST is accepted
	if ($_SERVER['REQUEST_METHOD'] != 'POST') {
		http_response_code(405);
		echo json_encode(array('error' => 'POST required'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	if (!is_array($input) || empty($input['place']) || !isset($input['lines']) || !is_array($input['lines'])) {
		echo json_encode(array('error' => 'Bad request: place and lines are required'));
		exit;
	}

	$result = fluxposSyncOfflineOrder((string) $input['place'], (array) $input['lines'], (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), (isset($input['payments']) && is_array($input['payments']) ? $input['payments'] : array()), (!empty($input['valid'])));
	echo json_encode($result);
} elseif ($action == 'syncOfflineSale' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/json');

	// Write operation: only POST is accepted
	if ($_SERVER['REQUEST_METHOD'] != 'POST') {
		http_response_code(405);
		echo json_encode(array('error' => 'POST required'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	if (!is_array($input) || empty($input['uid']) || !isset($input['lines']) || !is_array($input['lines'])) {
		echo json_encode(array('error' => 'Bad request: uid and lines are required'));
		exit;
	}

	$result = fluxposSyncOfflineSale((string) $input['uid'], (isset($input['place']) ? (string) $input['place'] : ''), (array) $input['lines'], (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), (isset($input['payments']) && is_array($input['payments']) ? $input['payments'] : array()), (!empty($input['created']) ? (int) $input['created'] : 0));
	echo json_encode($result);
} elseif ($action == 'ping' && $user->hasRight('fluxpos', 'run')) {
	// Light call used by the terminal to know when the server can be reached again
	top_httphead('application/json');
	echo json_encode(array('ok' => 1));
} elseif ($action == "opendrawer" && $term != '' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/html');

	require_once dol_buildpath('/fluxpos/class/dolreceiptprinter.class.php');
	$printer = new dolReceiptPrinter($db);

	// check printer for terminal
	if (getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term) > 0) {
		// TODO Set the profile into $this->profile (used by initPrinter). Profile not used yet.

		// Init printer
		$printer->initPrinter(getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term));
		// open cashdrawer
		if ($printer->getPrintConnector()) {
			$printer->pulse();
			$printer->close();
		} else {
			print 'Failed to init printer with ID='.getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term);
		}
	}
} elseif ($action == "printinvoiceticket" && $term != '' && $id > 0 && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/html');

	fluxposPrintInvoiceTicket($id, $term, $user);
} elseif ($action == 'getInvoice' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/json');

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$object = new Facture($db);
	if ($id > 0) {
		$object->fetch($id);
		// The lines are used by the snapshots of the offline engine: it caches the order
		// while online so it can adopt it into the local cart when offline (see
		// captureOnServerOrder() of js/offline11.js)
		$object->fetch_lines();
	}

	// Remove sensitive internal properties before serialization to avoid
	// leaking the database connection parameters and the schema metadata.
	unset($object->db);
	unset($object->fields);
	foreach ($object->lines as $factureline) {
		unset($factureline->db);
		unset($factureline->fields);
	}

	echo json_encode($object);
} elseif ($action == 'thecheck' && $user->hasRight('fluxpos', 'run')) {
	top_httphead('application/html');

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once dol_buildpath('/fluxpos/class/dolreceiptprinter.class.php');

	$object = new Facture($db);
	$printer = new dolReceiptPrinter($db);

	$printer->sendToPrinter($object, getDolGlobalInt('FLUXPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term), getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term));
}
