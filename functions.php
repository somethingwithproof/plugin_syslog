<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function syslog_include_js() {
	global $config;
	?>
	<script type='text/javascript' src='<?php print $config['url_path'];?>plugins/syslog/js/functions.js'></script>
	<?php
}

function syslog_allow_edits() {
	global $config;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] > 1) {
			return false;
		}
	}

	return true;
}

function syslog_sync_save($data, $table, $primary = '') {
	global $config, $syslogdb_default;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] == 1) {
			$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

			$id = syslog_sql_save($data, $stable, $primary);

			if ($id > 0) {
				raise_message(1);
			} else {
				raise_message(2);
			}

			$pollers = array_rekey(
				db_fetch_assoc('SELECT poller_id
					FROM pollers
					WHERE disabled = ""
					AND id > 1'),
				'id', 'id'
			);

			if (cacti_sizeof($pollers)) {
				foreach($pollers as $poller_id) {
					$rcnn_id = poller_connect_to_remote($poller_id);

					if ($rcnn_id !== false) {
						$id = sql_save($data, $table, $primary, true, $rcnn_id);
					}
				}
			}
		} else {
			raise_message('syslog_denied', __('Save Failed.  Remote Data Collectors in Sync Mode are not allowed to Save Rules.  Save from the Main Cacti Server instead.', 'syslog'), MESSAGE_LEVEL_ERROR);
		}
	} else {
		$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

		$id = syslog_sql_save($data, $stable, $primary);

		if ($id > 0) {
			raise_message(1);
		} else {
			raise_message(2);
		}
	}
}

function syslog_sendemail($to, $from, $subject, $message, $smsmessage = '') {
	syslog_debug("Sending Alert email to '" . $to . "'");

	$sms    = '';
	$nonsms = '';

	/* if there are SMS emails, process separately */
	if (substr_count($to, 'sms@')) {
		$emails = explode(',', $to);

		if (cacti_sizeof($emails)) {
			foreach($emails as $email) {
				if (substr_count($email, 'sms@')) {
					$sms .= ($sms != '' ? ', ':'') . str_replace('sms@', '', trim($email));
				} else {
					$nonsms .= ($nonsms != '' ? ', ':'') . trim($email);
				}
			}
		}
	} else {
		$nonsms = $to;
	}

	if (strlen($sms) && $smsmessage != '') {
		mailer($from, $sms, '', '', '', $subject, '', $smsmessage);
	}

	if (strlen($nonsms)) {
		if (read_config_option('syslog_html') == 'on') {
			mailer($from, $nonsms, '', '', '', $subject, $message, __('Please use an HTML Email Client', 'syslog'));
		} else {
			$message = strip_tags(str_replace('<br>', "\n", $message));
			mailer($from, $nonsms, '', '', '', $subject, '', $message, '', '', false);
		}
	}
}

/**
 * Dispatch a bulk action on selected items from a form submission.
 *
 * @param  array|false $selected_items Unserialized selected item IDs, or false
 * @param  int         $drp_action     The dropdown action identifier
 * @param  array       $action_map     Map of action IDs to handler function names
 * @param  string      $export_action  The action ID that triggers CSV export
 * @param  string      $export_items   Comma-separated item IDs for export
 *
 * @return void
 */
/**
 * syslog_draw_bulk_action_confirm - Renders the confirmation list for bulk actions.
 *
 * @param  (string) $drp_action      The action ID
 * @param  (string) $item_list       The HTML <li> list of items
 * @param  (string) $item_type_label The plural label for the items (e.g. 'Syslog Alert Rule(s)')
 * @param  (array)  $action_labels   Map of action IDs to their infinitive verbs (e.g. 1 => 'Delete')
 */
function syslog_draw_bulk_action_confirm($drp_action, $item_list, $item_type_label, $action_labels) {
	$action_verb = $action_labels[$drp_action] ?? '';

	print "<tr>
		<td class='textArea'>
			<p>" . __esc("Click 'Continue' to %s the following %s.", $action_verb, $item_type_label, 'syslog') . "</p>
			<div class='itemlist'><ul>$item_list</ul></div>
		</td>
	</tr>\n";
}

function syslog_apply_selected_items_action($selected_items, $drp_action, $action_map, $export_action = '', $export_items = '') {
	if ($selected_items != false) {
		if (isset($action_map[$drp_action])) {
			$action_function = $action_map[$drp_action];

			if (function_exists($action_function)) {
				foreach($selected_items as $selected_item) {
					try {
						$action_function($selected_item);
					} catch (Exception $e) {
						cacti_log("SYSLOG ERROR: Action '$action_function' failed for item '$selected_item': " . $e->getMessage(), false, 'SYSTEM');
					}
				}
			} else {
				cacti_log("SYSLOG ERROR: Bulk action function '$action_function' not found.", false, 'SYSTEM');
			}
		} elseif ($export_action != '' && $drp_action == $export_action) {
			/* Re-serialize the sanitized array and URL-encode so the value is
			 * safe to embed in a JS document.location string (avoids injection
			 * via the raw request value that $export_items carries). */
			$_SESSION['exporter'] = rawurlencode(serialize($selected_items));
		}
	}
}

