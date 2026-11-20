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
