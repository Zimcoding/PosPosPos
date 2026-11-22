<?php
/* Copyright (C) 2018	Andreu Bisquerra	<jove@bisquerra.com>
 * Copyright (C) 2019	Josep Lluís Amador	<joseplluis@lliuretic.cat>
 * Copyright (C) 2020	Thibault FOUCART	<support@ptibogxiv.net>
 * Copyright (C) 2024-2026	MDW				<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024-2025  Frédéric France         <frederic.france@free.fr>
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
 *    \file       htdocs/fluxpos/index.php
 *    \ingroup    fluxpos
 *    \brief      Main FluxPos screen
 */

// if (! defined('NOREQUIREUSER')) 		define('NOREQUIREUSER','1'); 		// Not disabled cause need to load personalized language
// if (! defined('NOREQUIREDB')) 		define('NOREQUIREDB','1'); 			// Not disabled cause need to load personalized language
// if (! defined('NOREQUIRESOC')) 		define('NOREQUIRESOC','1');
// if (! defined('NOREQUIRETRAN')) 		define('NOREQUIRETRAN','1');

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
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';


$langs->loadLangs(array("bills", "orders", "commercial", "fluxpos@fluxpos", "receiptprinter", "banks"));

$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : 0); // $place is id of table for Bar or Restaurant or multiple sales
$action = GETPOST('action', 'aZ09');
$setterminal = GETPOSTINT('setterminal');
$setcurrency = GETPOST('setcurrency', 'aZ09');

$hookmanager->initHooks(array('fluxposfrontend'));
if (empty($_SESSION["fluxposterminal"])) {
	if (getDolGlobalInt('FLUXPOS_NUM_TERMINALS') == 1) {
		$_SESSION["fluxposterminal"] = 1; // Use terminal 1 if there is only 1 terminal
	} elseif (!empty($_COOKIE["fluxposterminal"])) {
		$_SESSION["fluxposterminal"] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_COOKIE["fluxposterminal"]); // Restore fluxposterminal from previous session
	}
}

if ($setterminal > 0) {
	$_SESSION["fluxposterminal"] = (int) $setterminal;
	dolSetCookie("fluxposterminal", (string) $setterminal, -1); // fluxposterminal var in a 1 year cookie
}

if ($setcurrency != "") {
	$_SESSION["fluxposcustomercurrency"] = $setcurrency;
	// We will recalculate amount for foreign currency at next call of invoice.php when $_SESSION["fluxposcustomercurrency"] differs from invoice->multicurrency_code.
}


$categorie = new Categorie($db);

$maxcategbydefaultforthisdevice = 12;
$maxproductbydefaultforthisdevice = 24;

$MAXCATEG = getDolGlobalInt('FLUXPOS_NB_MAXCATEG', $maxcategbydefaultforthisdevice);
$MAXPRODUCT = getDolGlobalInt('FLUXPOS_NB_MAXPRODUCT', $maxproductbydefaultforthisdevice);

// Layout 1.1 (Proposal A): the numberpad is contextual by default (it opens as a popup
// when a cart line is tapped) and the product grid is fluid. Constants:
// - FLUXPOS_DISPLAY_NUMBERPAD = 'lineedit' (default, popup) | 'always' (docked pad like before) | 'never'
// - FLUXPOS_TILE_SIZE = 'S' (~120 px tiles) | 'M' (~160 px) | 'L' (~220 px)
$fluxposDisplayNumberpad = getDolGlobalString('FLUXPOS_DISPLAY_NUMBERPAD', 'lineedit');
if (!in_array($fluxposDisplayNumberpad, array('lineedit', 'always', 'never'), true)) {
	$fluxposDisplayNumberpad = 'lineedit';
}
$fluxposTileSize = getDolGlobalString('FLUXPOS_TILE_SIZE', 'M');
if (!in_array($fluxposTileSize, array('S', 'M', 'L'), true)) {
	$fluxposTileSize = 'M';
}

$term = empty($_SESSION['fluxposterminal']) ? 1 : $_SESSION['fluxposterminal'];

$socid = getDolGlobalInt('FLUXPOS_ID_THIRDPARTY' . $term);


/*
 $constforcompanyid = 'FLUXPOS_ID_THIRDPARTY'.$_SESSION["fluxposterminal"];
 $soc = new Societe($db);
 if ($invoice->socid > 0) $soc->fetch($invoice->socid);
 else $soc->fetch(getDolGlobalInt($constforcompanyid));
 */

// Security check
$result = restrictedArea($user, 'fluxpos', 0, '');



/*
 * View
 */

$form = new Form($db);

$disablejs = 0;
$disablehead = 0;

// Static assets of the module carry the modification time of the file on disk as
// version, so a terminal drops its browser-cached copy after every deployment.
// Without this, a terminal could keep running an old offline.js for a long time
// (the service worker cache only renews when itself is renewed), and the old
// engine then calls the server on an obsolete URL with an obsolete token.
$fluxpos_javascripts = array('/fluxpos/js/jquery.colorbox-min.js', '/fluxpos/js/offline11.js');
$arrayofjs = array();
foreach ($fluxpos_javascripts as $fluxpos_javascript) {
	$fluxpos_javascript_mtime = (int) @filemtime(dol_buildpath($fluxpos_javascript, 0));
	$arrayofjs[] = dol_buildpath($fluxpos_javascript, 1).($fluxpos_javascript_mtime > 0 ? '?v='.$fluxpos_javascript_mtime : '');
}
// Same mtime-based cache busting as the JS files above; without this, browsers and proxies keep
// an old pos.css.php for up to 3 hours (Cache-Control max-age inside the file) after an update.
$fluxpos_css_mtime = (int) @filemtime(dol_buildpath('/fluxpos/css/pos11.css.php', 0));
$arrayofcss = array(dol_buildpath('/fluxpos/css/pos11.css.php', 1).($fluxpos_css_mtime > 0 ? '?v='.$fluxpos_css_mtime : ''), dol_buildpath('/fluxpos/css/colorbox.css', 1));

if (getDolGlobalInt('FLUXPOS_COLOR_THEME') == 1) {
	$arrayofcss[] =  dol_buildpath('/fluxpos/css/colorful.css', 1);
}


// Title
$title = 'FluxPos - Dolibarr '.DOL_VERSION;
if (getDolGlobalString('MAIN_APPLICATION_TITLE')) {
	$title = 'FluxPos - ' . getDolGlobalString('MAIN_APPLICATION_TITLE');
}
$head = '<meta name="apple-mobile-web-app-title" content="FluxPos"/>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>';

top_htmlhead($head, $title, $disablejs, $disablehead, $arrayofjs, $arrayofcss);

// Inline critical layout (1.1.8), printed in the HTML document itself: the two-column
// POS layout (products on the left, cart on the right) does not depend anymore on an
// external stylesheet copy. No intermediate cache (browser, LiteSpeed or an old service
// worker) can serve a document body stale, but every one of them could serve the
// linked pos11.css.php from before 1.1 and break the layout silently. This block is
// the last declaration source in the head, so it also repairs a screen on which an
// old stylesheet copy still arrives: the external file only adds the details
// (colors, sizes, animations) on top of this guaranteed structure.
print '<style type="text/css" id="fluxpos-criticallayout">'."\n";
print '/* FluxPos critical layout inline - always mirror the two-column structure of pos11.css.php */'."\n";
print '.posmain{display:flex;flex-direction:row;align-items:stretch;width:100%;height:100%;overflow:hidden;box-sizing:border-box;}'."\n";
print '.posmain.posmainwithhead{height:calc(100% - 50px);padding-top:9px;}'."\n";
print '.posmain .posleft{display:flex;flex-direction:column;flex:1 1 auto;min-width:0;height:100%;overflow:hidden;box-sizing:border-box;}'."\n";
print '.posmain .cartcolumn{display:flex;flex-direction:column;flex:0 0 28%;min-width:300px;max-width:420px;height:100%;overflow:hidden;box-sizing:border-box;border-left:1px solid #DDD;}'."\n";
print '.posmain .div4{flex:0 0 48px;height:48px;width:100%;box-sizing:border-box;padding-top:2px;}'."\n";
print '@media screen and (max-width: 767px){.posmain{flex-direction:column;}.posmain .cartcolumn{flex:0 0 45%;max-width:none;border-left:none;border-top:1px solid #DDD;}}'."\n";
print '</style>'."\n";


$categories = $categorie->get_full_arbo('product', getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID'), 1);


// Search root category to know its level
//$conf->global->FLUXPOS_ROOT_CATEGORY_ID=0;
$levelofrootcategory = 0;
if (getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID') > 0) {
	foreach ($categories as $key => $categorycursor) {
		// @phan-suppress-next-line PhanTypeInvalidDimOffset
		if ($categorycursor['id'] == getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID')) {
			$levelofrootcategory = $categorycursor['level'];
			break;
		}
	}
}

$levelofmaincategories = $levelofrootcategory + 1;		// Main categories are categories just under the root categorie of POS products.

$maincategories = array();
$subcategories = array();
foreach ($categories as $key => $categorycursor) {
	if ($categorycursor['level'] == $levelofmaincategories) {
		$maincategories[$key] = $categorycursor;
	} elseif ($categorycursor['level'] > $levelofmaincategories) {
		$subcategories[$key] = $categorycursor;			// If category level 2 or 3 or ...
	}
}

$maincategories = dol_sort_array($maincategories, 'label');
$subcategories = dol_sort_array($subcategories, 'label');
?>
<?php
$keyCodeForEnter = '';
if (!empty($_SESSION['fluxposterminal'])) {
	$keyCodeForEnter = getDolGlobalInt('FLUXPOS_READER_KEYCODE_FOR_ENTER'.$_SESSION['fluxposterminal']) > 0 ? getDolGlobalString('FLUXPOS_READER_KEYCODE_FOR_ENTER'.$_SESSION['fluxposterminal']) : '';
}
?>

<body class="bodyfluxpos fluxpos-tile-<?php echo $fluxposTileSize; ?>" style="overflow: hidden;">

<script>
var categories = <?php echo json_encode($maincategories); ?>;
var subcategories = <?php echo json_encode($subcategories); ?>;

var currentcat;
var pageproducts=0;
var currentsubcategories=[];	// Subcategories of currentcat, shown before its products in the product grid
var pagecategories=0;
var pageactions=0;
var place="<?php echo $place; ?>";
var fluxposbaseuri = '<?php echo fluxposUrlRoot(); ?>';
var fluxposnewtoken = '<?php echo newToken(); ?>';
var fluxposcurrency = '<?php echo $conf->currency; ?>';
var fluxposhideproductimages = <?php echo getDolGlobalString('FLUXPOS_HIDE_PRODUCT_IMAGES') ? 'true' : 'false'; ?>;
var fluxposlang = {
	Qty: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("Qty")); ?>',
	TotalTTCShort: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("TotalTTCShort")); ?>',
	TotalTTC: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("TotalTTC")); ?>',
	Empty: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("Empty")); ?>',
	Change: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("Change")); ?>',
	RemainToPay: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("RemainToPay")); ?>',
	PaymentTypeShortLIQ: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("PaymentTypeShortLIQ")); ?>',
	PaymentTypeShortCB: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("PaymentTypeShortCB")); ?>',
	PaymentTypeShortCHQ: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("PaymentTypeShortCHQ")); ?>',
	FluxPosOfflineNoIndexedDB: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineNoIndexedDB")); ?>',
	FluxPosOfflineBlockedNotice: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineBlockedNotice")); ?>',
	FluxPosOfflineSaleLocked: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineSaleLocked")); ?>',
	FluxPosOfflineNotInCachedCatalog: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineNotInCachedCatalog")); ?>',
	FluxPosOfflineAdoptedLineRaiseOnly: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineAdoptedLineRaiseOnly")); ?>',
	FluxPosOfflineOrderJustSent: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineOrderJustSent")); ?>',
	FluxPosOfflineNoOrderCaptured: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineNoOrderCaptured")); ?>',
	FluxPosOfflineAdoptedValidate: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineAdoptedValidate")); ?>',
	FluxPosOfflineSaleStored: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineSaleStored")); ?>',
	FluxPosOfflineSaleStoredNotice: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineSaleStoredNotice")); ?>',
	FluxPosOfflineAdoptedNotice: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineAdoptedNotice")); ?>',
	FluxPosOfflineOrderSynced: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineOrderSynced")); ?>',
	FluxPosOfflineOrderStoredOffline: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineOrderStoredOffline")); ?>',
	FluxPosOfflineEmptyCartNotice: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineEmptyCartNotice")); ?>',
	FluxPosOfflineUnexpectedAnswer: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineUnexpectedAnswer")); ?>',
	FluxPosOfflineStatusOffline: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusOffline")); ?>',
	FluxPosOfflineStatusCatalogOnly: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusCatalogOnly")); ?>',
	FluxPosOfflineStatusSalesWaiting: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusSalesWaiting")); ?>',
	FluxPosOfflineStatusRefused: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusRefused")); ?>',
	FluxPosOfflineStatusOrdersWaiting: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusOrdersWaiting")); ?>',
	FluxPosOfflineStatusClickRetry: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOfflineStatusClickRetry")); ?>',
	FluxPosTotal: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("TotalTTCShort")); ?>',
	FluxPosTileSize: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosTileSize")); ?>',
	FluxPosOtherPaymentModes: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosOtherPaymentModes")); ?>',
	FluxPosLineEditTitle: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosLineEditTitle")); ?>',
	FluxPosMoreActions: '<?php echo dol_escape_js($langs->transnoentitiesnoconv("FluxPosMoreActions")); ?>'
};
var editaction="qty";
var editnumber="";
var invoiceid=0;
var search2_timer=null;
var fluxposlastitems=[];	// Products of the category currently displayed in the grid (used to go back from the search)
var fluxposdisplaynumberpad='<?php echo $fluxposDisplayNumberpad; ?>';