function syslog_get_import_xml_payload($redirect_url) {
	/* Decode percent-encoding before allowlist validation so that encoded
	   characters (e.g. %2F, %3A) cannot smuggle past the regex. */
	$redirect_url = urldecode($redirect_url);

	/* Allow only relative paths that start with a filename character. Schemes
	   (http://), protocol-relative URLs (//), and backslash prefixes (\, /\)
	   all fail to match the allowlist and collapse to the safe default. */
	if (!preg_match('/^[a-zA-Z0-9_\-][a-zA-Z0-9_\-\.\/]*(?:\?[a-zA-Z0-9_\-&=%\.+]*)?$/', $redirect_url)) {
		$redirect_url = 'index.php';
	}

	if (strpos($redirect_url, '..') !== false) {
		$redirect_url = 'index.php';
	}

	/* Block double-slash sequences that survive urldecode (e.g. %2F%2F -> //)
	   and could be used to construct protocol-relative URLs or confuse path
	   normalization in certain web-server configurations. */
	if (strpos($redirect_url, '//') !== false) {
		$redirect_url = 'index.php';
	}

	if (trim(get_nfilter_request_var('import_text')) != '') {
		/* textbox input */
		return get_nfilter_request_var('import_text');
	}

	if (isset($_FILES['import_file']['tmp_name']) &&
		$_FILES['import_file']['tmp_name'] != 'none' &&
		$_FILES['import_file']['tmp_name'] != '') {
		/* file upload */
		$tmp_name = $_FILES['import_file']['tmp_name'];

		if (!isset($_FILES['import_file']['error']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
			header('Location: ' . $redirect_url);
			exit;
		}

		if (!is_uploaded_file($tmp_name)) {
			header('Location: ' . $redirect_url);
			exit;
		}

		if ($_FILES['import_file']['size'] > 1048576) {
			cacti_log('SYSLOG ERROR: Uploaded import file exceeds 1 MB limit', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		/* Reject non-XML uploads based on MIME sniffing of the actual bytes,
		   not the browser-supplied Content-Type which is attacker-controlled. */
		$mime = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';
		$mime_base = strtolower(explode(';', $mime)[0]);
		if ($mime_base !== 'text/xml' && $mime_base !== 'application/xml') {
			cacti_log('SYSLOG ERROR: Uploaded import file is not XML (detected: ' . $mime . ')', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		/* Secondary: verify XML magic bytes independently of mime_content_type so
		   that spoofed or environment-inconsistent MIME results are caught.
		   Accepts bare <?xml and UTF-8-BOM-prefixed <?xml openings. */
		$hdr = file_get_contents($tmp_name, false, null, 0, 8);
		$has_xml_sig = $hdr !== false && (
			strncmp($hdr, '<?xml', 5) === 0 ||
			strncmp($hdr, "\xef\xbb\xbf<?xml", 8) === 0
		);
		if (!$has_xml_sig) {
			cacti_log('SYSLOG ERROR: Uploaded import file failed XML signature check', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		$fp = fopen($tmp_name, 'rb');

		if ($fp === false) {
			cacti_log('SYSLOG ERROR: Failed to open uploaded import file', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		$xml_data = fread($fp, filesize($tmp_name));
		fclose($fp);

		if ($xml_data === false) {
			cacti_log('SYSLOG ERROR: Failed to read uploaded import file', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		/* Tertiary: parse the content to confirm it is well-formed XML.
		   mime_content_type() is heuristic and can be spoofed by crafted files;
		   a successful parse is the authoritative check. */
		libxml_use_internal_errors(true);
		$parsed = simplexml_load_string($xml_data);
		libxml_clear_errors();
		libxml_use_internal_errors(false);
		if ($parsed === false) {
			cacti_log('SYSLOG ERROR: Uploaded import file is not well-formed XML', false, 'SYSTEM');
			header('Location: ' . $redirect_url);
			exit;
		}

		return $xml_data;
	}

	header('Location: ' . $redirect_url);
	exit;
}

function syslog_is_partitioned() {
	global $syslogdb_default;

	/* see if the table is partitioned */
	$syntax = syslog_db_fetch_row("SHOW CREATE TABLE `" . $syslogdb_default . "`.`syslog`");
	if (substr_count($syntax['Create Table'], 'PARTITION')) {
		return true;
	} else {
		return false;
	}
}

/**
 * This function will manage old data for non-partitioned tables
 */
function syslog_traditional_manage() {
	global $syslogdb_default, $syslog_cnn;

	/* determine the oldest date to retain */
	if (read_config_option('syslog_retention') > 0) {
		$retention = gmdate('Y-m-d', time() - (86400 * read_config_option('syslog_retention')));
	} else {
		$retention = gmdate('Y-m-d', time() - (30 * 86400));
		set_config_option('syslog_retention', '30');
	}

	/* delete from the main syslog table first */
	syslog_db_execute_prepared("DELETE FROM `" . $syslogdb_default . "`.`syslog` WHERE logtime < ?", array($retention));

	$syslog_deleted = db_affected_rows($syslog_cnn);

	/* now delete from the syslog removed table */
	syslog_db_execute_prepared("DELETE FROM `" . $syslogdb_default . "`.`syslog_removed` WHERE logtime < ?", array($retention));

	$syslog_deleted += db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s, Syslog Message(s) (older than %s)', $syslog_deleted, $retention));

	return $syslog_deleted;
}

/**
 * This function will manage a partitioned table by checking for time to create
 */
function syslog_partition_manage() {
	$syslog_deleted = 0;

	if (syslog_partition_check('syslog')) {
		syslog_partition_create('syslog');
		$syslog_deleted = syslog_partition_remove('syslog');
	}

	if (syslog_partition_check('syslog_removed')) {
		syslog_partition_create('syslog_removed');
		$syslog_deleted += syslog_partition_remove('syslog_removed');
	}

	return $syslog_deleted;
}

/**
 * syslog_partition_table_allowed - validate that the table being partitioned
 *   is in our approved list.
 *
 * @param  (string)  The table name
 *
 * @return (bool)    True if allowed, False otherwise
 */
function syslog_partition_table_allowed($table) {
	if (in_array($table, array('syslog', 'syslog_removed'), true)) {
		return (bool)preg_match('/^[a-z_]+$/', $table);
	}

	return false;
}

/**
 * This function will create a new partition for the specified table.
 */
function syslog_partition_create($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	/* determine the format of the table name */
	$time    = time();
	$cformat = 'd' . gmdate('Ymd', $time);
	$lnow    = gmdate('Y-m-d', $time+86400);

	/* validate DDL interpolation values to prevent injection */
	if (!preg_match('/^d\d{8}$/', $cformat) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lnow)) {
		cacti_log('SYSLOG ERROR: Invalid partition format values detected', false, 'SYSTEM');
		return false;
	}

	$exists = syslog_db_fetch_row_prepared("SELECT *
		FROM `information_schema`.`partitions`
		WHERE table_schema = ?
		AND partition_name = ?
		AND table_name = ?
		ORDER BY partition_ordinal_position",
		array($syslogdb_default, $cformat, $table)
	);

	if (!cacti_sizeof($exists)) {
		$lock_name = hash('sha256', $syslogdb_default . 'syslog_partition_create.' . $table);

		try {
			$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', array($lock_name));

			if ((int)$locked !== 1) {
				cacti_log("SYSLOG WARNING: Failed to acquire partition create lock for '$table'", false, 'SYSTEM');
				return false;
			}

			$recheck = syslog_db_fetch_row_prepared("SELECT *
				FROM `information_schema`.`partitions`
				WHERE table_schema = ?
				AND partition_name = ?
				AND table_name = ?
				ORDER BY partition_ordinal_position",
				array($syslogdb_default, $cformat, $table)
			);

			if (cacti_sizeof($recheck)) {
				return true;
			}

			cacti_log("SYSLOG: Creating new partition '$cformat' for table '$table'", false, 'SYSTEM');

			syslog_debug("Creating new partition '$cformat' for table '$table'");

			/* MySQL does not support parameter binding for DDL statements;
			   $cformat and $lnow are validated above via regex */
			syslog_db_execute_prepared("ALTER TABLE `" . $syslogdb_default . "`.`$table` REORGANIZE PARTITION dMaxValue INTO (
				PARTITION $cformat VALUES LESS THAN (TO_DAYS('$lnow')),
				PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");
		} finally {
			syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', array($lock_name));
		}
	}

	return true;
}

/**
 * This function will remove all old partitions for the specified table.
 */
function syslog_partition_remove($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		cacti_log("SYSLOG ERROR: Attempt to remove partitions from disallowed table '$table'", false, 'SYSTEM');
		return 0;
	}

	$syslog_deleted = 0;
	$days = read_config_option('syslog_retention');

	if ($days > 0) {
		$lock_name = hash('sha256', $syslogdb_default . 'syslog_partition_remove.' . $table);

		try {
			$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', array($lock_name));

			if ((int)$locked !== 1) {
				cacti_log("SYSLOG WARNING: Failed to acquire partition remove lock for '$table'", false, 'SYSTEM');
				return $syslog_deleted;
			}

			$number_of_partitions = syslog_db_fetch_assoc_prepared("SELECT *
				FROM `information_schema`.`partitions`
				WHERE table_schema = ?
				AND table_name = ?
				ORDER BY partition_ordinal_position",
				array($syslogdb_default, $table)
			);

			syslog_debug("There are currently '" . sizeof($number_of_partitions) . "' Syslog Partitions for '$table', We will keep '$days' of them.");

			$user_partitions = sizeof($number_of_partitions) - 1;
			if ($user_partitions >= $days) {
				$i = 0;
				while ($user_partitions > $days && $i < cacti_sizeof($number_of_partitions)) {
					$oldest = $number_of_partitions[$i];

					/* PARTITION_NAME comes from information_schema, but validate the
					   format before DDL interpolation — MySQL does not support parameter
					   binding for DDL statements. */
					if (!preg_match('/^d\d{8}$/', $oldest['PARTITION_NAME'])) {
						cacti_log("SYSLOG ERROR: Unexpected partition name format '" . htmlspecialchars($oldest['PARTITION_NAME'], ENT_QUOTES, 'UTF-8') . "' for table '$table', skipping, cannot prune past this entry", false, 'SYSTEM');
						/* Stop immediately: partitions are ordered by age, so an invalid
						   name means we cannot safely drop any further entries. Breaking
						   here also ensures the loop terminates even if all remaining
						   names are invalid. */
						break;
					}

					cacti_log("SYSLOG: Removing old partition '" . $oldest['PARTITION_NAME'] . "' from table '$table'", false, 'SYSTEM');

					syslog_debug("Removing partition '" . $oldest['PARTITION_NAME'] . "' from table '$table'");

					syslog_db_execute_prepared("ALTER TABLE `" . $syslogdb_default . "`.`$table` DROP PARTITION " . $oldest['PARTITION_NAME']);

					$i++;
					$user_partitions--;
					$syslog_deleted++;
				}
			}
		} finally {
			syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', array($lock_name));
		}
	}

	return $syslog_deleted;
}

function syslog_partition_check($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	/* find date of last partition */
	$last_part = syslog_db_fetch_cell_prepared("SELECT PARTITION_NAME
		FROM `information_schema`.`partitions`
		WHERE table_schema = ?
		AND table_name = ?
		ORDER BY partition_ordinal_position DESC
		LIMIT 1,1",
		array($syslogdb_default, $table)
	);

	$lformat   = str_replace('d', '', $last_part);
	$cformat   = gmdate('Ymd');

	if ($cformat > $lformat) {

		return true;
	} else {
		return false;
	}
}

function syslog_check_changed($request, $session) {
	if ((isset_request_var($request)) && (isset($_SESSION[$session]))) {
		if (get_request_var($request) != $_SESSION[$session]) {
			return 1;
		}
	}
}

function syslog_remove_items($table, $uniqueID) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Removal Rules...');

	$rows = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . '`.`syslog_remove`
		WHERE enabled="on"');

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));

	$removed = 0;
	$xferred = 0;

	if ($table == 'syslog_incoming') {
		$total = syslog_db_fetch_cell_prepared('SELECT count(*)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = ?', array($uniqueID));
	} else {
		$total = 0;
	}

	if (cacti_sizeof($rows)) {
		foreach($rows as $remove) {
			$sql     = '';
			$sql1    = '';
			$params  = array();
			$params1 = array();

			$column_map = array(
				'facility' => 'facility_id',
				'host'     => 'host_id',
				'program'  => 'program_id',
				'messageb' => $syslog_incoming_config['textField'],
				'messagec' => $syslog_incoming_config['textField'],
				'messagee' => $syslog_incoming_config['textField'],
			);

			if ($table == 'syslog_incoming') {
				$column = $column_map[$remove['type']] ?? 'message';
				if ($remove['type'] == 'host') {
					$column = 'si.host';
				} elseif ($remove['type'] == 'program') {
					$column = 'si.program';
				}

				$filter = syslog_build_match_filter($remove['type'], $remove['message'], $column);

				if ($remove['method'] != 'del') {
					$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
						(logtime, priority_id, facility_id, program_id, host_id, message)
						SELECT logtime, priority_id, facility_id, program_id, host_id, message
						FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
							FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
							INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
							ON sf.facility_id = si.facility_id
							INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
							ON sp.priority_id = si.priority_id
							INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
							ON spg.program = si.program
							INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
							ON sh.host = si.host
							WHERE ' . $filter['sql'] . '
							AND `status` = ?
						) AS merge';
					$params1 = array_merge($filter['params'], array($uniqueID));
				}

				$delete_column = $column_map[$remove['type']] ?? $syslog_incoming_config['textField'];
				if ($remove['type'] == 'facility') {
					$delete_column = $syslog_incoming_config['facilityField'];
				} elseif ($remove['type'] == 'host') {
					$delete_column = $syslog_incoming_config['hostField'];
				} elseif ($remove['type'] == 'program') {
					$delete_column = $syslog_incoming_config['programField'];
				}
				$delete_filter = syslog_build_match_filter($remove['type'], $remove['message'], $delete_column);

				$sql = 'DELETE
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $delete_filter['sql'] . '
					AND `status` = ?';
				$params = array_merge($delete_filter['params'], array($uniqueID));
			} else {
				$column = $column_map[$remove['type']] ?? 'message';
				$filter = syslog_build_match_filter($remove['type'], $remove['message'], $column);

				if (!empty($filter['sql'])) {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM `' . $syslogdb_default . '`.`syslog`
							WHERE ' . $filter['sql'];
						$params1 = $filter['params'];
					}

					$sql = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
						WHERE ' . $filter['sql'];
					$params = $filter['params'];
				}
			}

			if ($sql != '' || $sql1 != '') {
				$debugm = '';
				/* process the removal rule first */
				if ($sql1 != '') {
					syslog_db_execute_prepared($sql1, $params1);
					$xferred += db_affected_rows($syslog_cnn);
				}

				/* now delete the remainder that match */
				syslog_db_execute_prepared($sql, $params);
				$affected = db_affected_rows($syslog_cnn);
				$removed += $affected;

				if ($sql1 != '') {
					$debugm = sprintf('Moved    %5s - ', $xferred);
				} else {
					$debugm = sprintf('Deleted  %5s - ', $removed);
				}

				syslog_debug($debugm . 'Message' . ($affected == 1 ? '' : 's' ) .
					" for removal rule '" . $remove['name'] . "'");
			}
		}
	}

	return array('removed' => $removed, 'xferred' => $xferred);
}

/** function syslog_log_row_color()
 *  This function set's the CSS for each row of the syslog table as it is displayed
 *  it supports both the legacy as well as the new approach to controlling these
 *  colors.
*/
/**
 * syslog_get_row_class - Maps a priority or severity level to a CSS class.
 *
 * @param  (int)    $level The priority (0-7) or severity (0-2) level
 * @param  (string) $type  The level type ('priority' or 'severity')
 *
 * @return (string) The CSS class name
 */
function syslog_get_row_class($level, $type = 'priority') {
	if ($type == 'severity') {
		switch ($level) {
			case '1': return 'logWarning';
			case '2': return 'logAlert';
			default:  return 'logInfo';
		}
	}

	$classes = array(
		'0' => 'logEmergency',
		'1' => 'logAlert',
		'2' => 'logCritical',
		'3' => 'logError',
		'4' => 'logWarning',
		'5' => 'logNotice',
		'6' => 'logInfo',
		'7' => 'logDebug'
	);

	return $classes[$level] ?? 'logInfo';
}

/**
 * syslog_print_row - Prints a <tr> with the appropriate class and title.
 *
 * @param  (int)    $level   The level
 * @param  (string) $message The title/tip content
 * @param  (string) $type    The level type
 * @param  (string) $extra   Additional CSS classes
 */
function syslog_print_row($level, $message, $type = 'priority', $extra = '') {
	$class = syslog_get_row_class($level, $type);
	$base_classes = ($type == 'priority') ? 'selectable syslogRow syslog-detail-row' : 'selectable';
	
	print "<tr title='" . html_escape($message) . "' class='tableRow $base_classes $class $extra'>";
	return $class;
}

function syslog_log_row_color($severity, $tip_title) {
	return syslog_print_row($severity, $tip_title, 'severity');
}

function syslog_row_color($priority, $message) {
	return syslog_print_row($priority, $message, 'priority');
}

function sql_hosts_where($tab) {
	global $hostfilter, $hostfilter_log, $syslog_incoming_config;
	global $syslogdb_default;

	$hostfilter     = '';
	$hostfilter_log = '';
	$hosts_array    = array();

	if (!isempty_request_var('host') && get_nfilter_request_var('host') != 'null') {
		$hostarray = explode(',', trim(get_nfilter_request_var('host')));
		if ($hostarray[0] != '0') {
			foreach($hostarray as $host_id) {
				input_validate_input_number($host_id);

				if ($host_id > 0) {
					$log_host = syslog_db_fetch_cell_prepared('SELECT host
						FROM `' . $syslogdb_default . '`.`syslog_hosts`
						WHERE host_id = ?',
						array($host_id));

					if (!empty($log_host)) {
						$hosts_array[] = db_qstr($log_host);
					}
				}
			}

			if (cacti_sizeof($hosts_array)) {
				$hostfilter_log = ' host IN(' . implode(',', $hosts_array) . ')';
			}

			$hostfilter .= ($hostfilter != '' ? ' AND ':'') . ' host_id IN(' . implode(',', $hostarray) . ')';
		}
	}
}

function syslog_export($tab) {
	global $syslog_incoming_config, $severities;
	global $syslogdb_default;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if ($tab == 'syslog') {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=syslog_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		$hosts = array_rekey(
			syslog_db_fetch_assoc('SELECT host_id, host
				FROM `' . $syslogdb_default . '`.`syslog_hosts`'),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc('SELECT facility_id, facility
				FROM `' . $syslogdb_default . '`.`syslog_facilities`'),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc('SELECT priority_id, priority
				FROM `' . $syslogdb_default . '`.`syslog_priorities`'),
			'priority_id', 'priority'
		);

		$programs = array_rekey(
			syslog_db_fetch_assoc('SELECT program_id, program
				FROM `' . $syslogdb_default . '`.`syslog_programs`'),
			'program_id', 'program'
		);

		print 'host, facility, priority, program, date, message' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($facilities[$message['facility_id']])) {
					$facility = $facilities[$message['facility_id']];
				} else {
					$facility = 'Unknown';
				}

				if (isset($programs[$message['program_id']])) {
					$program = $programs[$message['program_id']];
				} else {
					$program = 'Unknown';
				}

				if (isset($priorities[$message['priority_id']])) {
					$priority = $priorities[$message['priority_id']];
				} else {
					$priority = 'Unknown';
				}

				if (isset($hosts[$message['host_id']])) {
					$host = $hosts[$message['host_id']];
				} else {
					$host = 'Unknown';
				}

				print
					'"' .
					$host                                          . '","' .
					ucfirst($facility)                             . '","' .
					ucfirst($priority)                             . '","' .
					ucfirst($program)                              . '","' .
					$message['logtime']                            . '","' .
					$message[$syslog_incoming_config['textField']] . '"'   . "\r\n";
			}
		}
	} else {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=alert_log_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		print 'name, severity, date, message, host, facility, priority, count' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($severities[$message['severity']])) {
					$severity = $severities[$message['severity']];
				} else {
					$severity = 'Unknown';
				}

				print
					'"' .
					$message['name']                  . '","' .
					$severity                         . '","' .
					$message['logtime']               . '","' .
					$message['logmsg']                . '","' .
					$message['host']                  . '","' .
					ucfirst($message['facility'])     . '","' .
					ucfirst($message['priority'])     . '","' .
					$message['count']                 . '"'   . "\r\n";
			}
		}
	}
}

function syslog_debug($message) {
	global $debug;

	if ($debug) {
		print date('H:m:s') . ' SYSLOG DEBUG: ' . trim($message) . PHP_EOL;
	}
}

function syslog_log_alert($alert_id, $alert_name, $severity, $msg, $count = 1, $html = '', $hosts = array()) {
	global $config, $severities;
	global $syslogdb_default;

	if ($count <= 1) {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = $msg['seq'];
		$save['logtime']     = $msg['logtime'];
		$save['logmsg']      = $msg['message'];
		$save['host']        = $msg['host'];
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = 1;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']        = $id;
		$save['alert_name'] = $alert_name;
		api_plugin_hook_function('syslog_update_hostsalarm', $save);

		cacti_log("WARNING: The Syslog Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered on Host '" . $msg['host'] . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	} else {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = 0;
		$save['logtime']     = date('Y-m-d H:i:s');
		$save['logmsg']      = $alert_name;
		$save['host']        = 'N/A';
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = $count;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']         = $id;
		$save['alert_name']  = $alert_name;

		if (cacti_sizeof($hosts)) {
			foreach($hosts as $host) {
				$save['host'] = $host;
				api_plugin_hook_function('syslog_update_hostsalarm', $save);
			}
		}

		cacti_log("WARNING: The Syslog Instance Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered, Count was '" . $count . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	}
}

function syslog_manage_items($from_table, $to_table) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	/* Select filters to work on */
	$rows = syslog_db_fetch_assoc('SELECT * FROM `' . $syslogdb_default . "`.`syslog_remove` WHERE enabled='on'");

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));

	$removed = 0;
	$xferred = 0;
	$total   = 0;

	if (cacti_sizeof($rows)) {
		foreach($rows as $remove) {
			syslog_debug('Processing Rule  - ' . $remove['message']);

			$sql_sel = '';
			$sql_dlt = '';
			$params  = array();

			$column_map = array(
				'facility' => 'facility_id',
				'host'     => 'host_id',
				'program'  => 'program_id',
				'messageb' => $syslog_incoming_config['textField'],
				'messagec' => $syslog_incoming_config['textField'],
				'messagee' => $syslog_incoming_config['textField'],
			);

			$column = $column_map[$remove['type']] ?? 'message';
			$filter = syslog_build_match_filter($remove['type'], $remove['message'], $column);

			if ($remove['method'] != 'del') {
				$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table WHERE " . $filter['sql'];
			} else {
				$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table WHERE " . $filter['sql'];
			}
			$params = $filter['params'];

			if ($sql_sel != '' || $sql_dlt != '') {
				$debugm = '';
				/* process the removal rule first */
				if ($sql_sel != '') {
					$move_count = 0;
					/* first insert, then delete */
					$move_records = syslog_db_fetch_assoc_prepared($sql_sel, $params);
					syslog_debug(sprintf('Found   %5s - Message(s)', cacti_sizeof($move_records)));

					if (cacti_sizeof($move_records)) {
						$messages_moved = 0;
						/* Discard any seq values that are not numeric before building the
						   IN list; intval() silently coerces garbage to 0, which would
						   match the wrong rows. */
						$seq_values = array_filter(array_column($move_records, 'seq'), 'is_numeric');
						if (empty($seq_values)) {
							cacti_log('SYSLOG WARNING: Move set contained no valid seq values; skipping batch', false, 'SYSTEM');
						} else {
							$all_seq = implode(',', array_map('intval', $seq_values));
							syslog_db_execute_prepared("INSERT INTO `". $syslogdb_default . "`.`". $to_table ."`
								(facility_id, priority_id, host_id, logtime, message)
								(SELECT facility_id, priority_id, host_id, logtime, message
								FROM `". $syslogdb_default . "`.". $from_table ."
								WHERE seq IN (" . $all_seq ."))");

							$messages_moved = db_affected_rows($syslog_cnn);

							if ($messages_moved > 0) {
								syslog_db_execute_prepared("DELETE FROM `". $syslogdb_default . "`.`" . $from_table ."`
									WHERE seq IN (" . $all_seq .")" );
							}

							$xferred += $messages_moved;
							$move_count = $messages_moved;
						}
					}

					$debugm = sprintf('Moved   %5s - Message(s)', $move_count);
				}

				if ($sql_dlt != '') {
					/* now delete the remainder that match */
					syslog_db_execute_prepared($sql_dlt, $params);
					$removed += db_affected_rows($syslog_cnn);
					$debugm   = sprintf('Deleted %5s Message(s)', $removed);
				}

				syslog_debug($debugm);
			}
		}
	}

	return array('removed' => $removed, 'xferred' => $xferred);
}

/* get_hash_syslog - returns the current unique hash for an alert
   @arg $id - (int) the ID of the syslog item to return a hash for
   @returns - a 128-bit, hexadecimal hash */
function get_hash_syslog($id, $table) {
    $hash = syslog_db_fetch_cell_prepared('SELECT hash
		FROM ' . $table . '
		WHERE id = ?',
		array($id));

	if (empty($hash)) {
        return generate_hash();
	} elseif (preg_match('/[a-fA-F0-9]{32}/', $hash)) {
        return $hash;
    } else {
        return generate_hash();
    }
}

function syslog_ia2xml($array) {
	$xml = '';

	if (cacti_sizeof($array)) {
		foreach ($array as $key=>$value) {
			if (is_array($value)) {
				$xml .= "\t<$key>" . syslog_ia2xml($value) . "</$key>\n";
			} else {
				$xml .= "\t<$key>" . html_escape($value) . "</$key>\n";
			}
		}
	}

	return $xml;
}

function syslog_array2xml($array, $tag = 'template') {
	static $index = 1;

	$xml = "<$tag$index>\n" . syslog_ia2xml($array) . "</$tag$index>\n";

	$index++;

	return $xml;
}

/**
 * syslog_process_alerts - Process each of the Syslog Alerts
 *
 * Syslog Alerts come in essentially 4 types
 *
 * System Wide non-threshold alerts - These alerts are simply alerts that match the pattern defined by the alert
 * System Wide threshold alerts     - These alerts are syslog messages that both match the pattern and have more than the
 *                                    threshold amount that take place every collector cycle (30 seconds, 1 minutes, 5 minutes, etc)
 * Host based non-threshold alerts  - Alerts that happen on a per host basis, so you can alert for each host that the syslog message
 *                                    occurred to.
 * Host based threshold alerts      - Like the system level alert, it's an alert that happens more than x times per host.
 *
 * The advantage and reason for having host based alerts is that it allows you to target ticket generation for a specific host
 * and more importantly, to be able to have a separate re-alert cycles for that very same message as there can be similar messages
 * happening all the time at the system level, so it's hard to target a single host for re-alert rules.
 *
 * @param  (int)   The unique id to process
 *
 * @return (array) An array of the number of alerts processed and the number of alerts generated
 */
function syslog_process_alerts($uniqueID) {
	global $syslogdb_default;

	$syslog_alarms = 0;
	$syslog_alerts = 0;

	/* send out the alerts */
	$alerts = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_alert`
		WHERE enabled='on'");

	if (cacti_sizeof($alerts)) {
		$syslog_alerts = cacti_sizeof($alerts);
	}

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Alerts...');
	syslog_debug('-------------------------------------------------------------------------------------');

	syslog_debug(sprintf('Found   %5s - Alert Rule(s) to process', $syslog_alerts));

	if (cacti_sizeof($alerts)) {
		foreach($alerts as $alert) {
			$sql      = '';
			$th_sql   = '';
			$params   = array();

			/* we roll up statistics depending on the level */
			if ($alert['level'] == 1) {
				$groupBy = ' GROUP BY host';
			} else {
				$groupBy = '';
			}

			$sql_data = syslog_get_alert_sql($alert, $uniqueID);

			if (!cacti_sizeof($sql_data)) {
				syslog_debug(sprintf('Error       - Unable to determine SQL for Alert \'%s\'', $alert['name']));
				continue;
			}

			$sql    = $sql_data['sql'];
			$params = $sql_data['params'];

			/**
			 * For this next step in processing, we want to call the syslog_process_alert
			 * once for every host, or system level breach that is encountered.  This removes
			 * must of the complexity that would otherwise go into the syslog_process_alert
			 * function.
			 */
			if ($sql != '') {
				if ($alert['level'] == '1') {
					/**
					 * This is a host level alert process each host separately
					 * both thresholed and system levels have the same process
					 */
					$th_sql  = str_replace('*', 'host, COUNT(*) AS count', $sql);
					$results = syslog_db_fetch_assoc_prepared($th_sql . $groupBy, $params);

					if (cacti_sizeof($results)) {
						foreach($results as $result) {
							$aparams   = $params;
							$aparams[] = $result['host'];

							$asql = $sql . ' AND host = ?';

							$syslog_alarms += syslog_process_alert($alert, $asql, $aparams, $result['count'], $result['host']);
						}
					}
				} elseif ($alert['method'] == '1') {
					/**
					 * This is a system level threshold breach
					 */
					$th_sql = str_replace('*', 'COUNT(*)', $sql);
					$count  = syslog_db_fetch_cell_prepared($th_sql . $groupBy, $params);
					$syslog_alarms += syslog_process_alert($alert, $sql, $params, $count);
				} else {
					/**
					 * This is a system level classic syslog breach without a threshold
					 */
					$count = 0;
					$syslog_alarms += syslog_process_alert($alert, $sql, $params, $count);
				}
			}
		}
	}

	return array('syslog_alerts' => $syslog_alerts, 'syslog_alarms' => $syslog_alarms);
}

function syslog_execute_ticket_command($alert, $hostlist, $context) {
	if ($alert['open_ticket'] == 'on') {
		$command = trim(read_config_option('syslog_ticket_command'));

		if ($command != '') {
			/*
			 * Extract the executable portion from the configured command.
			 * This allows for quoted paths and additional arguments in the
			 * configuration while still validating the executable itself.
			 */
			$executable = $command;
			$firstChar  = substr($executable, 0, 1);

			if ($firstChar === '"' || $firstChar === "'") {
				$quoteChar = $firstChar;
				$closing   = strpos($executable, $quoteChar, 1);

				if ($closing !== false) {
					$executable = substr($executable, 1, $closing - 1);
				} else {
					// Unbalanced quotes; fall back to trimming quotes/whitespace.
					$executable = trim($executable, " \t\n\r\0\x0B\"'");
				}
			} else {
				$parts = preg_split('/\s+/', $executable);
				if (is_array($parts) && isset($parts[0])) {
					$executable = $parts[0];
				}
				$executable = trim($executable, " \t\n\r\0\x0B\"'");
			}

			if ($executable !== '' && is_executable($executable)) {
				$command = $command .
					' --alert-name=' . cacti_escapeshellarg(clean_up_name($alert['name'])) .
					' --severity='   . cacti_escapeshellarg($alert['severity']) .
					' --hostlist='   . cacti_escapeshellarg(implode(',',$hostlist)) .
					' --message='    . cacti_escapeshellarg($alert['message']);

				$output = array();
				$return = 0;

				exec($command, $output, $return);

				if ($return != 0) {
					cacti_log(sprintf('ERROR: %s Failed. Alert:%s, Exit:%s, Output:%s', $context, $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
				}
			} else {
				if (strpos($executable, DIRECTORY_SEPARATOR) === false) {
					cacti_log(sprintf('SYSLOG ERROR: %s path \'%s\' is missing absolute path separator', $context, $executable), false, 'SYSTEM');
				} else {
					cacti_log(sprintf('SYSLOG ERROR: %s path \'%s\' is not executable', $context, $executable), false, 'SYSTEM');
				}
			}
		}
	}
}

function syslog_execute_alert_command($alert, $results, $hostname) {
	$command = trim($alert['command']);

	if ($command != '') {
		$command = alert_replace_variables($alert, $results, $hostname);

		/*
		 * Extract the executable portion from the command string.
		 * This allows for quoted paths and additional arguments.
		 */
		$executable = $command;
		$firstChar  = substr($executable, 0, 1);

		if ($firstChar === '"' || $firstChar === "'") {
			$quoteChar = $firstChar;
			$closing   = strpos($executable, $quoteChar, 1);

			if ($closing !== false) {
				$executable = substr($executable, 1, $closing - 1);
			} else {
				// Unbalanced quotes; fall back to trimming quotes/whitespace.
				$executable = trim($executable, " \t\n\r\0\x0B\"'");
			}
		} else {
			$parts = preg_split('/\s+/', $executable);
			if (is_array($parts) && isset($parts[0])) {
				$executable = $parts[0];
			}
			$executable = trim($executable, " \t\n\r\0\x0B\"'");
		}

		if ($executable !== '' && is_executable($executable)) {
			$output = array();
			$returnCode = 0;

			exec($command, $output, $returnCode);

			$logMessage = "SYSLOG NOTICE: Executing '$command' Command return code: $returnCode";
			cacti_log($logMessage, true, 'SYSTEM');
		} else {
			if (strpos($executable, DIRECTORY_SEPARATOR) === false) {
				cacti_log(sprintf('SYSLOG ERROR: Alert Command path \'%s\' is missing absolute path separator', $executable), false, 'SYSTEM');
			} else {
				cacti_log(sprintf('SYSLOG ERROR: Alert Command path \'%s\' is not executable', $executable), false, 'SYSTEM');
			}
		}
	}
}

/**
 * syslog_process_alert - Process the Alert and generate notifications, execute commands, etc.
 *
 * @param  (array)  The alert to process
 * @param  (string) The SQL to search for the Alert
 * @param  (array)  The SQL parameters to be prepared into the SQL
 * @param  (int)    In the case of a threshold alert, the number of occurrents
 *                  of hosts with occurrences that were encountered through
 *                  pre-processing the message
 * @param  (string) The hostname that this alert rule is for
 *
 * @return (int)    '1' if the alert triggered, else '0'
 */
function syslog_process_alert($alert, $sql, $params, $count, $hostname = '') {
	global $config, $severities, $syslog_levels;

	include_once($config['base_path'] . '/lib/reports.php');

	$messese  = '';
	$smsalert = '';

	$alert_count   = 0;
	$syslog_alarms = 0;
	$hostlist      = array();
	$max_alerts    = read_config_option('syslog_maxrecords');
	$report_tag    = false;
	$theme         = false;
	$format_ok     = false;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug(sprintf('Processing    - %s', $alert['name']));

	if (read_config_option('syslog_html') == 'on') {
		$html = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok':'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included':'missing'));
	} else {
		$html = false;
	}

	/**
	 * format the from Email address
	 */
	$from_email = read_config_option('settings_from_email');
	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');
	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = array($from_email, $from_name);

	/**
	 * format the destination Email addresses
	 */
	$alert['email'] = trim($alert['email'], ', ');
	if ($alert['notify'] > 0) {
		$additional = db_fetch_cell_prepared('SELECT emails
			FROM plugin_notification_lists
			WHERE id = ?',
			array($alert['notify']));

		if ($additional != '') {
			$alert['email'] .= ', ' . trim($additional, ' ,');
		}
	}

	/**
	 * process the alert now.
	 */
	if (($alert['method'] == '1' && $count >= $alert['num']) || $alert['method'] == '0') {
		$at = syslog_db_fetch_assoc_prepared($sql, $params);

		/**
		 * get a date for the repeat alert
		 */
		if ($alert['repeat_alert']) {
			$date = date('Y-m-d H:i:s', time() - ($alert['repeat_alert'] * read_config_option('poller_interval')));
		} else {
			$date = '';
		}

		/**
		 * The finalized email or test message.
		 */
		$message  = '';

		/**
		 * A list of all messages from the alert
		 */
		$results = array();

		syslog_debug(sprintf('Found   %5s - Matching Records.', cacti_sizeof($at)));

		if (cacti_sizeof($at)) {
			if ($html) {
				if (!$format_ok) {
					$message .= "<style type='text/css'>";
					$message .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
					$message .= '</style>';
				}

				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message  .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message  .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}

					$message  .= '<table class="cactiTable">';
					$message  .= '<tr class="header_row tableHeader">
						<th>' . __('Alert Name', 'syslog') . '</th>
						<th>' . __('Severity', 'syslog') . '</th>
						<th>' . __('Threshold', 'syslog') . '</th>
						<th>' . __('Count', 'syslog') . '</th>
						<th>' . __('Match String', 'syslog') . '</th>
					</tr>';

					$message  .= '<tr><td>' . html_escape($alert['name']) . '</td>';
					$message  .= '<td>'     . $severities[$alert['severity']]  . '</td>';
					$message  .= '<td>'     . $alert['num']     . '</td>';
					$message  .= '<td>'     . sizeof($at)       . '</td>';
					$message  .= '<td>'     . html_escape($alert['message']) . '</td></tr></table><br>';
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}
				}

				$message .= '<table class="cactiTable">';
				$message .= '<tr class="header_row tableHeader">
					<th>' . __('Hostname', 'syslog') . '</th>
					<th>' . __('Date', 'syslog')     . '</th>
					<th>' . __('Severity', 'syslog') . '</th>
					<th>' . __('Level', 'syslog')    . '</th>
					<th>' . __('Message', 'syslog')  . '</th>
				</tr>';
			} else {
				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						if ($hostname != '') {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered for Host \'%s\'', $hostname, 'syslog') . PHP_EOL . PHP_EOL;
						} else {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered', 'syslog') . PHP_EOL . PHP_EOL;
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'] . PHP_EOL;
					}

					$message .= __('Name:', 'syslog')           . ' ' . html_escape($alert['name'])     . PHP_EOL;
					$message .= __('Severity:', 'syslog')       . ' ' . $severities[$alert['severity']] . PHP_EOL;
					$message .= __('Threshold:', 'syslog')      . ' ' . $alert['num']                   . PHP_EOL;
					$message .= __('Count:', 'syslog')          . ' ' . sizeof($at)                     . PHP_EOL;
					$message .= __('Message String:', 'syslog') . ' ' . html_escape($alert['message'])  . PHP_EOL;
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog');
						} else {
							$message .= __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog');
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'];
					}
				}
			}

			$hmessage = $message;
			$plogged  = false;
			$flogged  = false;

			foreach($at as $a) {
				$hostlist[] = $a['host'];
				$results['message'] = (isset($results['message']) ? $results['message'] . ', ':'') . $a['message'];

				if (isset($results['priority_id']) && $results['priority_id'] != $a['priority_id'] && !$plogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one priority id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$plogged = true;
				}

				if (isset($results['facility_id']) && $results['facility_id'] != $a['facility_id'] && !$flogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one facility id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$flogged = true;
				}

				$results['priority_id'] = $a['priority_id'];
				$results['facility_id'] = $a['facility_id'];

				if (($alert['method'] == 1 && $alert_count < $max_alerts) || $alert['method'] == 0) {
					if ($alert['method'] == 0) {
						$message = $hmessage;
					}

					if ($html) {
						$message  .= '<tr>
							<td>' . html_escape($a['host'])           . '</td>
							<td>' . $a['logtime']                     . '</td>
							<td>' . $severities[$alert['severity']]   . '</td>
							<td>' . $syslog_levels[$a['priority_id']] . '</td>
							<td>' . html_escape($a['message'])        . '</td>
						</tr>';
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= __('Hostname:', 'syslog') . ' ' . html_escape($a['host'])           . PHP_EOL;
						$message .= __('Date:', 'syslog')     . ' ' . $a['logtime']                     . PHP_EOL;
						$message .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']]   . PHP_EOL . PHP_EOL;
						$message .= __('Level:', 'syslog')    . ' ' . $syslog_levels[$a['priority_id']] . PHP_EOL . PHP_EOL;
						$message .= __('Message:', 'syslog')  . ' ' . PHP_EOL . $a['message']           . PHP_EOL;
					}
				}
			}

			$hostlist = array_unique($hostlist);

			$syslog_alarms++;
			$alert_count++;

			$send  = true;
			$found = false;

			/**
			 * If this is a repeat alert type threshold, then check to
			 * see if it's time to re-alert.
			 */
			if ($alert['repeat_alert'] > 0) {
				if ($hostname != '') {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = ?',
						array($alert['id'], $date, $hostname));
				} else {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = "system"',
						array($alert['id'], $date));
				}
			}

			if ($found) {
				$send = false;
			}

			if ($html) {
				$message  .= '</table>';
			} else {
				$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
			}

			if ($html) {
				if ($format_ok) {
					if ($report_tag) {
						$message = str_replace('<REPORT>', $message, $output);
					} else {
						$message = $output . $message . '</body></html>';
					}
				} else {
					$message = '<html><body>' . $message . '</body></html>';
				}
			}

			/**
			 * This is a Traditional syslog alert where all matching messages
			 * will be reported in the notification.
			 */
			if ($alert['method'] == '0') {
				if ($send) {
					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $a, 1, $message);

					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Host:', 'syslog') . $a['host'] . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					/**
					 * Send the Email notification
					 */
					if ($alert['email'] != '' || $smsalert != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					/**
					 * Open a ticket if this options have been selected.
					 */
					syslog_execute_ticket_command($alert, $hostlist, 'Ticket Command');

					if (!$found) {
						syslog_execute_alert_command($alert, $results, $hostname);
					}

				}
			} elseif ($alert['method'] == 1) {
				if ($send) {
					/**
					 * Send the Email notification
					 */
					if ($alert['email'] != '' || $smsalert != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $at[0], sizeof($at), $message, $hostlist);
					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Count:', 'syslog') . sizeof($at) . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					/**
					 * Open a ticket if this options have been selected.
					 */
					syslog_execute_ticket_command($alert, $hostlist, 'Command');

					if (!$found) {
						syslog_execute_alert_command($alert, $results, $hostname);
					}
				}
			}

			syslog_debug("Alert Rule '" . $alert['name'] . "' has been triggered");
		}
	}

	return $alert_count;
}

/**
 * syslog_get_alert_sql - Get the SQL and params for the alert to
 *   checi.
 *
 * @param  (array)  The alert attributes to process
 *
 * @return (array)  The SQL and the prepared array for the SQL
 */
/**
 * syslog_build_match_filter - Builds a SQL WHERE clause and parameters for common match types.
 *
 * @param  (string) $type   The match type (facility, host, program, messageb, messagec, messagee, sql)
 * @param  (string) $value  The search value
 * @param  (string) $column The column name to search against (default depends on type)
 *
 * @return (array)  Array with 'sql' and 'params' keys
 */
function syslog_build_match_filter($type, $value, $column = '') {
	global $syslogdb_default;
	$sql    = '';
	$params = array();

	switch ($type) {
		case 'facility':
			if ($column == 'facility_id') {
				$sql = 'facility_id IN (SELECT DISTINCT facility_id FROM `' . $syslogdb_default . '`.`syslog_facilities` WHERE facility = ?)';
			} else {
				$sql = ($column ?: 'facility') . ' = ?';
			}
			$params[] = $value;
			break;
		case 'host':
			if ($column == 'host_id') {
				$sql = 'host_id IN (SELECT DISTINCT host_id FROM `' . $syslogdb_default . '`.`syslog_hosts` WHERE host = ?)';
			} else {
				$sql = ($column ?: 'host') . ' = ?';
			}
			$params[] = $value;
			break;
		case 'program':
			if ($column == 'program_id') {
				$sql = 'program_id IN (SELECT DISTINCT program_id FROM `' . $syslogdb_default . '`.`syslog_programs` WHERE program = ?)';
			} else {
				$sql = ($column ?: 'program') . ' = ?';
			}
			$params[] = $value;
			break;
		case 'messageb':
			$sql      = ($column ?: 'message') . ' LIKE ?';
			$params[] = $value . '%';
			break;
		case 'messagec':
			$sql      = ($column ?: 'message') . ' LIKE ?';
			$params[] = '%' . $value . '%';
			break;
		case 'messagee':
			$sql      = ($column ?: 'message') . ' LIKE ?';
			$params[] = '%' . $value;
			break;
		case 'sql':
			/* The 'sql' match type passes admin-configured expressions directly into
			 * the WHERE clause. This is an intentional trust boundary: only Cacti
			 * administrators with console access can configure removal/alert rules.
			 * No programmatic sanitization can safely parse arbitrary SQL fragments. */
			$sql = '(' . $value . ')';
			break;
	}

	return array('sql' => $sql, 'params' => $params);
}

function syslog_get_alert_sql(&$alert, $uniqueID) {
	global $syslogdb_default, $syslog_incoming_config;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if (!isset($syslog_incoming_config['programField'])) {
		$syslog_incoming_config['programField'] = 'program';
	}

	$field_map = array(
		'facility' => $syslog_incoming_config['facilityField'],
		'host'     => $syslog_incoming_config['hostField'],
		'program'  => $syslog_incoming_config['programField'],
		'messageb' => $syslog_incoming_config['textField'],
		'messagec' => $syslog_incoming_config['textField'],
		'messagee' => $syslog_incoming_config['textField'],
	);

	$column = $field_map[$alert['type']] ?? '';
	$filter = syslog_build_match_filter($alert['type'], $alert['message'], $column);

	$sql    = 'SELECT *
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE ' . $filter['sql'] . '
		AND `status` = ?';

	$params = $filter['params'];
	$params[] = $uniqueID;

	return array('sql' => $sql, 'params' => $params);
}

/**
 * syslog_preprocess_incoming_records - Generate a uniqueID to allow moving of
 *   records to done table and mark incoming records with the uniqueID and
 *   then if syslog is configured to strip domains, perform that first.
 *
 * @return (int) Unique id to allow syslog messages that come in randomly to
 *               be differentiate between messages to process and messages
 *               to be left till then ext polling cycle.
 */
function syslog_preprocess_incoming_records() {
	global $syslogdb_default, $syslog_cnn;

	$lock_name = hash('sha256', $syslogdb_default . '.preprocess_incoming');
	$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', array($lock_name));

	if ((int)$locked !== 1) {
		cacti_log('SYSLOG ERROR: Unable to acquire preprocess lock', false, 'SYSTEM');
		return array('uniqueID' => 0, 'incoming' => 0);
	}

	$uniqueID = 0;
	$incoming = 0;

	/* uniqueID is constrained to tinyint range (1-127) by the status column.
	 * Collision probability rises with concurrent pollers; the retry loop
	 * (up to 256 attempts) mitigates but does not eliminate this risk.
	 * A future schema change to widen status to int would allow a larger space. */
	try {
		$attempts = 0;
		while (1) {
			$uniqueID = rand(1, 127);

			$count = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
				FROM `' . $syslogdb_default . '`.`syslog_incoming`
				WHERE `status` = ?',
				array($uniqueID));

			if ($count == 0) {
				break;
			}

			$attempts++;

			if ($attempts >= 256) {
				cacti_log('SYSLOG ERROR: Unable to find unused uniqueID after 256 attempts', false, 'SYSTEM');
				return array('uniqueID' => 0, 'incoming' => 0);
			}
		}

		/* flag all records with the uniqueID prior to moving */
		syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
			SET `status` = ?
			WHERE `status` = 0',
			array($uniqueID));

		$incoming = db_affected_rows($syslog_cnn);
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', array($lock_name));
	}

	syslog_debug('Unique ID = ' . $uniqueID);
	syslog_debug('-------------------------------------------------------------------------------------');

	syslog_debug(sprintf('Found   %5s - New Message(s) to process', $incoming));

	/* strip domains if we have requested to do so */
	syslog_strip_incoming_domains($uniqueID);

	api_plugin_hook('plugin_syslog_before_processing');

	return array('uniqueID' => $uniqueID, 'incoming' => $incoming);
}

/**
 * syslog_strip_incoming_domains - If syslog is setup to strip DNS domain name suffixes do that
 *   prior to processing the records.
 *
 * @param  (string) The uniqueID records to process
 *
 * @return (void)
 */
function syslog_strip_incoming_domains($uniqueID) {
	global $syslogdb_default;

	$syslog_domains = read_config_option('syslog_domains');

	if ($syslog_domains != '') {
		$domains = explode(',', trim($syslog_domains));

		foreach($domains as $domain) {
			syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . "`.`syslog_incoming`
				SET host = SUBSTRING_INDEX(host, '.', 1)
				WHERE host LIKE ?
				AND `status` = ?",
				array('%' . $domain, $uniqueID));
		}
	}
}



