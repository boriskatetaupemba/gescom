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
 * \file    custom/posnova/core/lib/posnova.lib.php
 * \ingroup posnova
 * \brief   Shared library (admin tab heads).
 */

/**
 * Build the tab head for the PosNova administration pages.
 *
 * @return array Head array for dol_get_fiche_head()
 */
function posnovaAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("posnova@posnova");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/custom/posnova/admin/setup.php', 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/posnova/admin/pos_list.php', 1);
	$head[$h][1] = $langs->trans("PosNovaTerminals");
	$head[$h][2] = 'terminals';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'posnova@posnova');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'posnova@posnova', 'remove');

	return $head;
}