/*
var app = this;
app.hasKeyboard = false;
this.keyboardPress = function() {
	app.hasKeyboard = true;
	$(window).unbind("keyup", app.keyboardPress);
	localStorage.hasKeyboard = true;
	console.log("has keyboard!")
}
$(window).on("keyup", app.keyboardPress)
if(localStorage.hasKeyboard) {
	app.hasKeyboard = true;
	$(window).unbind("keyup", app.keyboardPress);
	console.log("has keyboard from localStorage")
}
*/

function ClearSearch(clearSearchResults) {
	console.log("ClearSearch");
	$("#search").val('');
	$("#qty").html("<?php echo $langs->trans("Qty"); ?>").removeClass('clicked');
	$("#price").html("<?php echo $langs->trans("Price"); ?>").removeClass('clicked');
	$("#reduction").html("<?php echo $langs->trans("LineDiscountShort"); ?>").removeClass('clicked');
	<?php if ($conf->browser->layout == 'classic') { ?>
	setFocusOnSearchField();
	<?php } ?>
	if (clearSearchResults) {
		$("#search").trigger('keyup');
	}
}

// Set the focus on search field but only on desktop. On tablet or smartphone, we don't to avoid to have the keyboard open automatically
function setFocusOnSearchField() {
	console.log("Call setFocusOnSearchField in page index.php");
	<?php if ($conf->browser->layout == 'classic') { ?>
		console.log("has keyboard from localStorage, so we can force focus on search field");
		$("#search").focus();
	<?php } ?>
}

function PrintCategories(first) {
	console.log("PrintCategories (legacy shim: the 1.1 tab bar renders every category server side)");
}

// Scroll the category tab strip by roughly one visible page in the given direction.
function FluxPosScrollTabs(step) {
	var bar = document.getElementById('tabbar');
	if (!bar) {
		return;
	}
	bar.scrollBy({ left: step * Math.max(bar.clientWidth * 0.8, 200), behavior: 'smooth' });
}

/**
 * Href of the pos11 stylesheet link for diagnostics (used by the layout guard).
 *
 * @param   Element|null    linkEl   The <link> element found, if any
 * @return  string                   The href or an explicit not-found text
 */
function detectCssLinkHref(linkEl) {
	'use strict';
	return (linkEl && linkEl.getAttribute('href')) ? linkEl.getAttribute('href') : 'pos11.css.php (link not found in this page)';
}

/**
 * Show the layout-guard banner above the cart (1.1.8): makes a degraded layout visible
 * and says what to do, instead of an old screen that only the console reveals.
 *
 * @param   string  text    Explanation shown to the user
 * @return  void
 */
function FluxPosShowLayoutWarning(text) {
	'use strict';
	var col = document.querySelector('.cartcolumn');
	if (!col || document.getElementById('fluxposlayoutwarning')) {
		return;
	}
	var warn = document.createElement('div');
	warn.id = 'fluxposlayoutwarning';
	warn.style.cssText = 'padding:4px 6px;background:#f8d7da;border:1px solid #f5c2c7;color:#842029;font-size:12px;';
	warn.textContent = text;
	col.insertBefore(warn, col.firstChild);
}

// Show the tab arrows (before and after the strip) only when the strip overflows and the
// hidden side is not already fully visible.
function FluxPosUpdateTabArrows() {
	var bar = document.getElementById('tabbar');
	if (!bar) {
		return;
	}
	var left = document.querySelector('.tabarrowleft');
	var right = document.querySelector('.tabarrowright');
	if (left) {
		left.style.display = (bar.scrollLeft > 2) ? 'flex' : 'none';
	}
	if (right) {
		right.style.display = (bar.scrollWidth - bar.clientWidth - bar.scrollLeft > 2) ? 'flex' : 'none';
	}
}

function MoreCategories(moreorless) {
	// Legacy TakePOS category paging: useless since the tab bar holds every category
	// at once and scrolls instead (kept as a shim for external callers).
	console.log("MoreCategories (legacy shim, no paging in the 1.1 tab bar)");
}

// Escape a text for safe insertion into a tile (names are user data)
function FluxPosEscapeHtml(text) {
	return jQuery('<div/>').text(text == null ? '' : String(text)).html();
}

// Tile HTML for the fluid product grid. The tiles keep the historical DOM contract:
// ids prodivN/proimgN/prodescN/propriceN/prodivdescN (index = position on the grid)
// and data-rowid/data-iscat/data-unit on the tile, so ClickProduct(position), the
// weighing scale and the product-display hooks keep working like before.
function FluxPosTileHtml(item, index) {
	var val = item.data;
	if (item.iscat == 1) {
		var catlabel = FluxPosEscapeHtml(val.label);
		<?php if (getDolGlobalString('FLUXPOS_SHOW_CATEGORY_DESCRIPTION') == 1) { ?>
			catlabel = FluxPosEscapeHtml(val.label) + ' - ' + FluxPosEscapeHtml(val.description);
		<?php } ?>
		return '<div class="wrapper2" id="prodiv'+index+'" data-rowid="'+val.rowid+'" data-iscat="1" onclick="ClickProduct('+index+')">'
			+ '<img class="imgwrapper" id="proimg'+index+'" onerror="this.style.display=&#39;none&#39;;" src="genimg/index.php?query=cat&id='+val.rowid+'" title="">'
			+ '<div class="description" id="prodivdesc'+index+'"><div class="description_content" id="prodesc'+index+'">'+catlabel+'</div></div>'
			+ '</div>';
	}
	var rowid = (typeof val['rowid'] != 'undefined') ? val['rowid'] : val['id'];
	var label = FluxPosEscapeHtml(val['label']);
	<?php
	if (getDolGlobalInt('FLUXPOS_SHOW_PRODUCT_REFERENCE') == 1) {
		?>
		label = FluxPosEscapeHtml(val['ref']) + ' - ' + FluxPosEscapeHtml(val['label']);
		<?php
	} elseif (getDolGlobalInt('FLUXPOS_SHOW_PRODUCT_REFERENCE') == 2) {
		?>
		label = '<b>' + FluxPosEscapeHtml(val['ref']) + '</b>';
		<?php
	}
	?>
	var imgtitle = <?php echo "'".dol_escape_js($langs->transnoentities('Ref').': ')."' + (val['ref'] || '')"; ?>;
	imgtitle += <?php echo "' - ".dol_escape_js($langs->trans("Barcode").': ')."' + (val['barcode'] || '')"; ?>;
	var imgsrc = (typeof val['img'] != 'undefined' && val['img']) ? val['img'] : 'genimg/index.php?query=pro&id='+rowid;
	var html = '<div class="wrapper2" id="prodiv'+index+'" data-rowid="'+rowid+'" data-iscat="0"';
	if (typeof val['fk_unit'] != 'undefined') {
		html += ' data-unit="'+FluxPosEscapeHtml(val['fk_unit'])+'"';
	}
	html += ' onclick="ClickProduct('+index+')">';
	<?php if (!getDolGlobalString('FLUXPOS_HIDE_PRODUCT_PRICES')) { ?>
		if (val['price_formated']) {
			var pricehtml = <?php if (getDolGlobalInt('FLUXPOS_CHANGE_PRICE_HT')) {
				echo "val['price_formated']";
			} else {
				echo "val['price_ttc_formated']";
			} ?>;
			// The formatted price comes from the server (price() output) and already
			// contains the right HTML entities (&nbsp; before the currency): it must be
			// inserted raw — escaping it would display the entity as plain text.
			html += '<div class="productprice" id="proprice'+index+'">'+pricehtml+'</div>';
		} else {
			html += '<div class="productprice hidden" id="proprice'+index+'"></div>';
		}
	<?php } else { ?>
		html += '<div class="hidden" id="proprice'+index+'"></div>';
	<?php } ?>
	<?php if (!getDolGlobalString('FLUXPOS_HIDE_PRODUCT_IMAGES')) { ?>
		html += '<img class="imgwrapper" id="proimg'+index+'" onerror="this.style.display=&#39;none&#39;;" src="'+imgsrc+'" title="'+FluxPosEscapeHtml(imgtitle)+'">';
		html += '<div class="description" id="prodivdesc'+index+'"><div class="description_content" id="prodesc'+index+'">'+label+'</div></div>';
	<?php } else { ?>
		html += '<img class="imgwrapper hidden" id="proimg'+index+'" onerror="this.style.display=&#39;none&#39;;" src="'+imgsrc+'" title="">';
		html += '<button type="button" class="productbutton" id="probutton'+index+'">'+label+'</button>';
	<?php } ?>
	html += '</div>';
	return html;
}

// Render the fluid product grid: every subcategory of the current category first (when
// wanted), then every product of the data array. No fixed tile pool, no pagination:
// the whole content of the category is rendered at once and the area scrolls.
function RenderProducts(data, withsubcats) {
	console.log("RenderProducts "+data.length+" products, withsubcats="+withsubcats);
	var grid = jQuery("#productgrid");
	if (!grid.length) {
		return;
	}
	if (withsubcats !== false) {
		withsubcats = true;
	}
	fluxposlastitems = data;	// keep for the "back to the category" of ClearSearch

	var html = '';
	var index = 0;
	var i;
	if (withsubcats && typeof currentsubcategories != 'undefined') {
		for (i = 0; i < currentsubcategories.length; i++) {
			html += FluxPosTileHtml({ iscat: 1, data: currentsubcategories[i] }, index++);
		}
	}
	for (i = 0; i < data.length; i++) {
		if (typeof (data[i]) == "undefined") {
			continue;
		}
		html += FluxPosTileHtml({ iscat: 0, data: data[i] }, index++);
	}
	if (index == 0) {
		html = '<div class="fluxposgridempty opacitymedium"><?php echo dol_escape_js($langs->trans("Empty")); ?></div>';
	}
	grid.html(html);
	ishow = index;
	idata = data.length;

	<?php
	// Add js from hooks
	$parameters = array();
	$parameters['caller'] = 'loadProducts';
	$hookmanager->executeHooks('completeJSProductDisplay', $parameters);
	print $hookmanager->resPrint;
	?>
}

// Show a subcategory into the tile ishow of the product grid
function PrintSubCategory(ishow, val) {
	// Legacy fixed-cell renderer: kept as a thin alias for compatibility, the tiles
	// are (re)rendered by RenderProducts since the fluid grid.
	if (typeof currentsubcategories != 'undefined') {
		if (val && typeof val.fk_parent != 'undefined') {
			RenderProducts(fluxposlastitems || [], true);
		}
	}
}