/**
 * Check if the hostname is in the cacti hosts table
 * Some devices only send IP addresses in syslog messages, and may not be in the DNS
 * however they may be in the cacti hosts table as monitored devices.
 * 
 * @param  (string) The hostname to check
 * @param  (int) The unique id for syslog_incoming messages to process
 * 
 * @return (bool) True if the host exists in the Cacti database, false otherwise
 */

function syslog_check_cacti_hosts($host, $uniqueID) {
	global $syslogdb_default;

	if (empty($host)) {
		return false;
	}

	// Check if the host exists in cacti by hostname and get the description
	$cacti_host = db_fetch_row_prepared('SELECT DISTINCT description
		FROM host
		WHERE hostname = ?
		LIMIT 1',
		array($host));

	if (cacti_sizeof($cacti_host) && !empty($cacti_host['description'])) {
		syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
			SET host = ?
			WHERE host = ?
			AND `status` = ?',
			array($cacti_host['description'], $host, $uniqueID));
		
		return true;
	}
	
	return false;
}
/**
 * syslog_update_reference_tables - There are many values in the syslog plugin
 *   that for the purposes of reducing the size of the syslog table are normalized
 *   the columns includes the facility, the priority, and the hostname.
 *
 *   This function will add those new hostnames to the various reference tables
 *   and assign an id to each of them.  This way the syslog table can be optimized
 *   for size as much as possible.
 *
 * @param  (int)  The unique id for syslog_incoming messages to process
 *
 * @return (void)
 */
