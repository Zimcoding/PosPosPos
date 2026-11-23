<?php
/* Copyright (C) 2004-2018 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2018 SuperAdmin
 * Copyright (C) 2024-2026  Frédéric France             <frederic.france@free.fr>
 * Copyright (C) 2026       FluxPos contributors
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
 * 	\defgroup   fluxpos     Module FluxPos
 *  \brief      FluxPos module descriptor.
 *
 *  \file       htdocs/fluxpos/core/modules/modFluxPos.class.php
 *  \ingroup    fluxpos
 *  \brief      Description and activation file for the module FluxPos
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
include_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';


/**
 *  Class to describe and enable module FluxPos
 */
class modFluxPos extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 94410;
		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'fluxpos';

		// Family can be 'crm','financial','hr','projects','products','ecm','technic','interface','other'
		// It is used to group modules by family in module setup page
		$this->family = "portal";
		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '46';
		// Gives the possibility to the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));

		// Module label (no space allowed), used if translation string 'ModuleFluxPosName' not found.
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		// Module description, used if translation string 'ModuleFluxPosDesc' not found.
		$this->description = "Point of sales module (Touch Screen POS)";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "Point Of Sales (compliant with touch screen), offline capable";

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
		$this->version = '1.6.0';
		// Key used in llx_const table to save module status enabled/disabled (where FLUXPOS is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		$this->picto = 'cash-register';

		// Defined all module parts (triggers, login, substitutions, menus, css, etc...)
		// for default path (eg: /fluxpos/core/xxxxx) (0=disable, 1=enable)
		// for specific path of parts (eg: /fluxpos/core/modules/barcode)
		// for specific css file (eg: /fluxpos/css/fluxpos.css.php)
		$this->module_parts = array(
			'triggers' => 0, // Set this to 1 if module has its own trigger directory (core/triggers)
			'login' => 0, // Set this to 1 if module has its own login method file (core/login)
			'substitutions' => 0, // Set this to 1 if module has its own substitution function file (core/substitutions)
			'menus' => 0, // Set this to 1 if module has its own menus handler directory (core/menus)
			'theme' => 0, // Set this to 1 if module has its own theme directory (theme)
			'tpl' => 0, // Set this to 1 if module overwrite template dir (core/tpl)
			'barcode' => 0, // Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'models' => 1, // Set this to 1 if module has its own models directory (here core/modules/fluxpos, scanned for numbering models)
			'hooks' => array() 	// Set here all hooks context managed by module.
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/fluxpos/temp","/fluxpos/subdir");
		$this->dirs = array();

		// Config pages. Put here list of php page, stored into fluxpos/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@fluxpos");

		// Dependencies
		$this->hidden = false; // A condition to hide module
		// List of module class names as string that must be enabled if this module is enabled.
		$this->depends = array('always'=>array("modBanque", "modFacture", "modProduct", "modCategorie"), 'FR'=>array('modBlockedLog'));
		$this->requiredby = array(); // List of module ids to disable if this one is disabled
		$this->conflictwith = array('modTakePos'); // This module replaces the core TakePOS module
		$this->langfiles = array("fluxpos@fluxpos");
		$this->phpmin = array(7, 4); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(16, 0); // Minimum version of Dolibarr required by module
		$this->warnings_activation = array('FR' => array('WarningNoteModulePOSForFrenchLaw', 'WarningNoteModulePOSForFrenchLaw2', 'WarningNoteModulePOSForFrenchLaw3')); // Warning to show when we activate module.
		$this->warnings_activation_ext = array();

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		$this->const = array(
			//1=>array('FLUXPOS_MYCONSTANT', 'chaine', 'avalue', 'This is a constant to add', 1, 'allentities', 1)
		);

		// To avoid warning
		if (!isModEnabled('fluxpos')) {
			$conf->fluxpos = new stdClass();
			$conf->fluxpos->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		$this->cronjobs = array();

		// Permissions
		$this->rights = array(); // Permission array used by this module

		$r = 0;

		$r++;
		$this->rights[$r][0] = 94411;
		$this->rights[$r][1] = 'Use Point Of Sale (record a sale, add products, record payment)';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'run';

		$r++;
		$this->rights[$r][0] = 94412;
		$this->rights[$r][1] = 'Can modify added sales lines (prices, discount)';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'editlines';

		$r++;
		$this->rights[$r][0] = 94413;
		$this->rights[$r][1] = 'Edit ordered sales lines (useful only when option "Order printers" has been enabled). Allow to edit sales lines even after the order has been printed';
		$this->rights[$r][2] = 'a';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'editorderedlines';

		// Main menu entries
		$this->menu = array(); // List of menus to add
		$r = 0;

		$this->menu[$r++] = array('fk_menu'=>'', // '' if this is a top menu.
			'type'=>'top', // This is a Top menu entry
			'titre'=>'PointOfSaleShort',
			'mainmenu'=>'fluxpos',
			'leftmenu'=>'',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'url'=>'/fluxpos/index.php',
			'langs'=>'fluxpos@fluxpos', // Lang file to use (without .lang) by module.
			'position'=>1000 + $r,
			'enabled'=>'isModEnabled("fluxpos")', // Define condition to show or hide menu entry.
			'perms'=>'$user->hasRight("fluxpos", "run")',
			'target'=>'fluxpos',
			'user'=>2); // 0=Menu for internal users, 1=external users, 2=both
	}

	/**
	 *	Function called when module is enabled.
	 *	The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *	It also creates data directories
	 *
	 *	@param      string	$options    Options when enabling module ('', 'noboxes')
	 *	@return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs, $user, $mysoc;

		$langs->load("fluxpos@fluxpos");

		dolibarr_set_const($this->db, "FLUXPOS_PRINT_METHOD", "browser", 'chaine', 0, '', $conf->entity);

		// --- Migration from an existing TakePOS installation -------------------
		// Import every legacy TAKEPOS_* / CASHDESK_* constant into its FLUXPOS_*
		// equivalent (prefix per key: TAKEPOS_(len 8) and CASHDESK_(len 9)) when
		// the matching new constant does not exist yet for that entity.
		$this->importLegacyTakePosConstants();

		// Default customer for Point of sale
		if (!getDolGlobalInt('FLUXPOS_ID_THIRDPARTY1')) {	// If a customer has already been set into the FluxPos setup page
			$societe = new Societe($this->db);
			$nametouse = $langs->trans("DefaultPOSThirdLabel");

			$searchcompanyid = $societe->fetch(0, $nametouse);
			if ($searchcompanyid == 0) {
				$societe->name = $nametouse;
				$societe->client = 1;
				$societe->code_client = '-1';
				$societe->code_fournisseur = '-1';
				$societe->country_id = $mysoc->country_id ? $mysoc->country_id : 1; // By default we consider the default customer is in the same country than the company
				$societe->note_private = "Default customer automatically created by Point Of Sale module activation. Can be used as the default generic customer in the Point Of Sale setup. Can also be edited or removed if you don't need a generic customer.";

				$searchcompanyid = $societe->create($user);
			}
			if ($searchcompanyid > 0) {
				// We already have or we have create a thirdparty with id = $searchcompanyid, so we link use it into setup
				dolibarr_set_const($this->db, "FLUXPOS_ID_THIRDPARTY1", $searchcompanyid, 'chaine', 0, '', $conf->entity);
			} else {
				setEventMessages($societe->error, $societe->errors, 'errors');
			}
		}

		// Create product category FluxPosCatLabel if not exists
		$categories = new Categorie($this->db);
		$cate_arbo = $categories->get_full_arbo('product', 0, 1);
		if (is_array($cate_arbo)) {
			if (!count($cate_arbo) || (!getDolGlobalString('FLUXPOS_ROOT_CATEGORY_ID') || getDolGlobalString('FLUXPOS_ROOT_CATEGORY_ID') == '-1')) {
				$category = new Categorie($this->db);

				$category->label = $langs->trans("DefaultPOSCatLabel");
				$category->type = Categorie::TYPE_PRODUCT;

				$result = $category->create($user);

				if ($result > 0) {
					dolibarr_set_const($this->db, 'FLUXPOS_ROOT_CATEGORY_ID', $result, 'chaine', 0, 'Id of category for products visible in FluxPos', $conf->entity);
				} else {
					setEventMessages($category->error, $category->errors, 'errors');
				}
			}
		}

		// Create cash account CASH-POS / DefaultCashPOSLabel if not exists
		if (!getDolGlobalInt('FLUXPOS_ID_BANKACCOUNT_CASH1')) {
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
			$cashaccount = new Account($this->db);
			$searchaccountid = $cashaccount->fetch(0, "CASH-POS");
			if ($searchaccountid == 0) {
				$cashaccount->ref = "CASH-POS";
				$cashaccount->label = $langs->trans("DefaultCashPOSLabel");
				$cashaccount->courant = Account::TYPE_CASH; // deprecated
				$cashaccount->type = Account::TYPE_CASH;
				$cashaccount->country_id = $mysoc->country_id ? $mysoc->country_id : 1;
				$cashaccount->date_solde = dol_now();
				$idjournal = dol_getIdFromCode($this->db, 'BQ', 'accounting_journal', 'code', 'rowid');
				$cashaccount->fk_accountancy_journal = (int) $idjournal;
				$searchaccountid = $cashaccount->create($user);
			}
			if ($searchaccountid > 0) {
				dolibarr_set_const($this->db, "FLUXPOS_ID_BANKACCOUNT_CASH1", $searchaccountid, 'chaine', 0, '', $conf->entity);
			} else {
				setEventMessages($cashaccount->error, $cashaccount->errors, 'errors');
			}
		}

		$result = $this->_load_tables('/fluxpos/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Migration: import floor plan data from a legacy TakePOS installation
		$this->importLegacyFloorTables();

		// Clean before activation
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param      string	$options    Options when enabling module ('', 'noboxes')
	 *	@return     int             	1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}

	/**
	 * Migrate settings from a legacy TakePOS installation: for every taking
	 * TAKEPOS_* or CASHDESK_* row of llx_const of any entity, create the
	 * FLUXPOS_* equivalent with the same value, type and entity, unless the
	 * new constant already exists (never overwrite user settings).
	 *
	 * @return void
	 */
	private function importLegacyTakePosConstants()
	{
		$sql = "SELECT name, value, type, entity, note FROM ".$this->db->prefix()."const";
		$sql .= " WHERE (name LIKE 'TAKEPOS\\_%' OR name LIKE 'CASHDESK\\_%')";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$count = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			if (strpos($obj->name, 'TAKEPOS_') === 0) {
				$newname = 'FLUXPOS_'.substr($obj->name, 8);
			} else {
				$newname = 'FLUXPOS_'.substr($obj->name, 9);
			}

			// Never overwrite an existing value of the new constant
			$sqlcheck = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."const";
			$sqlcheck .= " WHERE name = '".$this->db->escape($newname)."' AND entity = ".((int) $obj->entity);
			$rescheck = $this->db->query($sqlcheck);
			if ($rescheck) {
				$objcheck = $this->db->fetch_object($rescheck);
				$this->db->free($rescheck);
				if ($objcheck && $objcheck->nb > 0) {
					continue;
				}
			}

			dolibarr_set_const($this->db, $newname, $obj->value, $obj->type, 0, 'Migrated from legacy TakePOS constant '.$obj->name, $obj->entity);
			$count++;
		}
		$this->db->free($resql);

		if ($count > 0) {
			dol_syslog(get_class($this)."::importLegacyTakePosConstants migrated ".$count." legacy constant(s)", LOG_INFO);
		}
	}

	/**
	 * Migrate the floor plan from a legacy TakePOS installation: copy rows of
	 * llx_takepos_floor_tables into llx_fluxpos_floor_tables when the legacy
	 * table exists and the new table is still empty.
	 *
	 * @return void
	 */
	private function importLegacyFloorTables()
	{
		$sqlcheck = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."fluxpos_floor_tables";
		$rescheck = $this->db->query($sqlcheck);
		if (!$rescheck) {
			return; // New table does not exist (install error reported by _load_tables)
		}
		$objcheck = $this->db->fetch_object($rescheck);
		$this->db->free($rescheck);
		if (!$objcheck || $objcheck->nb > 0) {
			return; // New table already has data (or cannot be read)
		}

		$sqlinsert = "INSERT INTO ".$this->db->prefix()."fluxpos_floor_tables (entity, label, leftpos, toppos, floor)";
		$sqlinsert .= " SELECT entity, label, leftpos, toppos, floor FROM ".$this->db->prefix()."takepos_floor_tables";

		$resql = $this->db->query($sqlinsert, 1);
		if (!$resql) {
			// Legacy table does not exist: this is not a TakePOS upgrade, nothing to migrate
			return;
		}
		dol_syslog(get_class($this)."::importLegacyFloorTables imported legacy floor tables", LOG_INFO);
		$this->db->free($resql);
	}
}
