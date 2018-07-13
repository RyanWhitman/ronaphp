<?php
/**
 * @package RonaPHP
 * @author Ryan Whitman ryanawhitman@gmail.com
 * @copyright Copyright (c) 2018 Ryan Whitman (https://ryanwhitman.com)
 * @license https://opensource.org/licenses/MIT
 * @link https://github.com/RyanWhitman/ronaphp
 * @version 1.6.0
 */

namespace Rona\Modules\Rona_Logger\Procedures;

/**
 * General procedures
 */
class General extends \Rona\Procedure_Group {

	/**
	 * @see parent class
	 */
	protected function register_procedures() {

		/**
		 * Deploy RonaPHP Logger.
		 */
		$this->register('deploy',
			function($param_exam, $raw_input) {},
			function($input) {

				// Open DB connection.
				$mysqli = $this->get_module_resource('rona', 'db')->mysqli;

				// Determine the current DB version. If this is a new install, the DB version will remain at 0.
				$current_db_version = 0;
				$stmt = $mysqli->prepare("SHOW TABLES LIKE '{$this->config('db_table_prefix')}';");
				$is_success = $stmt->execute();
				$rs = $stmt->get_result();
				$stmt->close();
				if (!$is_success)
					return $this->failure('unknown_error');
				if ($rs->num_rows === 1) {
					$stmt = $mysqli->prepare("SELECT db_version FROM {$this->config('db_table_prefix')} LIMIT 1;");
					$is_success = $stmt->execute();
					$rs = $stmt->get_result();
					$stmt->close();
					if (!$is_success)
						return $this->failure('unknown_error');
					if ($rs->num_rows === 1)
						$current_db_version = $rs->fetch_assoc()['db_version'];
				}

				// Loop through the DB updates and apply the queries, as applicable.
				foreach ($this->config('db_updates') as $db_version => $queries) {
					if ($db_version > $current_db_version) {
						foreach ($queries as $query) {
							$mysqli->query($query);
							$mysqli->query("TRUNCATE {$this->config('db_table_prefix')};");
							$mysqli->query("INSERT INTO {$this->config('db_table_prefix')} SET db_version = $db_version;");
						}
					}
				}

				// Close DB connection.
				$mysqli->close();

				// Success
				return $this->success('deployment_successful');
			}
		);

		/**
		 * Generate and email a report of log entries.
		 */
		$this->register('email_report',
			function($param_exam, $raw_input) {
				$param_exam->opt_param('force_send', ['rona', 'general.boolean'], ['default' => false]);
			},
			function($input) {

				// Open DB connection.
				$mysqli = $this->get_module_resource('rona', 'db')->mysqli;

				// Start transaction.
				$mysqli->query("START TRANSACTION;");

				// Get the entries.
				$stmt = $mysqli->prepare("SELECT * FROM {$this->config('db_table_prefix')}_entries WHERE email_report_sent = 0 ORDER BY when_created ASC FOR UPDATE;");
				$is_success = $stmt->execute();
				$rs = $stmt->get_result();
				$stmt->close();
				if (!$is_success)
					return $this->failure('unknown_error');
				$entries = $rs->fetch_all(MYSQLI_ASSOC);
				$total_entries = count($entries);

				// If entries were found and the right conditions are met, proceed.
				if (
					$total_entries &&
					(
						$input['force_send'] ||
						(
							$this->config('email_report.force_send_at') !== false &&
							$total_entries >= $this->config('email_report.force_send_at')
						)
					)
				) {

					// Compile the entries and collect the entry IDs.
					$compilation = [];
					$entry_ids = [];
					foreach ($entries as $entry) {
						$entry_ids[] = $entry['entry_id'];
						if (!isset($compilation[$entry['tag']][$entry['description']]))
							$compilation[$entry['tag']][$entry['description']] = [];
						$compilation[$entry['tag']][$entry['description']][] = $entry['entry_id'];
					}

					// Mark these entry IDs as having been sent.
					$stmt = $mysqli->prepare("UPDATE {$this->config('db_table_prefix')}_entries SET email_report_sent = 1 WHERE entry_id IN (" . implode(',', $entry_ids) . ");");
					$is_success = $stmt->execute();
					$stmt->close();
					if (!$is_success)
						return $this->failure('unknown_error');

					// Generate the email body.
					ob_start();
						foreach ($compilation as $tag => $entries_by_tag) {
							?>
							<div><strong>Tag: <?php echo $tag ?></strong></div>
							<ul style="padding-left: 0;">
								<?php
								foreach ($entries_by_tag as $description => $entry_ids) {
									$count = count($entry_ids);
									?>
									<li style="padding-bottom: 5px;">
										<?php echo $description ?>
										<ul style="padding-left: 5px;">
											<li style="padding-top: 2px;">
												<?php echo $count . ' entr' . ($count == 1 ? 'y' : 'ies') ?>:
												<?php
												foreach ($entry_ids as $entry_id) {
													?>
													<a href="<?php echo $this->config('view_entry_url')('/' . $this->config('route_base') . '/entry/' . $entry_id) ?>"><?php echo $entry_id ?></a>
													<?php
												}
												?>
											</li>
										</ul>
									</li>
									<?php
								}
								?>
							</ul>
							<?php
						}
						?>
						<div><small><em>This report was generated by <?php echo $this->config('display_name') ?>.</em></small></div>
						<?php
					$body = ob_get_clean();

					// Send email
					$from = $this->config('display_name');
					$subject = '[' . $this->app_config('environment_name') . '] ' . $total_entries . ' Entr' . ($total_entries == 1 ? 'y' : 'ies') . ' for ' . date('m/d/y H:i:s');
					$this->config('email_report.email_handler')($from, $subject, $body);
				}

				// End transaction.
				$mysqli->query("COMMIT;");

				// Close DB connection.
				$mysqli->close();

				// Success
				return $this->success('success');
			}
		);
	}
}