function syslog_update_reference_tables($uniqueID) {
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Updating Reference Tables from New Syslog Records');

	/* Validate and resolve hostnames - check DNS first, then Cacti, then mark invalid */
    if (read_config_option('syslog_resolve_hostname') == 'on') {
        $hosts = syslog_db_fetch_assoc_prepared('SELECT DISTINCT host
            FROM `' . $syslogdb_default . '`.`syslog_incoming`
            WHERE `status` = ?',
            array($uniqueID));

        foreach($hosts as $host) {
            if (!isset($host['host']) || empty($host['host'])) {
                continue;
            }
            
            $resolved = false;
            
            // Check if hostname resolves via DNS (only if DNS is enabled)
            if (read_config_option('syslog_no_dns') != 'on') {
                if ($host['host'] != gethostbyname($host['host'])) {
                    // DNS resolved successfully
                    $resolved = true;
                }
            }
            
            // Check if hostname exists in Cacti hosts table (only if not already resolved via DNS)
            if (!$resolved) {
                $resolved = syslog_check_cacti_hosts($host['host'], $uniqueID);
            }
            
            // If not resolved via DNS or found in Cacti, prefix the hostname
            if (!$resolved) {
                $unresolved_host = 'unresolved-' . $host['host'];
                cacti_log("SYSLOG WARNING: Hostname '" . $host['host'] . "' could not be resolved via DNS or found in Cacti hosts table, marking as '" . $unresolved_host . "'", false, 'SYSLOG');
                syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . "`.`syslog_incoming`
                    SET host = ?
                    WHERE host = ?
                    AND `status` = ?",
                    array($unresolved_host, $host['host'], $uniqueID));
            }
        }
    }

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_programs`
		(program, last_updated)
		SELECT DISTINCT program, NOW()
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = ?
		ON DUPLICATE KEY UPDATE
			program=VALUES(program),
			last_updated=VALUES(last_updated)',
		array($uniqueID));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_hosts`
		(host, last_updated)
		SELECT DISTINCT host, NOW() AS last_updated
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = ?
		ON DUPLICATE KEY UPDATE
			host=VALUES(host),
			last_updated=NOW()',
		array($uniqueID));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_host_facilities`
		(host_id, facility_id)
		SELECT host_id, facility_id
		FROM (
			(
				SELECT DISTINCT host, facility_id
				FROM `' . $syslogdb_default . "`.`syslog_incoming`
				WHERE `status` = ?
			) AS s
			INNER JOIN `" . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON s.host = sh.host
		)
		ON DUPLICATE KEY UPDATE
			host_id=VALUES(host_id),
			last_updated=NOW()',
		array($uniqueID));
}

/**
 * syslog_update_statistics - Insert new statistics rows into the syslog statistics
 *   table for post review
 *
 * @param  (int) The unique id for all syslog incoming records to be processed
 *
 * @return (void)
 */
function syslog_update_statistics($uniqueID) {
	global $syslogdb_default, $syslog_cnn;

	if (read_config_option('syslog_statistics') == 'on') {
		syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_statistics`
			(host_id, facility_id, priority_id, program_id, insert_time, records)
			SELECT host_id, facility_id, priority_id, program_id, NOW(), SUM(records) AS records
			FROM (SELECT host_id, facility_id, priority_id, program_id, COUNT(*) AS records
				FROM syslog_incoming AS si
				INNER JOIN syslog_hosts AS sh
				ON sh.host=si.host
				INNER JOIN syslog_programs AS sp
				ON sp.program=si.program
				WHERE `status` = ?
				GROUP BY host_id, priority_id, facility_id, program_id) AS merge
			GROUP BY host_id, priority_id, facility_id, program_id',
			array($uniqueID));

		$stats = db_affected_rows($syslog_cnn);

		syslog_debug('Stats   ' . $stats . " - Record(s) to the 'syslog_statistics' table");
	}
}

