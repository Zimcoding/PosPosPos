<?php
/* Copyright (C) 2008-2011  Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2011-2017  Juanjo Menent		<jmenent@2byte.es>
 * Copyright (C) 2022       Alexandre Spangaro  <aspangaro@open-dsi.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025-2026	MDW						<mdeweerd@users.noreply.github.com>
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
 *	\file       htdocs/fluxpos/admin/bar.php
 *	\ingroup    fluxpos
 *	\brief      Setup page for FluxPos module - Bar Restaurant features
 */

// Load Dolibarr environment
require_once __DIR__.'/../mainenv.php'; // Load $user and permissions
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 * @var string $dolibarr_main_url_root
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once dol_buildpath('/fluxpos/lib/fluxpos.lib.php');

// Security check
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "fluxpos@fluxpos", "printing"));

$res = 0;


/*
 * Actions
 */
$error = 0;

if (GETPOST('action', 'alpha') == 'set') {
	$db->begin();

	dol_syslog("admin/bar");

	$suplement_category = GETPOST('FLUXPOS_SUPPLEMENTS_CATEGORY', 'alpha');
	if ($suplement_category < 0) {
		$suplement_category = 0;
	}

	$res = dolibarr_set_const($db, "FLUXPOS_SUPPLEMENTS_CATEGORY", $suplement_category, 'chaine', 0, '', $conf->entity);
	if ($res <= 0) {
		$error++;
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

if (getDolGlobalInt('FLUXPOS_ORDER_NOTES') == 1) {
	$extrafields = new ExtraFields($db);
	$extrafields->addExtraField('order_notes', 'Order notes', 'varchar', 0, '255', 'facturedet', 0, 0, '', '', 0, '', '0', '1');
}

/*
 * View
 */

$form = new Form($db);
$formproduct = new FormProduct($db);

$arrayofjs = array();
$arrayofcss = array(dol_buildpath('/fluxpos/css/colorbox.css', 1));

llxHeader('', $langs->trans("CashDeskSetup"), '', '', 0, 0, $arrayofjs, $arrayofcss, '', 'mod-fluxpos page-admin_bar');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans("CashDeskSetup").' (FluxPos)', $linkback, 'title_setup');
$head = fluxpos_admin_prepare_head();
print dol_get_fiche_head($head, 'bar', 'FluxPos', -1, 'cash-register');


// Mode
print '<form action="'.$_SERVER["PHP_SELF"].'" method="post">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="set">';

?>
<script type="text/javascript" src="<?php echo dol_buildpath(dol_buildpath('/fluxpos/js/jquery.colorbox-min.js', 1), 1) ?>"></script>	<!-- TODO It seems we don't need this -->
<script type="text/javascript">
function Floors() {
	console.log("Open box to select floor");
	$.colorbox({href:"<?php echo dol_buildpath('/fluxpos/floors.php', 1) ?>?mode=edit&place=0", width:"90%", height:"90%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("Floors"); ?>"});
}
</script>

<?php

print '<center>';
print $langs->trans("EnableBarOrRestaurantFeatures");
print ajax_constantonoff("FLUXPOS_BAR_RESTAURANT", array(), $conf->entity, 0, 0, 1, 0);
print '</center>';

if (getDolGlobalInt('FLUXPOS_BAR_RESTAURANT')) {
	print '<br>';
	print '<br>';
	print '<a href="" onclick="Floors(); return false;"><span class="fa fa-glass-cheers"></span> '.$langs->trans("DefineTablePlan").'</a><br>';
	print '<br><br>';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("Parameters").'</td><td class=""></td>';
	print "</tr>\n";

	print '<tr class="oddeven value"><td>';
	print $langs->trans("OrderPrinters");
	print '</td>';
	print '<td class="">';
	print ajax_constantonoff("FLUXPOS_ORDER_PRINTERS", array(), $conf->entity, 0, 0, 1, 0);
	if (getDolGlobalString('FLUXPOS_ORDER_PRINTERS')) {
		print' &nbsp; <a href="'.dol_buildpath('/fluxpos/admin/orderprinters.php', 1).'?leftmenu=setup">'.$langs->trans("Setup").'</a>';
	}
	print '</td></tr>';

	if (getDolGlobalString('FLUXPOS_ORDER_PRINTERS')) {
		print '<tr class="oddeven value"><td>';
		print $langs->trans("OrderNotes");
		print '</td>';
		print '<td class="">';
		print ajax_constantonoff("FLUXPOS_ORDER_NOTES", array(), $conf->entity, 0, 0, 1, 0);
		print '</td></tr>';
	}
	/*else {
		print '<tr class="oddeven value"><td>';
		print $langs->trans("OrderPrinters");
		print '</td>';
		print '<td class="">';
		print '<span class="opacitymedium">'.$langs->trans("NotAvailableWithBrowserPrinter").'</span>';
		print '</td></tr>';

		print '<tr class="oddeven value"><td>';
		print $langs->trans("OrderNotes");
		print '</td>';
		print '<td class="">';
		print '<span class="opacitymedium">'.$langs->trans("NotAvailableWithBrowserPrinter").'</span>';
		print '</td></tr>';
	}*/

	print '<tr class="oddeven value"><td>';
	print $langs->trans("ProductSupplements");
	print '</td>';
	print '<td class="">';
	//print $form->selectyesno("FLUXPOS_SUPPLEMENTS", $conf->global->FLUXPOS_SUPPLEMENTS, 1);
	print ajax_constantonoff("FLUXPOS_SUPPLEMENTS", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	if (getDolGlobalInt('FLUXPOS_SUPPLEMENTS')) {
		print '<tr class="oddeven"><td>';
		print $langs->trans("SupplementCategory");
		print '</td>';
		print '<td class="nowrap">';
		//print $form->selectCategories(Categorie::TYPE_PRODUCT, 'FLUXPOS_SUPPLEMENTS_CATEGORY', null);
		print img_picto('', 'category', 'class="pictofixedwidth"');
		print $form->select_all_categories(Categorie::TYPE_PRODUCT, getDolGlobalString('FLUXPOS_SUPPLEMENTS_CATEGORY'), 'FLUXPOS_SUPPLEMENTS_CATEGORY', 64, 0, 0, 0, 'minwidth 200 maxwidth500 widthcentpercentminusx');
		print ajax_combobox('FLUXPOS_SUPPLEMENTS_CATEGORY');
		print "</td></tr>\n";
	}

	print '<tr class="oddeven value"><td>';
	print 'QR - '.$langs->trans("CustomerMenu");
	print '</td>';
	print '<td class="">';
	print ajax_constantonoff("FLUXPOS_QR_MENU", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	print '<tr class="oddeven value"><td>';
	print 'QR - '.$langs->trans("AutoOrder");
	print ' - <span class="warning">'.$langs->trans("Development").'</span>';
	print '</td>';
	print '<td class="">';
	print ajax_constantonoff("FLUXPOS_AUTO_ORDER", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	print '</table>';
	print '</div>';

	print '<br>';

	print $form->buttonsSaveCancel("Save", '');
}

if (getDolGlobalInt('FLUXPOS_BAR_RESTAURANT')) {
	if (getDolGlobalInt('FLUXPOS_QR_MENU')) {
		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
		print '<br>';
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans("URL").' - '.$langs->trans("CustomerMenu").'</td><td class="right">'.$langs->trans("QR").'</td>';
		print "</tr>\n";
		print '<tr class="oddeven value"><td>';
		print '<a target="_blank" rel="noopener noreferrer" href="'.dol_buildpath('/fluxpos/public/menu.php', 1).'">'.dol_buildpath('/fluxpos/public/menu.php', 1).'</a>';
		print '</td>';
		print '<td class="right">';
		print '<a target="_blank" rel="noopener noreferrer" href="printqr.php"><img src="'.dol_buildpath(dol_buildpath('/fluxpos/genimg/qr.php', 1), 1).'" height="42" width="42"></a>';
		print '</td></tr>';
		print '</table>';
		print '</div>';
	}

	if (getDolGlobalInt('FLUXPOS_AUTO_ORDER')) {
		print '<br>';
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans("Table").'</td><td>'.$langs->trans("URL").' - '.$langs->trans("AutoOrder").'</td><td class="right">'.$langs->trans("QR").'</td>';
		print "</tr>\n";

		//global $dolibarr_main_url_root;
		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
		$sql = "SELECT rowid, entity, label, leftpos, toppos, floor FROM ".MAIN_DB_PREFIX."fluxpos_floor_tables WHERE entity IN (".getEntity('fluxpos').")";
		$resql = $db->query($sql);
		$rows = array();
		while ($row = $db->fetch_array($resql)) {
			print '<tr class="oddeven value"><td>';
			print $langs->trans("Table")." ".$row['label'];
			print '</td>';
			print '<td>';
			print '<a target="_blank" rel="noopener noreferrer" href="'.dol_buildpath('/fluxpos/public/menu.php', 1).'">'.dol_buildpath('/fluxpos/public/menu.php', 1).'</a>';
			print '</td>';
			print '<td class="right">';
			print '<a target="_blank" rel="noopener noreferrer" href="printqr.php?id='.$row['rowid'].'"><img src="'.dol_buildpath(dol_buildpath('/fluxpos/genimg/qr.php', 1), 1).'?key='.dol_encode($row['rowid']).'" height="42" width="42"></a>';
			print '</td></tr>';
		}

		print '</table>';
		print '</div>';
	}
}


print "</form>\n";

print '<br>';

llxFooter();
$db->close();
