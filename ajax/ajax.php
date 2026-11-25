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

	$withdeposits = isModEnabled('deposito');
	if ($withdeposits) {
		require_once dol_buildpath('/deposito/lib/deposito.lib.php', 0);
		$withdeposits = function_exists('depositoProductInfo');
	}
	static $depositproducts = array();

	$res = array();
	if (is_array($prods) && count($prods) > 0) {
		foreach ($prods as $prod) {
			'@phan-var-force Product $prod';
			// Deposit data of Deposito (only when that module is on): the terminal shows
			// the deposit line of a drink before the server has booked it
			$depositdata = array();
			if ($withdeposits) {
				$info = depositoProductInfo($db, (int) $prod->id);
				if (!empty($info['return'])) {
					$depositdata['ret'] = 1;
				} elseif ((int) $info['deposit'] > 0) {
					$depid = (int) $info['deposit'];
					if (!isset($depositproducts[$depid])) {
						$dep = new Product($db);
						$depositproducts[$depid] = ($dep->fetch($depid) > 0) ? array('label' => $dep->label, 'price_ttc' => (float) $dep->price_ttc) : null;
					}
					if ($depositproducts[$depid] !== null) {
						$depositdata = array('deposit' => $depid, 'deposit_qty' => (float) $info['qty'], 'deposit_label' => $depositproducts[$depid]['label'], 'deposit_price_ttc' => $depositproducts[$depid]['price_ttc']);
					}
				}
			}
			$res[] = $depositdata + array(
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
	global $db;

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

		$existingline = fluxposFindProductLine($invoice, 0, $idproduct);
		if ($existingline !== null) {
			if ($qty > abs((float) $existingline->qty) + 0.0001 && fluxposSetLineCount($invoice, $existingline, $qty) < 0) {
				return -1;
			}
		} elseif (fluxposAddProductLine($invoice, $customer, $prod, $qty) < 0) {
			return -1;
		}
	}

	return 0;
}

/**
 * Add one product line to a draft invoice, with the price of the customer and the
 * margin settings (same rules as invoice.php action=addline). The caller owns the
 * database transaction.
 *
 * @param  Facture $invoice  Draft invoice
 * @param  Societe $customer Customer of the invoice
 * @param  Product $prod     Fetched product
 * @param  float   $qty      Quantity (> 0)
 * @return int               Id of the new line, < 0 if error (message in $invoice->error)
 */
function fluxposAddProductLine($invoice, $customer, $prod, $qty)
{
	global $db, $mysoc;

	$datapriceofproduct = $prod->getSellPrice($mysoc, $customer, 0);
	$price = $datapriceofproduct['pu_ht'];
	$price_ttc = $datapriceofproduct['pu_ttc'];
	$price_base_type = empty($datapriceofproduct['price_base_type']) ? 'HT' : $datapriceofproduct['price_base_type'];
	$tva_tx = $datapriceofproduct['tva_tx'];
	$tva_npr = (int) $datapriceofproduct['tva_npr'];
	$localtax1_tx = get_localtax($tva_tx, 1, $customer, $mysoc, $tva_npr);
	$localtax2_tx = get_localtax($tva_tx, 2, $customer, $mysoc, $tva_npr);

	$fk_fournprice = 0;
	$pa_ht = '';
	if (getDolGlobalString('MARGIN_TYPE')) {
		if (getDolGlobalString('MARGIN_TYPE') == 'pmp' && !empty($prod->pmp)) {
			$pa_ht = $prod->pmp;
		} elseif (getDolGlobalString('MARGIN_TYPE') == 'costprice' && !empty($prod->cost_price)) {
			$pa_ht = $prod->cost_price;
		} else {
			// default is fournprice
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
			$pf = new ProductFournisseur($db);
			if ($pf->find_min_price_product_fournisseur($prod->id, $qty) > 0) {
				$fk_fournprice = $pf->product_fourn_price_id;
				$pa_ht = $pf->fourn_unitprice_with_discount;
				if (getDolGlobalString('PRODUCT_CHARGES') && $pf->fourn_charges > 0) {
					$pa_ht += (float) $pf->fourn_charges / $pf->fourn_qty;
				}
			}
		}
	}

	return $invoice->addline($prod->description, $price, $qty, $tva_tx, $localtax1_tx, $localtax2_tx, $prod->id, (float) $customer->remise_percent, '', 0, 0, 0, 0, $price_base_type, $price_ttc, $prod->type, -1, 0, '', 0, '', $fk_fournprice, $pa_ht, '', array(), 100, 0, $prod->fk_unit, 0);
}

/**
 * Clean a place (table) id received from a terminal: only letters, digits, _ and -
 * (the place ends up in the reference of the draft invoice and in SQL).
 *
 * @param  mixed  $place Place as received
 * @return string        Clean place ('0' stays '0')
 */
function fluxposCleanPlace($place)
{
	return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $place);
}