/**
 * syslog_incoming_to_syslog - Move incoming syslog records to the syslog table
 *
 * Once all Alerts have been processed, we need to move entries first to
 * the syslog table, and then after which we can perform various
 * removal rules against them.
 *
 * @param  (int) The unique id for rows in the syslog table
 *
 * @return (int) The number of rows moved to the syslog table
 */
function syslog_incoming_to_syslog($uniqueID) {
	global $syslogdb_default, $syslog_cnn;

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT logtime, priority_id, facility_id, program_id, host_id, message
		FROM (
			SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
			FROM syslog_incoming AS si
			INNER JOIN syslog_hosts AS sh
			ON sh.host = si.host
			INNER JOIN syslog_programs AS sp
			ON sp.program = si.program
			WHERE `status` = ?
		) AS merge',
		array($uniqueID));

	$moved = db_affected_rows($syslog_cnn);

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Moving or Removing Processed Records');

	syslog_debug(sprintf('Moved   %5s - Message(s) to the syslog table', $moved));

	syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE status = ?', array($uniqueID));

	syslog_debug(sprintf('Deleted %5s - Already Processed Message(s) from incoming', db_affected_rows($syslog_cnn)));

	syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE logtime < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

	$stale = db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s - Stale Message(s) from incoming', $stale));

	return array('moved' => $moved, 'stale' => $stale);
}

