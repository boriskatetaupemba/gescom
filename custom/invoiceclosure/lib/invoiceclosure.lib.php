<?php
/* Copyright (C) 2026 InvoiceClosure module
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
 * \file    custom/invoiceclosure/lib/invoiceclosure.lib.php
 * \ingroup invoiceclosure
 * \brief   Library of shared functions for the InvoiceClosure module.
 */

/**
 * Prepare the head array of the admin tabs of the module.
 *
 * @return array Head array with tabs
 */
function invoiceclosureAdminPrepareHead()
{
	global $langs, $conf;

	$langs->loadLangs(array('invoiceclosure@invoiceclosure'));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/invoiceclosure/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/invoiceclosure/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'invoiceclosure@invoiceclosure');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'invoiceclosure@invoiceclosure', 'remove');

	return $head;
}
