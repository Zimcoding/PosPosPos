<?php
/* Copyright (C) - 2020	Andreu Bisquerra Gaya <jove@bisquerra.com>
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
 *       \file       htdocs/fluxpos/public/auto_order.php
 *       \ingroup    fluxpos
 *       \brief      This is the entry page called by customers to maje order. This page will redirect to phone page or img.
 */

if (!defined("NOLOGIN")) {
	define("NOLOGIN", '1'); // If this page is public (can be called outside logged session)
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1'); // Do not check IP defined into conf $dolibarr_main_restrict_ip
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// Load Dolibarr environment
require_once __DIR__.'/../mainenv.php';

if (!getDolGlobalString('FLUXPOS_AUTO_ORDER')) {
	accessforbidden('Auto order is not allowed'); // If Auto Order is disabled never allow access to this page (that is a NO LOGIN access)
}

$_SESSION["basiclayout"] = 1;	// For the simple layout for public submission
$_SESSION["fluxposterminal"] = getDolGlobalInt('FLUXPOS_TERMINAL_NB_FOR_PUBLIC', 1);	// Default terminal for public submission is 1

define('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE', 1);
if (GETPOSTISSET("mobilepage")) {
	require dol_buildpath('/fluxpos/invoice.php');	// The section with list of selected products in order
} else {
	require dol_buildpath('/fluxpos/phone.php');
}
