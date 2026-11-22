<?php
/* Copyright (C) 2021		Andreu Bisquerra		<jove@bisquerra.com>
 * Copyright (C) 2024		Frédéric France			<frederic.france@free.fr>
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
 *	\file       htdocs/fluxpos/split.php
 *	\ingroup	fluxpos
 *	\brief      Page with the content of the popup to split sale
 */

//if (! defined('NOREQUIREUSER'))	define('NOREQUIREUSER', '1');	// Not disabled cause need to load personalized language
//if (! defined('NOREQUIREDB'))		define('NOREQUIREDB', '1');		// Not disabled cause need to load personalized language
//if (! defined('NOREQUIRESOC'))		define('NOREQUIRESOC', '1');
//if (! defined('NOREQUIRETRAN'))		define('NOREQUIRETRAN', '1');
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

// Load Dolibarr environment
require_once __DIR__.'/mainenv.php'; // Load $user and permissions
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
$langs->loadLangs(array("main", "bills", "fluxpos@fluxpos", "banks"));

$action = GETPOST('action', 'aZ09');
$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : 0);

if (!$user->hasRight('fluxpos', 'run')) {
	accessforbidden();
}


/*
 * Actions
 */

if ($action == "split" && $user->hasRight('fluxpos', 'run')) {
	$line = GETPOSTINT('line');
	$split = GETPOSTINT('split');
	$invoice = null;
	$placeid = 0;
	// The line can only be taken from the draft invoice of this terminal it is moved from
	$sourceref = '(PROV-POS'.$_SESSION["fluxposterminal"].'-'.($split == 1 ? $place : 'SPLIT').')';
	if ($split == 1) { // Split line
		$invoice = new Facture($db);
		$ret = $invoice->fetch(0, '(PROV-POS'.$_SESSION["fluxposterminal"].'-SPLIT)');
		if ($ret > 0) {
			$placeid = $invoice->id;
		} else {
			$constforcompanyid = 'FLUXPOS_ID_THIRDPARTY'.$_SESSION["fluxposterminal"];
			$invoice->socid = getDolGlobalInt($constforcompanyid);
			$invoice->date = dol_now();
			$invoice->module_source = 'fluxpos';
			$invoice->pos_source = $_SESSION["fluxposterminal"];
			$invoice->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;
			if ($invoice->socid <= 0) {
				$langs->load('errors');
				dol_htmloutput_errors($langs->trans("ErrorModuleSetupNotComplete", "FluxPos"), [], 1);
			} else {
				$placeid = $invoice->create($user);
				if ($placeid < 0) {
					dol_htmloutput_errors($invoice->error, $invoice->errors, 1);
				}

				$sql = "UPDATE ".MAIN_DB_PREFIX."facture SET ref='(PROV-POS".$_SESSION["fluxposterminal"]."-SPLIT)'";
				$sql .= " WHERE rowid = ".((int) $placeid);
				$db->query($sql);
			}
		}
		if ($placeid > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."facturedet SET fk_facture = ".((int) $placeid)." WHERE rowid = ".((int) $line);
			$sql .= " AND fk_facture IN (SELECT f.rowid FROM ".MAIN_DB_PREFIX."facture as f WHERE f.ref = '".$db->escape($sourceref)."'";
			$sql .= " AND f.fk_statut = ".Facture::STATUS_DRAFT." AND f.entity IN (".getEntity('invoice')."))";
			$db->query($sql);
		}
	} elseif ($split == 0) { // Unsplit line
		$invoice = new Facture($db);
		if ($place == "SPLIT") {
			$place = "0";
		} // Avoid move line to the same place (from SPLIT to SPLIT place)
		$ret = $invoice->fetch(0, '(PROV-POS'.$_SESSION["fluxposterminal"].'-'.$place.')');
		if ($ret > 0) {
			$placeid = $invoice->id;
		} else {
			$constforcompanyid = 'FLUXPOS_ID_THIRDPARTY'.$_SESSION["fluxposterminal"];
			$invoice->socid = getDolGlobalInt($constforcompanyid);
			$invoice->date = dol_now();
			$invoice->module_source = 'fluxpos';
			$invoice->pos_source = $_SESSION["fluxposterminal"];
			$invoice->entity = !empty($_SESSION["fluxposinvoiceentity"]) ? $_SESSION["fluxposinvoiceentity"] : $conf->entity;
			if ($invoice->socid <= 0) {
				$langs->load('errors');
				dol_htmloutput_errors($langs->trans("ErrorModuleSetupNotComplete", "FluxPos"), [], 1);
			} else {
				$placeid = $invoice->create($user);
				if ($placeid < 0) {
					dol_htmloutput_errors($invoice->error, $invoice->errors, 1);
				}

				$sql = "UPDATE ".MAIN_DB_PREFIX."facture SET ref='(PROV-POS".$_SESSION["fluxposterminal"]."-".$place.")'";
				$sql .= " WHERE rowid = ".((int) $placeid);
				$db->query($sql);
			}
		}
		if ($placeid > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."facturedet SET fk_facture = ".((int) $placeid)." WHERE rowid = ".((int) $line);
			$sql .= " AND fk_facture IN (SELECT f.rowid FROM ".MAIN_DB_PREFIX."facture as f WHERE f.ref = '".$db->escape($sourceref)."'";
			$sql .= " AND f.fk_statut = ".Facture::STATUS_DRAFT." AND f.entity IN (".getEntity('invoice')."))";
			$db->query($sql);
		}
	}
	if ($invoice !== null) {
		$invoice->fetch(0, '(PROV-POS'.$_SESSION["fluxposterminal"].'-SPLIT)');
		$invoice->update_price();

		$invoice->fetch(0, '(PROV-POS'.$_SESSION["fluxposterminal"].'-'.$place.')');
		$invoice->update_price();
	}
}