/**
 * Open ticket of a place: the draft invoice "(PROV-POS<terminal>-<place>)". Devices
 * that share the tickets of their places must use the same terminal number.
 *
 * @param  string               $place  Clean place
 * @param  int                  $socid  Customer for a new ticket (0 = default customer of the terminal)
 * @param  bool                 $create Create the ticket when it does not exist
 * @return Facture|null|string          The ticket, null when there is none, or an error message
 */
function fluxposPlaceTicket($place, $socid, $create)
{
	global $conf, $db, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$fluxposterminal = empty($_SESSION['fluxposterminal']) ? 1 : $_SESSION['fluxposterminal'];
	$ref = '(PROV-POS'.$fluxposterminal.'-'.$place.')';

	$invoice = new Facture($db);
	if ($invoice->fetch(0, $ref) > 0) {
		return ($invoice->status == Facture::STATUS_DRAFT) ? $invoice : null;
	}
	if (!$create) {
		return null;
	}

	$invoice = new Facture($db);
	$invoice->socid = ($socid > 0) ? $socid : getDolGlobalInt('FLUXPOS_ID_THIRDPARTY'.$fluxposterminal);
	if ($invoice->socid <= 0) {
		return $langs->trans("ErrorModuleSetupNotComplete", "FluxPos");
	}
	$dolnowtzuserrel = dol_now('tzuserrel');	// If user is 02 january 22:00, we want to store '02 january'
	$invoice->date = dol_mktime(0, 0, 0, (int) dol_print_date($dolnowtzuserrel, '%m', 'gmt'), (int) dol_print_date($dolnowtzuserrel, '%d', 'gmt'), (int) dol_print_date($dolnowtzuserrel, '%Y', 'gmt'), 'tzserver');
	$invoice->module_source = 'fluxpos';
	$invoice->pos_source = $fluxposterminal;
	$invoice->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;

	$db->begin();
	$placeid = $invoice->create($user);
	if ($placeid < 0) {
		$errmsg = ($invoice->error ? $invoice->error : $db->lasterror());
		$db->rollback();
		return $errmsg;
	}
	if (!$db->query("UPDATE ".MAIN_DB_PREFIX."facture SET ref = '".$db->escape($ref)."' WHERE rowid = ".((int) $placeid))) {
		$errmsg = $db->lasterror();
		$db->rollback();
		return $errmsg;
	}
	$db->commit();

	$invoice->fetch($placeid);
	return $invoice;
}

/**
 * Role of a ticket line:
 * - 'product': a product line the terminals change (taps, +/-, quantity pad); 'sent' is
 *   true when it was already sent to the kitchen or bar printer (special_code 4): it is
 *   still charged, but a new tap of that product starts a new line (a new order);
 * - 'deposit': an automatic deposit line of the Deposito module (deposito_auto > 0):
 *   written by its triggers, it follows the drink lines and is never changed by the
 *   terminals. A deposit product sold by hand is an ordinary product line;
 * - 'other': free lines, sub-lines, discount lines.
 * 'ret' is true for a returned-empties (leeggoed) product of Deposito: Deposito stores it
 * as a negative quantity, the terminals count it as a positive number of empties.
 *
 * @param  FactureLigne $line Invoice line
 * @return array{kind:string,ret:bool,sent:bool}
 */
function fluxposLineKind($line)
{
	global $db;

	$res = array('kind' => 'other', 'ret' => false, 'sent' => false);
	if ((int) $line->fk_product <= 0 || $line->fk_parent_line > 0) {
		return $res;
	}
	$res['kind'] = 'product';
	$res['sent'] = ($line->special_code == 4);
	if (isModEnabled('deposito')) {
		if (!isset($line->array_options['options_deposito_auto']) && method_exists($line, 'fetch_optionals') && $line->id > 0) {
			$line->fetch_optionals();
		}
		if (isset($line->array_options['options_deposito_auto']) && (int) $line->array_options['options_deposito_auto'] > 0) {
			$res['kind'] = 'deposit';
			return $res;
		}
		require_once dol_buildpath('/deposito/lib/deposito.lib.php', 0);
		if (function_exists('depositoProductInfo')) {
			$info = depositoProductInfo($db, (int) $line->fk_product);
			$res['ret'] = !empty($info['return']);
		}
	}
	return $res;
}

