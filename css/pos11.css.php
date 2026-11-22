<?php
/* Copyright (C) 2004-2017	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2006		Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2007-2017	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2011		Philippe Grand			<philippe.grand@atoo-net.com>
 * Copyright (C) 2012		Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2018       Ferran Marcet           <fmarcet@2byte.es>
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
 *		\file       htdocs/fluxpos/css/pos11.css.php
 *		\brief      File for CSS style for FluxPos
 */

//if (! defined('NOREQUIREUSER')) define('NOREQUIREUSER','1');	// Not disabled because need to load personalized language
//if (! defined('NOREQUIREDB'))   define('NOREQUIREDB','1');	// Not disabled to increase speed. Language code is found on url.
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
//if (! defined('NOREQUIRETRAN')) define('NOREQUIRETRAN','1');	// Not disabled because need to do translations
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1); // File must be accessed by logon page so without login
}
//if (! defined('NOREQUIREMENU'))   define('NOREQUIREMENU',1);  // We need top menu content
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}


define('ISLOADEDBYSTEELSHEET', '1');


session_cache_limiter('public');

require_once __DIR__.'/../mainenv.php';
/**
 * @var Conf $conf
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// Define css type
top_httphead('text/css');
// This stylesheet should never be served stale: it defines the whole POS layout and is
// byte-identical when unchanged (the ?v=<filemtime> suffix on the link of the POS screens
// prevents even the intermediate caches from keeping an old body). With no-cache the
// browser revalidates (cheap 304) instead of running up to 3 hours on an old layout, which
// is what a terminal saw after every deployment until now.
echo "/* FluxPos CSS - pos11 (loaded from /custom/fluxpos/css/pos11.css.php) */\n";
if (empty($dolibarr_nocache)) {
	header('Cache-Control: no-cache, must-revalidate');
} else {
	header('Cache-Control: no-cache');
}


include DOL_DOCUMENT_ROOT.'/theme/'.$conf->theme.'/theme_vars.inc.php';
if (defined('THEME_ONLY_CONSTANT')) {
	return;
}

?>

html,body {
	box-sizing: border-box;
	padding:0px;
	margin:0;
	height:100%;
	width:100%;
}

.bodyfluxpos {
	background-color: var(--colorbackgrey);
}

/* Fix dropdown user menu clipped by overflow:hidden on body and #topnav under MD theme */
.bodyfluxpos#topmenu-login-dropdown.dropdown-menu{
	position:fixed;
	top:45px;
	left:auto;
	right:0;
	overflow-y:auto;
	max-height:calc(100vh-50px);
}

/* Fix dropdown user-header layout missing .side-nav-vert context under FluxPos */
.bodyfluxpos #topmenu-login-dropdown .dropdown-menu {
	line-height: 1.3em;
}

.bodyfluxpos #topmenu-login-dropdown .dropdown-menu > .user-header {
	min-height: 100px;
	padding: 10px;
	text-align: center;
	white-space: normal;
}

.bodyfluxpos div.login_block_user {
	text-align: right;
}

.center {
	text-align: center;
}

button.calcbutton.poscolorblue {
	background-color: #0066AA;
}

button.calcbutton2.poscolordelete {
	background: rgb(255, 188, 185);
	color: #633;
	/*background-color: #884444;
	color: #fff;*/
}

button.calcbutton {
	display: inline-block;
	position: relative;
	padding: 0;
	line-height: normal;
	cursor: pointer;
	vertical-align: middle;
	text-align: center;
	overflow: visible; /* removes extra width in IE */
	width: calc(25% - 2px);
	height: calc(25% - 2px);
	font-weight: bold;
	background-color: #8c907e;
	color: #fff;
	/* border-color: unset; */
	border-width: 0;
	margin: 1px;
	font-size: 14pt;
	border-radius: 4px;
}

div.wrapper, div.wrapper2 {
	border-radius: 5px;
}

button.calcbutton2 {
	color: #fff;
	background-color: #5555AA;
	border-width: 0px;
	display: inline-block;
	position: relative;
	padding: 0;
	line-height: normal;
	cursor: pointer;
	vertical-align: middle;
	text-align: center;
	overflow: visible; /* removes extra width in IE */
	width: calc(25% - 2px);
	height: calc(25% - 2px);
	font-weight: bold;
	font-size: 10pt;
	margin: 1px;
	border-radius: 4px;
}
button.calcbutton2.clicked {
	background-color: #8855AA;
}
button.calcbutton2 .iconwithlabel {
	padding-bottom: 10px;
}

button.calcbutton3 {
	display: inline-block;
	position: relative;
	padding: 0;
	line-height: normal;
	cursor: pointer;
	vertical-align: middle;
	text-align: center;
	overflow: visible; /* removes extra width in IE */
	width: calc(25% - 2px);
	height: calc(25% - 2px);
	font-size: 14pt;
	margin: 1px;
	border-radius: 4px;
}

