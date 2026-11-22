<?php
/* Copyright (C) 2018 Andreu Bisquerra	<jove@bisquerra.com>
 * Copyright (C) 2020 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
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
 *	\file       htdocs/fluxpos/printbox.php
 *	\ingroup    fluxpos
 *	\brief      Popup to enter details before printing
 */

//if (! defined('NOREQUIREUSER'))	define('NOREQUIREUSER','1');	// Not disabled cause need to load personalized language
//if (! defined('NOREQUIREDB'))		define('NOREQUIREDB','1');		// Not disabled cause need to load personalized language
//if (! defined('NOREQUIRESOC'))	define('NOREQUIRESOC','1');
//if (! defined('NOREQUIRETRAN'))	define('NOREQUIRETRAN','1');
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
/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

global $langs, $db;

$langs->loadLangs(array("bills", "fluxpos@fluxpos"));

$facid = GETPOSTINT('facid');

$action = GETPOST('action', 'aZ09');

if (!$user->hasRight('fluxpos', 'run')) {
	accessforbidden();
}


/*
 * Actions
 */

// None


/*
 * View
 */

$arrayofcss = array(dol_buildpath('/fluxpos/css/pos11.css.php', 1));
$arrayofjs = array(dol_buildpath('/fluxpos/js/jquery.colorbox-min.js', 1));

$head = '';
top_htmlhead($head, '', 0, 0, $arrayofjs, $arrayofcss);

?>
<body>
<script>
	/**
	 * Save (validate)
	 */
	function Save() {
		console.log("We click so we call page receipt.php with facid=<?php echo $facid; ?>");
		parent.$.colorbox.close();
		$.colorbox({ href:"receipt.php?facid=<?php echo $facid; ?>&action=<?php echo $action; ?>&token=<?php echo newToken(); ?>&label="+$('#label').val()+"&qty="+$('#qty').val(), width:"40%", height:"90%", transition:"none", iframe:"true", title:'<?php echo dol_escape_js($langs->trans("PrintTicket")); ?>'});
	}

	jQuery(document).ready(function(){
		$('#label').focus()
	});
</script>

<br>
<center>
<?php
if ($action == "without_details") {
	$label_default = getDolGlobalString('FLUXPOS_PRINT_WITHOUT_DETAILS_LABEL_DEFAULT');
	$qty_default = 1;

	print '<input type="text" id="label" name="label" class="fluxpospay" value="' . $label_default . '" style="width:40%;" placeholder="' . $langs->trans('Label') . '">';
	print '<input type="text" id="qty" name="qty" class="fluxpospay" value="' . $qty_default . '" style="width:10%;" placeholder="' . $langs->trans('Qty') . '">';
}
?>
<input type="button" class="button fluxpospay clearboth" value="OK" onclick="Save();">
</center>

</body>
</html>
