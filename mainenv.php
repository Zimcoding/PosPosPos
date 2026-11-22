<?php
/* Copyright (C) 2026		Zimcoding
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
 *	\file       htdocs/fluxpos/mainenv.php
 *	\ingroup    fluxpos
 *	\brief      Load the Dolibarr environment (main.inc.php) whatever the installation
 *				directory of this module.
 *
 *				A page must include this file instead of requiring main.inc.php with a
 *				relative path: a path like '../../main.inc.php' only worked when the module
 *				was installed into htdocs/fluxpos. The module is placement independent
 *				(htdocs/fluxpos or htdocs/custom/fluxpos or any other directory inside
 *				htdocs), so the main.inc.php is looked for here: at the PHP document root
 *				then going up the directory tree of this file.
 *
 *				Define the NOREQUIRE* constants of the calling page BEFORE including
 *				this file, exactly like before a require of main.inc.php.
 */

$res = 0;

// Try main.inc.php into the web root known from the server (standard setups)
if (!$res && !empty($_SERVER['DOCUMENT_ROOT'])) {
	$docroot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
	if ($docroot && file_exists($docroot.'/main.inc.php')) {
		require_once $docroot.'/main.inc.php';
		$res = 1;
	}
}

// Try main.inc.php going up the directory tree of this file (aliases, docroot above
// htdocs, or any nonstandard layout: the module and the environment are on the same tree)
if (!$res) {
	$dir = __DIR__;
	for ($i = 0; $i < 5 && !$res; $i++) {
		if (file_exists($dir.'/main.inc.php')) {
			require_once $dir.'/main.inc.php';
			$res = 1;
		}
		$dir = dirname($dir);
	}
}

if (!$res) {
	die('Include of main fails: the FluxPos module directory must stay inside the Dolibarr htdocs directory (htdocs/fluxpos or htdocs/custom/fluxpos).');
}

/**
 * Return the web URL of the root directory of the module, with a trailing slash.
 *
 *	The URL is derived from the location of the page being served, so it always
 * points to the copy of the module that runs, whatever its placement (htdocs/fluxpos,
 * htdocs/custom/fluxpos, ...) and whatever the Dolibarr version. Do not use
 * dol_buildpath('/fluxpos/', 1) for this: dol_buildpath() resolves the custom
 * path by testing the existence of files on disk, so the URL of a directory
 * (used by js/offline11.js as the base URL of the module) is not resolved and
 * falls back to the url of the core copy.
 *
 * Must be called after this file has been included (DOL_URL_ROOT is defined).
 *
 * @return string	URL of the module root with a trailing slash (eg. '/custom/fluxpos/')
 */
function fluxposUrlRoot()
{
	$urlroot = defined('DOL_URL_ROOT') ? DOL_URL_ROOT : '';
	if (empty($_SERVER['SCRIPT_NAME'])) {
		return $urlroot.'/fluxpos/';
	}
	$url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));	// .../fluxpos or .../fluxpos/ajax

	// A page served from a subdirectory of the module: go back to the module root
	$url = preg_replace('@/(ajax|admin|css|genimg|public)$@i', '', $url);

	// Safety net when the served page is not inside the module directory
	if (substr($url, -8) != '/fluxpos') {
		if (defined('DOL_DOCUMENT_ROOT') && strpos(DOL_DOCUMENT_ROOT, dirname($url)) === 0) {
			$url .= '/fluxpos';
		} else {
			$url = $urlroot.'/fluxpos';
		}
	}

	return $url.'/';
}

// Compatibility helpers with Dolibarr versions lower than 23.0.
// The ALNE (Archiving Legal Numbering Engine) helpers are only defined by Dolibarr 23+ and are
// only meaningful there (they check whether the new French e-invoicing / certified archiving
// layer is active). On Dolibarr 22 or lower these features do not exist, so the safe value is
// false. Defining the shims here keeps pages using them (receipt.php, pay.php, admin pages, the
// receipt printer class) from dying with a "Call to undefined function" fatal error.
if (! function_exists('isALNERunningVersion')) {
	/**
	 * Say whether the Dolibarr ALNE e-invoicing layer is running.
	 *
	 * @return bool			False on Dolibarr versions lower than 23.0 (feature does not exist yet).
	 */
	function isALNERunningVersion()
	{
		return false;
	}
}

if (! function_exists('isALNEQualifiedVersion')) {
	/**
	 * Say whether the Dolibarr ALNE e-invoicing layer is qualified.
	 *
	 * @return bool			False on Dolibarr versions lower than 23.0 (feature does not exist yet).
	 */
	function isALNEQualifiedVersion()
	{
		return false;
	}
}

