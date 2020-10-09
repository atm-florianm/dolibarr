<?php
/**
 * Copyright (C) - ATM Consulting 2020
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

$res = 0;
if (file_exists("../main.inc.php")) {
	$res = include "../main.inc.php"; // From htdocs directory
} elseif (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php"; // From "custom" directory
} else {
	die("Include of main fails");
}

require_once 'class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/actions_ticket.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticketstats.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/dolgraph.class.php';

// Load traductions files requiredby by page
$langs->load("companies");
$langs->load("other");
$langs->load("ticket@ticket");

$WIDTH = DolGraph::getDefaultGraphSizeForStats('width');
$HEIGHT = DolGraph::getDefaultGraphSizeForStats('height');

// Year
$nowyear = strftime("%Y", dol_now());
$startyear = $year - 1;

// Get parameters
$id = GETPOST('id', 'int');
$msg_id = GETPOST('msg_id', 'int');
$action = GETPOST('action', 'aZ09');

if ($user->societe_id) {
	$socid = $user->societe_id;
}

// Security check
$result = restrictedArea($user, 'ticket', 0, '', '', '', '');

$object = new ActionsTicket($db);
$stats = new TicketStats($db, $socid, $userid);
$form = new Form($db);

/*******************************************************************
 * ACTIONS
 *
 * Put here all code to do according to value of "action" parameter
 ********************************************************************/



/***************************************************
 * PAGE
 *
 * Put here all code to build page
 ****************************************************/

llxHeader('', $langs->trans('Ticket'), '');

print '<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.js"></script>';
print '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.19/css/jquery.dataTables.css">';
print '<div class="fichecenter"><div class="fichethirdleft">';
print '<h3 id="NONASSIGNES">Tickets à prendre en charge</h3>';

/*
 * Derniers tickets non attribués
 */
$max = 50;

$sql ="SELECT ";
$sql.=" t.rowid,";
$sql.=" t.ref,";
$sql.=" t.track_id,";
$sql.=" t.datec,";
$sql.=" t.subject,";
$sql.=" t.type_code,";
$sql.=" t.category_code,";
$sql.=" t.severity_code, ";
$sql.=" type.label as type_label,";
$sql.=" category.label as category_label,";
$sql.=" severity.label as severity_label,";
$sql.=" extra.deadline as deadline,";
$sql.=" IF(DATEDIFF(extra.deadline,NOW()) <=3,'Alerte','') AS 'Alerte',";
$sql.=" DATEDIFF(extra.deadline,NOW()) AS 'delais'";
$sql.=" FROM " . MAIN_DB_PREFIX . "ticket as t";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_type as type ON type.code=t.type_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_category as category ON category.code=t.category_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_severity as severity ON severity.code=t.severity_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "ticket_extrafields as extra ON extra.fk_object=t.rowid";

if (!$user->rights->societe->client->voir && !$socid) {
	$sql.= ", " . MAIN_DB_PREFIX."societe_commerciaux as sc";
}

$sql.=" WHERE t.entity IN (".getEntity('ticket', 1).")";
$sql.=" AND t.fk_statut<>8";
$sql.=" AND t.fk_user_assign = 0";
$sql.=" AND t.category_code IN ('INTERNE', 'CMD','EQUIPEMENT','INCIDENT','SUIVACT')";
$sql.=" AND extra.deadline <= DATE_ADD(NOW(), INTERVAL 15 DAY)";

if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= " AND t.fk_soc = sc.fk_soc AND sc.fk_user = ".$user->id;
}

if ($user->societe_id > 0) {
	$sql.= " AND t.fk_soc='" . $user->societe_id . "'";
} else {
	// Restricted to assigned user only
	if ($conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY && !$user->rights->ticket->manage) {
		$sql .= " AND t.fk_user_assign=" . $user->id;
	}
}
$sql .= $db->order("t.datec", "DESC");
$sql .= $db->plimit($max, 0);

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("LastNewTickets", $max);
	print '<table id ="nonassigne" class="display">';
	print '<thead>';
	print '<th>' . $langs->trans('Ref') . '</th>';
	print '<th>' . $langs->trans('Subject') . '</th>';
	print '<th>' . $langs->trans('Deadline PEC') . '</th>';

	print '<th></th>';
	print '</tr></thead>';
	print '<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";

			// Ref
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->ref . '</a>';
			print "</td>\n";

			// Subject
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->subject . '</a>';
			if ($objp->Alerte != "") {
				print img_warning($objp->delais,$objp->delais);
			}
			print "</td>\n";

			// Deadline
			print '<td>';
			print $objp->deadline;
			print '</td>';

			// Prendre en charge
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '&action=view&set=assign_ticket"><img src="'.DOL_MAIN_URL_ROOT.'/theme/eldy/img/edit.png" border="0" alt="Prendre en charge" title=""></a>';
			print "</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="3"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