// LoadProducts
function LoadProducts(position, issubcat) {
	console.log("LoadProducts position="+position+" issubcat="+issubcat);
	var maxproduct = <?php echo (int) ($MAXPRODUCT - 2); ?>;

	if (position == "supplements") {
		currentcat = "supplements";

		if (subcategories.count == 0) {
			alert("No product found into categories level 2 or more");
		}
	} else {
		$('#catimg'+position).animate({opacity: '0.5'}, 1);
		$('#catimg'+position).animate({opacity: '1'}, 100);
		if (issubcat == true) {
			currentcat = $('#prodiv'+position).data('rowid');
		} else {
			console.log('#catdiv'+position);
			currentcat = $('#catdiv'+position).data('rowid');
			console.log("currentcat="+currentcat);
			// Highlight the selected category tab
			jQuery(".div4 .wrapper").removeClass("activetab");
			jQuery("#catdiv"+position).addClass("activetab");
		}
	}
	if (currentcat == undefined) {
		return;
	}
	pageproducts=0;
	ishow=0; //product to show counter

	currentsubcategories = [];
	if (currentcat != "supplements") {
		console.log("Loop on each category level 2 or more");
		jQuery.each(subcategories, function(i, val) {
			if (currentcat == val.fk_parent) {
				currentsubcategories.push(val);
			}
		});
	}

	// Get socid
	let socid = jQuery('#thirdpartyid').val();
	if ((socid === undefined || socid === "") && parseInt("<?php echo dol_escape_js($socid) ?>") > 0) {
		socid = parseInt("<?php echo dol_escape_js($socid); ?>");
	}

	// Only show products for sale (tosell=1). The whole category is loaded at once
	// (limit=0): the fluid grid scrolls instead of paging through a fixed cell pool.
	$.getJSON('<?php echo dol_buildpath('/fluxpos/ajax/ajax.php', 1) ?>?action=getProducts&token=<?php echo currentToken();?>&thirdpartyid=' + socid + '&category='+currentcat+'&tosell=1&limit=0&offset=0', function(data) {
		console.log("Call ajax.php (in LoadProducts) to get Products of category "+currentcat+" then render the fluid grid");
		console.log("Found "+data.length+" record");

		if (data.length == 0 && currentcat == "supplements") {
			alert("No supplements found into the supplement category. Check the module setup and your product categories.");
		}

		RenderProducts(data);
	}).fail(function(jqxhr, textStatus) {
		// Offline fallback: fill the grid from the catalog cached into IndexedDB.
		// handleFailure() also switches to offline mode when the server is unreachable
		// (Wi-Fi still up), so the next actions of the terminal use the local data.
		if (typeof FluxPosOffline == 'undefined' || !FluxPosOffline.handleFailure(jqxhr, textStatus)) {
			return;
		}
		FluxPosOffline.getCategoryProducts(currentcat, socid).then(function(data) {
			if (data !== null) {
				RenderProducts(data);
			}
		});
	});

	ClearSearch(false);
}

function MoreProducts(moreorless) {
	// Legacy page pager of the fixed tile pool: the fluid grid scrolls instead.
	// Kept as a compatibility shim (the category is simply rendered again).
	console.log("MoreProducts (legacy shim, no pagination in the fluid grid)");

	if ($('#search_pagination').val() != '') {
		return Search2('<?php echo(isset($keyCodeForEnter) ? $keyCodeForEnter : ''); ?>', moreorless);
	}

	ClearSearch(false);
}

function ClickProduct(position, qty = 1, qty_std = 0) {
	console.log("ClickProduct at position"+position);
	if ($('#invoiceid').val() == "") {
		invoiceid = $('#invoiceid').val();
		Refresh();
	}
	$('#proimg'+position).animate({opacity: '0.5'}, 1);
	$('#proimg'+position).animate({opacity: '1'}, 100);
	if ($('#prodiv'+position).data('iscat') == 1){
		console.log("Click on a category at position "+position);
		LoadProducts(position, true);
	}
	else{
		invoiceid = $("#invoiceid").val();
		idproduct=$('#prodiv'+position).data('rowid');
		console.log("Click on product at position "+position+" for idproduct "+idproduct+", qty="+qty+" invoiceid="+invoiceid);
		if (idproduct == "") {
			return;
		}

		// Offline order entry: queue the product into the local cart of the place (it
		// will be synchronized to the server in background). An order not yet created on
		// the server becomes a local order; an order already registered on the server is
		// adopted into the local cart, so the new products are merged into it when the
		// connection is back.
		// The service worker of a terminal can still serve the offline.js of the previous
		// deployment for a moment, so every function of the engine is checked before use.
		if (typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.isOffline == 'function' && FluxPosOffline.isOffline()) {
			if (($('#invoiceid').val() || '') == '') {
				if (typeof FluxPosOffline.addProduct == 'function') {
					FluxPosOffline.addProduct(idproduct, qty);
				} else {
					console.log('FluxPos offline: this screen runs an older version of the offline engine, reload the POS screen to work offline');
				}
			} else if (typeof FluxPosOffline.captureOnServerOrder == 'function') {
				// The order of the place is on the server: adopt it into the local cart so
				// the tap is captured and merged into that order later
				FluxPosOffline.captureOnServerOrder(idproduct, qty);
			} else if (typeof FluxPosOffline.notifyBlockedCapture == 'function') {
				FluxPosOffline.notifyBlockedCapture();
			}
			ClearSearch(false);
			return;
		}

		addInvoiceLine = function(qty, qty_std_inner = 0) {
			// Call page invoice.php to generate the section with product lines
			var url = "invoice.php?action=addline&token=<?php echo currentToken(); ?>&place="+place+"&idproduct="+idproduct+"&selectedline="+selectedline+"&qty="+qty+"&invoiceid="+invoiceid+(qty_std_inner ? '&qty_std=1' : '');
			var idproducttotap = idproduct;		// The callback resets idproduct before it can fall back
			$("#poslines").load(url, function(response, status, xhr) {
				idproduct = "";
				<?php if (getDolGlobalString('FLUXPOS_CUSTOMER_DISPLAY')) {
					echo "CustomerDisplay();";
				}?>
				if (status != 'error') {
					fluxposUpdateCartTotal();	// Keep the sticky total of the cart footer in sync
				}
				if (status == 'error') {
					// The server could not be reached (or refused the call): never lose the
					// tap silently. On a network failure the terminal works offline, so the
					// product goes into the local cart of the place like a direct tap would.
					console.log("ClickProduct addline failed http="+((xhr||{}).status||0)+" status="+status);
					if (typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.handleFailure == 'function' && FluxPosOffline.handleFailure(xhr, status)) {
						if (($('#invoiceid').val() || '') == '') {
							if (typeof FluxPosOffline.addProduct == 'function') {
								FluxPosOffline.addProduct(idproducttotap, qty);
							}
						} else if (typeof FluxPosOffline.captureOnServerOrder == 'function') {
							// The order of the place is on the server: adopt it into the local
							// cart so the tap is captured and merged into that order later
							FluxPosOffline.captureOnServerOrder(idproducttotap, qty);
						} else if (typeof FluxPosOffline.notifyBlockedCapture == 'function') {
							FluxPosOffline.notifyBlockedCapture();
						}
					}
				} else if (typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.cacheServerOrder == 'function') {
					// Keep a snapshot of the order on the server while online (baseline
					// taken by the engine when this place must be captured while offline)
					FluxPosOffline.cacheServerOrder();
				}
			});
		};

		// call WeighingScale() if product is a product that need to measure weight
		<?php if (getDolGlobalString('FLUXPOS_WEIGHING_SCALE')) { ?>
			if ($('#prodiv'+position).data('unit') == 2) {
				WeighingScale(addInvoiceLine);
			} else {
				addInvoiceLine(qty, qty_std);
			}
		<?php } else { ?>
			addInvoiceLine(qty, qty_std);
		<?php } ?>
	}

	ClearSearch(false);
}

function ChangeThirdparty(idcustomer) {
	 console.log("ChangeThirdparty");
		// Call page list.php to change customer
		$("#poslines").load("<?php echo DOL_URL_ROOT ?>/societe/list.php?action=change&token=<?php echo currentToken();?>&type=t&contextpage=poslist&idcustomer="+idcustomer+"&place="+place+"", function() {
			// list.php replaces the cart fragment with a customer list: old total must not linger in the cart footer
			fluxposUpdateCartTotal('');
		});

	ClearSearch(false);
}

function deleteline() {
	invoiceid = $("#invoiceid").val();
	console.log("Delete line invoiceid="+invoiceid);
	$("#poslines").load("invoice.php?action=deleteline&token=<?php echo currentToken(); ?>&place="+place+"&idline="+selectedline+"&invoiceid="+invoiceid, function() {
		//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
		FluxPosCloseLineEdit();	// The contextual numberpad closes once its change was applied
		fluxposUpdateCartTotal();
	});
	ClearSearch(false);
}

/*
 * Line-edit popup (contextual numberpad of the 1.1 layout).
 * Opens over the cart when a line of a server order is tapped, so the numberpad is
 * never permanent on the screen (FLUXPOS_DISPLAY_NUMBERPAD = 'lineedit', default).
 */
function FluxPosOpenLineEdit() {
	if (fluxposdisplaynumberpad == 'never') {
		return;
	}
	if (typeof(selectedline) == "undefined" || !selectedline) {
		return;	// No server line tapped (an offline cart line has its own +/- steppers)
	}
	ModalBox('ModalLineEdit');
}

function FluxPosCloseLineEdit() {
	var modal = document.getElementById('ModalLineEdit');
	if (modal) {
		modal.style.display = 'none';
	}
}

/*
 * Quick quantity stepper of the cart lines (the - qty + control, also used by the
 * offline cart): one tap = one unit, the numberpad is only needed for free amounts.
 */
function FluxPosQtyStep(btn) {
	invoiceid = $("#invoiceid").val();
	idline = $(btn).closest('tr').attr('id');
	qty = $(btn).data('qty');
	console.log("FluxPosQtyStep line="+idline+" qty="+qty);
	if (qty == null || invoiceid == '') {
		return;
	}
	$("#poslines").load("invoice.php?action=updateqty&token=<?php echo currentToken(); ?>&place="+place+"&idline="+idline+"&number="+qty, function() {
		fluxposUpdateCartTotal();
	});
}

/*
 * Sticky total of the cart footer. Called with an explicit amount by the offline
 * renderer, or computes it from the invoice fragment header of invoice.php when the
 * cart is on the server.
 */
function fluxposUpdateCartTotal(amountText) {
	var el = document.getElementById('carttotalvalue');
	if (!el) {
		return;
	}
	if (typeof amountText == 'undefined' || amountText === null) {
		var spans = jQuery('#poslines span[id="linecolht-span-total"]');
		amountText = spans.length ? spans.last().text() : '';
	}
	el.textContent = amountText;
	jQuery('.cartfooter').toggleClass('emptycart', !amountText);
}

function Customer() {
	console.log("Open box to select the thirdparty place="+place);
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/societe/list.php?type=t&contextpage=poslist&nomassaction=1&place="+place, width:"90%", height:"80%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("Customer"); ?>"});
}

function Contact() {
	console.log("Open box to select the contact place="+place);
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/contact/list.php?type=c&contextpage=poslist&nomassaction=1&place="+place, width:"90%", height:"80%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("Contact"); ?>"});
}

function History()
{
	console.log("Open box to select the history");
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/compta/facture/list.php?contextpage=poslist&search_module_source=takepos%7Cfluxpos", width:"90%", height:"80%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("History"); ?>"});
}

function Reduction() {
	invoiceid = $("#invoiceid").val();
	console.log("Open popup to enter reduction on invoiceid="+invoiceid);
	$.colorbox({href:"reduction.php?place="+place+"&invoiceid="+invoiceid, width:"80%", height:"90%", transition:"none", iframe:"true", title:""});
}

var closeBillParams="";
function CloseBill(paycode) {
	<?php
	$parameters = array();
	$reshook = $hookmanager->executeHooks('paramsForCloseBill', $parameters, $obj, $action);
	if (getDolGlobalString('FLUXPOS_FORBID_SALES_TO_DEFAULT_CUSTOMER')) {
		echo "customerAnchorTag = document.querySelector('a[id=\"customer\"]'); ";
		echo "if (customerAnchorTag && customerAnchorTag.innerText.trim() === '".dol_escape_js($langs->transnoentitiesnoconv("Customer"))."') { ";
		echo "alert('".dol_escape_js($langs->transnoentitiesnoconv("NoClientErrorMessage"))."'); ";
		echo "return; } \n";
	}
	?>
	invoiceid = $("#invoiceid").val();

	if (typeof FluxPosOffline != 'undefined' && FluxPosOffline.isOffline()) {
		if ($('#offlinecart').val() == '1' && ($('#invoiceid').val() || '') == '') {
			// Offline: pay and validate the sale captured on this terminal
			OpenOfflinePay();
		} else {
			window.alert('Payment of an order already registered on the server requires the connection.');
		}
		return;
	}

	console.log("Open popup to enter payment on invoiceid="+invoiceid);
	<?php if (getDolGlobalInt("FLUXPOS_NO_GENERIC_THIRDPARTY")) { ?>
		if ($("#idcustomer").val() == "") {
			alert("<?php echo dol_escape_js($langs->transnoentitiesnoconv('FluxPosCustomerMandatory'), 2); ?>");
			<?php if (getDolGlobalString('FLUXPOS_CHOOSE_CONTACT')) { ?>
				Contact();
			<?php } else { ?>
				Customer();
			<?php } ?>
			return;
		}
	<?php }	?>
	<?php
	$alternative_payurl = getDolGlobalString('FLUXPOS_ALTERNATIVE_PAYMENT_SCREEN');
	if (empty($alternative_payurl)) {
		$payurl = "pay.php";
	} else {
		$payurl = dol_buildpath($alternative_payurl, 1);
	}
	?>
	// paycode (optional): preselect the payment mode on the payment screen (the payment
	// block of the cart footer opens the same screen of pay.php with its pad, cash fence
	// and bank-account overrides untouched)
	var paycodeparam = (typeof paycode != 'undefined' && paycode) ? '&paycode='+encodeURIComponent(paycode) : '';
	$.colorbox({href:"<?php echo $payurl; ?>?place="+place+"&invoiceid="+invoiceid+closeBillParams+paycodeparam, width:"80%", height:"90%", transition:"none", iframe:"true", title:""});
}