/**
 * State of a ticket as the terminals show it: all its lines, its total and a version
 * (a hash of the content: it changes with every change made from any device or screen).
 *
 * @param  Facture|null $invoice Ticket (with its lines), null when the place has none
 * @return array                 array('placeid', 'socid', 'total_ttc', 'lines', 'version')
 */
function fluxposTicketState($invoice)
{
	if (!is_object($invoice) || $invoice->id <= 0) {
		return array('placeid' => 0, 'socid' => 0, 'total_ttc' => 0, 'lines' => array(), 'version' => 'empty');
	}
	$lines = array();
	foreach ($invoice->lines as $line) {
		$kind = fluxposLineKind($line);
		$label = (string) (!empty($line->product_label) ? $line->product_label : $line->label);
		if ($label === '') {
			$label = dol_trunc(dol_string_nohtmltag((string) $line->desc), 60);
		}
		$lines[] = array(
			'lineid' => (int) $line->id,
			'product' => (int) $line->fk_product,
			'label' => $label,
			'qty' => (float) $line->qty,
			'total_ttc' => (float) $line->total_ttc,
			'remise_percent' => (float) $line->remise_percent,
			'kind' => $kind['kind'],
			'ret' => ($kind['ret'] ? 1 : 0),
			'sent' => ($kind['sent'] ? 1 : 0),
		);
	}
	$state = array('placeid' => (int) $invoice->id, 'socid' => (int) $invoice->socid, 'total_ttc' => (float) $invoice->total_ttc, 'lines' => $lines);
	$state['version'] = md5(json_encode($state));
	return $state;
}

/**
 * Product line of a ticket that an operation targets: the line with that id, else the
 * first product line of that product not sent to the kitchen yet. Deposit lines and
 * other lines are never targeted.
 *
 * @param  Facture           $invoice Ticket with its lines
 * @param  int               $lineid  Line id (0 when unknown)
 * @param  int               $product Product id (0 when unknown)
 * @return FactureLigne|null
 */
function fluxposFindProductLine($invoice, $lineid, $product)
{
	if ($lineid > 0) {
		foreach ($invoice->lines as $line) {
			if ((int) $line->id == $lineid) {
				return (fluxposLineKind($line)['kind'] == 'product') ? $line : null;
			}
		}
	}
	if ($product > 0) {
		foreach ($invoice->lines as $line) {
			$kind = fluxposLineKind($line);
			if ((int) $line->fk_product == $product && $kind['kind'] == 'product' && !$kind['sent']) {
				return $line;
			}
		}
	}
	return null;
}

/**
 * Set the counted quantity of a product line (removed at 0). A returned-empties line of
 * Deposito is stored negative: it gets the positive count and Deposito turns it negative
 * again; another negative line keeps its sign. The caller owns the transaction.
 *
 * @param  Facture      $invoice Ticket
 * @param  FactureLigne $line    Line to change
 * @param  float        $count   New counted quantity (<= 0 removes the line)
 * @return int                   0 if OK, < 0 if error (message in $invoice->error)
 */
function fluxposSetLineCount($invoice, $line, $count)
{
	global $user;

	if ($count <= 0.0001) {
		return ($invoice->deleteline($line->id) < 0) ? -1 : 0;
	}
	if (abs(abs((float) $line->qty) - $count) <= 0.0001) {
		return 0;
	}
	$kind = fluxposLineKind($line);
	$qty = ($kind['ret'] || (float) $line->qty >= 0) ? $count : -$count;
	$vatratecode = $line->tva_tx;
	if ($line->vat_src_code) {
		$vatratecode .= ' ('.$line->vat_src_code.')';
	}
	$result = $invoice->updateline($line->id, $line->desc, $line->subprice, $qty, $line->remise_percent, $line->date_start, $line->date_end, $vatratecode, $line->localtax1_tx, $line->localtax2_tx, 'HT', $line->info_bits, $line->product_type, $line->fk_parent_line, 0, $line->fk_fournprice, $line->pa_ht, $line->label, $line->special_code, $line->array_options, $line->situation_percent, $line->fk_unit);
	return ($result < 0) ? -1 : 0;
}

/**
 * Apply one operation of a terminal to a ticket. Operations are changes, never copies
 * of the cart, so changes of several devices on the same ticket add up:
 * - add    {product, qty}:         qty more of the product (new line when absent)
 * - delta  {lineid|product, qty}:  the counted quantity of the line changes by qty (+ or -)
 * - set    {lineid|product, qty}:  the counted quantity of the line becomes qty
 * - remove {lineid|product}:       the line is removed
 * The caller owns the transaction.
 *
 * @param  Facture $invoice  Ticket with its lines
 * @param  Societe $customer Customer of the ticket
 * @param  array   $op       Operation
 * @return string            '' if OK, else the error message
 */
