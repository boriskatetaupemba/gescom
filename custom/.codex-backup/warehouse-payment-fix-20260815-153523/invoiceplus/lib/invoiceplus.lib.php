<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/lib/invoiceplus.lib.php
 * \ingroup invoiceplus
 * \brief   Shared InvoicePlus UI helpers.
 */

/**
 * Prepare InvoicePlus administration tabs.
 *
 * @return array Head array
 */
function invoiceplusAdminPrepareHead()
{
	global $conf, $langs;

	$langs->loadLangs(array('invoiceplus@invoiceplus'));

	$head = array();
	$h = 0;
	$head[$h][0] = dol_buildpath('/invoiceplus/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;
	$head[$h][0] = dol_buildpath('/invoiceplus/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'invoiceplus@invoiceplus');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'invoiceplus@invoiceplus', 'remove');

	return $head;
}
