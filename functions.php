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


function syslog_apply_selected_items_action($selected_items, $drp_action, $action_map, $export_action = '', $export_items = '') {
	if ($selected_items != false) {
		if (isset($action_map[$drp_action])) {
			$action_function = $action_map[$drp_action];

			if (function_exists($action_function)) {
				foreach($selected_items as $selected_item) {
					$action_function($selected_item);
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

function syslog_is_partitioned() {
	global $syslogdb_default, $database_default;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	$syslog_partitioning = read_config_option('syslog_partitioning');

	if ($syslog_partitioning == 'on') {
		$table_name = 'syslog';

		$result = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
			FROM information_schema.partitions
			WHERE table_schema = ?
			AND table_name = ?
			AND partition_name IS NOT NULL',
			array($syslogdb_default, $table_name));

		if ($result > 0) {
			return true;
		}
	}

	return false;
}

function syslog_partition_create($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	$lock_name = 'syslog_partition_create_' . $table;
	$lock = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', array($lock_name));

	if (!$lock) {
		syslog_debug("SYSLOG: Failed to acquire lock for $lock_name");
		return false;
	}

	try {
		$next_date = date('Ymd', strtotime('+1 day'));
		$part_name = 'd' . $next_date;

		syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table` ADD PARTITION (PARTITION $part_name VALUES LESS THAN ($next_date))");
		cacti_log("SYSLOG: Created new partition '$part_name' for table '$table'", false, 'SYSTEM');
	} catch (Exception $e) {
		cacti_log("SYSLOG ERROR: Failed to create partition for $table: " . $e->getMessage(), false, 'SYSTEM');
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', array($lock_name));
	}

	return true;
}

function syslog_partition_remove($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	$syslog_deleted = 0;
	$lock_name = 'syslog_partition_remove_' . $table;
	$lock = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', array($lock_name));

	if (!$lock) {
		syslog_debug("SYSLOG: Failed to acquire lock for $lock_name");
		return false;
	}

	$number_of_partitions = syslog_db_fetch_assoc_prepared("SELECT PARTITION_NAME
		FROM `information_schema`.`partitions`
		WHERE table_schema = ? AND table_name = ?
		AND PARTITION_NAME IS NOT NULL
		ORDER BY partition_ordinal_position",
		array($syslogdb_default, $table));

	$days = read_config_option('syslog_retention');

	syslog_debug("There are currently '" . cacti_sizeof($number_of_partitions) . "' Syslog Partitions, We will keep '$days' of them.");

	if ($days > 0) {
		$user_partitions = cacti_sizeof($number_of_partitions);
		if ($user_partitions > $days) {
			$i = 0;
			while ($user_partitions > $days) {
				$oldest = $number_of_partitions[$i];

				cacti_log("SYSLOG: Removing old partition '" . $oldest['PARTITION_NAME'] . "'", false, 'SYSTEM');
				syslog_debug("Removing partition '" . $oldest['PARTITION_NAME'] . "'");

				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table` DROP PARTITION " . $oldest['PARTITION_NAME']);

				$i++;
				$user_partitions--;
				$syslog_deleted++;
			}
		}
	}

	syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', array($lock_name));

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
		WHERE table_schema = ? AND table_name = ?
		AND PARTITION_NAME IS NOT NULL
		ORDER BY partition_ordinal_position DESC
		LIMIT 1",
		array($syslogdb_default, $table));

	if (!$last_part) {
		return true;
	}

	$lformat = str_replace('d', '', $last_part);
	$cformat = date('Ymd');

	if ($lformat <= $cformat) {
		return true;
	} else {
		return false;
	}
}

function syslog_partition_table_allowed($table) {
	$allowed = array('syslog', 'syslog_incoming');
	if (in_array($table, $allowed)) {
		return true;
	}
	return false;
}

function syslog_remove_items($table, $max_seq) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Removal Rules...');

	if ($table == 'syslog') {
		$rows = syslog_db_fetch_assoc_prepared("SELECT *
			FROM `" . $syslogdb_default . "`.`syslog_remove`
			WHERE enabled = 'on'
			AND id <= ?", array($max_seq));
	} else {
		$rows = syslog_db_fetch_assoc('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_remove`
			WHERE enabled="on"');
	}

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));

	$removed = 0;
	$xferred = 0;

	if ($table == 'syslog_incoming') {
		$total = syslog_db_fetch_cell_prepared('SELECT count(*)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?',
			array($max_seq));
	} else {
		$total = 0;
	}

	if (cacti_sizeof($rows)) {
		foreach($rows as $remove) {
			$sql  = '';
			$sql1 = '';
			$sql2 = '';

			$params = array();

			if ($remove['type'] == 'facility') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['facilityField'] . '` = ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = $remove['message'];
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'messageb') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = $remove['message'] . '%';
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'messagec') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = '%' . $remove['message'] . '%';
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'messagee') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = '%' . $remove['message'];
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'host') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['hostField'] . '` = ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = $remove['message'];
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'program') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE `' . $syslog_incoming_config['programField'] . '` = ?
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = $remove['message'];
				$params[] = $max_seq;
			} elseif ($remove['type'] == 'sql') {
				$sql = 'FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE (' . $remove['message'] . ')
					AND `status` = 1
					AND `seq` <= ?';

				$params[] = $max_seq;
			}

			if ($sql != '') {
				if ($remove['action'] == 1) {
					/* remove them */
					$sql1 = 'DELETE ' . $sql;
					syslog_db_execute_prepared($sql1, $params);
					$removed += db_affected_rows($syslog_cnn);
				} elseif ($remove['action'] == 2) {
					/* transfer them */
					$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog`
						(logtime, priority_id, facility_id, program_id, host_id, message)
						SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
						FROM (
							SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message ' . $sql . '
							INNER JOIN syslog_hosts AS sh
							ON sh.host = syslog_incoming.host
							INNER JOIN syslog_programs AS sp
							ON sp.program = syslog_incoming.program
						) AS merge';

					syslog_db_execute_prepared($sql1, $params);
					$xferred += db_affected_rows($syslog_cnn);

					$sql2 = 'DELETE ' . $sql;
					syslog_db_execute_prepared($sql2, $params);
				}
			}
		}
	}

	syslog_debug(sprintf('Removed %5s - Record(s) from incoming', $removed));
	syslog_debug(sprintf('Xferred %5s - Record(s) to the syslog table', $xferred));

	return array('removed' => $removed, 'xferred' => $xferred);
}

function syslog_process_alerts($max_seq) {
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
			$params   = array();

			/* we roll up statistics depending on the level */
			if ($alert['level'] == 1) {
				$groupBy = ' GROUP BY host';
			} else {
				$groupBy = '';
			}

			$sql_data = syslog_get_alert_sql($alert, $max_seq);

			if (!cacti_sizeof($sql_data)) {
				syslog_debug(sprintf('Error       - Unable to determine SQL for Alert \'%s\'', $alert['name']));
				continue;
			}

			$sql    = $sql_data['sql'];
			$params = $sql_data['params'];

			if ($sql != '') {
				$results = syslog_db_fetch_assoc_prepared($sql . $groupBy, $params);

				if (cacti_sizeof($results)) {
					foreach($results as $result) {
						$syslog_alarms++;
						syslog_debug(sprintf('Alert       - Alert \'%s\' matched', $alert['name']));

						/* send the alert */
						syslog_send_alert($alert, $result);
					}
				}
			}
		}
	}

	return array('syslog_alerts' => $syslog_alerts, 'syslog_alarms' => $syslog_alarms);
}

function syslog_get_alert_sql(&$alert, $max_seq) {
	global $syslogdb_default, $syslog_incoming_config;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if (!isset($syslog_incoming_config['programField'])) {
		$syslog_incoming_config['programField'] = 'program';
	}

	$params = array();
	$sql    = '';

	if ($alert['type'] == 'facility') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['facilityField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messageb') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagec') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = '%' . $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagee') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = '%' . $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'host') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['hostField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'program') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `' . $syslog_incoming_config['programField'] . '` = ?
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'sql') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE (' . $alert['message'] . ')
			AND `status` = 1
			AND `seq` <= ?';

		$params[] = $max_seq;
	}

	return array('sql' => $sql, 'params' => $params);
}

function syslog_preprocess_incoming_records() {
	global $syslogdb_default;

	$max_seq = syslog_db_fetch_cell('SELECT MAX(seq) FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE status = 0');

	if ($max_seq > 0) {
		/* flag all records with the status = 1 prior to moving */
		syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
			SET `status` = 1
			WHERE `status` = 0
			AND `seq` <= ?',
			array($max_seq));

		syslog_debug('Max Sequence ID = ' . $max_seq);
		syslog_debug('-------------------------------------------------------------------------------------');

		$syslog_incoming = syslog_db_fetch_cell_prepared('SELECT COUNT(seq)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?',
			array($max_seq));

		syslog_debug(sprintf('Found   %5s - New Message(s) to process', $syslog_incoming));

		/* strip domains if we have requested to do so */
		syslog_strip_incoming_domains($max_seq);

		api_plugin_hook('plugin_syslog_before_processing');

		return array('max_seq' => $max_seq, 'incoming' => $syslog_incoming);
	}

	return array('max_seq' => 0, 'incoming' => 0);
}

function syslog_strip_incoming_domains($max_seq) {
	global $syslogdb_default;

	$syslog_domains = read_config_option('syslog_domains');

	if ($syslog_domains != '') {
		$domains = explode(',', trim($syslog_domains));

		foreach($domains as $domain) {
			syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
				SET host = SUBSTRING_INDEX(host, \'.\', 1)
				WHERE host LIKE ?
				AND `status` = 1
				AND `seq` <= ?',
				array(\'%\' . $domain, $max_seq));
		}
	}
}

function syslog_check_cacti_hosts($host, $max_seq) {
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
			AND `status` = 1
			AND `seq` <= ?',
			array($cacti_host['description'], $host, $max_seq));

		return true;
	}

	return false;
}

function syslog_update_reference_tables($max_seq) {
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Updating Reference Tables from New Syslog Records');

	/* Validate and resolve hostnames - check DNS first, then Cacti, then mark invalid */
	if (read_config_option('syslog_resolve_hostname') == 'on') {
		$hosts = syslog_db_fetch_assoc_prepared('SELECT DISTINCT host
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?',
			array($max_seq));

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
				$resolved = syslog_check_cacti_hosts($host['host'], $max_seq);
			}
			
			// If not resolved via DNS or found in Cacti, prefix the hostname
			if (!$resolved) {
				$unresolved_host = 'unresolved-' . $host['host'];
				cacti_log("SYSLOG WARNING: Hostname '" . $host['host'] . "' could not be resolved via DNS or found in Cacti hosts table, marking as '" . $unresolved_host . "'", false, 'SYSLOG');
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . "`.`syslog_incoming`
					SET host = ?
					WHERE host = ?
					AND `status` = 1
					AND `seq` <= ?",
					array($unresolved_host, $host['host'], $max_seq));
			}
		}
	}

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_programs`
		(program, last_updated)
		SELECT DISTINCT program, NOW()
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			program=VALUES(program),
			last_updated=VALUES(last_updated)',
		array($max_seq));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_hosts`
		(host, last_updated)
		SELECT DISTINCT host, NOW() AS last_updated
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			host=VALUES(host),
			last_updated=NOW()',
		array($max_seq));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_host_facilities`
		(host_id, facility_id)
		SELECT host_id, facility_id
		FROM (
			(
				SELECT DISTINCT host, facility_id
				FROM `' . $syslogdb_default . "`.`syslog_incoming`
				WHERE `status` = 1
				AND `seq` <= ?
			) AS s
			INNER JOIN `" . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON s.host = sh.host
		) AS merge
		ON DUPLICATE KEY UPDATE
			host_id=VALUES(host_id),
			last_updated=NOW()',
		array($max_seq));
}

function syslog_update_statistics($max_seq) {
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
				WHERE `status` = 1
				AND `seq` <= ?
				GROUP BY host_id, priority_id, facility_id, program_id) AS merge
			GROUP BY host_id, priority_id, facility_id, program_id',
			array($max_seq));

		$stats = db_affected_rows($syslog_cnn);

		syslog_debug('Stats   ' . $stats . " - Record(s) to the 'syslog_statistics' table");
	}
}

function syslog_incoming_to_syslog($max_seq) {
	global $syslogdb_default, $syslog_cnn;

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
		FROM (
			SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
			FROM syslog_incoming AS si
			INNER JOIN syslog_hosts AS sh
			ON sh.host = si.host
			INNER JOIN syslog_programs AS sp
			ON sp.program = si.program
			WHERE `status` = 1
			AND `seq` <= ?
		) AS merge',
		array($max_seq));

	$moved = db_affected_rows($syslog_cnn);

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Moving or Removing Processed Records');

	syslog_debug(sprintf('Moved   %5s - Message(s) to the syslog table', $moved));

	syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?',
		array($max_seq));

	syslog_debug(sprintf('Deleted %5s - Already Processed Message(s) from incoming', db_affected_rows($syslog_cnn)));

	syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE logtime < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

	$stale = db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s - Stale Message(s) from incoming', $stale));

	return array('moved' => $moved, 'stale' => $stale);
}
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
		syslog_db_execute('TRUNCATE `' . $syslogdb_default . '`.`syslog_statistics`');
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
			syslog_db_execute('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_removed`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		} else {
			syslog_db_execute('OPTIMIZE TABLE
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

				$sql = syslog_get_report_sql($report);

				if ($sql != '') {
					$date2 = date('Y-m-d H:i:s', $current_time);
					$date1 = date('Y-m-d H:i:s', $current_time - $time_span);
					$sql  .= " AND logtime BETWEEN ". db_qstr($date1) . " AND " . db_qstr($date2);
					$sql  .= ' ORDER BY logtime DESC';
					$items = syslog_db_fetch_assoc($sql);

					syslog_debug('We have ' . db_affected_rows($syslog_cnn) . ' items for the Report');

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
 * @return (string) The unprepared SQL
 */
function syslog_get_report_sql(&$report) {
	global $syslogdb_default;

	if ($report['type'] == 'messageb') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr($report['message'] . '%');
	}

	if ($report['type'] == 'messagec') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr('%' . $report['message'] . '%');
	}

	if ($report['type'] == 'messagee') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE ' . db_qstr('%' . $report['message']);
	}

	if ($report['type'] == 'host') {
		$sql = 'SELECT sl.*, sh.host
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE sh.host = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'facility') {
		$sql = 'SELECT sl.*, sf.facility
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
			ON sl.facility_id = sf.facility_id
			WHERE sf.facility = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'program') {
		$sql = 'SELECT sl.*, sp.program
			FROM `' . $syslogdb_default . '`.`syslog` AS sl
			INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS sp
			ON sl.program_id = sp.program_id
			WHERE sp.program = ' . db_qstr($report['message']);
	}

	if ($report['type'] == 'sql') {
		$sql = 'SELECT *
			FROM `' . $syslogdb_default . '`.`syslog`
			WHERE (' . $report['message'] . ')';
	}

	return $sql;
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