function fluxposApplyOp($invoice, $customer, $op)
{
	global $db;

	$type = isset($op['type']) ? (string) $op['type'] : '';
	$qty = isset($op['qty']) ? (float) $op['qty'] : 0;
	$product = isset($op['product']) ? (int) $op['product'] : 0;
	$lineid = isset($op['lineid']) ? (int) $op['lineid'] : 0;
	if (!in_array($type, array('add', 'delta', 'set', 'remove'))) {
		return 'Unknown operation '.$type;
	}

	$line = fluxposFindProductLine($invoice, ($type == 'add' ? 0 : $lineid), ($type == 'add' || $lineid <= 0) ? $product : 0);
	if ($line === null) {
		// The line is not on the ticket: an 'add', or a change of a line the terminal had
		// not seen on the server yet (no line id), adds the product. A change of a known
		// line that is gone (removed or paid on another device) has nothing left to change:
		// it never reopens a ticket closed elsewhere.
		if (($type == 'add' || (($type == 'delta' || $type == 'set') && $lineid <= 0)) && $qty > 0 && $product > 0) {
			$prod = new Product($db);
			if ($prod->fetch($product) <= 0) {
				return 'Product '.$product.' not found';
			}
			return (fluxposAddProductLine($invoice, $customer, $prod, $qty) < 0) ? ($invoice->error ? $invoice->error : $db->lasterror()) : '';
		}
		return '';
	}

	$count = abs((float) $line->qty);
	if ($type == 'add' || $type == 'delta') {
		$count += $qty;
	} elseif ($type == 'set') {
		$count = $qty;
	} else {
		$count = 0;
	}
	return (fluxposSetLineCount($invoice, $line, $count) < 0) ? ($invoice->error ? $invoice->error : $db->lasterror()) : '';
}

/**
 * Apply the operations of a terminal to the ticket of a place, each exactly once: its
 * unique id is stored in llx_fluxpos_op in the same transaction, so an operation sent
 * again (lost answer, retry) is acknowledged without being applied twice. An operation
 * the server refuses is answered as rejected (the terminal drops it) instead of
 * blocking the ones after it.
 *
 * @param  string $place  Clean place
 * @param  int    $socid  Customer for a new ticket
 * @param  array  $ops    Operations: array('uid', 'type', 'product', 'lineid', 'qty')
 * @return array          Ticket state + 'acked' (uids) + 'rejected' (uid, error), or array('error' => ...)
 */
function fluxposApplyOps($place, $socid, $ops)
{
	global $conf, $db, $user;

	$clean = array();
	$create = false;
	foreach ($ops as $op) {
		if (!is_array($op) || empty($op['uid']) || !preg_match('/^[a-zA-Z0-9\-]{8,64}$/', (string) $op['uid'])) {
			continue;
		}
		$clean[] = $op;
		// Only what can add a line opens a ticket (same rule as fluxposApplyOp)
		$optype = isset($op['type']) ? (string) $op['type'] : '';
		if (isset($op['qty']) && (float) $op['qty'] > 0 && ($optype == 'add' || (($optype == 'delta' || $optype == 'set') && empty($op['lineid'])))) {
			$create = true;
		}
	}

	$invoice = fluxposPlaceTicket($place, $socid, $create);
	if (is_string($invoice)) {
		return array('error' => $invoice);
	}

	$acked = array();
	$rejected = array();
	if ($invoice === null) {
		// No ticket and nothing that creates one (only lowering or removing): nothing to do
		foreach ($clean as $op) {
			$acked[] = (string) $op['uid'];
		}
		$state = fluxposTicketState(null);
		$state['acked'] = $acked;
		$state['rejected'] = $rejected;
		return $state;
	}

	$customer = new Societe($db);
	$customer->fetch($invoice->socid);

	foreach ($clean as $op) {
		$uid = (string) $op['uid'];
		$db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."fluxpos_op (entity, op_uid, fk_facture, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($uid)."', ".((int) $invoice->id).", '".$db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$db->query($sql)) {
			$errno = $db->lasterrno();
			$errmsg = $db->lasterror();
			$db->rollback();
			if ($errno == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$acked[] = $uid;	// Applied before: the terminal did not get that answer
				continue;
			}
			return array('error' => $errmsg);	// Table missing for example: the terminal keeps its operations
		}
		$err = fluxposApplyOp($invoice, $customer, $op);
		if ($err !== '') {
			$db->rollback();
			$rejected[] = array('uid' => $uid, 'error' => $err);
			dol_syslog('ajax.php applyOps: operation '.$uid.' refused: '.$err, LOG_WARNING);
		} else {
			$db->commit();
			$acked[] = $uid;
		}
		$invoice->fetch($invoice->id);
	}

	// A ticket emptied by the operations is removed, so the place is free again
	if (empty($invoice->lines)) {
		$invoice->delete($user);
		$state = fluxposTicketState(null);
	} else {
		$state = fluxposTicketState($invoice);
	}
	$state['acked'] = $acked;
	$state['rejected'] = $rejected;
	return $state;
}