function Split() {
	invoiceid = $("#invoiceid").val();
	console.log("Open popup to split on invoiceid="+invoiceid);
	$.colorbox({href:"split.php?place="+place+"&invoiceid="+invoiceid, width:"80%", height:"90%", transition:"none", iframe:"true", title:""});
}

function Floors() {
	console.log("Open box to select floor place="+place);
	$.colorbox({href:"floors.php?place="+place, width:"90%", height:"90%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("Floors"); ?>"});
}

function FreeZone() {
	invoiceid = $("#invoiceid").val();
	console.log("Open box to enter a free product on invoiceid="+invoiceid);
	$.colorbox({href:"freezone.php?action=freezone&token=<?php echo newToken(); ?>&place="+place+"&invoiceid="+invoiceid, width:"80%", height:"40%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("FreeZone"); ?>"});
}

function FluxPosOrderNotes() {
	console.log("Open box to order notes");
	ModalBox('ModalNote');
	$("#textinput").focus();
}

function Refresh() {
	if (typeof FluxPosOffline != 'undefined' && FluxPosOffline.isOffline() && ($('#invoiceid').length == 0 || ($('#invoiceid').val() || '') == '')) {
		// Offline: show the local cart of the place instead of asking the server.
		// An order already registered on the server cannot be displayed until the
		// connection is back.
		console.log("Refresh offline with the local cart of place="+place);
		FluxPosOffline.renderCart();
		return;
	}
	console.log("Refresh by reloading place="+place+" invoiceid="+invoiceid);
	$("#poslines").load("invoice.php?place="+place+"&invoiceid="+invoiceid, function(response, status, xhr) {
		if (status == 'error') {
			// The server could not be reached: switch the display to the data of the
			// terminal instead of leaving the lines of the order as they are.
			console.log("Refresh failed http="+((xhr||{}).status||0)+" status="+status);
			if (typeof FluxPosOffline != 'undefined') {
				if (typeof FluxPosOffline.handleFailure == 'function') {
					FluxPosOffline.handleFailure(xhr, status);
				}
				if (typeof FluxPosOffline.renderCart == 'function') {
					FluxPosOffline.renderCart();
				}
			}
		} else if (typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.cacheServerOrder == 'function') {
			// Keep a snapshot of the order on the server while online (baseline taken
			// by the engine when this place must be captured while offline)
			FluxPosOffline.cacheServerOrder();
		}
		if (status != 'error') {
			fluxposUpdateCartTotal();	// Keep the sticky total of the cart footer in sync
		}
		//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
	});
}

function New() {
	// If we go here,it means $conf->global->FLUXPOS_BAR_RESTAURANT is not defined
	invoiceid = $("#invoiceid").val();		// This is a hidden field added by invoice.php

	if (typeof FluxPosOffline != 'undefined' && FluxPosOffline.isOffline()) {
		if ($('#offlinecart').val() == '1' && ($('#invoiceid').val() || '') == '') {
			// Discard the local cart of the place (nothing was sent to the server yet)
			FluxPosOffline.clearCart();
		} else if (confirm('This place has an order registered on the server, which cannot be modified while the terminal is offline. Start a new separate order on this terminal? It can only be paid whole while it is offline.')) {
			// The order of the place stays apart on the server and is shown again when the
			// connection is back. The new order is stored on the terminal and can be paid whole.
			FluxPosOffline.startSeparateOrder();
		}
		ClearSearch(false);
		return;
	}

	console.log("New with place = <?php echo $place; ?>, js place="+place+", invoiceid="+invoiceid);

	// A cart that the terminal stored while offline must not come back once the cart
	// of the server is removed: drop the local copy of the place too (the fragment that
	// replaces #poslines stops the engine from rendering it again).
	if ($('#offlinecart').val() == '1' && typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.clearCart == 'function') {
		FluxPosOffline.clearCart();
	}

	if (invoiceid == '') {
		$("#poslines").load("invoice.php?action=delete&token=<?php echo currentToken(); ?>&place=" + place, function () {
			//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
			fluxposUpdateCartTotal('');
		});

		ClearSearch(false);
		$("#idcustomer").val("");
		return;
	}

	$.getJSON('<?php echo dol_buildpath('/fluxpos/ajax/ajax.php', 1) ?>?action=getInvoice&token=<?php echo currentToken();?>&id='+invoiceid, function(data) {
		var r;

		if (parseInt(data['paye']) === 1) {
			r = true;
		} else {
			r = confirm('<?php echo($place > 0 ? $langs->transnoentitiesnoconv("ConfirmDeletionOfThisPOSSale") : $langs->transnoentitiesnoconv("ConfirmDiscardOfThisPOSSale")); ?>');
		}

		if (r == true) {
			// Reload section with invoice lines
			$("#poslines").load("invoice.php?action=delete&token=<?php echo currentToken(); ?>&place=" + place, function () {
				//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
				fluxposUpdateCartTotal('');
				// The order of the place is gone on the server: drop the snapshot cached
				// for an offline capture of it
				if (typeof FluxPosOffline != 'undefined' && typeof FluxPosOffline.cacheServerOrder == 'function') {
					FluxPosOffline.cacheServerOrder();
				}
			});

			ClearSearch(false);
			$("#idcustomer").val("");
		}
	});
}
/**
 * Search products: typing filters the tiles of the fluid grid in place. The
 * individual-barcode behaviors of the original implementation are kept (single exact
 * thirdparty or product bar code acts at once). The pager of the old fixed grid is
 * gone: every match is rendered, emptying the search field or pressing Escape brings
 * the current category back.
 *
 * @param   keyCodeForEnter     Key code for "enter" or '' if not
 * @param   moreorless          Deprecated (old pager), ignored
 * return   void
 */