button.productbutton {
	display: inline-block;
	position: relative;
	padding: 0;
	line-height: normal;
	cursor: pointer;
	vertical-align: middle;
	text-align: center;
	overflow: visible; /* removes extra width in IE */
	width: calc(100% - 2px);
	height: calc(100% - 2px);
	font-weight: bold;
	background-color: #a3a6a3;
	color: #fff;
	/* border-color: unset; */
	border-width: 0;
	margin: 1px;
	font-size: 14pt;
	border-radius: 4px;
}

button.actionbutton {
	background: #EACCC6;
	color: #222;
	/* border: 2px solid #EEE; */
	min-height: 40px;
	border-radius: 4px;
	padding: 12px;
	display: inline-block;
	position: relative;
	line-height: normal;
	cursor: pointer;
	vertical-align: middle;
	text-align: center;
	overflow: visible; /* removes extra width in IE */
	width: calc(33.33% - 2px);
	height: calc(25% - 2px);
	margin: 1px;
	border-width: 0;
}

button.item_value {
	background: #bbbbbb;
	border: #000000 1px solid;
	border-radius: 4px;
	padding: 8px;
}

button.item_value.selected {
	background: #ffffff;
	color: #000000;
	font-weight: bold;
}

div[aria-describedby="dialog-info"] button:before {
	content: "\f788";
	font-family: "<?php echo getDolGlobalString('MAIN_FONTAWESOME_FAMILY', 'Font Awesome 5 Free'); ?>";
	font-weight: 900;
	padding-right: 5px;
}
div[aria-describedby="dialog-info"].ui-dialog .ui-dialog-buttonpane {
	border-width: 0;
}

.fluxpospay {
	font-size: 1.5em;
	height: 40px !important;
}

.fa.fa-trash:before {
	font-size: 1.5em;
}


div.wrapper{
	float:left; /* important */
	position:relative; /* important(so we can absolutely position the description div */
	width:25%;
	height:33%;
	margin:0;
	padding:1px;
	border: 2px solid #EEE;
	/*box-shadow: 3px 3px 3px #bbb; */
	text-align: center;
	box-sizing: border-box;
	background-color:#fff;
	display: flex;
	align-items: center;
	justify-content: center;
}

div.wrapper2{
	float:left; /* important */
	position:relative; /* important(so we can absolutely position the description div */
	width:12.5%;
	height:33%;
	margin:0;
	/* padding:1px; */
	border: 2px solid #EEE;
	/*box-shadow: 3px 3px 3px #bbb;*/
	text-align: center;
	box-sizing: border-box;
	background-color:#fff;
	display: flex;
	align-items: center;
	justify-content: center;
}

img.imgwrapper {
	max-width: 100%;
	max-height: 100%;
}

button:active{
	background:black;
	color: white;
}

div.description{
	position:absolute; /* absolute position (so we can position it where we want)*/
	bottom:0px; /* position will be on bottom */
	left:0px;
	width:100%;
	/* styling below */
	background-color:black;
	/*color:white;*/
	opacity:1; /* transparency */
	/*filter:alpha(opacity=80); IE transparency */
	text-align:center;

	padding-top: 30px;
	background: -webkit-linear-gradient(top, rgba(250,250,250,0), rgba(250,250,250,0.5), rgba(250,250,250,0.95), rgba(250,250,250,1));
}

div.catwatermark{
	position:absolute;
	top:3%;
	left:3%;
	width:20%;
	background-color:black;
	color:white;
	text-align:center;
	font-size: 20px;
	display: none;
	opacity: 0.25;
}

table.postablelines tr td {
	line-height: unset;
	padding-top: 3px;
	padding-bottom: 3px;
}

.posinvoiceline td {
	height: 40px !important;
	background-color: var(--colorbacklineimpair2);
}

.postablelines td.linecolht {
	line-height: 1.3em !important;
}

div.paymentbordline
{
	width:calc(50% - 16px);
	background-color:#aaa;
	border-radius: 8px;
	margin-bottom: 4px;
	display: inline-block;
	padding: 5px;
}

@media only screen and (max-aspect-ratio: 6/4) {
	div.description{
	min-height:20%;
	}
}

.container{
	width: 100%;
	height: 100%;
	margin: 0 auto;
	<?php
	if (getDolGlobalString('FLUXPOS_USE_ARROW_ON_NAVBAR')) {
		?>
		overflow-x: hidden;
		overfloy-y: scroll;
		<?php
	} else {
		?>
		overflow: visible;
		<?php
	}
	?>
	box-sizing: border-box;
}

/* ---- Layout 1.1 (Proposal A) ------------------------------------------
   posmain = flex row [ posleft (products, hero) | cartcolumn (28%) ].   */