/**
 * syslog_postprocess_tables - Remove stale records and optimize tables after
 *   message processing has been completed.
 *
 * @return (void)
 */
function syslog_postprocess_tables() {
	global $syslogdb_default, $syslog_cnn;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Post Processing/Maintenance of Syslog Tables');
	syslog_debug('-------------------------------------------------------------------------------------');

	$delete_date = date('Y-m-d H:i:s', time() - (read_config_option('syslog_retention')*86400));

	/* remove stats messages */
	if (read_config_option('syslog_statistics') == 'on') {
		if (read_config_option('syslog_retention') > 0) {
			syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_statistics`
				WHERE insert_time < ?',
				array($delete_date));

			syslog_debug(sprintf('Deleted %5s - Syslog Statistics Record(s)', db_affected_rows($syslog_cnn)));
		}
	} else {
		syslog_db_execute_prepared('TRUNCATE `' . $syslogdb_default . '`.`syslog_statistics`');
	}

	/* remove alert log messages */
	if (read_config_option('syslog_alert_retention') > 0) {
		api_plugin_hook_function('syslog_delete_hostsalarm', $delete_date);

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_logs`
			WHERE logtime < ?',
			array($delete_date));

		syslog_debug(sprintf('Deleted %5s - Syslog alarm log Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_hosts`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug(sprintf('Deleted %5s - Syslog Host Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_programs`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug(sprintf('Deleted %5s - Old programs from programs table', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_host_facilities`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug(sprintf('Deleted %5s - Syslog Host/Facility Record(s)', db_affected_rows($syslog_cnn)));
	}

	/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
	if (date('G') == 0 && date('i') < 5) {
		syslog_debug('Optimizing Tables');
		if (!syslog_is_partitioned()) {
			syslog_db_execute_prepared('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_removed`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		} else {
			syslog_db_execute_prepared('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		}
	}
}

/**
 * syslog_process_reports - Processes all syslog reports scheduled to run
 *
 * @return (array) An array of total and sent reports
 */
function syslog_process_reports() {
	global $config, $syslogdb_default, $syslog_cnn, $forcer;

	include_once($config['base_path'] . '/lib/reports.php');

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Reports...');
	syslog_debug('-------------------------------------------------------------------------------------');

	$report_tag = false;
	$theme      = false;
	$format_ok  = false;

	if (read_config_option('syslog_html') == 'on') {
		$html = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok':'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included':'missing'));
	} else {
		$html = false;
	}

	$from_email = read_config_option('settings_from_email');
	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');
	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = array($from_email, $from_name);

	/* Lets run the reports */
	$reports = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_reports`
		WHERE enabled='on'");

	$total_reports = cacti_sizeof($reports);
	$sent_reports  = 0;

	syslog_debug('We have ' . $total_reports . ' Reports in the database to check');

	if (cacti_sizeof($reports)) {
		$total_reports = cacti_sizeof($reports);
		foreach($reports as $report) {
			syslog_debug('-------------------------------------------------------------------------------------');
			syslog_debug(sprintf('Processing    - %s', $report['name']));

			$base_start_time = $report['timepart'];
			$last_run_time   = $report['lastsent'];
			$time_span       = $report['timespan'];
			$seconds_offset  = read_config_option('cron_interval');

			$current_time = time();

			if (empty($last_run_time)) {
				$start = strtotime(date('Y-m-d 00:00', $current_time)) + $base_start_time;

				if ($current_time > $start) {
					/* if timer expired within a polling interval, then poll */
					if (($current_time - $seconds_offset) < $start) {
						$next_run_time = $start;
					} else {
						$next_run_time = $start+ 3600*24;
					}
				} else {
					$next_run_time = $start;
				}
			} else {
				$next_run_time = strtotime(date('Y-m-d 00:00', $last_run_time)) + $base_start_time + $time_span;
			}

			$time_till_next_run = $next_run_time - $current_time;

			if ($time_till_next_run < 0 || $forcer) {
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_reports`
					SET lastsent = ?
					WHERE id = ?',
					array(time(), $report['id']));

				syslog_debug('Next Send     - Now');
				syslog_debug('Creating Report...');

				$reptext = '';

				$report_sql = syslog_get_report_sql($report);

				if ($report_sql['sql'] != '') {
					$date2 = date('Y-m-d H:i:s', $current_time);
					$date1 = date('Y-m-d H:i:s', $current_time - $time_span);
					
					$sql    = $report_sql['sql'] . ' AND logtime BETWEEN ? AND ? ORDER BY logtime DESC';
					$params = array_merge($report_sql['params'], array($date1, $date2));
					
					$items = syslog_db_fetch_assoc_prepared($sql, $params);

					syslog_debug('We have ' . cacti_sizeof($items) . ' items for the Report');

					$classes = array('even', 'odd');

					if (cacti_sizeof($items)) {
						$i = 0;
						foreach($items as $item) {
							$class = $classes[$i % 2];

							$reptext .= '<tr class="' . $class . '">
								<td class="host">'    . html_escape($item['host'])    . '</td>
								<td class="date">'    . $item['logtime']              . '</td>
								<td class="message">' . html_escape($item['message']) . '</td>
							</tr>';

							$i++;
						}
					}

					if ($reptext != '') {
						if (!$format_ok) {
							$message  = '<style type="text/css">';
							$message .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
							$message .= '</style>';
						}

						$message .= '<h1>Cacti Syslog Report - ' . html_escape($report['name']) . '</h1>';
						$message .= '<hr>';
						$message .= '<p>' . $report['body'] . '</p>';
						$message .= '<hr>';

						$message .= '<table class="cactiTable">';

						$message .= '<tr class="header_row tableHeader">
							<th>' . __('Host', 'syslog')    . '</th>
							<th>' . __('Date', 'syslog')    . '</th>
							<th>' . __('Message', 'syslog') . '</th>
						</tr>';

						$message .= $reptext;

						$message .= '</table>';

						$smsalert  = '';

						$sent_reports++;

						if ($html) {
							if ($format_ok) {
								if ($report_tag) {
									$message = str_replace('<REPORT>', $message, $output);
								} else {
									$message = $output . $message . '</body></html>';
								}
							} else {
								$message = '<html><body>' . $message . '</body></html>';
							}
						}

						syslog_sendemail($report['email'], $from, __esc('Event Report - %s', $report['name'], 'syslog'), $message, $smsalert);
					}
				}
			} else {
				syslog_debug('Next Send     - ' . date('Y-m-d H:i:s', $next_run_time));
			}
		}
	}

	return array('total_reports' => $total_reports, 'sent_reports' => $sent_reports);
}

/**
 * syslog_get_report_sql - Return the SQL syntax for the report query
 *
 * @param  (array)  The report to process
 *
 * @return (array)  The SQL and the prepared array for the SQL
 */
function syslog_get_report_sql(&$report) {
	global $syslogdb_default;

	$sql    = '';
	$params = array();

	$column_map = array(
		'facility' => 'sf.facility',
		'host'     => 'sh.host',
		'program'  => 'sp.program',
		'messageb' => 'message',
		'messagec' => 'message',
		'messagee' => 'message',
	);

	$column = $column_map[$report['type']] ?? '';
	$filter = syslog_build_match_filter($report['type'], $report['message'], $column);

	if (!empty($filter['sql'])) {
		if ($report['type'] == 'host') {
			$sql = 'SELECT sl.*, sh.host
				FROM `' . $syslogdb_default . '`.`syslog` AS sl
				INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
				ON sl.host_id = sh.host_id
				WHERE ' . $filter['sql'];
		} elseif ($report['type'] == 'facility') {
			$sql = 'SELECT sl.*, sf.facility
				FROM `' . $syslogdb_default . '`.`syslog` AS sl
				INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
				ON sl.facility_id = sf.facility_id
				WHERE ' . $filter['sql'];
		} elseif ($report['type'] == 'program') {
			$sql = 'SELECT sl.*, sp.program
				FROM `' . $syslogdb_default . '`.`syslog` AS sl
				INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS sp
				ON sl.program_id = sp.program_id
				WHERE ' . $filter['sql'];
		} elseif ($report['type'] == 'sql') {
			$sql = 'SELECT *
				FROM `' . $syslogdb_default . '`.`syslog`
				WHERE ' . $filter['sql'];
		} else {
			$sql = 'SELECT sl.*, sh.host
				FROM `' . $syslogdb_default . '`.`syslog` AS sl
				INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
				ON sl.host_id = sh.host_id
				WHERE ' . $filter['sql'];
		}
		$params = $filter['params'];
	}

	return array('sql' => $sql, 'params' => $params);
}

/**
 * generate a Cacti log message and save settings in the settings table for use
 *   by various graph templates
 *
 * @param  (string) The start time of the polling process
 * @param  (int)    The number of syslog messages deleted
 * @param  (int)    The number of syslog incoming messages
 * @param  (int)    The number of syslog messages removed
 * @param  (int)    The number of syslog messages transferred
 * @param  (int)    The number of alerts processed
 * @param  (int)    The number of alerts triggered
 * @param  (int)    The number of reports sent
 *
 * @return (void)
 */
function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	global $database_default, $debug;

	/* record the end time */
	$end_time = microtime(true);

	$stats =
		' Time:'     . round($end_time-$start_time,2) .
		' Deletes:'  . $deleted  .
		' Incoming:' . $incoming .
		' Removes:'  . $removed  .
		' XFers:'    . $xferred  .
		' Alerts:'   . $alerts   .
		' Alarms:'   . $alarms   .
		' Reports:'  . $reports;

	cacti_log('SYSLOG STATS:' . $stats, false, 'SYSTEM');

	syslog_debug('-------------------------------------------------------------------------------------');

	if ($debug) {
		syslog_debug($stats);
	} else {
		print date('H:m:s') . ' SYSLOG NOTE: ' . $stats . PHP_EOL;
	}

	set_config_option('syslog_stats',
		'time:' . round($end_time-$start_time,2) .
		' deletes:'  . $deleted  .
		' incoming:' . $incoming .
		' removes:'  . $removed  .
		' xfers:'    . $xferred  .
		' alerts:'   . $alerts   .
		' alarms:'   . $alarms   .
		' reports:'  . $reports
	);
}