function Search2(keyCodeForEnter, moreorless) {
	var eventKeyCode = null;

	if (window.event && window.event.keyCode) {
		eventKeyCode = window.event.keyCode;
	}
	if (eventKeyCode === null && keyCodeForEnter !== '') {
		// No key code available, exit early if a key code is required
		return;
	}

	var search_term = $('#search').val();
	var search_limit = 500;	// The fluid grid scrolls: search without pagination

	console.log("Search2 keyCodeForEnter="+keyCodeForEnter+", eventKeyCode="+eventKeyCode+", search_term="+search_term);

	if (search_term == '') {
		// Empty search: go back to the current category ("Escape" behavior)
		RenderProducts(fluxposlastitems || [], typeof currentsubcategories != 'undefined');
		return;
	}

	var search = false;
	if (keyCodeForEnter == '' || eventKeyCode == keyCodeForEnter) {
		search = true;
	}

	if (search === true) {
		// if a timer has been already started (search2_timer is a global js variable), we cancel it now
		// we click onto another key, we will restart another timer just after
		if (search2_timer) {
			clearTimeout(search2_timer);
		}

		// temporization time to give time to type
		search2_timer = setTimeout(function(){
			pageproducts = 0;

			// Only show products for sale (tosell=1)
			let socid = jQuery('#thirdpartyid').val();
			if ((socid === undefined || socid === "") && parseInt("<?php echo dol_escape_js($socid) ?>") > 0) {
				socid = parseInt("<?php echo dol_escape_js($socid); ?>");
			}

			$.getJSON('<?php echo dol_buildpath('/fluxpos/ajax/ajax.php', 1) ?>?action=search&token=<?php echo currentToken();?>&search_term=' + search_term + '&thirdpartyid=' + socid + '&search_start=0&search_limit=' + search_limit, function (data) {
				RenderSearchResults(data);
			}).fail(function (jqxhr, textStatus) {
				// Offline fallback: search into the catalog cached into IndexedDB
				if (typeof FluxPosOffline == 'undefined' || !FluxPosOffline.handleFailure(jqxhr, textStatus)) {
					return;
				}
				FluxPosOffline.searchProducts(search_term, socid, search_limit).then(function (cacheddata) {
					RenderSearchResults(cacheddata);
					searchAlways(cacheddata);
				});
			}).always(function (data) {
				if (typeof (data) == "undefined" || typeof (data.length) != "number") {
					return; // Failed request, handled by the offline fallback above
				}
				searchAlways(data);
			});

			// Post processing of a search: single answer actions and error message
			var searchAlways = function (data) {
				// If there is only 1 answer
				if ($('#search').val().length > 0 && data.length == 1) {
					console.log($('#search').val()+' - '+data[0]['barcode']);
					if ($('#search').val() == data[0]['barcode'] && 'thirdparty' == data[0]['object']) {
						console.log("There is only 1 answer with barcode matching the search, so we change the thirdparty "+data[0]['rowid']);
						ChangeThirdparty(data[0]['rowid']);
					}
					else if ('product' == data[0]['object'] && $('#search').val() == data[0]['barcode']) {
						console.log("There is only 1 answer and we found search on a barcode, so we add the product in basket, qty="+data[0]['qty']);
						ClickProduct(0, data[0]['qty'], 1);
					}
				}
				if (eventKeyCode == keyCodeForEnter){
					if (data.length == 0) {
						$('#search').val('<?php
						$langs->load('errors');
						echo dol_escape_js($langs->transnoentitiesnoconv("ErrorRecordNotFoundShort"));
						?> ('+search_term+')');
						$('#search').select();
					}
					else ClearSearch(false);
				}
			};
		}, 500); // 500ms delay
	}

}

// Render the results of a search into the fluid product grid and return the number of
// results shown. Thirdparty rows are excluded (they are acted on by the auto-change of
// searchAlways when unique).
function RenderSearchResults(data) {
	console.log("RenderSearchResults");
	var products = [];
	var i;
	for (i = 0; i < data.length; i++) {
		if (typeof (data[i]) == "undefined") {
			continue;
		}
		if (data[i]['object'] == 'thirdparty') {
			continue;
		}
		products.push(data[i]);
	}
	RenderProducts(products, false);	// No subcategory tiles while searching
	return products.length;
}

function Edit(number) {
	console.log("We click on PAD on key="+number);

	if (typeof(selectedtext) == "undefined") {
		return;	// We click on an action on the number pad but there is no line selected
	}

	var text=selectedtext+"<br> ";


	if (number=='c') {
		editnumber='';
		Refresh();
		$("#qty").html("<?php echo $langs->trans("Qty"); ?>").removeClass('clicked');
		$("#price").html("<?php echo $langs->trans("Price"); ?>").removeClass('clicked');
		$("#reduction").html("<?php echo $langs->trans("LineDiscountShort"); ?>").removeClass('clicked');
		FluxPosCloseLineEdit();	// C also closes the contextual numberpad
		return;
	} else if (number=='qty') {
		if (editaction=='qty' && editnumber != '') {
			$("#poslines").load("invoice.php?action=updateqty&token=<?php echo currentToken(); ?>&place="+place+"&idline="+selectedline+"&number="+editnumber, function() {
				editnumber="";
				//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
				$("#qty").html("<?php echo $langs->trans("Qty"); ?>").removeClass('clicked');
				FluxPosCloseLineEdit();	// The contextual numberpad closes once its change was applied
				fluxposUpdateCartTotal();
			});

			setFocusOnSearchField();
			return;
		}
		else {
			editaction="qty";
		}
	} else if (number=='p') {
		if (editaction=='p' && editnumber!="") {
			$("#poslines").load("invoice.php?action=updateprice&token=<?php echo currentToken(); ?>&place="+place+"&idline="+selectedline+"&number="+editnumber, function() {
				editnumber="";
				//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
				$("#price").html("<?php echo $langs->trans("Price"); ?>").removeClass('clicked');
				FluxPosCloseLineEdit();	// The contextual numberpad closes once its change was applied
				fluxposUpdateCartTotal();
			});

			ClearSearch(false);
			return;
		}
		else {
			editaction="p";
		}
	} else if (number=='r') {
		if (editaction=='r' && editnumber!="") {
			$("#poslines").load("invoice.php?action=updatereduction&token=<?php echo currentToken(); ?>&place="+place+"&idline="+selectedline+"&number="+editnumber, function() {
				editnumber="";
				//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
				$("#reduction").html("<?php echo $langs->trans("LineDiscountShort"); ?>").removeClass('clicked');
				FluxPosCloseLineEdit();	// The contextual numberpad closes once its change was applied
				fluxposUpdateCartTotal();
			});

			ClearSearch(false);
			return;
		}
		else {
			editaction="r";
		}
	}
	else {
		editnumber=editnumber+number;
	}
	if (editaction=='qty'){
		text=text+"<?php echo $langs->trans("Modify")." -> ".$langs->trans("Qty").": "; ?>";
		$("#qty").html("OK").addClass("clicked");
		$("#price").html("<?php echo $langs->trans("Price"); ?>").removeClass('clicked');
		$("#reduction").html("<?php echo $langs->trans("LineDiscountShort"); ?>").removeClass('clicked');
	}
	if (editaction=='p'){
		text=text+"<?php echo $langs->trans("Modify")." -> ".$langs->trans("Price").": "; ?>";
		$("#qty").html("<?php echo $langs->trans("Qty"); ?>").removeClass('clicked');
		$("#price").html("OK").addClass("clicked");
		$("#reduction").html("<?php echo $langs->trans("LineDiscountShort"); ?>").removeClass('clicked');
	}
	if (editaction=='r'){
		text=text+"<?php echo $langs->trans("Modify")." -> ".$langs->trans("LineDiscountShort").": "; ?>";
		$("#qty").html("<?php echo $langs->trans("Qty"); ?>").removeClass('clicked');
		$("#price").html("<?php echo $langs->trans("Price"); ?>").removeClass('clicked');
		$("#reduction").html("OK").addClass("clicked");
	}
	$('#'+selectedline).find("td:first").html(text+editnumber);
}


function FluxPosPrintingOrder(){
	console.log("FluxPosPrintingOrder output invoice to print order");
	$("#poslines").load("invoice.php?action=order&token=<?php echo currentToken();?>&place="+place, function() {
		//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
	});
}

function FluxPosPrintingTemp(){
	console.log("FluxPosPrintingTemp");
	$("#poslines").load("invoice.php?action=temp&token=<?php echo currentToken();?>&place="+place, function() {
		//$('#poslines').scrollTop($('#poslines')[0].scrollHeight);
	});
}

function OpenDrawer(){
	console.log("OpenDrawer call ajax url http://<?php print getDolGlobalString('FLUXPOS_PRINT_SERVER'); ?>:8111/print");
	$.ajax({
		type: "POST",
		data: { token: 'notrequired' },
		<?php
		if (getDolGlobalString('FLUXPOS_PRINT_SERVER') && filter_var($conf->global->FLUXPOS_PRINT_SERVER, FILTER_VALIDATE_URL) == true) {
			echo "url: '".getDolGlobalString('FLUXPOS_PRINT_SERVER', 'localhost')."/printer/drawer.php',";
		} else {
			echo "url: 'http://".getDolGlobalString('FLUXPOS_PRINT_SERVER', 'localhost').":8111/print',";
		}
		?>
		data: "opendrawer"
	});
}

/* Click on button to open drawer */
function DolibarrOpenDrawer() {
	console.log("DolibarrOpenDrawer call ajax url /fluxpos/ajax/ajax.php?action=opendrawer&token=<?php echo currentToken();?>&term=<?php print urlencode(empty($_SESSION["fluxposterminal"]) ? '' : $_SESSION["fluxposterminal"]); ?>");
	$.ajax({
		type: "GET",
		data: { token: '<?php echo currentToken(); ?>' },
		url: "<?php print dol_buildpath('/fluxpos/ajax/ajax.php', 1).'?action=opendrawer&token='.currentToken().'&term='.urlencode(empty($_SESSION["fluxposterminal"]) ? '' : $_SESSION["fluxposterminal"]); ?>",
	});
}

function MoreActions(totalactions) {
	for (i = 0; i <= totalactions; i++) {
		$("#action" + i).toggle();
	}
	$("#actionnext").toggle();
	$("#actionprevious").toggle();
}

function ControlCashOpening()
{
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/compta/cashcontrol/cashcontrol_card.php?action=create&contextpage=fluxpos", width:"90%", height:"60%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("NewCashFence"); ?>"});
}

function CloseCashFence(rowid)
{
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/compta/cashcontrol/cashcontrol_card.php?id="+rowid+"&contextpage=fluxpos", width:"90%", height:"90%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("NewCashFence"); ?>"});
}

function CashReport(rowid)
{
	$.colorbox({href:"<?php echo DOL_URL_ROOT ?>/compta/cashcontrol/report.php?id="+rowid+"&contextpage=fluxpos", width:"60%", height:"90%", transition:"none", iframe:"true", title:"<?php echo $langs->trans("CashReport"); ?>"});
}

/*
 * FluxPos Popup
 * Open the <div id='ModalID'>in a popup. Example with ModalID='ModalCreditNote'
 */
function ModalBox(ModalID)
{
	var modal = document.getElementById(ModalID);
	modal.style.display = "block";
}

function DirectPayment(){
	if (typeof FluxPosOffline != 'undefined' && FluxPosOffline.isOffline()) {
		if ($('#offlinecart').val() == '1' && ($('#invoiceid').val() || '') == '') {
			// Offline: pay the full sale in cash, the payment and the invoice number
			// will be registered at the synchronization (same as amount=0 online)
			FluxPosOffline.validateCart([{ pay: 'LIQ', amount: 0, excess: 0 }]);
		} else {
			window.alert('Payment of an order already registered on the server requires the connection.');
		}
		return;
	}
	console.log("DirectPayment");
	$("#poslines").load("invoice.php?place="+place+"&action=valid&token=<?php echo currentToken(); ?>&pay=LIQ", function() {
		$('#invoiceid').val("");
		fluxposUpdateCartTotal('');
	});
}

function OpenOfflinePay() {
	console.log("Open the offline payment dialog for place="+place);
	if (typeof FluxPosOffline == "undefined") {
		return;
	}
	FluxPosOffline.getCart().then(function (cart) {
		var total = 0;
		if (cart && cart.lines) {
			for (var i = 0; i < cart.lines.length; i++) {
				total += cart.lines[i].price_ttc * cart.lines[i].qty;
			}
		}
		jQuery("#offlinepaytotal").text(FluxPosOffline.t('TotalTTC', 'Total TTC') + " : " + FluxPosOffline.formatAmount(total));
		jQuery("#offlinepayreturned").text("");
		jQuery("#offlinepayamountLIQ, #offlinepayamountCB, #offlinepayamountCHQ").val("");
	});
	ModalBox('ModalOfflinePay');
	jQuery("#offlinepayamountLIQ").focus();
}

function ValidateOfflinePay() {
	var payamounts = [
		{ pay: 'LIQ', amount: parseFloat(jQuery("#offlinepayamountLIQ").val()) || 0 },
		{ pay: 'CB', amount: parseFloat(jQuery("#offlinepayamountCB").val()) || 0 },
		{ pay: 'CHQ', amount: parseFloat(jQuery("#offlinepayamountCHQ").val()) || 0 }
	];
	var payments = [];
	var i;
	for (i = 0; i < payamounts.length; i++) {
		if (payamounts[i].amount > 0) {
			payments.push(payamounts[i]);
		}
	}
	if (!payments.length) {
		alert('Enter the amount received for at least one payment mode');
		return;
	}
	// Change given on a cash payment (money received instead of the exact total), same rule as pay.php
	FluxPosOffline.getCart().then(function (cart) {
		var total = 0;
		if (cart && cart.lines) {
			for (i = 0; i < cart.lines.length; i++) {
				total += cart.lines[i].price_ttc * cart.lines[i].qty;
			}
		}
		if (payments[0].pay == 'LIQ' && payments[0].amount > total) {
			payments[0].excess = Math.round((payments[0].amount - total) * 100) / 100;
		}
		document.getElementById('ModalOfflinePay').style.display = 'none';
		FluxPosOffline.validateCart(payments);
	});
}

function FullScreen() {
	document.documentElement.requestFullscreen();
}

function WeighingScale(callback) {
	console.log("Weighing Scale: invoiceid = " + placeid + ", lineid = " + selectedline);
	<?php if (getDolGlobalString('FLUXPOS_CONNECTOR_TO_WHB_SCALE')) {?>
		<?php if (getDolGlobalString('WEIGHINGSCALE_PROTOCOL') == "diag06") { ?>
			// Protocole Dialog-06, il a besoin du prix unitaire, le demander s'il manque
			var urlProduct;
			if (idproduct == "" && selectedline > 0) {
				urlProduct = "<?php echo DOL_URL_ROOT; ?>/api/index.php/takeposconnector/invoices/" + placeid + "/lines/" + selectedline + "/product";
			} else {
				urlProduct = "<?php echo DOL_URL_ROOT; ?>/api/index.php/products/" + idproduct;
			}
			$.ajax({
				type: "GET",
				headers: { "DOLAPIKEY": '<?php echo $user->api_key; ?>' },
				url: urlProduct,
			})
			.done(function(product) {
				if (callback === undefined) {
					callback = function(qty) {
						$("#poslines").load("invoice.php?token=<?php echo currentToken(); ?>&action=updateqty&place="+place+"&idline="+selectedline+"&number="+qty);
					};
				}
				if (product.fk_unit == "2") {
					askForWeight(product.multiprices_ttc[1], callback, function (errorMessage) {
						console.log("Erreur: " + errorMessage);
					});
				}
			});
		<?php } else { ?>
			// Protocole par défaut de takeposconnector: réception continue du poids/stabilité
			editnumber = globalWeight;
			$("#poslines").load("invoice.php?token=<?php echo currentToken(); ?>&action=updateqty&place="+place+"&idline="+selectedline+"&number="+editnumber, function() {
				editnumber="";
				fluxposUpdateCartTotal();
			});
		<?php } ?>
	<?php } else { ?> // utilisation du new FluxPos-Connector PHP: envoie "$" à la balance pour avoir le poids
	$.ajax({
		type: "POST",
		data: { token: 'notrequired' },
		url: '<?php print getDolGlobalString('FLUXPOS_PRINT_SERVER'); ?>/scale/index.php',
	})
	.done(function( editnumber ) {
		$("#poslines").load("invoice.php?token=<?php echo currentToken(); ?>&action=updateqty&place="+place+"&idline="+selectedline+"&number="+editnumber, function() {
				editnumber="";
				fluxposUpdateCartTotal();
			});
	});
	<?php } ?>
}

$( document ).ready(function() {

	/*
	 * 1.1 layout wiring
	 */
	// Tapping a line of a cart order that lives on the server opens the contextual
	// line-edit popup (the offline cart lines keep their own +/- steppers)
	jQuery(document).on('click', 'tr.posinvoiceline', function() {
		if (this.id && ($('#invoiceid').val() || '') != '') {
			FluxPosOpenLineEdit();
		}
	});

	// Escape in the search field brings the current category back into the grid
	jQuery('#search').keydown(function(ev) {
		if (ev.key == 'Escape' || ev.keyCode == 27) {
			jQuery('#search').val('');
			RenderProducts(fluxposlastitems || [], typeof currentsubcategories != 'undefined');
		}
	});

	// Tile density control (S/M/L), remembered per terminal in localStorage
	var fluxposTileTerm = '<?php echo (int) $term; ?>';
	(function() {
		var pref = null;
		try { pref = window.localStorage.getItem('fluxposTileSize' + fluxposTileTerm); } catch (e) {}
		if (pref && ['S', 'M', 'L'].indexOf(pref) >= 0) {
			jQuery('body').removeClass('fluxpos-tile-S fluxpos-tile-M fluxpos-tile-L').addClass('fluxpos-tile-' + pref);
		}
	})();
	jQuery('#tiledensity button').each(function() {
		var size = jQuery(this).data('size');
		jQuery(this).attr('title', fluxposlang.FluxPosTileSize + ' ' + size);
	}).on('click', function() {
		var size = jQuery(this).data('size');
		if (['S', 'M', 'L'].indexOf(size) < 0) {
			return;
		}
		jQuery('body').removeClass('fluxpos-tile-S fluxpos-tile-M fluxpos-tile-L').addClass('fluxpos-tile-' + size);
		try { window.localStorage.setItem('fluxposTileSize' + fluxposTileTerm, String(size)); } catch (e) {}
		jQuery('#tiledensity button').removeClass('clicked');
		jQuery(this).addClass('clicked');
	});

	// Category tab strip: update the scroll arrows when needed (window resize, strip
	// scrolled, tiles images loaded, fonts ready) and once on load
	jQuery('#tabbar').on('scroll', FluxPosUpdateTabArrows);
	jQuery(window).on('resize', FluxPosUpdateTabArrows);
	FluxPosUpdateTabArrows();
	// Layout guard (1.1.8): if the cart is not a right column although the screen is
	// wide enough, the terminal is running an old stylesheet. Report it visibly in the
	// page (with the facts needed to know who is wrong: the viewport and the orientation
	// actually applied), instead of a two-column layout that fails silently.
	window.addEventListener('load', function () {
		var main = document.querySelector('.posmain');
		if (!main) {
			return;
		}
		var mainstyle = window.getComputedStyle(main);
		var direction = (mainstyle.display == 'flex') ? (mainstyle.flexDirection || 'row') : 'none';
		if (direction == 'none') {
			var csslink = document.querySelector('link[href*="pos11.css.php"]');
			console.error('FluxPos layout guard: .posmain is not a flex container on a screen of ' + window.innerWidth + 'px. The stylesheet ' + (detectCssLinkHref(csslink)) + ' did not apply on this terminal.');
			FluxPosShowLayoutWarning('FluxPos: the stylesheet did not apply on this terminal (no flex layout at all). Reload the page with Ctrl+Shift+R (or empty the caches) so the current pos11.css.php can be served.');
		} else if (direction == 'column') {
			if (window.innerWidth > 767) {
				FluxPosShowLayoutWarning('FluxPos: a stacked (mobile) layout was served on a screen of ' + window.innerWidth + 'px. The stylesheet of this terminal predates version 1.1: reload the page with Ctrl+Shift+R (or empty the caches) so the current pos11.css.php can be served.');
			} else {
				console.log('FluxPos layout guard: viewport is only ' + window.innerWidth + 'px wide (browser zoom or small window?): the designed stacked layout for narrow screens applies. Widen the window or reduce the zoom for the two-column layout.');
				FluxPosShowLayoutWarning('FluxPos: the viewport of this terminal is only ' + window.innerWidth + 'px wide (browser zoom or narrow window): the stacked layout for narrow screens applies by design. Reduce the zoom (Ctrl+0) or widen the window to get the cart back on the right.');
			}
		}
	});
	window.addEventListener('load', FluxPosUpdateTabArrows);
	if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
		document.fonts.ready.then(FluxPosUpdateTabArrows);
	}

	PrintCategories(0);
	LoadProducts(0);
	Refresh();
	FluxPosOffline.registerServiceWorker();
	FluxPosOffline.initOfflineStatus();
	FluxPosOffline.syncCatalog();
	FluxPosOffline.syncOutbox();	// Replay the orders captured while offline, if any
	<?php
	// IF NO TERMINAL SELECTED
	if (empty($_SESSION["fluxposterminal"]) || $_SESSION["fluxposterminal"] == "") {
		print "ModalBox('ModalTerminal');";
	}

	if (getDolGlobalString('FLUXPOS_CONTROL_CASH_OPENING') && !empty($_SESSION["fluxposterminal"])) {
		// Look for any cash control fence created today on this terminal, regardless of its status,
		// so the auto-open popup only fires on the first cash control of a calendar day. Without this,
		// refreshing the browser the same day after closing a fence used to immediately re-fire the
		// popup because the closed fences were filtered out by the status check (#38597).
		$sql = "SELECT rowid, status FROM ".MAIN_DB_PREFIX."pos_cash_fence WHERE";
		$sql .= " entity = ".((int) $conf->entity)." AND ";
		$sql .= " posnumber = ".((int) $_SESSION["fluxposterminal"])." AND ";
		$sql .= " date_creation > '".$db->idate(dol_get_first_hour(dol_now()))."'";
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			// If there is no cash control from today open it
			if (!isset($obj->rowid) || is_null($obj->rowid)) {
				print "ControlCashOpening();";
			}
		}
	}
	?>

	/* For Header Scroll */
	var elem1 = $("#topnav-left")[0];
	var elem2 = $("#topnav-right")[0];
	var checkOverflow = function() {
		if (scrollBars().horizontal) $("#topnav").addClass("overflow");
		else  $("#topnav").removeClass("overflow");
	}

	var scrollBars = function(){
		var container= $('#topnav')[0];
		return {
			vertical:container.scrollHeight > container.clientHeight,
			horizontal:container.scrollWidth > container.clientWidth
		}
	}

	$(window).resize(function(){
		checkOverflow();
	});

	   let resizeObserver = new ResizeObserver(() => {
		   checkOverflow();
	   });
		  resizeObserver.observe(elem1);
	   resizeObserver.observe(elem2);
	checkOverflow();

	var pressTimer = [];
	var direction = 1;
	var step = 200;

	$(".indicator").mousedown(function(){
		direction = $(this).hasClass("left") ? -1 : 1;
		scrollTo();
		pressTimer.push(setInterval(scrollTo, 100));
	});

	$(".indicator").mouseup(function(){
		pressTimer.forEach(clearInterval);
	});

	$("body").mouseup(function(){
		pressTimer.forEach(clearInterval);
		console.log("body mouseup");
	});

	function scrollTo(){
		console.log("here");
		var pos = $("#topnav").scrollLeft();
		document.getElementById("topnav").scrollTo({ left: $("#topnav").scrollLeft() + direction * step, behavior: 'smooth' })
	}

	$("#topnav").scroll(function(){
		if (($("#topnav").offsetWidth + $("#topnav").scrollLeft >= $("#topnav").scrollWidth)) {
			console.log("end");
		}
	});
	/* End Header Scroll */
});
</script>