print '
<script type="text/javascript">
	$(document).ready(function() {
		$(\'#nonassigne\').DataTable({
			"iDisplayLength": 20,
			"order": [[2,"asc"]]
			});
	} );
</script>
';

print '</div><div class="fichetwothirdright">';
print '<div class="ficheaddleft">';

/*
 * Repartition de la charge par utilisateurs
 */
print '<h3 id="CHARGE">Répartition de la charge</h3>';

$max = 25;
$sql = "SELECT";
$sql.=" u.rowid as 'rowid',";
$sql.=" CONCAT(u.firstname,' ',u.lastname) as 'login',";
$sql.=" COUNT(IF(t.fk_statut NOT IN (0,7), t.subject, NULL)) as 'Nombre',";
$sql.=" COUNT(IF(t.fk_statut IN (0,7), t.subject, NULL)) as 'NombreAttete',";
$sql.=" ROUND(SUM(IF(fk_statut IN (0,6), te.heure_est, 0)),1) as 'Somme',";
$sql.=" ROUND(SUM(IF(fk_statut NOT IN (0,6), te.heure_est, 0)),1) as 'SommeAttente'";
$sql.=" FROM ".MAIN_DB_PREFIX."ticket as t";
$sql.=" LEFT JOIN ".MAIN_DB_PREFIX."ticket_extrafields te ON t.rowid = te.fk_object";
$sql.=" LEFT JOIN ".MAIN_DB_PREFIX."user as u ON t.fk_user_assign = u.rowid";
$sql.=" LEFT JOIN ".MAIN_DB_PREFIX."usergroup_user ugu on u.rowid = ugu.fk_user";
$sql.=" WHERE ugu.fk_usergroup = 2";
$sql.=" AND t.fk_statut NOT IN (8,9)";
$sql.=" GROUP BY (u.rowid);";


$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("Repartition", $max);
	print '<table id ="repartition" class="display">';
	print '<thead>';
	print '<tr>';
	print '<th>' . $langs->trans('Responsable') . '</th>';
	print '<th>' . $langs->trans('Durée estimée en cours (h)') . '</th>';
	print '<th>' . $langs->trans('Nombre de tickets en cours') . '</th>';
	print '<th>' . $langs->trans('Nombre de tickets en attente') . '</th>';
	print '<th>' . $langs->trans('Durée estimée en attente (h)') . '</th>';
	print '</tr>';
	print '</thead>';
	print '<tbody>';

	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";
			// Login
			print '<td class="nowrap">';
			print $objp->login;
			print "</td>\n";

			// Eval
			print '<td class="nowrap">';
			print $objp->Somme;
			if ($objp->Somme >= 24.5) {
				print img_warning($objp->Somme,$objp->Somme);}

			// Nombre
			print '<td class="nowrap">';
			print $objp->Nombre;
			print "</td>\n";

			//Nombre attente
			print '<td class="nowrap">';
			print $objp->NombreAttente;
			print "</td>\n";

			// Eval	attente
			print '<td class="nowrap">';
			print $objp->SommeAttente;
			print "</td>";
			print "</tr>\n";

			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}
print '</div></div></div>';
print '<div class="inline-block divButAction"><a class="butAction" href="#NONASSIGNES"> Non assignés </a></div> <div class="inline-block divButAction"><a class="butAction" href="#MODELES"> Modèles </a></div>';
print '<div class="inline-block divButAction"><a class="butAction" href="#LAST"> Derniers tickets</a></div> <div class="inline-block divButAction"><a class="butAction" href="#SI"> Tickets SI </a></div>';
print '<div class="inline-block divButAction"><a class="butAction" href="#STAT">Statistiques</a></div>';
print '<div style="clear:both"></div>';
print '<br/>';