/**
 * Record that an operation of a terminal is applied to a ticket (unique key on its id).
 * The caller owns the transaction.
 *
 * @param  string $uid      Unique id of the operation
 * @param  int    $ticketid Id of the ticket
 * @return int              1 when recorded now, 0 when it was applied before, -1 on error
 */
function fluxposClaimOp($uid, $ticketid)
{
	global $conf, $db, $user;

	$sql = "INSERT INTO ".MAIN_DB_PREFIX."fluxpos_op (entity, op_uid, fk_facture, date_creation, fk_user_creat)";
	$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($uid)."', ".((int) $ticketid).", '".$db->idate(dol_now())."', ".((int) $user->id).")";
	if ($db->query($sql)) {
		return 1;
	}
	return ($db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') ? 0 : -1;
}

/**
 * Take what a terminal sold offline off the open ticket it came from. First the
 * operations of the terminal that the server had not applied yet are applied, once
 * (their ids are recorded, so they can never be applied later either): the ticket then
 * holds everything the customer saw. Then the sold quantities are taken off the product
 * lines of the ticket, product by product, so whatever another device added meanwhile
 * stays open. A ticket left empty is removed. The caller owns the transaction.
 *
 * @param  string $place     Place of the sale
 * @param  array  $ticket    array('id' => ticket id known by the terminal (0 if none), 'ops' => operations not confirmed to the terminal)
 * @param  array  $soldlines Sold lines: array('id' => product id, 'qty' => qty), one per product
 * @return string            '' if OK, else a note for the sale to be checked
 */
function fluxposCloseTicketLines($place, $ticket, $soldlines)
{
	global $conf, $db, $user;

	$ticketid = isset($ticket['id']) ? (int) $ticket['id'] : 0;
	$ops = array();
	if (isset($ticket['ops']) && is_array($ticket['ops'])) {
		foreach ($ticket['ops'] as $op) {
			if (is_array($op) && !empty($op['uid']) && preg_match('/^[a-zA-Z0-9\-]{8,64}$/', (string) $op['uid'])) {
				$ops[] = $op;
			}
		}
	}

	if ($ticketid <= 0 && count($ops)) {
		// The terminal never got the ticket from the server, but its operations may have
		// opened it (the answer was lost): find it through the recorded operation ids
		$uids = array();
		foreach ($ops as $op) {
			$uids[] = "'".$db->escape((string) $op['uid'])."'";
		}
		$sql = "SELECT fk_facture FROM ".MAIN_DB_PREFIX."fluxpos_op WHERE entity = ".((int) $conf->entity)." AND op_uid IN (".implode(',', $uids).")";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$ticketid = (int) $obj->fk_facture;
		}
	}
	if ($ticketid <= 0) {
		return '';	// Nothing of this sale is on a ticket of the server
	}

	$invoice = new Facture($db);
	if ($invoice->fetch($ticketid) <= 0 || $invoice->status != Facture::STATUS_DRAFT || $invoice->module_source != 'fluxpos') {
		return 'The open ticket of this sale was already closed elsewhere: check that it was not paid twice';
	}
	$customer = new Societe($db);
	$customer->fetch($invoice->socid);

	$notes = array();
	foreach ($ops as $op) {
		$claim = fluxposClaimOp((string) $op['uid'], $invoice->id);
		if ($claim < 0) {
			$notes[] = 'operation '.$op['uid'].' could not be recorded: '.$db->lasterror();
			continue;
		}
		if ($claim == 0) {
			continue;	// Applied before: its effect is on the ticket already
		}
		$err = fluxposApplyOp($invoice, $customer, $op);
		if ($err !== '') {
			$notes[] = 'operation '.$op['uid'].': '.$err;
		}
		$invoice->fetch($invoice->id);
	}

	foreach ($soldlines as $sold) {
		$product = isset($sold['id']) ? (int) $sold['id'] : 0;
		$remaining = isset($sold['qty']) ? (float) $sold['qty'] : 0;
		$lineids = array();
		foreach ($invoice->lines as $line) {
			if ((int) $line->fk_product == $product && fluxposLineKind($line)['kind'] == 'product') {
				$lineids[] = (int) $line->id;
			}
		}
		foreach ($lineids as $lineid) {
			if ($remaining <= 0.0001) {
				break;
			}
			$line = fluxposFindProductLine($invoice, $lineid, 0);
			if ($line === null) {
				continue;
			}
			$count = abs((float) $line->qty);
			$take = min($count, $remaining);
			if (fluxposSetLineCount($invoice, $line, $count - $take) < 0) {
				$notes[] = 'product '.$product.' could not be taken off the ticket: '.$invoice->error;
				break;
			}
			$remaining -= $take;
			$invoice->fetch($invoice->id);
		}
		if ($remaining > 0.0001) {
			$notes[] = 'product '.$product.': '.$remaining.' sold more than the ticket held';
		}
	}

	$invoice->fetch($invoice->id);
	if (empty($invoice->lines)) {
		$invoice->delete($user);
	}
	return implode('; ', $notes);
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
 * Merge an order captured by a terminal into the ticket (draft invoice) of a place.
 * Kept only for terminals still running the offline.js of 1.7 or older (their cached
 * copy until the service worker updates): quantities are only added or raised, never
 * lowered or removed. Terminals from 1.8 send operations to applyOps instead.
 *
 * @param  string $place    Clean place (table) of the order
 * @param  array  $lines    Captured lines: array('id' => product id, 'qty' => qty)
 * @param  int    $socid    Thirdparty id to use if the ticket must be created
 * @param  array  $payments Payments captured on the terminal (only with $valid)
 * @param  bool   $valid    Validate the sale and record the payments (terminals older than 1.0.1)
 * @return array            Result info: array('placeid', 'socid', 'total_ttc', 'ref', 'paid', 'lines') or array('error' => ...)
 */
function fluxposSyncOfflineOrder($place, $lines, $socid, $payments = array(), $valid = false)
{
	global $db, $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
	$langs->loadLangs(array("bills", "errors", "admin", "fluxpos@fluxpos"));

	$fluxposterminal = empty($_SESSION['fluxposterminal']) ? 1 : $_SESSION['fluxposterminal'];

	$haslines = false;
	foreach ($lines as $linetarget) {
		if ((int) $linetarget['id'] > 0 && (float) $linetarget['qty'] > 0) {
			$haslines = true;
			break;
		}
	}
	$invoice = fluxposPlaceTicket($place, $socid, $haslines);
	if (is_string($invoice)) {
		return array('error' => $invoice);
	}
	if ($invoice === null) {
		return array('place' => $place, 'socid' => 0, 'total_ttc' => 0, 'created' => 0);
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
		$errmsg = fluxposValidateAndPay($invoice, $payments, $fluxposterminal, $validatedhere);
		if ($errmsg !== '') {
			$db->rollback();
			return array('error' => $errmsg);
		}
	}
	$db->commit();

	$invoice->fetch($invoice->id);
	if ($validatedhere) {
		fluxposPrintInvoiceTicket($invoice->id, $fluxposterminal, $user);
	}

	$state = fluxposTicketState($invoice);
	$outlines = array();
	foreach ($state['lines'] as $line) {
		if ($line['kind'] != 'other') {
			$outlines[] = array('id' => $line['product'], 'lineid' => $line['lineid'], 'qty' => $line['qty'], 'total_ttc' => $line['total_ttc'], 'remise_percent' => $line['remise_percent'], 'label' => $line['label']);
		}
	}
	return array('placeid' => $invoice->id, 'place' => $place, 'socid' => $invoice->socid, 'total_ttc' => $invoice->total_ttc, 'ref' => $invoice->ref, 'paid' => (int) $invoice->paye, 'lines' => $outlines);
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
 * @param  array  $ticket   Open ticket the sale came from: array('id' => draft invoice id known by the terminal (0 if none), 'ops' => its operations not confirmed yet), empty when the sale was not on a ticket (see fluxposCloseTicketLines())
 * @param  float  $total    Total the terminal showed and charged (null when unknown)
 * @return array            Result info: array('uid', 'placeid', 'ref', 'total_ttc', 'paid', 'duplicate', 'check') or array('error' => ...)
 */
function fluxposSyncOfflineSale($saleuid, $place, $lines, $socid, $payments, $datesale, $ticket = array(), $total = null)
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

	// One line per product: a ticket can hold a product on two lines, the sale books the sum
	$summed = array();
	foreach ($lines as $linetarget) {
		$idsum = isset($linetarget['id']) ? (int) $linetarget['id'] : 0;
		$qtysum = isset($linetarget['qty']) ? (float) $linetarget['qty'] : 0;
		if ($idsum > 0 && $qtysum > 0) {
			$summed[$idsum] = (isset($summed[$idsum]) ? $summed[$idsum] : 0) + $qtysum;
		}
	}
	$lines = array();
	foreach ($summed as $idsum => $qtysum) {
		$lines[] = array('id' => $idsum, 'qty' => $qtysum);
	}
	if (!count($lines)) {
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

	// The sale came from an open ticket: take the sold lines off it (what another device
	// added meanwhile stays open). Anything unexpected is noted on the sale to be checked,
	// never refused: the money was taken.
	$checknotes = array();
	if (!empty($ticket['id']) || !empty($ticket['ops'])) {
		$note = fluxposCloseTicketLines($place, $ticket, $lines);
		if ($note !== '') {
			$checknotes[] = $note;
		}
	}
	$invoice->fetch($invoice->id);
	if ($total !== null && abs((float) $total - (float) $invoice->total_ttc) > 0.005) {
		$checknotes[] = 'The terminal charged '.price((float) $total, 0, $langs, 1, -1, 2, $conf->currency).' but the sale is booked at '.price((float) $invoice->total_ttc, 0, $langs, 1, -1, 2, $conf->currency);
	}

	$sql = "UPDATE ".MAIN_DB_PREFIX."fluxpos_sale SET fk_facture = ".((int) $invoice->id);
	$sql .= ", status = ".(count($checknotes) ? 1 : 0).", note = ".(count($checknotes) ? "'".$db->escape(dol_trunc(implode('; ', $checknotes), 250, 'right', 'UTF-8', 1))."'" : "NULL");
	$sql .= " WHERE rowid = ".((int) $saleid);
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

	return array('uid' => $saleuid, 'placeid' => $invoice->id, 'ref' => $invoice->ref, 'total_ttc' => $invoice->total_ttc, 'paid' => (int) $invoice->paye, 'duplicate' => 0, 'check' => count($checknotes));
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
	// The place "0" (terminal without tables) is a valid place: never test it with empty()
	$placeinput = (is_array($input) && isset($input['place'])) ? fluxposCleanPlace($input['place']) : '';
	if (!is_array($input) || $placeinput === '' || !isset($input['lines']) || !is_array($input['lines'])) {
		echo json_encode(array('error' => 'Bad request: place and lines are required'));
		exit;
	}

	$result = fluxposSyncOfflineOrder($placeinput, (array) $input['lines'], (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), (isset($input['payments']) && is_array($input['payments']) ? $input['payments'] : array()), (!empty($input['valid'])));
	echo json_encode($result);
} elseif ($action == 'applyOps' && $user->hasRight('fluxpos', 'run')) {
	// 1.8: the changes of a terminal to the open ticket of a place, as operations
	top_httphead('application/json');

	if ($_SERVER['REQUEST_METHOD'] != 'POST') {
		http_response_code(405);
		echo json_encode(array('error' => 'POST required'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	$placeinput = (is_array($input) && isset($input['place'])) ? fluxposCleanPlace($input['place']) : '';
	if (!is_array($input) || $placeinput === '' || !isset($input['ops']) || !is_array($input['ops'])) {
		echo json_encode(array('error' => 'Bad request: place and ops are required'));
		exit;
	}

	echo json_encode(fluxposApplyOps($placeinput, (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), $input['ops']));
} elseif ($action == 'getTicket' && $user->hasRight('fluxpos', 'run')) {
	// 1.8: current state of the open ticket of a place ({unchanged: 1} when the terminal
	// already has this version), read by the terminals every few seconds
	top_httphead('application/json');

	$placeinput = fluxposCleanPlace(GETPOST('place', 'alphanohtml'));
	if ($placeinput === '') {
		echo json_encode(array('error' => 'Bad request: place is required'));
		exit;
	}
	$ticketinput = fluxposPlaceTicket($placeinput, 0, false);
	$state = fluxposTicketState(is_object($ticketinput) ? $ticketinput : null);
	if (GETPOST('version', 'alphanohtml') !== '' && GETPOST('version', 'alphanohtml') === $state['version']) {
		echo json_encode(array('unchanged' => 1, 'version' => $state['version']));
	} else {
		echo json_encode($state);
	}
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

	$result = fluxposSyncOfflineSale((string) $input['uid'], (isset($input['place']) ? fluxposCleanPlace($input['place']) : ''), (array) $input['lines'], (!empty($input['thirdpartyid']) ? (int) $input['thirdpartyid'] : 0), (isset($input['payments']) && is_array($input['payments']) ? $input['payments'] : array()), (!empty($input['created']) ? (int) $input['created'] : 0), ((isset($input['ticket']) && is_array($input['ticket'])) ? $input['ticket'] : array()), (isset($input['total']) && is_numeric($input['total']) ? (float) $input['total'] : null));
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
} elseif ($action == 'setcustomer' && $user->hasRight('fluxpos', 'run')) {
	// Select the customer of the current place (1.3). Core list.php does this with its
	// own "action=change" block, but that one is gated on the takepos permission: on an
	// install that only runs FluxPos it is silently skipped and the customer list just
	// reloads itself. This endpoint does the same thing with the FluxPos constants.
	top_httphead('application/json');

	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$idcustomerSetCust = GETPOSTINT('idcustomer');
	$placeSetCust = GETPOST('place', 'alphanohtml');
	$termSetCust = empty($_SESSION["fluxposterminal"]) ? 1 : $_SESSION["fluxposterminal"];

	$setcustomerResp = array('success' => false, 'error' => 'CustomerNotFound', 'placeid' => 0);

	// Only an existing thirdparty of this install can be selected
	$socSetCust = new Societe($db);
	if ($idcustomerSetCust > 0 && $socSetCust->fetch($idcustomerSetCust) > 0) {
		$setcustomerResp = array('success' => false, 'error' => 'SaleNotADraftAnymore', 'placeid' => 0);

		$db->begin();

		// Check if the draft of this place already exists, if not create it
		// (same draft creation as invoice.php)
		$placeidSetCust = 0;
		$sqlSetCust = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture";
		$sqlSetCust .= " WHERE ref='(PROV-POS".$db->escape($termSetCust)."-".$db->escape($placeSetCust).")' AND entity IN (".getEntity('invoice').")";
		$resqlSetCust = $db->query($sqlSetCust);
		if ($resqlSetCust && $db->num_rows($resqlSetCust) > 0) {
			$objSetCust = $db->fetch_object($resqlSetCust);
			$placeidSetCust = (int) $objSetCust->rowid;
			$db->free($resqlSetCust);
		}
		if ($placeidSetCust == 0) {
			$invoiceSetCust = new Facture($db);
			$invoiceSetCust->socid = (int) getDolGlobalInt('FLUXPOS_ID_THIRDPARTY'.$termSetCust);

			$dolnowtzuserrel = dol_now('tzuserrel');	// If user is 02 january 22:00, we want to store '02 january'
			$monthuserSetCust = dol_print_date($dolnowtzuserrel, '%m', 'gmt');
			$dayuserSetCust = dol_print_date($dolnowtzuserrel, '%d', 'gmt');
			$yearuserSetCust = dol_print_date($dolnowtzuserrel, '%Y', 'gmt');
			$invoiceSetCust->date = dol_mktime(0, 0, 0, (int) $monthuserSetCust, (int) $dayuserSetCust, (int) $yearuserSetCust, 'tzserver');

			$invoiceSetCust->module_source = 'fluxpos';
			$invoiceSetCust->pos_source = $termSetCust;
			$invoiceSetCust->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;

			$placeidSetCust = $invoiceSetCust->create($user);
			if ($placeidSetCust > 0) {
				$sqlSetCust = "UPDATE ".MAIN_DB_PREFIX."facture";
				$sqlSetCust .= " SET ref='(PROV-POS".$db->escape($termSetCust)."-".$db->escape($placeSetCust).")'";
				$sqlSetCust .= " WHERE rowid = ".((int) $placeidSetCust);
				$db->query($sqlSetCust);
			} else {
				$setcustomerResp = array('success' => false, 'error' => 'DraftCreateFailed', 'placeid' => 0);
				dol_syslog('ajax.php setcustomer: DraftCreateFailed '.$invoiceSetCust->error, LOG_ERR);
			}
		}

		if ($placeidSetCust > 0) {
			// Guard on the status: a sale that is not an open draft anymore (validated
			// or paid first, then the connection returned) cannot get another customer
			$sqlSetCust = "UPDATE ".MAIN_DB_PREFIX."facture SET fk_soc = ".((int) $idcustomerSetCust);
			$sqlSetCust .= " WHERE rowid = ".((int) $placeidSetCust)." AND fk_statut = ".Facture::STATUS_DRAFT;
			$resqlSetCust = $db->query($sqlSetCust);
			if ($resqlSetCust && $db->affected_rows($resqlSetCust) > 0) {
				$setcustomerResp = array('success' => true, 'error' => '', 'placeid' => $placeidSetCust);
			} else {
				$setcustomerResp = array('success' => false, 'error' => 'SaleNotADraftAnymore', 'placeid' => $placeidSetCust);
			}
		}

		if ($setcustomerResp['success']) {
			$db->commit();
		} else {
			$db->rollback();
		}
	}

	echo json_encode($setcustomerResp);
}