<div class="container">

<?php
if (!getDolGlobalString('FLUXPOS_HIDE_HEAD_BAR')) {
	?>
	<div class="header">
		<div id="topnav" class="topnav">
			<div id="topnav-left" class="topnav-left">
				<div class="inline-block valignmiddle">
				<a class="topnav-terminalhour" onclick="ModalBox('ModalTerminal')">
				<span class="fa fa-cash-register"></span>
				<span class="hideonsmartphone">
				<?php
				if (!empty($_SESSION["fluxposterminal"])) {
					echo getDolGlobalString("FLUXPOS_TERMINAL_NAME_".$_SESSION["fluxposterminal"], $langs->trans("TerminalName", $_SESSION["fluxposterminal"]));
				}
				?>
				</span>
				<?php
				echo '<span class="hideonsmartphone"> - '.dol_print_date(dol_now(), "day").'</span>'; ?>
				</a>
				<?php
				if (isModEnabled('multicurrency')) {
					print '<a class="valignmiddle tdoverflowmax100" id="multicurrency" onclick="ModalBox(\'ModalCurrency\')" title=""><span class="fas fa-coins paddingrightonly"></span>';
					print '<span class="hideonsmartphone">'.$langs->trans("Currency").'</span>';
					print '</a>';
				} ?>
				</div>
				<!-- section for customer -->
				<div class="inline-block valignmiddle" id="customerandsales"></div>
				<input type="hidden" id="idcustomer" value="">
				<!-- section for shopping carts -->
				<div class="inline-block valignmiddle" id="shoppingcart"></div>
				<!-- More info about customer -->
				<div class="inline-block valignmiddle tdoverflowmax150onsmartphone" id="moreinfo"></div>
				<?php
				if (isModEnabled('stock')) {
					?>
				<!-- More info about warehouse -->
				<div class="inline-block valignmiddle tdoverflowmax150onsmartphone" id="infowarehouse"></div>
					<?php
				} ?>
			</div>
			<div id="topnav-right" class="topnav-right">
				<?php
				$reshook = $hookmanager->executeHooks('fluxpos_login_block_other');
				if ($reshook == 0) {  //Search method
					?>
					<div class="login_block_other fluxpos">
					<input type="text" id="search" name="search" class="input-nobottom" onkeyup="Search2('<?php echo dol_escape_js($keyCodeForEnter); ?>', null);" placeholder="<?php echo dol_escape_htmltag($langs->trans("Search")); ?>" autofocus>
					<a onclick="ClearSearch(false);" class="nohover"><span class="fa fa-backspace"></span></a>
					<a href="<?php echo DOL_URL_ROOT.'/index.php?token='.newToken(); ?>" target="backoffice" rel="opener"><!-- we need rel="opener" here, we are on same domain and we need to be able to reuse this tab several times -->
					<span class="fas fa-home"></span></a>
					<?php if (empty($conf->dol_use_jmobile)) { ?>
					<a class="hideonsmartphone" onclick="FullScreen();" title="<?php echo dol_escape_htmltag($langs->trans("ClickFullScreenEscapeToLeave")); ?>"><span class="fa fa-expand-arrows-alt"></span></a>
					<?php } ?>
					</div>
					<?php
				}
				?>
				<div class="login_block_user">
				<?php
				print top_menu_user(1, DOL_URL_ROOT.'/user/logout.php?token='.newToken().'&urlfrom='.urlencode(dol_buildpath('/fluxpos/index.php', 1).'?setterminal='.((int) $term)));
				?>
				</div>
			</div>
			<div class="arrows">
				<span class="indicator left"><i class="fa fa-arrow-left"></i></span>
				<span class="indicator right"><i class="fa fa-arrow-right"></i></span>
			</div>
		</div>
	</div>
	<?php
}
?>

<!-- Modal terminal box -->
<div id="ModalTerminal" class="modal">
	<div class="modal-content">
		<div class="modal-header">
		<?php
		if (!getDolGlobalString('FLUXPOS_FORCE_TERMINAL_SELECT')) {
			?>
			<span class="close" href="#" onclick="document.getElementById('ModalTerminal').style.display = 'none';">&times;</span>
			<?php
		} ?>
		<h3><?php print $langs->trans("TerminalSelect"); ?></h3>
	</div>
	<div class="modal-body">
		<button type="button" class="block" onclick="location.href='index.php?setterminal=1'"><?php print getDolGlobalString("FLUXPOS_TERMINAL_NAME_1", $langs->trans("TerminalName", 1)); ?></button>
		<?php
		$nbloop = getDolGlobalInt('FLUXPOS_NUM_TERMINALS');
		for ($i = 2; $i <= $nbloop; $i++) {
			print '<button type="button" class="block" onclick="location.href=\'index.php?setterminal='.$i.'\'">'.getDolGlobalString("FLUXPOS_TERMINAL_NAME_".$i, $langs->trans("TerminalName", $i)).'</button>';
		}
		?>
	</div>
</div>
</div>

<!-- Modal multicurrency box -->
<?php if (isModEnabled('multicurrency')) { ?>
<div id="ModalCurrency" class="modal">
	<div class="modal-content">
		<div class="modal-header">
			<span class="close" href="#" onclick="document.getElementById('ModalCurrency').style.display = 'none';">&times;</span>
			<h3><?php print $langs->trans("SetMultiCurrencyCode"); ?></h3>
		</div>
		<div class="modal-body">
			<?php
			$sql = 'SELECT code FROM '.MAIN_DB_PREFIX.'multicurrency';
			$sql .= " WHERE entity IN ('".getEntity('multicurrency')."')";
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					print '<button type="button" class="block" onclick="location.href=\'index.php?setcurrency='.urlencode($obj->code).'\'">'.$obj->code.'</button>';
				}
			}
			?>
		</div>
	</div>
</div>
<?php } ?>

<!-- Modal popup to create a Credit Note -->
<div id="ModalCreditNote" class="modal">
	<div class="modal-content">
		<div class="modal-header">
		<span class="close" href="#" onclick="document.getElementById('ModalCreditNote').style.display = 'none';">&times;</span>
		<h3><?php print $langs->trans("invoiceAvoirWithLines"); ?></h3>
	</div>
	<div class="modal-body">
		<button type="button" class="block" onclick="CreditNote(); document.getElementById('ModalCreditNote').style.display = 'none';"><?php print $langs->trans("Yes"); ?></button>
		<button type="button" class="block" onclick="document.getElementById('ModalCreditNote').style.display = 'none';"><?php print $langs->trans("No"); ?></button>
	</div>
</div>
</div>

<!-- Modal Note -->
<div id="ModalNote" class="modal">
	<div class="modal-content">
		<div class="modal-header">
		<span class="close" href="#" onclick="document.getElementById('ModalNote').style.display = 'none';">&times;</span>
		<h3><?php print $langs->trans("Note"); ?></h3>
	</div>
	<div class="modal-body">
		<input type="text" class="block" id="textinput">
		<button type="button" class="block" onclick="SetNote(); document.getElementById('ModalNote').style.display = 'none';">OK</button>
	</div>
</div>
</div>

<!-- Modal Offline payment: dialog used to pay and validate a sale captured while the terminal is offline.
	 The sale stays on the terminal until its background synchronization validates it on the server with
	 the same rules as invoice.php action=valid (invoice number, payments, receipt printing). -->
<div id="ModalOfflinePay" class="modal">
	<div class="modal-content">
		<div class="modal-header">
		<span class="close" href="#" onclick="document.getElementById('ModalOfflinePay').style.display = 'none';">&times;</span>
		<h3><?php print $langs->trans("PaymentMode"); ?></h3>
	</div>
	<div class="modal-body">
		<div id="offlinepaytotal" class="offlinepaytotal"></div>
		<div class="offlinepayrow"><span class="offlinepaymode"><span class="fa fa-coins"></span> <?php print $langs->trans("PaymentTypeShortLIQ"); ?></span><input type="number" step="any" min="0" class="block offlinepayamount" id="offlinepayamountLIQ"></div>
		<div class="offlinepayrow"><span class="offlinepaymode"><span class="fa fa-credit-card"></span> <?php print $langs->trans("PaymentTypeShortCB"); ?></span><input type="number" step="any" min="0" class="block offlinepayamount" id="offlinepayamountCB"></div>
		<div class="offlinepayrow"><span class="offlinepaymode"><span class="fa fa-money-check"></span> <?php print $langs->trans("PaymentTypeShortCHQ"); ?></span><input type="number" step="any" min="0" class="block offlinepayamount" id="offlinepayamountCHQ"></div>
		<div id="offlinepayreturned" class="offlinepayreturned"></div>
		<button type="button" class="block" onclick="ValidateOfflinePay();"><?php print $langs->trans("Validate"); ?></button>
	</div>