print '
	<script type="text/javascript">
	$(document).ready(function() {
		$(\'#repartition\').DataTable({
			"iDisplayLength": 20,
			"order": [[1,"desc"]]
			});
	} );
	</script>
';

/*
 * Modèles de tickets - nécessite Reportico pour fonctionner
 */
print '<h3 id="MODELES">Modèles de tickets<div class="inline-block divButAction"><a class="butAction" href="#repartition">Top</a></h3><div>';
set_include_path(DOL_DOCUMENT_ROOT.'/../reportico');
require_once 'reportico.php';        // Include Reportico
$q = new reportico();                         // Create instance
$q->initial_project = "CellAndCo";            // Name of report project folder
$q->initial_report = "CreationTicket";           // Name of report to run
$q->initial_execute_mode = "EXECUTE";         // Just executes specified report
$q->access_mode = "REPORTOUTPUT";
$q->output_template_parameters["show_hide_report_output_title"] = "hide";
$q->bootstrap_styles = "3";                   // Set to "3" for bootstrap v3, "2" for V2 or false for no bootstrap
$q->force_reportico_mini_maintains = true;    // Often required
$q->bootstrap_preloaded = false;               // true if you dont need Reportico to load its own bootstrap
$q->embedded_report = true;
$q->clear_reportico_session = true;
$q->reportico_ajax_mode = true;
$q->session_namespace = "MODELES";
$q->execute();
print '</div>';


/*
 * Planification à plus de deux semaines
 */
print '<h3 id="LONGTERME">Planification à plus de deux semaines</h3>';

$max = 50;
$sql ="SELECT";
$sql.=" t.rowid,";
$sql.=" t.ref,";
$sql.=" t.track_id,";
$sql.=" t.datec,";
$sql.=" t.subject,";
$sql.=" t.type_code,";
$sql.=" t.category_code," ;
$sql.=" t.severity_code,";
$sql.= "type.label as type_label,";
$sql.="category.label as category_label,";
$sql.="severity.label as severity_label,";
$sql.="extra.deadline as deadline,";
$sql.="IF(DATEDIFF(extra.deadline,NOW())<=1,'Alerte','') AS 'Alerte',";
$sql.="DATEDIFF(extra.deadline,NOW()) AS 'delais'";
$sql.=" FROM " . MAIN_DB_PREFIX . "ticket as t";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_type as type ON type.code=t.type_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_category as category ON category.code=t.category_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_severity as severity ON severity.code=t.severity_code";
$sql.=" LEFT JOIN " . MAIN_DB_PREFIX . "ticket_extrafields as extra ON extra.fk_object=t.rowid";

if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= ", " . MAIN_DB_PREFIX . "societe_commerciaux as sc";
}

$sql .= ' WHERE t.entity IN (' . getEntity('ticket', 1) . ')';
$sql .= " AND t.fk_statut<>8 AND t.fk_user_assign IS NULL AND t.category_code IN ('INTERNE', 'CMD','EQUIPEMENT','INCIDENT','SUIVACT') AND extra.deadline >= DATE_ADD(NOW(), INTERVAL 15 DAY)";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= " AND t.fk_soc = sc.fk_soc AND sc.fk_user = " . $user->id;
}

if ($user->societe_id > 0) {
	$sql .= " AND t.fk_soc='" . $user->societe_id . "'";
} else {
	// Restricted to assigned user only
	if ($conf->global->Cet_LIMIT_VIEW_ASSIGNED_ONLY && !$user->rights->ticket->manage) {
		$sql .= " AND t.fk_user_assign=" . $user->id;
	}
}
$sql .= $db->order("t.datec", "DESC");
$sql .= $db->plimit($max, 0);

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("Planification long-terme", $max);
	print '<table id ="longterme" class="display">';
	print '<thead>';
	print '<tr><th>Tickets à prendre en charge</th>';
	print '<th>' . $langs->trans('Ref') . '</th>';
	print '<th>' . $langs->trans('Subject') . '</th>';
	print '<th>' . $langs->trans('Deadline PEC') . '</th>';
	print '<th>' . $langs->trans('Type') . '</th>';
	print '<th>' . $langs->trans('Category') . '</th>';
	print '<th>' . $langs->trans('Severity') . '</th>';
	print '<th></th>';
	print '</tr></thead>';
	print '<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";
			// Creation date
			print '<td align="left">';
			print dol_print_date($db->jdate($objp->datec), 'dayhour');
			print "</td>";

			// Ref
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->ref . '</a>';
			print "</td>\n";

			// Subject
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->subject . '</a>';
			if ($objp->Alerte != "") {
				print img_warning($objp->delais,$objp->delais);
			}
			print "</td>\n";

			// Deadline
			print '<td>';
			print $objp->deadline;
			print '</td>';

			// Type
			print '<td>';
			print $objp->type_label;
			print '</td>';

			// Category
			print '<td>';
			print $objp->category_label;
			print "</td>";

			// Severity
			print '<td>';
			print $objp->severity_label;
			print "</td>";

			// Prendre en charge
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '&action=view&set=assign_ticket"><img src="/htdocs/theme/eldy/img/edit.png" border="0" alt="Prendre en charge" title=""></a>';
			print "</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

