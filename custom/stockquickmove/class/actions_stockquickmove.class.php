<?php
/* Copyright (C) 2026 StockQuickMove module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/stockquickmove/class/actions_stockquickmove.class.php
 * \ingroup stockquickmove
 * \brief   Hook actions for the stock movement list.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook actions provided by StockQuickMove.
 */
class ActionsStockQuickMove extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

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
	 * Add the quick movement action to the native stock movement list.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to keep the standard actions
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';

		$currentcontext = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];
		if (strpos(':'.$currentcontext.':', ':stockmovementlist:') === false) {
			return 0;
		}
		if (!isModEnabled('stockquickmove') || !empty($user->socid) || !$user->hasRight('stock', 'mouvement', 'creer')) {
			return 0;
		}

		$langs->load('stockquickmove@stockquickmove');
		$url = dol_buildpath('/stockquickmove/quickmovement.php', 1);
		print dolGetButtonAction(
			$langs->trans('StockQuickMoveButton'),
			'',
			'default',
			$url,
			'stockquickmove-quickmovement',
			true
		);

		return 0;
	}
}