</div>
</div>

	<div class="posmain<?php if (!getDolGlobalString('FLUXPOS_HIDE_HEAD_BAR')) {
		print 'withhead';
					} ?>">

		<!-- Layout 1.1 (Proposal A): product area is the hero (left column, fluid grid),
			 the cart becomes the right column (lines, sticky total, payment block, quick
			 actions). The numberpad is contextual: it opens as a popup when a cart line is
			 tapped (or stays docked with FLUXPOS_DISPLAY_NUMBERPAD = 'always'). -->
		<div id="dialogforpopupfluxpos"></div>

		<div class="posleft">

<?php

// Dependency modulecheck
if (!isModEnabled('invoice')) {
	setEventMessages($langs->trans("ErrorModuleSetupNotComplete", $langs->transnoentitiesnoconv("Invoice")), null, 'errors');
}

// FluxPos setup check
if (isset($_SESSION["fluxposterminal"]) && $_SESSION["fluxposterminal"]) {
	$sql = "SELECT code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement";
	$sql .= " WHERE entity IN (" . getEntity('c_paiement') . ")";
	$sql .= " AND active = 1";
	$sql .= " ORDER BY libelle";

	$resql = $db->query($sql);
	$paiementsModes = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$paycode = $obj->code;
			if ($paycode == 'LIQ') {
				$paycode = 'CASH';
			}
			if ($paycode == 'CHQ') {
				$paycode = 'CHEQUE';
			}

			$constantforkey = "FLUXPOS_ID_BANKACCOUNT_" . $paycode . $_SESSION["fluxposterminal"];
			//var_dump($constantforkey.' '.getDolGlobalInt($constantforkey));
			if (getDolGlobalInt($constantforkey) > 0) {
				array_push($paiementsModes, $obj);
			}
		}
	}

	if (empty($paiementsModes) && isModEnabled("bank")) {
		$langs->load('errors');
		setEventMessages($langs->trans("ErrorModuleSetupNotComplete", $langs->transnoentitiesnoconv("FluxPos")), null, 'errors');
		setEventMessages($langs->trans("ProblemIsInSetupOfTerminal", $_SESSION["fluxposterminal"]), null, 'errors');
	}
}

if (count($maincategories) == 0) {
	if (getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID') > 0) {
		$tmpcategory = new Categorie($db);
		$tmpcategory->fetch(getDolGlobalInt('FLUXPOS_ROOT_CATEGORY_ID'));
		setEventMessages($langs->trans("FluxPosNeedsAtLeastOnSubCategoryIntoParentCategory", $tmpcategory->label), null, 'errors');
	} else {
		setEventMessages($langs->trans("FluxPosNeedsCategories"), null, 'errors');
	}
}
// User menu and external FluxPos modules
$menus = array();
$r = 0;

if (! getDolGlobalString('FLUXPOS_HIDE_HISTORY')) {
	$menus[$r++] = array('title' => '<span class="fa fa-history paddingrightonly"></span><div class="trunc">'.$langs->trans("History").'</div>', 'action' => 'History();');
}

if (getDolGlobalString('FLUXPOS_HIDE_HEAD_BAR')) {
	if (getDolGlobalString('FLUXPOS_CHOOSE_CONTACT')) {
		$menus[$r++] = array('title' => '<span class="far fa-building paddingrightonly"></span><div class="trunc">'.$langs->trans("Contact").'</div>', 'action' => 'Contact();');
	} else {
		$menus[$r++] = array('title' => '<span class="far fa-building paddingrightonly"></span><div class="trunc">'.$langs->trans("Customer").'</div>', 'action' => 'Customer();');
	}
}

if (!getDolGlobalString('FLUXPOS_BAR_RESTAURANT')) {
	$menus[$r++] = array('title' => '<span class="fa fa-layer-group paddingrightonly"></span><div class="trunc">'.$langs->trans("New").'</div>', 'action' => 'New();');
} else {
	// BAR RESTAURANT specific menu
	$menus[$r++] = array('title' => '<span class="fa fa-layer-group paddingrightonly"></span><div class="trunc">'.$langs->trans("Place").'</div>', 'action' => 'Floors();');
}

// Add a non predefined product
if (!getDolGlobalString('FLUXPOS_NO_FREE_ZONE_PRODUCT')) {
	$menus[$r++] = array('title' => '<span class="fa fa-cube paddingrightonly"></span><div class="trunc">'.$langs->trans("FreeZone").'</div>', 'action' => 'FreeZone();');
}

// BAR RESTAURANT specific menu "Print on kitchen/order printer"
if (getDolGlobalString('FLUXPOS_BAR_RESTAURANT')) {
	if (getDolGlobalString('FLUXPOS_SUPPLEMENTS')) {
		$menus[$r++] = array('title' => '<span class="fa fa-cube  paddingrightonly"></span><div class="trunc">'.$langs->trans("ProductSupplements").'</div>', 'action' => 'LoadProducts(\'supplements\');');
	}

	if (getDolGlobalString('FLUXPOS_ORDER_PRINTERS')) {
		$menus[$r++] = array('title' => '<span class="fa fa-blender-phone paddingrightonly"></span><div class="trunc">'.$langs->trans("SentOrderToKitchen").'</span>', 'action' => 'FluxPosPrintingOrder();');
	}
}

// Add a discount
$menus[$r++] = array('title' => '<span class="fa fa-percent paddingrightonly"></span><div class="trunc">'.$langs->trans("InvoiceDiscountShort").'</div>', 'action' => 'Reduction();');

if (!getDolGlobalString('FLUXPOS_NO_SPLIT_SALE')) {
	$menus[$r++] = array('title' => '<span class="fas fa-cut paddingrightonly"></span><div class="trunc">'.$langs->trans("SplitSale").'</div>', 'action' => 'Split();');
}

// Last action that close the sell (payments)
$menus[$r++] = array('title' => '<span class="far fa-money-bill-alt paddingrightonly"></span><div class="trunc">'.$langs->trans("Payment").'</div>', 'action' => 'CloseBill();');
if (getDolGlobalString('FLUXPOS_DIRECT_PAYMENT')) {
	$menus[$r++] = array('title' => '<span class="far fa-money-bill-alt paddingrightonly"></span><div class="trunc">'.$langs->trans("DirectPayment").' <span class="opacitymedium">('.$langs->trans("Cash").')</span></div>', 'action' => 'DirectPayment();');
}

$customprinterallowed = false;
$customprinttemplateallowed = true;

// BAR RESTAURANT specific menu
if (getDolGlobalString('FLUXPOS_BAR_RESTAURANT')) {
	// Button to print receipt before payment
	$customprinterallowed = true;
	$customprinttemplateallowed = true;
}

include_once DOL_DOCUMENT_ROOT.'/blockedlog/lib/blockedlog.lib.php';
// isALNERunningVersion() exists only in Dolibarr 23+ (the function is defined in its blockedlog lib).
// Calling it unconditionally on Dolibarr 22 or lower throws a "Call to undefined function" fatal
// that kills the page between the numpad and the action buttons. Guard it to stay compatible.
if (function_exists('isALNERunningVersion') && isALNERunningVersion()) {
	// Custom printer may be allowed if mandatory information in template are guaranteed. For the moment, we prefer not allow this.
	$customprinttemplateallowed = false;
}