print '<script type="text/javascript">
$(document).ready(function() {
    $(\'#longterme\').DataTable({
		"iDisplayLength": 20,
		"order": [[3,"asc"]]
		});
} );
</script>';


/*
 * Last tickets
 */

print '<h3 id="LAST">Derniers tickets créés <div class="inline-block divButAction"><a class="butAction" href="#repartition">Top</a></h3>';

$max = 30;
$sql = "SELECT t.rowid, t.ref, t.track_id, t.datec, t.subject, t.type_code, t.category_code, t.severity_code";
$sql .= ", type.label as type_label, category.label as category_label, severity.label as severity_label, extra.deadline as deadline";
$sql .= " FROM " . MAIN_DB_PREFIX . "ticket as t";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_type as type ON type.code=t.type_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_category as category ON category.code=t.category_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_severity as severity ON severity.code=t.severity_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "ticket_extrafields as extra ON extra.fk_object=t.rowid";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= ", " . MAIN_DB_PREFIX . "societe_commerciaux as sc";
}

$sql .= ' WHERE t.entity IN (' . getEntity('ticket', 1) . ')';
$sql .= " AND t.fk_statut>0";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= " AND t.fk_soc = sc.fk_soc AND sc.fk_user = " . $user->id;
}

if ($user->societe_id > 0) {
	$sql .= " AND t.fk_soc='" . $user->societe_id . "'";
} else {
	// Restricted to assigned user only
	if ($conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY && !$user->rights->ticket->manage) {
		$sql .= " AND t.fk_user_assign=" . $user->id;
	}
}
$sql .= $db->order("t.rowid", "DESC");
$sql .= $db->plimit($max, 0);

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	//$transRecordedType = $langs->trans("LastNewTickets", $max);
	print '<table id ="derniers" class="display">';
	print '<thead>';
	print '<tr><th>30 Derniers tickets</th>';
	print '<th>' . $langs->trans('Ref') . '</th>';
	print '<th>' . $langs->trans('Subject') . '</th>';
	print '<th>' . $langs->trans('Deadline PEC') . '</th>';
	print '<th>' . $langs->trans('Type') . '</th>';
	print '<th>' . $langs->trans('Category') . '</th>';
	print '<th>' . $langs->trans('Severity') . '</th>';
	print '</tr>';
	print '</thead>
	<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";
			// Creation date
			print '<td align="left">';
			print dol_print_date($db->jdate($objp->datec), 'dayhour');
			print "</td>";

			// Ref
			print '<td class="nowrap">';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->ref . '</a>';
			print "</td>\n";

			// Subject
			print '<td class="nowrap">';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->subject . '</a>';
			print "</td>\n";

			// Deadline
			print '<td class="nowrap">';
			print $objp->deadline;
			print '</td>';

			// Type
			print '<td class="nowrap">';
			print $objp->type_label;
			print '</td>';


			// Category
			print '<td class="nowrap">';
			print $objp->category_label;
			print "</td>";

			// Severity
			print '<td class="nowrap">';
			print $objp->severity_label;
			print "</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

print '<script type="text/javascript">
$(document).ready(function() {
$(\'#derniers\').DataTable({
	"iDisplayLength": 30,
	"order": [[1,"desc"]]
	});
} );
</script>';


/*
 * Tickets support informatique
 */
print '<h3 id="SI">Tickets SI <div class="inline-block divButAction"><a class="butAction" href="#repartition">Top</a></h3>';