/**
 * syslog_init_variables - initialize key variables on first pass of a run
 *   of the syslog plugin.  This function should not have to run more than
 *   once during the syslog plugins lifecycle.
 *
 * @return (void)
 */
function syslog_init_variables() {
	$syslog_retention = read_config_option('syslog_retention');
	$alert_retention  = read_config_option('syslog_alert_retention');

	if ($syslog_retention == '' or $syslog_retention < 0 or $syslog_retention > 365) {
		set_config_option('syslog_retention', '30');
	}

	if ($alert_retention == '' || $alert_retention < 0 || $alert_retention > 365) {
		set_config_option('syslog_alert_retention', '30');
	}

	if (substr(read_config_option('base_url'), 0, 4) != 'http') {
		if (read_config_option('force_https') == 'on') {
			$prefix = 'https://';
		} else {
			$prefix = 'http://';
		}

		set_config_option('base_url', $prefix . read_config_option('base_url'));
	}
}

/**
 * alert_setup_environment - set's up the environment for a syslog alert
 *
 * @param  (array)  The alert definition
 * @param  (string) A comma delimited list of syslog messages
 * @param  (array)  The list of hosts that match for the alert
 * @param  (string) The hostname in the case of a host level alert
 *
 * @return (void)
 */
function alert_setup_environment(&$alert, $results, $hostlist = array(), $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	putenv('ALERT_ALERTID='       . cacti_escapeshellarg($alert['id']));
	putenv('ALERT_NAME='          . cacti_escapeshellarg(clean_up_name($alert['name'])));
	putenv('ALERT_MESSAGE='       . cacti_escapeshellarg($alert['message']));

	putenv('ALERT_SEVERITY='      . cacti_escapeshellarg($alert['severity']));
	putenv('ALERT_SEVERITY_TEXT=' . cacti_escapeshellarg($severities[$alert['severity']]));

	putenv('ALERT_PRIORITY='      . cacti_escapeshellarg($syslog_levels[$results['priority_id']]));
	putenv('ALERT_FACILITY='      . cacti_escapeshellarg($syslog_facilities[$results['facility_id']]));

	putenv('ALERT_HOSTLIST='      . cacti_escapeshellarg(implode(',', $hostlist)));
	putenv('ALERT_HOSTNAME='      . cacti_escapeshellarg($hostname));

	putenv('ALERT_MESSAGES='      . cacti_escapeshellarg(trim(str_replace("\0", ' ', $results['message']))));
}

/**
 * alert_replace_variables - add command line parameter to the syslog command
 *   or ticket opening script
 *
 * @param  (array)  The alert definition
 * @param  (string) A comma delimited list of syslog messages
 * @param  (string) The hostname in the case of a host level alert
 *
 * @return (string) The command and it'a arguments escaped
 */
function alert_replace_variables($alert, $results, $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	$command = $alert['command'];

	$command = str_replace('<ALERTID>',  cacti_escapeshellarg($alert['id']), $command);
	$command = str_replace('<HOSTNAME>', cacti_escapeshellarg($hostname), $command);
	$command = str_replace('<PRIORITY>', cacti_escapeshellarg($syslog_levels[$results['priority_id']]), $command);
	$command = str_replace('<FACILITY>', cacti_escapeshellarg($syslog_facilities[$results['facility_id']]), $command);
	$command = str_replace('<MESSAGE>',  cacti_escapeshellarg($results['message']), $command);
	$command = str_replace('<SEVERITY>', cacti_escapeshellarg($severities[$alert['severity']]), $command);

	return $command;
}
