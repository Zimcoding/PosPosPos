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
 *	\file       htdocs/takepos/ajax/ajax.php
 *	\brief      Ajax search component for TakePos. It search products of a category.
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
require '../../main.inc.php'; // Load $user and permissions
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

if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('takeposproductsearch')); // new context for product search hooks

$pricelevel = 1;	// default price level if PRODUIT_MULTIPRICES. TODO Get price level from thirdparty.


/**
 * Return the products for sale of a category, trimmed to the fields used by the POS grid and search.
 * Used to build the catalog synchronized into IndexedDB by js/offline.js.
 *
 * @param  int $categid    ID of category
 * @param  int $pricelevel Price level to use for prices
 * @return array           Array of products
 */
function getTakePosProductsForCategory($categid, $pricelevel)
{
	global $conf, $db, $langs;

	$object = new Categorie($db);
	$result = $object->fetch($categid);
	if ($result <= 0) {
		return array();
	}

	$prods = $object->getObjectsInCateg("product", 0, 0, 0, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD'), 'ASC', '(o.tosell:=:1)');

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
function takeposPrintInvoiceTicket($id, $term, $user)
{
	global $db;

	if (empty($id) || $id <= 0) {
		return;
	}

	require_once DOL_DOCUMENT_ROOT.'/takepos/class/dolreceiptprinter.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	// check printer for terminal
	if (getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term) <= 0 && getDolGlobalString('TAKEPOS_PRINT_METHOD') != "takeposconnector") {
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

		$templateidtouse = getDolGlobalInt('TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term);	// Note that this may not be ignored by the sendToPrinter()
	}

	if ($resultinccounter) {
		// Send to printer
		$printer = new dolReceiptPrinter($db);
		$printer->sendToPrinter($object, $templateidtouse, getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term));
	} else {
		print 'Failed to update print counter for object ID='.$object->id.' so we refuse to print.';
	}
}

/**
 * Synchronize an offline order into the draft invoice of a place.
 * The draft invoice of the place is created if it does not exist yet (same rules as
 * invoice.php action=addline), then its product lines are reconciled with the line
 * list captured on the terminal: products of the capture are added to the draft and
 * quantities are raised to at least the captured quantity (the same product is never
 * added twice, like invoice.php action=addline does). The capture of a terminal never
 * mirrors the server lines (they are unknown while the terminal was offline), so this
 * is done only by additions: no draft line is ever deleted and no quantity is ever
 * lowered, so nothing captured by the server or by another terminal can be lost, and
 * the operation is idempotent: it can be replayed at any time (retry after a failure,
 * background synchronization) without duplicating lines.
 *
 * When a list of payments is provided, the sale is then validated and paid exactly
 * like invoice.php action=valid does (validation with or without stock move, cash
 * fence check, payment recorded into the bank account of the terminal for its
 * payment mode, credit note conversion of a negative sale, change given for cash).
 * The invoice number is attributed by the numbering module of Dolibarr at the
 * validation, so an order paid on a terminal while offline keeps a regular and
 * in-series invoice number. The receipt is printed automatically when the validation
 * is made by this call and a receipt printer is configured for the terminal.
 *
 * @param  string $place    Place (table) of the order
 * @param  array  $lines    Array of captured lines: array('id' => product id, 'qty' => qty)
 * @param  int    $socid    Thirdparty id to use if the invoice must be created
 * @param  array  $payments Payments captured on the terminal: array('pay' => 'cash'|'card'|'cheque'|'LIQ'|..., 'amount', 'excess', 'accountid')
 * @param  bool   $valid    Validate the sale and record the payments
 * @return array            Result info: array('placeid', 'socid', 'total_ttc', 'ref', 'paid') or array('error' => ...)
 */
function takeposSyncOfflineOrder($place, $lines, $socid, $payments = array(), $valid = false)
{
	global $conf, $db, $langs, $mysoc, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

	$langs->loadLangs(array("bills", "errors", "admin", "takepos@takepos"));

	$takeposterminal = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
	$constforcompanyid = 'CASHDESK_ID_THIRDPARTY'.$takeposterminal;

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
	$ret = $invoice->fetch(0, '(PROV-POS'.$takeposterminal.'-'.$place.')');
	if ($ret <= 0) {
		if (!$haslines) {
			return array('place' => $place, 'socid' => 0, 'total_ttc' => 0, 'created' => 0);
		}
		$invoice->socid = ($socid > 0) ? $socid : getDolGlobalInt($constforcompanyid);
		if ($invoice->socid <= 0) {
			return array('error' => $langs->trans("ErrorModuleSetupNotComplete", "TakePOS"));
		}

		$dolnowtzuserrel = dol_now('tzuserrel');	// If user is 02 january 22:00, we want to store '02 january'
		$monthuser = dol_print_date($dolnowtzuserrel, '%m', 'gmt');
		$dayuser = dol_print_date($dolnowtzuserrel, '%d', 'gmt');
		$yearuser = dol_print_date($dolnowtzuserrel, '%Y', 'gmt');
		$invoice->date = dol_mktime(0, 0, 0, (int) $monthuser, (int) $dayuser, (int) $yearuser, 'tzserver');

		$invoice->module_source = 'takepos';
		$invoice->pos_source = $takeposterminal;
		$invoice->entity = !empty($_SESSION["takeposinvoiceentity"]) ? $_SESSION["takeposinvoiceentity"] : $conf->entity;

		$db->begin();
		$placeid = $invoice->create($user);
		if ($placeid < 0) {
			$db->rollback();
			return array('error' => $invoice->error);
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."facture";
		$sql .= " SET ref='(PROV-POS".$takeposterminal."-".$place.")'";
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

	// Reconcile the lines of the draft invoice with the target line list
	$error = 0;
	$db->begin();

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
				$error++;
				break;
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
				$error++;
				break;
			}
		}
	}

	if ($valid) {
		if (!$haslines) {
			$db->rollback();
			return array('error' => $langs->trans("NoLinesToBill", "TakePOS"));
		}

		// Test that the period is not close: a cash control closed for today on this
		// terminal blocks any sale (same as invoice.php)
		$tmpcurrentday = dol_getdate(dol_now());
		$sql = "SELECT MIN(ref) as firstref FROM ".MAIN_DB_PREFIX."pos_cash_fence";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND posnumber = ".((int) $takeposterminal);
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
				$db->rollback();
				return array('error' => $langs->trans("ACashControlHasBeenclosedForCurrentDay", $obj->firstref));
			}
		}

		$invoicewasdraft = ($invoice->status == Facture::STATUS_DRAFT);

		// Draft invoice with a negative total: transform into a credit note of the last
		// validated invoice of the customer (same as invoice.php)
		if ($invoicewasdraft && $invoice->total_ttc < 0) {
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
				$db->rollback();
				return array('error' => $langs->trans("NoPreviousBillForCustomer"));
			}
			$invoice->fk_facture_source = $fk_source;
			$invoice->update($user);
		}

		// Validation of the draft invoice: the invoice number is attributed here by the
		// numbering module (same rules as invoice.php for the stock changes)
		if ($invoice->status == Facture::STATUS_DRAFT) {
			$constantforkey = 'CASHDESK_NO_DECREASE_STOCK'.$takeposterminal;
			$allowstockchange = (getDolGlobalString($constantforkey) != "1");

			if (isModEnabled('stock') && !isModEnabled('productbatch') && $allowstockchange) {
				// Validation with a stock change into the warehouse of the terminal
				$savconst = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
				$conf->global->STOCK_CALCULATE_ON_BILL = 1;	// To force the change of stock during invoice validation
				$constantforkey = 'CASHDESK_ID_WAREHOUSE'.$takeposterminal;
				$res = $invoice->validate($user, '', getDolGlobalInt($constantforkey), 0, 0);
				$conf->global->STOCK_CALCULATE_ON_BILL = $savconst;
			} else {
				// Validation with no change into stock (same as invoice.php)
				$res = $invoice->validate($user);
			}
			if ($res < 0) {
				$db->rollback();
				return array('error' => ($invoice->error == 'NotConfigured' ? $langs->trans("NotConfigured").' (TakePOS numbering module)' : $invoice->error));
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
					if (getDolGlobalInt("TAKEPOS_DELAYED_TERMS")) {
						$invoice->setPaymentTerms(getDolGlobalInt("TAKEPOS_DELAYED_TERMS"));
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
					$bankaccount = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_CASH'.$takeposterminal);	// For backward compatibility
				} elseif ($paycode == 'CHQ') {
					$bankaccount = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_CHEQUE'.$takeposterminal);	// For backward compatibility
				} else {
					$bankaccount = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_'.$paycode.$takeposterminal);
				}
				if ($bankaccount <= 0 && isModEnabled("bank")) {
					$error++;
					break;
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
					$error++;
					break;
				}
				$res = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankaccount, '', '');
				if ($res < 0) {
					$error++;
					break;
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
	}

	if ($error) {
		if (!empty($payment) && $payment->error) {
			return array('error' => $payment->error);
		}
		$db->rollback();
		return array('error' => $invoice->error ? $invoice->error : $db->lasterror());
	}
	$db->commit();

	$invoice->fetch($invoice->id);

	// The receipt of a sale validated by this synchronization is printed on the receipt
	// printer of the terminal when there is one configured (the receipt of a sale paid
	// with browser printing can be printed from the card of the invoice)
	if ($validatedhere) {
		takeposPrintInvoiceTicket($invoice->id, $takeposterminal, $user);
	}

	return array('placeid' => $invoice->id, 'place' => $place, 'socid' => $invoice->socid, 'total_ttc' => $invoice->total_ttc, 'ref' => $invoice->ref, 'paid' => (int) $invoice->paye);
}