$max = 50;
$sql = "SELECT t.rowid, t.ref, t.track_id, t.datec, t.subject, t.type_code, t.category_code, t.severity_code";
$sql .= ", type.label as type_label, category.label as category_label, severity.label as severity_label, extra.deadline as deadline";
$sql .= " FROM " . MAIN_DB_PREFIX . "ticket as t";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_type as type ON type.code=t.type_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_category as category ON category.code=t.category_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_ticket_severity as severity ON severity.code=t.severity_code";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "ticket_extrafields as extra ON extra.fk_object=t.rowid";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= ", " . MAIN_DB_PREFIX . "societe_commerciaux as sc";
}

$sql .= ' WHERE t.entity IN (' . getEntity('ticket', 1) . ')';
$sql .= " AND t.fk_statut<>8 AND t.fk_user_assign IS NULL OR t.fk_user_assign = 0 AND t.category_code IN ('BIOTRACKER', 'SIRIUS','DOLIBARR','FC')";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= " AND t.fk_soc = sc.fk_soc AND sc.fk_user = " . $user->id;
}

if ($user->societe_id > 0) {
	$sql .= " AND t.fk_soc='" . $user->societe_id . "'";
} else {
	// Restricted to assigned user only
	if ($conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY && !$user->rights->ticket->manage) {
		$sql .= " AND t.fk_user_assign=" . $user->id;
	}
}
$sql .= $db->order("t.datec", "DESC");
$sql .= $db->plimit($max, 0);

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("Informatique", $max);
	print '<table id ="informatique" class="display">';
	print '<thead>';
	print '<tr><th>Tickets à prendre en charge</th>';
	print '<th>' . $langs->trans('Ref') . '</th>';
	print '<th>' . $langs->trans('Subject') . '</th>';
	print '<th>' . $langs->trans('Deadline PEC') . '</th>';
	print '<th>' . $langs->trans('Type') . '</th>';
	print '<th>' . $langs->trans('Category') . '</th>';
	print '<th>' . $langs->trans('Severity') . '</th>';
	print '</tr></thead>';
	print '<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";
			// Creation date
			print '<td align="left">';
			print dol_print_date($db->jdate($objp->datec), 'dayhour');
			print "</td>";

			// Ref
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->ref . '</a>';
			print "</td>\n";

			// Subject
			print '<td>';
			print '<a href="card.php?track_id=' . $objp->track_id . '">' . $objp->subject . '</a>';
			print "</td>\n";

			// Deadline
			print '<td>';
			print $objp->deadline;
			print '</td>';

			// Type
			print '<td>';
			print $objp->type_label;
			print '</td>';


			// Category
			print '<td>';
			print $objp->category_label;
			print "</td>";

			// Severity
			print '<td>';
			print $objp->severity_label;
			print "</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

print '<script type="text/javascript">
$(document).ready(function() {
    $(\'#informatique\').DataTable();
} );
</script>';


/*
 * Statistiques
 */
print '<h3 id="STAT">Statistiques<div class="inline-block divButAction"><a class="butAction" href="#repartition">Top</a></h3>';
$tick = array(
	'unread' => 0,
	'read' => 0,
	'answered' => 0,
	'assigned' => 0,
	'inprogress' => 0,
	'waiting' => 0,
);

$total = 0;
$sql = "SELECT";
$sql.=" t.fk_statut,";
$sql.=" COUNT(t.fk_statut) as nb";
$sql.=" FROM ".MAIN_DB_PREFIX."ticket as t";

if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= ", " . MAIN_DB_PREFIX . "societe_commerciaux as sc";
}

$sql .= ' WHERE t.entity IN (' . getEntity('ticket', 1) . ')';
$sql .= " AND t.fk_statut IS NOT NULL";
if (!$user->rights->societe->client->voir && !$socid) {
	$sql .= " AND t.fk_soc = sc.fk_soc AND sc.fk_user = " . $user->id;
}

// External users restriction
if ($user->societe_id > 0) {
	$sql .= " AND t.fk_soc='" . $user->societe_id . "'";
} else {
	// For internals users,
	if (!empty($conf->global->TICKET_LIMIT_VIEW_ASSIGNED_ONLY) && !$user->rights->ticket->manage) {
		$sql .= " AND t.fk_user_assign=" . $user->id;
	}
}
$sql .= " GROUP BY t.fk_statut";