/*
 * View
 */

$invoice = new Facture($db);
if (isset($invoiceid) && $invoiceid > 0) {
	$invoice->fetch($invoiceid);
} else {
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture where ref = '".$db->escape("(PROV-POS".$_SESSION["fluxposterminal"]."-".$place.")")."'";
	$resql = $db->query($sql);
	$obj = $db->fetch_object($resql);
	if ($obj) {
		$invoiceid = $obj->rowid;
	}
	if (!isset($invoiceid)) {
		$invoiceid = 0; // Invoice does not exist yet
	} else {
		$invoice->fetch($invoiceid);
	}
}

$arrayofcss = array(dol_buildpath('/fluxpos/css/pos11.css.php', 1));
if (getDolGlobalInt('FLUXPOS_COLOR_THEME') == 1) {
	$arrayofcss[] = dol_buildpath('/fluxpos/css/colorful.css', 1);
}
$arrayofjs = array();

$head = '';
$title = '';
$disablejs = 0;
$disablehead = 0;

top_htmlhead($head, $title, $disablejs, $disablehead, $arrayofjs, $arrayofcss);

// Define list of possible payments
$arrayOfValidPaymentModes = array();
$arrayOfValidBankAccount = array();

?>
<body class="fluxpossplitphp">

<script>
function Split(selectedline, split) {
	$.ajax({
		url: "split.php?action=split&token=<?php echo newToken(); ?>&line="+selectedline+"&split="+split+"&place=<?php echo $place;?>",
		context: document.body
	}).done(function() {
		$("#currentplace").load("invoice.php?place="+parent.place+"&invoiceid="+parent.invoiceid, function() {
			$('#currentplace').find('.posinvoiceline').click(function(){
				Split(this.id, 1);
			});
		});
		$("#splitplace").load("invoice.php?place=SPLIT", function() {
			$('#splitplace').find('.posinvoiceline').click(function(){
				Split(this.id, 0);
			});
		});
	});
}

$( document ).ready(function() {
	if (parent.place=='SPLIT') {
		parent.place=0;
		parent.invoiceid=0;
		parent.Refresh();
	}
	$("#currentplace").load("invoice.php?place="+parent.place+"&invoiceid="+parent.invoiceid, function() {
		$('#currentplace').find('.posinvoiceline')
		.click(function(){
			Split(this.id, 1);
		});
	});

	$("#splitplace").load("invoice.php?place=SPLIT", function() {
		$('#splitplace').find('.posinvoiceline').click(function(){
			Split(this.id, 0);
		});
	});



	$("#headersplit1").html("<?php echo $langs->trans("Place");?> "+parent.place);
	$("#headersplit2").html("<?php echo $langs->trans("SplitSale");?>");

});
</script>

<div class="headersplit">
  <a href="#" onclick="top.location.href='index.php?place='+parent.place"><div class="headercontent" id="headersplit1"></div></a>
</div>

<div class="rowsplit">
  <div class="splitsale" id="currentplace"></div>
</div>

<div class="headersplit">
  <a href="#" onclick="top.location.href='index.php?place=SPLIT'"><div class="headercontent" id="headersplit2"></div></a>
</div>

<div class="rowsplit">
  <div class="splitsale" id="splitplace"></div>
</div>

</body>
</html>