.posmain{
	position: relative;
	display: flex;
	flex-direction: row;
	align-items: stretch;
	width: 100%;
	height: 100%;
	overflow: hidden;
	box-sizing: border-box;
}

.posmain.posmainwithhead{
	height: calc(100% - 50px);
	padding-top: 9px;
}

.posleft{
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
	height: 100%;
	overflow: hidden;
	box-sizing: border-box;
}

.cartcolumn{
	display: flex;
	flex-direction: column;
	/* 1.4: a bit wider than before (a third of the screen, between 300 and 460px) and
	   clamp() instead of min/max so the column can never be squeezed away nor push the
	   products out of the viewport (no horizontal scrollbar, always cleanly visible) */
	flex: 0 0 clamp(300px, 30%, 460px);
	height: 100%;
	overflow: hidden;
	box-sizing: border-box;
	border-left: 1px solid #DDD;
	background-color: var(--colorbackbody, #f8f8f8);
}

.div4{
	flex: 0 0 48px;
	height: 48px;
	width: 100%;
	box-sizing: border-box;
	padding-top: 2px;
	padding-bottom: 2px;
	display: flex;
	flex-direction: row;
	align-items: stretch;
	justify-content: flex-start;
	overflow: visible;
	gap: 4px;
}

/* The tab strip itself holds every category (server rendered) and scrolls horizontally;
   the arrows in front of and behind it are shown only while it actually overflows. */
.tabbar {
	flex: 0 1 auto;
	min-width: 0;
	height: 100%;
	display: flex;
	flex-direction: row;
	align-items: stretch;
	overflow-x: auto;
	overflow-y: hidden;
	scrollbar-width: none;
}
.tabbar::-webkit-scrollbar {
	display: none;
}

.tabarrow {
	flex: 0 0 auto;
	display: none; /* shown by FluxPosUpdateTabArrows() when the strip overflows */
	align-items: center;
	justify-content: center;
	width: 32px;
	color: #333;
	background-color: #fff;
	border: 1px solid #EEE;
	border-radius: 4px;
	cursor: pointer;
}
.tabarrow:hover {
	background-color: #f2f2f2;
}
.tabarrow i {
	pointer-events: none;
}

.div5{
	flex: 1 1 auto;
	width: 100%;
	min-height: 0;
	box-sizing: border-box;
	font-size: 6px;
	padding-top:10px;
	padding-bottom:10px;
	overflow-y: auto;
	overflow-x: hidden;
}

.div5.centpercent{
	height: 100%;
}

/* Category tabs */
.div4 .wrapper {
	float: none;
	width: auto;
	min-width: 90px;
	height: 100%;
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 12px;
	margin: 0 2px 0 0;
	border: 1px solid #EEE;
	border-bottom: 3px solid transparent;
	border-radius: 4px 4px 0 0;
	background-color: #fff;
	white-space: nowrap;
	overflow: hidden;
	cursor: pointer;
}

.div4 .wrapper.divempty {
	display: none;
}

.div4 .wrapper img.imgwrapper {
	max-height: 70%;
	max-width: 32px;
	margin-right: 4px;
}

.div4 .wrapper .description {
	position: static;
	width: auto;
	padding-top: 0;
	background: none;
	overflow: hidden;
	text-overflow: ellipsis;
}

.div4 .wrapper .description_content {
	font-size: 14px;
	color: #222;
}

.div4 .wrapper.activetab {
	border-bottom-color: var(--colorbackhmenu1, #0080c0);
	font-weight: bold;
}

.div4 .wrapper .fa-chevron-left,
.div4 .wrapper .fa-chevron-right {
	font-size: 1.4em;
	color: #333;
	cursor: pointer;
}

.div4 .catwatermark {
	display: none;
}

.div4, .div5 {
	padding-right: 5px;
	padding-left: 5px;
}
.div4 {
	padding-left: 8px;
}
.div5 {
	padding-right: 8px;
}

tr.selected, tr.selected td {
	background-color: var(--colorbacklinepairchecked) !important;
}
.order td {
	color: green;
	/* background-color: #f5f5f5; */
}

.colorwhite {
	color: white;
}
.colorred {
	color: red;
}
.colorgreen {
	color: green;
}
.poscolordelete {
	color: #844;
}
.poscolorgreen {
	color: #060;
}
.poscolorblue {
	color: #006;
}

.centerinmiddle {
	position: relative;
	/* transform: translate(0,-50%);
	top: 50%; */
}
.trunc {
	white-space: nowrap;
	text-overflow: ellipsis;
	overflow: hidden;
}

p.description_content{
	padding:10px;
	margin:0px;
}
div.description_content {
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: <?php echo getDolGlobalInt('FLUXPOS_LINES_TO_SHOW', 2); ?>;
	overflow: hidden;
	padding-left: 2px;
	padding-right: 2px;
}

.header {
	margin: 0 auto;
	width: 100%;
	min-height: 52px;
	background: rgb(60,70,100);
}

.topnav-left {
	float: left;
}
.topnav-right {

}

.topnav div.login_block_other, .topnav div.login_block_user {
	max-width: unset;
	width: unset;
}
.topnav{
	background: var(--colorbackhmenu1);
	overflow: hidden;
	height: 100%;
}
.topnav .tmenu {
	display: block;
}

.topnav a{
	float: left;
	color: #f2f2f2;
	text-decoration: none;
}
.topnav .login_block_other a {
	padding: 5px 10px;
	margin-left: 4px;
	font-size: 1.3em;
}
.topnav div.login_block_user {
	display: inline-block;
	vertical-align: middle;
	line-height: 50px;
	height: 50px;
}
.userimg.atoplogin img.userphoto, .userimgatoplogin img.userphoto {
	width: 30px;
	height: 30px;
	vertical-align: middle;
}

@media screen and (max-width: 767px) {
	.topnav .login_block_other a {
		padding: 5px 5px;
		font-size: 1.2em;
	}

	.div1, .div4 {
		padding-left: 5px;
	}
	.div3, .div5 {
		padding-right: 5px;
	}
}

.topnav-right > a {
	font-size: 17px;
}

.topnav-left a {
	padding: 7px 4px 7px 4px;
	margin: 8px;
	margin-left: 5px;
	margin-right: 5px;
	border-radius: 4px;
}
.topnav-left a:hover:not(.nohover), .topnav .login_block_other a:hover:not(.nohover) {
	background-color: #ddd;
	color: black;
}

.topnav-right{
	float: right;
}

.topnav input[type="text"] {
	background-color: #fff;
	color: #000;
	float: left;
	border-bottom: none !important;
	margin-left: 6px;
	font-size: 1.3em;
	max-width: 250px;
	border-radius: 5px;
}


.login_block_other.fluxpos {
	margin-top: 5px;
}


div#moreinfo, div#infowarehouse {
	color: #aaa;
	padding: 0 8px 0 8px;
}

.basketselected {
	font-weight: bold;
	/* text-decoration: underline; */
}
.basketnotselected {
	opacity: 0.8;
}

.productprice {
	position: absolute;
	top: 5px;
	right: 5px;
	background: var(--colorbackhmenu1);
	color: var(--colortextbackhmenu);
	font-size: 2em;
	padding: 4px;
	border-radius: 2px;
	opacity: 0.9;
	padding-left: 6px;
	padding-right: 6px;
}


@media screen and (min-width: 892px) {
	.actionbutton{
		font-size: 13px;
	}
	div.description{
		font-size: 15px;
	}
	.invoice{
		font-size: 14px;
	}
}

@media (max-width: 891px) and (min-width: 386px) {
	.actionbutton{
		font-size: 12px;
	}
	div.description{
		font-size: 13px;
	}
	.invoice{
		font-size: 12px;
	}
}

@media screen and (max-width: 385px){
	.actionbutton{
		font-size: 10px;
	}
	div.description{
		font-size: 11px;
	}
	.invoice{
		font-size: 10px;
	}
}

/* For small screens */

@media screen and (max-width: 1024px) {
	.topnav input[type="text"] {
		max-width: 150px;
	}
}

@media screen and (max-width: 767px) {
	.header {
		position: sticky;
		top: 0;
		z-index: 10;
	}

	.topnav input[type="text"] {
		max-width: 90px;
		font-size: 1.15em;
	}

	.topnav-right {
		float: unset;
	}
	.header {
		height: unset;
	}
	div.container {
		overflow-x: scroll;
	}
	div.wrapper {
		width: 50%;
	}

	div#moreinfo, div#infowarehouse {
		padding: 0 5px 0 5px;
	}

	/* Mobile step-through (1.2): the POS is two steps instead of one crammed page.
	   The step navigation stays on top with the running total; the customer and the
	   carts strip stay in the header. One pane visible at a time. */
	.posmain {
		flex-direction: column;
	}

	.cartcolumn {
		flex: 0 0 45%;
		max-width: none;
		border-left: none;
		border-top: 1px solid #DDD;
	}

	.mobilenav {
		display: flex;
		flex: 0 0 auto;
		flex-direction: row;
		align-items: stretch;
		width: 100%;
		height: 52px;
		box-sizing: border-box;
		border-bottom: 1px solid #DDD;
		background-color: var(--colorbackbody, #f8f8f8);
	}

	.mobilenavtab {
		flex: 1 1 50%;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border: none;
		border-radius: 0;
		background-color: transparent;
		color: var(--colortextbackhmenu, #333);
		font-size: 1.1em;
		min-height: 52px;
		cursor: pointer;
	}

	.mobilenavtab.clicked {
		background-color: var(--colorbackhmenu1, #eee);
		font-weight: bold;
	}

	.mobilenavtab .mobilenavtotal span {
		font-weight: bold;
	}

	/* One step visible at a time */
	body.fluxposview-products .posmain .cartcolumn {
		display: none;
	}

	body.fluxposview-cart .posmain .posleft {
		display: none;
	}

	body.fluxposview-cart .posmain .cartcolumn {
		flex: 1 1 auto;
		max-width: none;
		height: auto;
		border-top: none;
		overflow: auto;
	}

	button.calcbutton, button.calcbutton2 {
		min-height: 30px;
	}

	.fluxpospay {
		font-size: 1.2em;
	}

	button.actionbutton {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		padding-left: 4px;
		padding-right: 4px;
		min-height: 30px;
	}
}

/* 1.4: the step-through of the 1.2 layout now also applies on small tablets and on
   narrow desktop windows (raised breakpoint 767 -> 1023). Below 1024px the POS is two
   steps (Products / Cart), the running total stays visible in the step navigation and
   the cart column is always cleanly visible (never squeezed off the viewport). */
@media screen and (max-width: 1023px) {
	.posmain {
		flex-direction: column;
	}

	.cartcolumn {
		flex: 0 0 45%;
		max-width: none;
		border-left: none;
		border-top: 1px solid #DDD;
	}

	.mobilenav {
		display: flex;
		flex: 0 0 auto;
		flex-direction: row;
		align-items: stretch;
		width: 100%;
		height: 52px;
		box-sizing: border-box;
		border-bottom: 1px solid #DDD;
		background-color: var(--colorbackbody, #f8f8f8);
	}

	.mobilenavtab {
		flex: 1 1 50%;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		border: none;
		border-radius: 0;
		background-color: transparent;
		color: var(--colortextbackhmenu, #333);
		font-size: 1.1em;
		min-height: 52px;
		cursor: pointer;
	}

	.mobilenavtab.clicked {
		background-color: var(--colorbackhmenu1, #eee);
		font-weight: bold;
	}

	.mobilenavtab .mobilenavtotal span {
		font-weight: bold;
	}

	body.fluxposview-products .posmain .cartcolumn {
		display: none;
	}

	body.fluxposview-cart .posmain .posleft {
		display: none;
	}

	body.fluxposview-cart .posmain .cartcolumn {
		flex: 1 1 auto;
		max-width: none;
		height: auto;
		border-top: none;
		overflow: auto;
	}
}

/* Modal box */
.modal {
  display: none; /* Hidden by default */
  position: fixed;
  z-index: 20;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgb(0,0,0);
  background-color: rgba(0,0,0,0.4);
}

/* The Close Button */
.close {
  color: #aaa;
  float: right;
  font-size: 28px;
  font-weight: bold;
}

.close:hover,
.close:focus {
  color: black;
  text-decoration: none;
  cursor: pointer;
}

.modal-header {
  padding: 2px 16px;
  background-color: #2b4161;
  color: white;
}

.modal-body {padding: 2px 16px;}

.modal-content {
  position: relative;
  background-color: #fefefe;
  margin: 15% auto; /* 15% from the top and centered */
  padding: 0;
  border: 1px solid #888;
  width: 40%;
  box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2),0 6px 20px 0 rgba(0,0,0,0.19);
  animation-name: animatetop;
  animation-duration: 0.4s;
  min-width: 200px;
}

@keyframes animatetop {
  from {top: -300px; opacity: 0}
  to {top: 0; opacity: 1}
}

.block {
  display: block;
  width: 100%;
  border: none;
  color: white;
  background-color: #8c907e;
  padding: 14px 0px;
  font-size: 16px;
  cursor: pointer;
  text-align: center;
  margin: 2px;
}

.splitsale {
	float: left;
	width: 100%;
	height: 100%;
	overflow: auto;
}

.rowsplit {
	width: 100%;
	height: 40%;
}

.headersplit {
	height: 10%;
	width: 100%;
	padding-top: 20px;
	padding-bottom: 2px;
}

.headercontent {
	margin: auto;
	width: 50%;
	border: 3px solid black;
	text-align: center;
	font-size: 150%;
	background-color: rgb(233,234,237);
}


@media only screen and (max-width: 767px)
{
	.headercontent {
		width: 80%;
	}

	.headersplit .headercontent {
		font-size: 1em;
	}
}


.row:after {
  content: "";
  display: table;
  clear: both;
}

.div5 .imgadd {
	display: none;
}

#dialogforpopupfluxpos {
	display: none;
	position: fixed;
	top: 20px;
	right: 20px;
	background: #333;
	color: #fff;
	padding: 15px 20px;
	border-radius: 8px;
	box-shadow: 0 0 10px rgba(0,0,0,0.3);
	z-index: 1000;
	font-family: sans-serif;
}

/* Offline / synchronization status pill: docked at the top of the cart column, above the
   cart lines, so it never covers the tabs, the product tiles or the payment buttons. */
.fluxposofflinestatus {
	display: none;
	position: static;
	flex: 0 0 auto;
	width: 100%;
	box-sizing: border-box;
	z-index: 1100;
	padding: 4px 10px;
	border-radius: 0;
	background-color: #e0533d;
	color: #fff;
	font-size: 13px;
	font-weight: bold;
	text-align: center;
}

.fluxposofflinestatus.offline {
	display: block;
}

.fluxposofflinestatus.pending {
	display: block;
	background-color: #e8a33d;
	cursor: pointer;
}

/* Local cart of a place, displayed while the terminal is offline, before the
 * background synchronization replays it to the server. */
.fluxposofflinecart .fluxposofflineqtybtn {
	width: 26px;
	height: 26px;
	margin: 0 4px;
	padding: 0;
	border: none;
	border-radius: 4px;
	background-color: #2f5f8f;
	color: #fff;
	font-weight: bold;
	font-size: 15px;
	cursor: pointer;
}

.fluxposofflinecart .fluxposofflineqtybtn:active {
	background-color: #244a70;
}

.fluxposofflinecart .fluxposofflineqtyval {
	display: inline-block;
	min-width: 28px;
	font-weight: bold;
}

.fluxposofflinecart .fluxposofflinelabel {
	white-space: normal;
}

.fluxposofflinenotice {
	margin: 4px 6px;
	padding: 4px 8px;
	border: 1px dashed #999999;
	border-radius: 4px;
	font-size: 12px;
}

/* Payments validated on the terminal, shown on the local cart of a validated sale,
 * before its synchronization registers them on the server. */
.fluxposofflinepayments {
	margin: 0 6px;
	font-size: 13px;
}

.fluxposofflinepayments.remaining {
	font-weight: bold;
}

.fluxposofflinepayment .fluxposofflinepaymentamount {
	font-weight: bold;
}

/* Dialog used to pay and validate a sale captured while the terminal is offline. */
.offlinepaytotal {
	font-weight: bold;
	font-size: 1.2em;
	margin-bottom: 8px;
}

.offlinepayrow {
	display: flex;
	align-items: center;
	margin-bottom: 6px;
}

.offlinepaymode {
	min-width: 110px;
}

.offlinepayamount {
	width: 130px;
}

.offlinepayreturned {
	font-weight: bold;
	margin: 6px 0;
}


@media screen and (max-width: 767px) {
	.div4 {
		height: 40px;
		width: 100%;
		float: left;
		box-sizing: border-box;
		padding-top: 2px;
		padding-bottom: 2px;
		margin-left: 2px;
	}

	.div4 .wrapper.divempty, .catwatermark {
		display: none!important;
	}

	.div4 .wrapper {
		min-width: 70px;
		padding: 2px 8px;
	}

	.div4 .wrapper .description_content {
		font-size: 12px;
	}

	.div4 .wrapper.activetab {
		border-bottom-color: var(--colorbackhmenu1) !important;
	}

	.div5 {
		padding-top: 0px;
	}

	div.description {
		position: initial;
		width: auto;
		background-color: black;
		opacity: 1;
		text-align: center;
		padding-top: 0px;
		background: -webkit-linear-gradient(top, rgba(250,250,250,0), rgba(250,250,250,0.5), rgba(250,250,250,0.95), rgba(250,250,250,1));
	}

	.div5 .description .description_content {
		font-weight: bold;
		font-size: 14px;
		padding-left: 10px;
	}

	.div5 .wrapper2 {
		width: 100%;
		display: inline-flex;
		align-items: center;
		padding: 10px;
		justify-content: normal;
	}

	.div5 .wrapper2.divempty {
		display: none;
	}

	div.wrapper2 {
		float: none;
	}

	.div5 .arrow {
		width: auto;
		height: auto;
		display: none!important;
	}

	.div5 .arrow .centerinmiddle {
		transform: translate(0, 0);
	}

	.div5 .imgadd {
		display: flex;
	}

	div.wrapper2{
		height: 40px;
	}
}


<?php
if (!getDolGlobalString('FLUXPOS_USE_ARROW_ON_NAVBAR')) {
	?>

.arrows {
	display: none;
}

	<?php
} else { ?>
.indicator {
	background: #00000042;
	padding: 15px 5px;
	cursor: pointer;
	position:absolute;
}

.indicator.left {
	left:0;
}

.indicator.right {
	right:0;
}

.indicator:hover {
	background: #000000;
}

.indicator i {
	color: white;
}

.topnav-left {
	margin-left: 20px;
}

.topnav-right {
	margin-right: 20px;
}

/* For Header Scroll */
html {
  scroll-behavior: smooth;
}

.topnav {
  scroll-behavior: smooth;
}

.header {
	height: unset;
}

.topnav {
	width: 100%;
	white-space: nowrap;
	overflow-x: scroll;
	display: inline-flex;
}

.topnav-left {
	white-space: nowrap;
	float: none;
	margin-right: auto;
	align-items: center;
}

.topnav-right {
	display: flex;
	white-space: nowrap;
	float: none;
	align-items: center;
}

.topnav-left #shoppingcart {
	display:inline-flex;
}

.topnav-right .login_block_other {
	display: flex;
	white-space: nowrap;
}

::-webkit-scrollbar {
  width: 8px;
}


::-webkit-scrollbar-track {
  background: #f1f1f1;
}


::-webkit-scrollbar-thumb {
  background: #888;
}

.topnav::-webkit-scrollbar-track{
  background: #eeeeee;
}

.topnav::-webkit-scrollbar{
  width: 1px;
  background: #F5F5F5;
}

.topnav::-webkit-scrollbar-thumb{
	background: #f9171700;
}

.topnav.overflow .arrows {
	display: flex;
}

<?php } ?>

/* ========================================================================
   Layout 1.1 (Proposal A) — component styling.
   Fluid product grid, tile density S/M/L, right cart column with sticky
   total, payment block and quick actions, contextual and docked numpad.
   Minimum supported screen: 1280 x 800.
   ======================================================================== */

body.bodyfluxpos {
	--fluxpos-tile-w: 160px;	/* default = density M */
	--fluxpos-tile-h: 140px;
}
body.fluxpos-tile-S {
	--fluxpos-tile-w: 120px;
	--fluxpos-tile-h: 110px;
}
body.fluxpos-tile-M {
	--fluxpos-tile-w: 160px;
	--fluxpos-tile-h: 140px;
}
body.fluxpos-tile-L {
	--fluxpos-tile-w: 220px;
	--fluxpos-tile-h: 180px;
}

/* Fluid product grid: the whole category renders at once, the area scrolls */
.productgrid {
	display: grid;
	/* 1.4: min(var(--fluxpos-tile-w), 100%) makes the minimum track size shrink to the
	   available width, so a tile column can never be wider than the products area
	   (canonical fix for a last-column clip on narrow screens) */
	grid-template-columns: repeat(auto-fill, minmax(min(var(--fluxpos-tile-w), 100%), 1fr));
	gap: 8px;
	align-content: start;
	width: 100%;
}

.productgrid .wrapper2 {
	float: none;
	position: relative;
	width: auto;
	/* 1.4: a long product name may not blow the track out of the grid */
	min-width: 0;
	overflow: hidden;
	height: var(--fluxpos-tile-h);
	margin: 0;
	border: 1px solid #EEE;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
}

/* 1.4: long names wrap (or break) inside the tile instead of pushing the grid wider */
.productgrid .description_content {
	overflow-wrap: anywhere;
	word-break: break-word;
}

.productgrid .wrapper2 img.imgwrapper {
	max-width: 100%;
	max-height: calc(100% - 26px);
}

.productgrid .wrapper2:hover {
	border-color: var(--colorbackhmenu1, #0080c0);
}

.productgrid .fluxposgridempty {
	grid-column: 1 / -1;
	padding: 30px 10px;
	text-align: center;
	font-size: 15px;
}

/* Tile density control (S/M/L) on the tab row, right after the tabs, sitting a bit lower */
.tiledensity {
	display: inline-flex;
	float: none;
	flex: 0 0 auto;
	align-self: flex-end;
	margin: auto 8px 3px 8px;
	border: 1px solid #DDD;
	border-radius: 6px;
	overflow: hidden;
	background-color: #fff;
}
.tiledensity button {
	border: none;
	background-color: #fff;
	color: #444;
	font-size: 12px;
	font-weight: bold;
	padding: 4px 10px;
	cursor: pointer;
	line-height: 1.2;
}
.tiledensity button + button {
	border-left: 1px solid #DDD;
}
.tiledensity button.clicked {
	background-color: var(--colorbackhmenu1, #0080c0);
	color: #fff;
}

/* Search input shown inside the grid when the head bar is hidden */
.gridheadsearch {
	display: flex;
	align-items: center;
	padding: 0 8px 6px 8px;
}
.gridheadsearch input[type="text"] {
	flex: 1 1 auto;
	min-width: 0;
}
.gridheadsearch a {
	cursor: pointer;
	padding: 4px 6px;
}

/* Cart column ---------------------------------------------------------- */
.cartlines {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	overflow-x: hidden;
	box-sizing: border-box;
}

.cartfooter {
	flex: 0 0 auto;
	padding: 6px 8px 8px 8px;
	background-color: #fff;
	border-top: 1px solid #DDD;
}

.carttotal {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	padding: 2px 2px 6px 2px;
}
.carttotal.emptycart .carttotalvalue {
	opacity: 0.4;
}
.carttotallabel {
	font-size: 14px;
	font-weight: bold;
	color: #333;
}
.carttotalvalue {
	font-size: 22px;
	font-weight: bold;
	color: #222;
}

.paymentblock {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 4px;
	margin-bottom: 6px;
}
button.paymentmode {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	width: 100%;
	min-height: 44px;
	padding: 4px 6px;
	border: 1px solid #DDD;
	border-radius: 6px;
	background-color: #8c907e;
	color: #fff;
	font-size: 14px;
	font-weight: bold;
	cursor: pointer;
	box-sizing: border-box;
}
button.paymentmode:hover {
	background-color: #75796a;
}
.paymentmodelabel {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.cartquick {
	display: flex;
	gap: 4px;
}
button.cartquickbtn {
	flex: 1 1 auto;
	min-height: 40px;
	min-width: 0;
	padding: 4px 6px;
	border: none;
	border-radius: 6px;
	background-color: #8c907e;
	color: #fff;
	font-size: 13px;
	font-weight: bold;
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
button.cartquickbtn:hover {
	background-color: #75796a;
}
button.cartquickbtn.cartmore {
	flex: 0 0 44px;
}

/* Docked numberpad (FLUXPOS_DISPLAY_NUMBERPAD = 'always') */
.numpaddocked {
	flex: 0 0 auto;
	padding-top: 6px;
}

/* Line-edit pad: shared by the contextual modal and the docked variant.
   Fixed grid geometry: a modal has no intrinsic height so the historical
   height: calc(25% - 2px) of the numpad buttons cannot work there. */
.lineeditpad {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 4px;
	align-content: start;
}
.lineeditpad button.calcbutton,
.lineeditpad button.calcbutton2,
.lineeditpad button.calcbutton3 {
	width: 100%;
	height: 58px;
	margin: 0;
}
.lineeditpad.numpaddocked button.calcbutton,
.lineeditpad.numpaddocked button.calcbutton2,
.lineeditpad.numpaddocked button.calcbutton3 {
	height: 62px;
}
.modal .lineeditpad {
	margin-top: 6px;
}

/* 1.4: the line-edit popup carries only digits, a backspace and one full-width OK */
.lineeditpad button.lineeditok {
	grid-column: 1 / -1;
	height: 52px;
	font-size: 1.5em;
	font-weight: bold;
	background-color: var(--colorbackhmenu1, #0080c0);
	border-color: var(--colorbackhmenu1, #0080c0);
	color: #fff;
}
.lineeditpad button.lineeditok:hover {
	opacity: 0.9;
}

/* Cart line visibility: thumbs and qty steppers ---------- */
img.cartthumb {
	width: 36px;
	height: 36px;
	object-fit: cover;
	border-radius: 4px;
	margin-right: 6px;
	vertical-align: middle;
	background-color: #f3f3f3;
}

button.fluxposqtybtn {
	display: inline-block;
	width: 24px;
	height: 24px;
	line-height: 22px;
	padding: 0;
	margin: 0 4px;
	text-align: center;
	font-weight: bold;
	font-size: 15px;
	border: 1px solid #BBB;
	border-radius: 4px;
	background-color: #fff;
	color: #222;
	cursor: pointer;
}
button.fluxposqtybtn:hover {
	background-color: #e8e8e8;
}

/* 1.3: the qty value between the +/- steppers is centered in its own inline box (the
 * plain text value was a little off against the buttons) and the discount and qty cells
 * are clickable: they open the line-edit popup on the matching mode */
.fluxposqtyvalue {
	display: inline-block;
	min-width: 2.4em;
	text-align: center;
	vertical-align: middle;
	font-weight: bold;
}
td.linecoledit {
	cursor: pointer;
}
td.linecoledit:hover {
	background-color: #e8e8e8;
}

/* 1.3: the cart footer as ONE row (FLUXPOS_PAYMENT_BLOCK<term>): the payment block
 * takes the flex layout of the quick row and every button shares the row width */
.paymentblock.cartquick {
	display: flex;
	flex-wrap: wrap;
	align-items: stretch;
	gap: 4px;
	grid-template-columns: none;
}
.paymentblock.cartquick > button {
	flex: 1 1 auto;
	min-width: 0;
	width: auto;
}

/* Preselected payment mode on pay.php (opened from the cart block) */
button.payvalidatepreselect {
	outline: 3px solid var(--colorbackhmenu1, #0080c0);
	outline-offset: 1px;
}
