<?php
/* Copyright (C) 2009       Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2022       Alexandre Spangaro  <aspangaro@open-dsi.fr>
 * Copyright (C) 2024		MDW					<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026       Jose Martinez       <jose.martinez@pichinov.com>
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
 * or see https://www.gnu.org/
 */

/**
 *	    \file       htdocs/fluxpos/lib/fluxpos.lib.php
 *		\brief      Library file with function for FluxPos module
 */

/**
 * Prepare array with list of tabs
 *
 * @return	array<array{0:string,1:string,2:string}>	Array of tabs to show
 */
function fluxpos_admin_prepare_head()
{
	global $langs, $conf;

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/fluxpos/admin/setup.php', 1);
	$head[$h][1] = $langs->trans("Parameters");
	$head[$h][2] = 'setup';
	$h++;

	$head[$h][0] = dol_buildpath('/fluxpos/admin/appearance.php', 1);
	$head[$h][1] = $langs->trans("Appearance");
	$head[$h][2] = 'appearance';
	$h++;

	$head[$h][0] = dol_buildpath('/fluxpos/admin/receipt.php', 1);
	$head[$h][1] = $langs->trans("Printers").' / '.$langs->trans("Receipt");
	$head[$h][2] = 'receipt';
	$h++;

	$head[$h][0] = dol_buildpath('/fluxpos/admin/bar.php', 1);
	$head[$h][1] = $langs->trans("BarRestaurant");
	$head[$h][2] = 'bar';
	$h++;

	$numterminals = max(1, getDolGlobalInt('FLUXPOS_NUM_TERMINALS', 1));
	for ($i = 1; $i <= $numterminals; $i++) {
		$label = getDolGlobalString('FLUXPOS_TERMINAL_NAME_'.$i, $langs->trans("TerminalName", $i));
		if (!fluxposTerminalIsEnabled($i)) {
			// A disabled terminal is greyed out but still listed, so it can be enabled again
			$label = '<span class="opacitymedium">'.$label.' ('.$langs->trans("Disabled").')</span>';
		}
		$head[$h][0] = dol_buildpath('/fluxpos/admin/terminal.php', 1).'?terminal='.$i;
		$head[$h][1] = $label;
		$head[$h][2] = 'terminal'.$i;
		$h++;
	}

	/*
	$head[$h][0] = dol_buildpath('/fluxpos/admin/other.php', 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'other';
	$h++;
	*/

	complete_head_from_modules($conf, $langs, null, $head, $h, 'fluxpos_admin');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'fluxpos_admin', 'remove');

	return $head;
}

/**
 * Tell if a FluxPos terminal can be used.
 *
 * A terminal is enabled by default: the constant is only recorded when the terminal is
 * explicitly disabled, so existing setups keep all their terminals available.
 * Disabling a terminal only hides it from the terminal selection: its past invoices
 * and its reference counter are left untouched.
 *
 * @param	int		$terminal	Terminal number (1 to FLUXPOS_NUM_TERMINALS)
 * @return	bool				True if the terminal can be used
 */
function fluxposTerminalIsEnabled($terminal)
{
	return !getDolGlobalInt('FLUXPOS_TERMINAL_DISABLED_'.((int) $terminal));
}

/**
 * Return the list of the FluxPos terminals that can be used.
 *
 * @return	int[]	Array of terminal numbers that are not disabled
 */
function fluxposEnabledTerminals()
{
	$result = array();
	$numterminals = max(1, getDolGlobalInt('FLUXPOS_NUM_TERMINALS', 1));
	for ($i = 1; $i <= $numterminals; $i++) {
		if (fluxposTerminalIsEnabled($i)) {
			$result[] = $i;
		}
	}
	return $result;
}