// Button to print receipt
// This section should be same than into invoice.php
if (getDolGlobalString('FLUXPOS_PRINT_METHOD') == "takeposconnector") {		// deprecated method
	if (getDolGlobalString('FLUXPOS_PRINT_SERVER') && filter_var(getDolGlobalString('FLUXPOS_PRINT_SERVER'), FILTER_VALIDATE_URL) == true) {
		// If FLUXPOS_PRINT_SERVER is an URL
		$menus[$r++] = array('title' => '<span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("PrintTicket").'</div>', 'action' => 'PrintByESCPOSOld(placeid);');
	} else {
		// If FLUXPOS_PRINT_SERVER is an IP
		// Print by calling the receipt.php to get HTML content and send the HTML content to FLUXPOS_PRINT_SERVER:8111/print
		$menus[$r++] = array('title' => '<span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("PrintTicket").'</div>', 'action' => 'PrintHTMLToSlashPrint(placeid);');
	}
} elseif ($customprinterallowed && $customprinttemplateallowed && (isModEnabled('receiptprinter') && getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term) > 0) || getDolGlobalString('FLUXPOS_PRINT_METHOD') == "receiptprinter") {		// @phpstan-ignore-line
	// Button Print Receipt on special custom printer using custom template
	// Only query the printer table when the core receiptprinter module is enabled: since Dolibarr 22 a
	// query on a missing table (llx_printer_receipt) throws instead of returning an error.
	$nameOfPrinter = '';
	if (isModEnabled('receiptprinter') && getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term) > 0) {
		$nameOfPrinter = dol_getIdFromCode($db, getDolGlobalInt('FLUXPOS_PRINTER_TO_USE'.$term), 'printer_receipt', 'rowid', 'name', 1);
	}
	$menus[$r++] = array('title' => '<div title="'.dolPrintHTMLForAttribute($langs->trans("PrintOn", $nameOfPrinter)).'"><span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("PrintTicket").'</div></div>', 'action' => 'PrintByESCPOS(placeid);');
} else {
	// Button Print Receipt on browser
	$menus[$r++] = array('title' => '<span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("PrintTicket").'</div>', 'action' => 'PrintByBrowser(placeid);');
}

if (getDolGlobalString('FLUXPOS_PRINT_METHOD') == "takeposconnector" && getDolGlobalString('FLUXPOS_ORDER_NOTES') == 1) {
	$menus[$r++] = array('title' => '<span class="fa fa-sticky-note paddingrightonly"></span><div class="trunc">'.$langs->trans("OrderNotes").'</div>', 'action' => 'FluxPosOrderNotes();');
}


if (getDolGlobalString('FLUXPOS_PRINT_METHOD') == "takeposconnector") {
	$menus[$r++] = array('title' => '<span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("DOL_OPEN_DRAWER").'</div>', 'action' => 'OpenDrawer();');
}
if (getDolGlobalInt('FLUXPOS_ADD_BUTTON_OPEN_DRAWER'.$term) > 0) {
	$menus[$r++] = array(
		'title' => '<span class="fa fa-receipt paddingrightonly"></span><div class="trunc">'.$langs->trans("DOL_OPEN_DRAWER").'</div>',
		'action' => 'DolibarrOpenDrawer();',
	);
}

// If there is an open cash control started
$sql = "SELECT rowid, status, entity FROM ".MAIN_DB_PREFIX."pos_cash_fence WHERE";
$sql .= " entity = ".((int) $conf->entity)." AND ";
$sql .= " posnumber = ".((int) empty($_SESSION["fluxposterminal"]) ? 0 : $_SESSION["fluxposterminal"])." AND ";
$sql .= " date_creation > '".$db->idate(dol_get_first_hour(dol_now()))."'";
$sql .= " AND status = 0";

$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	if ($num) {
		$obj = $db->fetch_object($resql);
		$menus[$r++] = array('title' => '<span class="fas fa-file-invoice-dollar paddingrightonly"></span><div class="trunc">'.$langs->trans("CashReport").'</div>', 'action' => 'CashReport('.$obj->rowid.');');
		if ($obj->status == 0) {
			$menus[$r++] = array('title' => '<span class="fas fa-cash-register paddingrightonly"></span><div class="trunc">'.$langs->trans("CloseCashFence").'</div>', 'action' => 'CloseCashFence('.$obj->rowid.');');
		}
	}
}

if (getDolGlobalString('FLUXPOS_HIDE_HEAD_BAR')) {
	$menus[$r++] = array('title' => '<span class="fa fa-sign-out-alt pictofixedwidth"></span><div class="trunc">'.$langs->trans("Logout").'</div>', 'action' => 'window.location.href=\''.DOL_URL_ROOT.'/user/logout.php?token='.newToken().'\';');
}

if (getDolGlobalString('FLUXPOS_WEIGHING_SCALE')) {
	$menus[$r++] = array('title' => '<span class="fa fa-balance-scale pictofixedwidth"></span><div class="trunc">'.$langs->trans("WeighingScale").'</div>', 'action' => 'WeighingScale();');
}

$parameters = array('menus' => $menus);
$reshook = $hookmanager->executeHooks('ActionButtons', $parameters);
if ($reshook == 0) {  //add buttons
	if (is_array($hookmanager->resArray)) {
		foreach ($hookmanager->resArray as $resArray) {
			foreach ($resArray as $butmenu) {
				$menus[$r++] = $butmenu;
			}
		}
	}
} elseif ($reshook == 1) {
	$r = 0; //replace buttons
	if (is_array($hookmanager->resArray)) {
		foreach ($hookmanager->resArray as $resArray) {
			foreach ($resArray as $butmenu) {
				$menus[$r++] = $butmenu;
			}
		}
	}
}

?>
		<!-- Layout 1.1: the action buttons move into the cart footer (quick row + overflow menu)
		 and the numberpad becomes a contextual line-edit popup (FLUXPOS_DISPLAY_NUMBERPAD). -->
		<?php
		// Numpad with the same keys as the old permanent pad, reused by the line-edit modal
		// and by the docked pad of the terminals that set FLUXPOS_DISPLAY_NUMBERPAD = 'always'.
		$fluxposNumpadHtml = '';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(7);">7</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(8);">8</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(9);">9</button>';
		$fluxposNumpadHtml .= '<button type="button" id="qty" class="calcbutton2" onclick="Edit(\'qty\')">'.dol_escape_htmltag($langs->trans("Qty")).'</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(4);">4</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(5);">5</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(6);">6</button>';
		$fluxposNumpadHtml .= '<button type="button" id="price" class="calcbutton2" onclick="Edit(\'p\')">'.dol_escape_htmltag($langs->trans("Price")).'</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(1);">1</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(2);">2</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(3);">3</button>';
		$fluxposNumpadHtml .= '<button type="button" id="reduction" class="calcbutton2" onclick="Edit(\'r\')">'.dol_escape_htmltag($langs->trans("LineDiscountShort")).'</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(0);">0</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton" onclick="Edit(\'.\')">.</button>';
		$fluxposNumpadHtml .= '<button type="button" class="calcbutton poscolorblue" onclick="Edit(\'c\')">C</button>';
		if ($user->hasRight('fluxpos', 'editlines')) {
			$fluxposNumpadHtml .= '<button type="button" class="calcbutton2 poscolordelete" id="delete" onclick="deleteline()"><span class="fa fa-trash"></span></button>';
		}

		// Quick buttons of the cart footer: the New/Place entry, the print receipt entry and
		// (when enabled) the direct payment entry. They are removed from the overflow menu.
		$fluxposQuickKeys = array();
		$fluxposMenuNewHtml = '';
		$fluxposMenuPrintHtml = '';
		$fluxposMenuDirectHtml = '';
		foreach ($menus as $mkey => $menu) {
			$maction = isset($menu['action']) ? $menu['action'] : '';
			if ($fluxposMenuNewHtml == '' && (strpos($maction, 'New();') !== false || strpos($maction, 'Floors();') !== false)) {
				$fluxposMenuNewHtml = '<button type="button" class="cartquickbtn" '.(empty($menu['style']) ? '' : 'style="'.dol_escape_htmltag($menu['style']).'"').' onclick="'.$maction.' document.getElementById(\'ModalActions\').style.display=\'none\';">'.$menu['title'].'</button>';
				$fluxposQuickKeys[] = $mkey;
				continue;
			}
			if ($fluxposMenuPrintHtml == '' && strpos($maction, 'PrintBy') !== false) {
				$fluxposMenuPrintHtml = '<button type="button" class="cartquickbtn" '.(empty($menu['style']) ? '' : 'style="'.dol_escape_htmltag($menu['style']).'"').' onclick="'.$maction.' document.getElementById(\'ModalActions\').style.display=\'none\';">'.$menu['title'].'</button>';
				$fluxposQuickKeys[] = $mkey;
				continue;
			}
			if (getDolGlobalString('FLUXPOS_DIRECT_PAYMENT') && $fluxposMenuDirectHtml == '' && strpos($maction, 'DirectPayment();') !== false) {
				$fluxposMenuDirectHtml = '<button type="button" class="cartquickbtn" '.(empty($menu['style']) ? '' : 'style="'.dol_escape_htmltag($menu['style']).'"').' onclick="'.$maction.' document.getElementById(\'ModalActions\').style.display=\'none\';">'.$menu['title'].'</button>';
				$fluxposQuickKeys[] = $mkey;
				continue;
			}
		}
		$fluxposMenusOverflow = array();
		foreach ($menus as $mkey => $menu) {
			if (in_array($mkey, $fluxposQuickKeys)) {
				continue;
			}
			$fluxposMenusOverflow[] = $menu;
		}
		?>
			<?php if ($fluxposDisplayNumberpad != 'never' && $fluxposDisplayNumberpad != 'always') { ?>
		<!-- Modal popup to edit a cart line: the contextual numberpad (qty / price / reduction,
			 delete). It opens when a line of the cart is tapped and closes when a change is
			 applied. The payment screen (pay.php) has its own pad. -->
<div id="ModalLineEdit" class="modal">
	<div class="modal-content modallineedit">
		<div class="modal-header">
			<span class="close" href="#" onclick="FluxPosCloseLineEdit();">&times;</span>
			<h3><?php print $langs->trans("Modify"); ?></h3>
		</div>
		<div class="modal-body">
			<div class="lineeditpad">
			<?php print $fluxposNumpadHtml; ?>
			</div>
		</div>
	</div>
</div>
			<?php } ?>

		<!-- Modal popup with all the other actions (hook-registered buttons included),
			 opened from the "..." button of the cart footer -->
<div id="ModalActions" class="modal">
	<div class="modal-content modalactions">
		<div class="modal-header">
			<span class="close" href="#" onclick="document.getElementById('ModalActions').style.display = 'none';">&times;</span>
			<h3><?php print $langs->trans("Actions"); ?></h3>
		</div>
		<div class="modal-body">
			<?php
			foreach ($fluxposMenusOverflow as $menu) {
				print '<button type="button" class="actionbutton block" '.(empty($menu['style']) ? '' : 'style="'.dol_escape_htmltag($menu['style']).'"').' onclick="'.(empty($menu['action']) ? '' : $menu['action']).' document.getElementById(\'ModalActions\').style.display=\'none\';">'.$menu['title'].'</button>';
			}
			?>
		</div>
	</div>
</div>

		<!--  Show categories as a tab bar: one scrollable strip holding ALL the categories of the
		     terminal (server rendered, historical DOM ids catdivN/catimgN/catdescN kept), with
		     arrows on both sides that appear only when the strip actually overflows, and the tile
		     density control on the same row, right after the tabs, sitting a bit lower. -->
		<?php
		if (getDolGlobalInt('FLUXPOS_HIDE_CATEGORIES') == 1) {
			print '<div class="div4" style="display: none;">';
		} else {
			print '<div class="div4">';
		}
		?>
		<a class="tabarrow tabarrowleft" onclick="FluxPosScrollTabs(-1);"><span class="fa fa-chevron-left"></span></a>
		<div class="tabbar" id="tabbar">
		<?php
		$catindex = 0;
		foreach ($maincategories as $maincategory) {
			?>
			<div class="wrapper" onclick="LoadProducts(<?php echo $catindex; ?>)" id="catdiv<?php echo $catindex; ?>" data-rowid="<?php echo $maincategory['rowid']; ?>">
				<?php
				if (!getDolGlobalString('FLUXPOS_HIDE_CATEGORY_IMAGES')) {
					echo '<img class="imgwrapper" id="catimg'.$catindex.'" src="genimg/index.php?query=cat&id='.$maincategory['rowid'].'" />';
				}
				?>
				<div class="description" id="catdivdesc<?php echo $catindex; ?>">
					<div class="description_content" id="catdesc<?php echo $catindex; ?>"><?php
					if (getDolGlobalString('FLUXPOS_SHOW_CATEGORY_DESCRIPTION') == 1) {
						echo dol_escape_htmltag($maincategory['label']).' - '.$maincategory['description'];
					} else {
						echo dol_escape_htmltag($maincategory['label']);
					}
					?></div>
				</div>
			</div>
			<?php
			$catindex++;
		}
		?>
		</div>
		<a class="tabarrow tabarrowright" onclick="FluxPosScrollTabs(1);"><span class="fa fa-chevron-right"></span></a>
		<!-- Tile density control (S / M / L), remembered per terminal in localStorage -->
		<div id="tiledensity" class="tiledensity">
			<button type="button" data-size="S" <?php echo $fluxposTileSize == 'S' ? 'class="clicked"' : ''; ?>>S</button><button type="button" data-size="M" <?php echo $fluxposTileSize == 'M' ? 'class="clicked"' : ''; ?>>M</button><button type="button" data-size="L" <?php echo $fluxposTileSize == 'L' ? 'class="clicked"' : ''; ?>>L</button>
		</div>
		</div>

		<!--  Show product -->
		<div class="div5<?php if (getDolGlobalInt('FLUXPOS_HIDE_CATEGORIES') == 1) {
			print ' centpercent';
						} ?>">

			<?php
			if (getDolGlobalString('FLUXPOS_HIDE_HEAD_BAR') && !getDolGlobalString('FLUXPOS_HIDE_SEARCH')) {
				print '<!-- Show the search input text (only when the head bar is hidden, otherwise it is in the topnav) -->'."\n";
				print '<div class="gridheadsearch">';
				print '<input type="text" id="search" class="input-search-fluxpos input-nobottom" name="search" onkeyup="Search2(\''.dol_escape_js($keyCodeForEnter).'\', null);" placeholder="'.dol_escape_htmltag($langs->trans("Search")).'" autofocus> ';
				print '<a onclick="ClearSearch(false);"><span class="fa fa-backspace"></span></a>';
				print '</div>';
			}
			?>

			<!-- The fluid product grid: the whole category is rendered at once (subcategories
			 first, then its products), the area scrolls instead of paging. The tiles keep the
			 historical DOM ids (prodivN, proimgN, ...) filled by RenderProducts(). -->
			<div id="productgrid" class="productgrid"></div>

			<input type="hidden" id="search_start_less" value="0">
			<input type="hidden" id="search_start_more" value="0">
			<input type="hidden" id="search_pagination" value="">
		</div>
		</div><!-- /posleft -->

		<!-- Cart column (right): the order lines, a sticky total and the payment block.
		     The offline / synchronization status pill sits at the top of this column, above the
		     cart, so it never blocks the product tiles, the tabs or the payment buttons. -->
		<div class="cartcolumn">
			<div id="fluxposofflinestatus" class="fluxposofflinestatus"></div>

			<div id="poslines" class="cartlines">
			</div>

			<div class="cartfooter">
				<div id="carttotal" class="carttotal">
					<span class="carttotallabel"><?php echo dol_escape_htmltag($langs->trans("TotalTTCShort")); ?></span>
					<span id="carttotalvalue" class="carttotalvalue"></span>
				</div>

				<!-- Payment modes configured for this terminal (same gating as today), each
				 one opens the payment screen of pay.php with this mode preselected -->
				<div class="paymentblock">
				<?php
				if (!empty($paiementsModes)) {
					foreach ($paiementsModes as $obj) {
						$pbCode = $obj->code;
						$pbIcon = 'money-bill-alt';
						if ($pbCode == 'LIQ') {
							$pbIcon = 'coins';
						} elseif ($pbCode == 'CB') {
							$pbIcon = 'credit-card';
						} elseif ($pbCode == 'CHQ') {
							$pbIcon = 'money-check';
						}
						$pbLabel = $langs->trans("PaymentTypeShort".$pbCode);
						if ($pbLabel == 'PaymentTypeShort'.$pbCode && !empty($obj->libelle)) {
							$pbLabel = $obj->libelle;
						}
						print '<button type="button" class="paymentmode" onclick="CloseBill(\''.dol_escape_js($pbCode).'\');"><span class="fa fa-'.$pbIcon.' iconwithlabel"></span><span class="paymentmodelabel">'.dol_escape_htmltag($pbLabel).'</span></button>';
					}
				}
				?>
					<button type="button" class="paymentmode paymentmore" onclick="CloseBill();"><span class="fa fa-ellipsis-h iconwithlabel"></span><span class="paymentmodelabel"><?php echo dol_escape_htmltag($langs->trans("More")); ?></span></button>
				</div>

				<!-- Quick actions of the cart footer -->
				<div class="cartquick">
					<?php
					print $fluxposMenuNewHtml.$fluxposMenuDirectHtml.$fluxposMenuPrintHtml;
					print '<button type="button" class="cartquickbtn cartmore" title="'.dol_escape_htmltag($langs->trans("Actions")).'" onclick="ModalBox(\'ModalActions\');"><span class="fa fa-ellipsis-h"></span><div class="trunc">...</div></button>';
					?>
				</div>
<?php if ($fluxposDisplayNumberpad == 'always') { ?>
				<!-- Docked numberpad for the terminals that keep it permanent (escape hatch) -->
				<div class="lineeditpad numpaddocked">
				<?php print $fluxposNumpadHtml; ?>
				</div>
<?php } ?>
			</div>
		</div>
	</div><!-- /posmain -->

</body>
<?php

llxFooter();

$db->close();
