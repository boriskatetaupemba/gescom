<?php
/* Copyright (C) 2026 PosNova module
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
 * \file    custom/posnova/class/actions_posnova.class.php
 * \ingroup posnova
 * \brief   Hooks: keep POS invoices out of the standard customer-invoice list,
 *          and flag them clearly on the invoice card.
 */

/**
 * Hook handler for the PosNova module.
 */
class ActionsPosNova
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var array */
	public $errors = array();
	/** @var array Results to output */
	public $results = array();
	/** @var string String to output */
	public $resprints;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Append a WHERE clause hiding POS invoices from the standard invoice list.
	 *
	 * @param  array        $parameters  Hook parameters (incl. 'context')
	 * @param  CommonObject $object      Current object
	 * @param  string       $action      Current action
	 * @param  HookManager  $hookmanager Hook manager
	 * @return int                       0 to continue
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		$contexts = explode(':', $parameters['context']);
		if (in_array('invoicelist', $contexts, true) && getDolGlobalInt('POSNOVA_HIDE_FROM_INVOICE_LIST', 1)) {
			$this->resprints = " AND (f.module_source IS NULL OR f.module_source <> 'posnova')";
		}
		return 0;
	}

	/**
	 * Show a banner on the invoice card when the invoice originates from a POS terminal.
	 *
	 * @param  array        $parameters  Hook parameters (incl. 'context')
	 * @param  CommonObject $object      Current invoice
	 * @param  string       $action      Current action
	 * @param  HookManager  $hookmanager Hook manager
	 * @return int                       0 to continue
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', $parameters['context']);
		if (in_array('invoicecard', $contexts, true) && is_object($object) && !empty($object->module_source) && $object->module_source === 'posnova') {
			$langs->load('posnova@posnova');
			$this->resprints = '<tr><td>'.$langs->trans('PosNovaOrigin').'</td><td><span class="badge badge-status4 badge-status">'.$langs->trans('PosNovaPointOfSale').'</span></td></tr>';
		}
		return 0;
	}
}