$result = $db->query($sql);
if ($result) {
	while ($objp = $db->fetch_object($result)) {
		$found = 0;
		if ($objp->fk_statut == 0) {
			$tick['unread'] = $objp->nb;
		}
		if ($objp->fk_statut == 1) {
			$tick['read'] = $objp->nb;
		}
		if ($objp->fk_statut == 3) {
			$tick['answered'] = $objp->nb;
		}
		if ($objp->fk_statut == 4) {
			$tick['assigned'] = $objp->nb;
		}
		if ($objp->fk_statut == 5) {
			$tick['inprogress'] = $objp->nb;
		}
		if ($objp->fk_statut == 6) {
			$tick['waiting'] = $objp->nb;
		}
	}

	if ((round($tick['unread']) ? 1 : 0) + (round($tick['read']) ? 1 : 0) + (round($tick['answered']) ? 1 : 0) + (round($tick['assigned']) ? 1 : 0) >= 2) {
		$dataseries = array();
		$dataseries[] = array('label' => $langs->trans("NotRead"), 'data' => round($tick['unread']));
		$dataseries[] = array('label' => $langs->trans("Read"), 'data' => round($tick['read']));
		$dataseries[] = array('label' => $langs->trans("Answered"), 'data' => round($tick['answered']));
		$dataseries[] = array('label' => $langs->trans("Assigned"), 'data' => round($tick['assigned']));
		$dataseries[] = array('label' => $langs->trans("InProgress"), 'data' => round($tick['inprogress']));
		$dataseries[] = array('label' => $langs->trans("Waiting"), 'data' => round($tick['waiting']));
	}
} else {
	dol_print_error($db);
}


print '<br/><br/><table class="noborder" width="100%">';
print '<tr class="liste_titre"><th align = "center">' . $langs->trans("Statistics") .'</th></tr>';

print '<tr><td align = "center">';

// don't display graph if no series
if (count($dataseries) >1) {

	$filenamenb = $dir . "/" . $prefix . "ticket-" . $nowyear . ".png";
	$data = array();
	foreach ($dataseries as $key => $value) {
		$data[] = array($value['label'], $value['data']);
	}

	$px1 = new DolGraph();
	$mesg = $px1->isGraphKo();
	if (!$mesg) {
		$px1->SetData($data);
		unset($data1);
		$px1->SetPrecisionY(0);
		$px1->SetType(array('pie'));
		$px1->SetLegend($legend);
		$px1->SetMaxValue($px1->GetCeilMaxValue());
		$px1->SetWidth($WIDTH);
		$px1->SetHeight($HEIGHT);
		$px1->SetYLabel($langs->trans("TicketStatByStatus"));
		$px1->SetShading(3);
		$px1->SetHorizTickIncrement(1);
		$px1->SetPrecisionY(0);
		$px1->SetCssPrefix("cssboxes");
		$px1->mode = 'depth';
		$px1->draw($filenamenb);
		print $px1->show();
	}
}
print '</td></tr>';

print '</table>';

/** Tableau statistiques urgents/normaux par catégories **/

$max = 25;

$sql = "SELECT";
$sql.= " tc.label as 'Categorie',";
$sql.= " COUNT(IF(t.severity_code IN ('LOW','NORMAL'), t.rowid, NULL)) as 'Normal',";
$sql.= " COUNT(*) as 'TotalEnCours',";
$sql.= " COUNT(IF(t.severity_code IN ('LOWHIGH','HIGH'), t.rowid, NULL)) as 'Important',";
$sql.= " ROUND(COUNT(IF(t.severity_code IN ('LOWHIGH','HIGH'), t.rowid, NULL)) / COUNT(*) * 100,0) as 'TauxDeTicketsUrgent'";
$sql.= " FROM ".MAIN_DB_PREFIX."ticket t";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."c_ticket_severity ts ON t.severity_code = ts.code";
$sql.= " LEft JOIN ".MAIN_DB_PREFIX."c_ticket_category tc ON t.category_code = tc.code";
$sql.= " WHERE tc.label IS NOT NULL";
$sql.= " AND t.date_close = 0 OR t.date_close IS NULL";
$sql.= " GROUP BY(tc.label)";

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("En cours", $max);
	print '<table id ="Encours" class="noborder" width="100%">';
	print '<thead>';
	print '<tr>';
	print '<th>' . $langs->trans('Catégorie') . '</th>';
	print '<th>' . $langs->trans('Total en cours') . '</th>';
	print '<th>' . $langs->trans('Normal') . '</th>';
	print '<th>' . $langs->trans('Important') . '</th>';
	print '<th>' . $langs->trans('Taux de tickets urgent') . '</th>';
	print '</tr>';
	print '</thead>
	<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";

			// Categorie
			print '<td class="nowrap" align = "center">';
			print $objp->Categorie;
			print "</td>\n";

			// TotalEnCours
			print '<td class="nowrap" align = "center">';
			print $objp->TotalEnCours;
			print "</td>\n";

			// Normal
			print '<td class="nowrap" align = "center">';
			print $objp->Normal;
			print "</td>\n";

			// Important
			print '<td class="nowrap" align = "center">';
			print $objp->Important;
			print "</td>\n";

			// TauxDeTicketsUrgent
			print '<td class="nowrap" align = "center">';
			print $objp->TauxDeTicketsUrgent;
			print "%</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