/*
 * View
 */

$thirdparty = new Societe($db);

if ($action == 'getProducts' && $user->hasRight('takepos', 'run')) {
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
		$category = getDolGlobalInt('TAKEPOS_SUPPLEMENTS_CATEGORY');
		if (empty($category)) {
			echo 'Error, the category to use for supplements is not defined. Go into setup of module TakePOS.';
			exit;
		}
	}

	$result = $object->fetch($category);
	if ($result > 0) {
		$filter = '';
		if ($tosell != '') {
			$filter = '(o.tosell:=:'.((int) $tosell).')';
		}
		$prods = $object->getObjectsInCateg("product", 0, $limit, $offset, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD'), 'ASC', $filter);
		// Removed properties we don't need
		$res = array();
		if (is_array($prods) && count($prods) > 0) {
			$productChildrenNb = 0;
			foreach ($prods as $prod) {
				'@phan-var-force Product $prod';
				if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1) {
					if (getDolGlobalInt('PRODUIT_SOUSPRODUITS')) {
						$productChildrenNb = $prod->hasFatherOrChild(1);
					}
					// always show virtual products (don't manage stock)
					if ($productChildrenNb == 0) {
						// remove products without stock
						$prod->load_stock('nobatch,novirtual');
						if ($prod->stock_warehouse[getDolGlobalString('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal'])]->real <= 0) {
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
} elseif ($action == 'search' && $search_term != '' && $user->hasRight('takepos', 'run')) {
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
	if (getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID') > 0) {	// A root category is defined, we must filter on products inside this category tree
		$object = new Categorie($db);
		//$result = $object->fetch($conf->global->TAKEPOS_ROOT_CATEGORY_ID);
		$arrayofcateg = $object->get_full_arbo('product', getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID'), 1);
		if (is_array($arrayofcateg) && count($arrayofcateg) > 0) {
			foreach ($arrayofcateg as $val) {
				$filteroncategids .= ($filteroncategids ? ', ' : '').$val['id'];
			}
		}
	}

	$barcode_rules = getDolGlobalString('TAKEPOS_BARCODE_RULE_TO_INSERT_PRODUCT');
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
						if (!getDolGlobalString('TAKEPOS_HIDE_PRODUCT_IMAGES')) {
							$image = $objProd->show_photos('product', $conf->product->multidir_output[(int) $objProd->entity], 'small', 1);

							$match = array();
							preg_match('@src="([^"]+)"@', $image, $match);
							$file = array_pop($match);

							if ($file != '') {
								if (!defined('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE')) {
									$ig = $file.'&cache=1';
								} else {
									$ig = $file.'&cache=1&publictakepos=1&modulepart=product';
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
	if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1) {
		if (getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal'])) {
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
	if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1) {
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_stock as ps';
		$sql .= ' ON (p.rowid = ps.fk_product';
		if (getDolGlobalString('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal'])) {
			$sql .= " AND ps.fk_entrepot = ".((int) getDolGlobalInt("CASHDESK_ID_WAREHOUSE".$_SESSION['takeposterminal']));
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
	if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1 && getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal'])) {
		$sql .= ' AND ps.reel > 0';
	}
	$sql .= natural_search(array('ref', 'label', 'barcode'), $search_term);
	// Add where from hooks
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters);
	if ($reshook >= 0) {
		$sql .= $hookmanager->resPrint;
	}

	if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1 && !getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal'])) {
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
					$ig = $file.'&cache=1&publictakepos=1&modulepart=product';
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
} elseif ($action == 'getCatalog' && $user->hasRight('takepos', 'run')) {
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
	$fulltree = $object->get_full_arbo('product', getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID'), 1);

	$levelofrootcategory = 0;
	if (getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID') > 0) {
		foreach ($fulltree as $key => $categorycursor) {
			if ($categorycursor['id'] == getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID')) {
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
	$supplementscategoryid = getDolGlobalInt('TAKEPOS_SUPPLEMENTS_CATEGORY');
	if ($supplementscategoryid > 0) {
		$categidstoload[] = $supplementscategoryid;
	}

	foreach ($categidstoload as $categid) {
		$catalogkey = ($categid == $supplementscategoryid) ? 'supplements' : (string) $categid;
		if (!isset($products[$catalogkey])) {
			$products[$catalogkey] = getTakePosProductsForCategory($categid, $pricelevel);
		}
	}

	echo json_encode(array('maincategories' => $maincategories, 'subcategories' => $subcategories, 'products' => $products));
} elseif ($action == 'syncOfflineOrder' && $user->hasRight('takepos', 'run')) {
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

	$result = takeposSyncOfflineOrder((string) $input['place'], (array) $input['lines'], (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), (isset($input['payments']) && is_array($input['payments']) ? $input['payments'] : array()), (!empty($input['valid'])));
	echo json_encode($result);
} elseif ($action == "opendrawer" && $term != '' && $user->hasRight('takepos', 'run')) {
	top_httphead('application/html');

	require_once DOL_DOCUMENT_ROOT.'/takepos/class/dolreceiptprinter.class.php';
	$printer = new dolReceiptPrinter($db);

	// check printer for terminal
	if (getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term) > 0) {
		// TODO Set the profile into $this->profile (used by initPrinter). Profile not used yet.

		// Init printer
		$printer->initPrinter(getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term));
		// open cashdrawer
		if ($printer->getPrintConnector()) {
			$printer->pulse();
			$printer->close();
		} else {
			print 'Failed to init printer with ID='.getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term);
		}
	}
} elseif ($action == "printinvoiceticket" && $term != '' && $id > 0 && $user->hasRight('takepos', 'run')) {
	top_httphead('application/html');

	takeposPrintInvoiceTicket($id, $term, $user);
} elseif ($action == 'getInvoice' && $user->hasRight('takepos', 'run')) {
	top_httphead('application/json');

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$object = new Facture($db);
	if ($id > 0) {
		$object->fetch($id);
	}

	// Remove sensitive internal properties before serialization to avoid
	// leaking the database connection parameters and the schema metadata.
	unset($object->db);
	unset($object->fields);

	echo json_encode($object);
} elseif ($action == 'thecheck' && $user->hasRight('takepos', 'run')) {
	top_httphead('application/html');

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/takepos/class/dolreceiptprinter.class.php';

	$object = new Facture($db);
	$printer = new dolReceiptPrinter($db);

	$printer->sendToPrinter($object, getDolGlobalInt('TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term), getDolGlobalInt('TAKEPOS_PRINTER_TO_USE'.$term));
}