$max = 25;

$sql = "SELECT";
$sql.= " COUNT(*) AS 'TotalEnCours',";
$sql.= " ROUND(COUNT(IF(tc.label IN ('Commande client'), t.rowid, NULL)) / COUNT(*) * 100,0) as 'PartCommandes',";
$sql.= " COUNT(IF(t.severity_code IN ('LOW','NORMAL'), t.rowid, NULL)) as 'Normal',";
$sql.= " COUNT(*) as 'TotalEnCours', COUNT(IF(t.severity_code IN ('LOWHIGH','HIGH'), t.rowid, NULL)) as 'Important',";
$sql.= " ROUND(COUNT(IF(t.severity_code IN ('LOWHIGH','HIGH'), t.rowid, NULL)) / COUNT(*) * 100,0) as 'TauxDeTicketsUrgent'";
$sql.= " FROM ".MAIN_DB_PREFIX."ticket t";
$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."c_ticket_category tc ON t.category_code = tc.code";
$sql.= " WHERE t.date_close = 0 OR t.date_close IS NULL";
$sql.= " AND tc.label IN ('Commande client','Tâches','Equipement');";

$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);

	$i = 0;

	$transRecordedType = $langs->trans("En cours", $max);
	print '<table id ="Encours" class="noborder" width="100%">';
	print '<thead>';
	print '<tr>';
	print '<th>' . $langs->trans('Total en cours') . '</th>';
	print '<th>' . $langs->trans('Taux de commandes') . '</th>';
	print '<th>' . $langs->trans('Normal') . '</th>';
	print '<th>' . $langs->trans('Important') . '</th>';
	print '<th>' . $langs->trans('Taux de tickets urgents') . '</th>';
	print '</tr>';
	print '</thead>
	<tbody>';
	if ($num > 0) {
		$var = true;

		while ($i < $num) {
			$objp = $db->fetch_object($result);

			$var = !$var;
			print "<tr $bc[$var]>";
			// TotalEnCours
			print '<td class="nowrap" align = "center">';
			print $objp->TotalEnCours;
			print "</td>\n";

			// PartCommandes
			print '<td class="nowrap" align = "center">';
			print $objp->PartCommandes;
			print " %</td>\n";

			// Normal
			print '<td class="nowrap" align = "center">';
			print $objp->Normal;
			print "</td>\n";

			// Important
			print '<td class="nowrap" align = "center">';
			print $objp->Important;
			print "</td>\n";

			// TauxDeTicketsUrgent
			print '<td class="nowrap" align = "center">';
			print $objp->TauxDeTicketsUrgent;
			print "%</td>";
			print "</tr>\n";
			$i++;
		}

		$db->free();
	} else {
		print '<tr><td colspan="5"><div class="info">' . $langs->trans('NoTicketsFound') . '</div></td></tr>';
	}

	print "</tbody>
	</table>";
} else {
	dol_print_error($db);
}

/**Fin tableau **/


print '</div></div></div>';
print '<div style="clear:both"></div>';

print '<div class="tabsAction">';
//print '<div class="inline-block divButAction"><a class="butAction" href="new.php?action=create_ticket">' . $langs->trans('CreateTicket') . '</a></div>';
//print '<div class="inline-block divButAction"><a class="butAction" href="list.php">' . $langs->trans('TicketList') . '</a></div>';
print '</div>';



// End of page
llxFooter('');
$db->close();
