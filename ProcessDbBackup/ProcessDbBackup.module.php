<?php namespace ProcessWire;

/**
 * ProcessDbBackup
 *
 * Database backup and restore module for ProcessWire.
 * Supports local storage and Backblaze B2, manual and scheduled backups via LazyCron.
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 2.2.1
 * @license MIT
 */
class ProcessDbBackup extends Process implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'DB Backup',
			'summary'  => 'Database backup and restore with local and Backblaze B2 storage, backup types (regular/weekly/monthly), chunked upload, streaming restore.',
			'version'  => 221,
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'icon'     => 'database',
			'requires' => ['ProcessWire>=3.0.0', 'PHP>=8.0.0'],
			'installs' => [],
			'page'     => [
				'name'   => 'db-backup',
				'parent' => 'admin',
				'title'  => 'DB Backup',
			],
			'permission'  => 'db-backup',
			'permissions' => [
				'db-backup' => 'Manage database backups',
			],
			'autoload' => true,
			'singular' => true,
		];
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	const BACKUP_DIR     = 'backups/db/';
	const BACKUP_EXT     = '.sql.gz';
	const META_FILE      = 'backups/db/.meta.json';
	const LOCK_FILE      = 'backups/db/.lock'; // base, type appended at runtime
	const MIGRATION_LOCK_FILE = 'backups/db/.migration.lock';
	const CHUNK_DIR      = 'backups/db/.chunks/';
	const STORAGE_DIR    = 'ProcessDbBackup/';
	const MIGRATIONS_DIR = 'ProcessDbBackup/migrations/';
	const SNAPSHOTS_DIR  = 'ProcessDbBackup/snapshots/';
	const MIGRATION_TABLE = 'process_db_backup_migrations';
	const LOG_NAME       = 'db-backup';
	const B2_API_AUTH    = 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account';

	// ── Init ──────────────────────────────────────────────────────────────────

	public function init(): void {
		parent::init();

		if (!$this->isCronDisabledByConfig()) {
			// Regular schedule
			if ($this->cron_interval && $this->cron_interval !== 'never') {
				$this->addHook('LazyCron::' . $this->cron_interval, $this, 'cronBackup');
			}
			// Weekly schedule
			if ($this->cron_weekly && $this->cron_weekly !== 'never') {
				$this->addHook('LazyCron::' . $this->cron_weekly, $this, 'cronBackupWeekly');
			}
			// Monthly schedule
			if ($this->cron_monthly && $this->cron_monthly !== 'never') {
				$this->addHook('LazyCron::' . $this->cron_monthly, $this, 'cronBackupMonthly');
			}
		}

		// Dashboard widget on PW admin home
		$this->addHookAfter('ProcessHome::execute', $this, 'renderWidget');

		// Migrate legacy meta entries — only in admin context, not on every frontend request
		if ($this->wire('page') && $this->wire('page')->template == 'admin') {
			$this->ensureRuntimeStorage();
			$this->migrateMeta();
			$this->ensureMigrationStore();
		}
	}

	protected function isCronDisabledByConfig(): bool {
		return (bool)$this->wire('config')->processDbBackupDisableCron;
	}

	// ── Admin UI entry point ──────────────────────────────────────────────────

	public function ___execute(): string {
		$this->headline('DB Backup');
		$this->browserTitle('DB Backup');

		// AJAX: chunk upload
		if ($this->input->get('pdb_ajax') === 'chunk') {
			$this->handleChunkUpload();
			return '';
		}

		// AJAX: create backup with progress
		if ($this->input->get('pdb_ajax') === 'create') {
			$this->handleAjaxCreate();
			return '';
		}

		// AJAX: save label
		if ($this->input->get('pdb_ajax') === 'label') {
			$this->handleAjaxLabel();
			return '';
		}

		// Handle POST actions
		if ($this->input->post('action') || !empty($_POST['action'])) {
			return $this->handleAction();
		}

		// Handle GET actions
		$action = $this->input->get('action');

		if ($action === 'download') {
			$this->doDownload($this->input->get('file'));
			return '';
		}

		if ($action === 'download_migration') {
			$this->downloadMigrationFile($this->input->get('file'));
			return '';
		}

		if ($action === 'download_snapshot') {
			$this->downloadSnapshotFile($this->input->get('file'));
			return '';
		}

		if ($action === 'verify') {
			return $this->renderVerify($this->input->get('file'));
		}

		if ($action === 'partial') {
			return $this->renderPartialRestore($this->input->get('file'));
		}

		if ($action === 'migrations') {
			return $this->renderMigrationsDashboard();
		}

		if ($action === 'cli') {
			return $this->renderCliDashboard();
		}

		if ($action === 'view_migration') {
			return $this->renderMigrationPreview($this->input->get('file'));
		}

		if ($action === 'migration_details') {
			return $this->renderMigrationDetails($this->input->get('file'));
		}

		if ($action === 'preview_schema_diff_migration') {
			return $this->renderSchemaDiffMigrationPreview($this->input->get('snapshot_file'));
		}

		return $this->renderDashboard();
	}

	// ── Action dispatcher ─────────────────────────────────────────────────────

	protected function handleAction(): string {
		// input->post may not populate for multipart/form-data — fall back to $_POST
		$action = $this->input->post('action') ?: ($_POST['action'] ?? '');
		if (!$this->session->CSRF->validate()) {
			$this->error('CSRF validation failed.');
			$this->session->redirect($this->page->url);
			return '';
		}

		switch ($action) {
			case 'create':
				$result = $this->createBackup('regular');
				if ($result['success']) {
					$this->message('Backup created: ' . $result['filename']);
				} else {
					$this->error('Backup failed: ' . $result['error']);
				}
				break;

			case 'create_typed':
				$btype  = $this->input->post('backup_type') ?? 'regular';
				$btype  = in_array($btype, ['regular', 'weekly', 'monthly']) ? $btype : 'regular';
				$result = $this->createBackup($btype);
				if ($result['success']) {
					$this->message(ucfirst($btype) . ' backup created: ' . $result['filename']);
				} else {
					$this->error(ucfirst($btype) . ' backup failed: ' . $result['error']);
				}
				break;

			case 'restore':
				$file = basename((string)($this->input->post('file') ?? ''));
				$result = $this->restoreBackup($file);
				if ($result['success']) {
					$this->message('Database restored from: ' . $file);
					if (!empty($result['pre_backup'])) {
						$this->message('Pre-restore backup saved: ' . $result['pre_backup']);
					}
				} else {
					$this->error('Restore failed: ' . $result['error']);
				}
				break;

			case 'delete':
				$file = basename((string)($this->input->post('file') ?? ''));
				$result = $this->deleteBackup($file);
				if ($result['success']) {
					$this->message('Backup deleted: ' . $file);
				} else {
					$this->error('Delete failed: ' . $result['error']);
				}
				break;


			case 'partial_restore':
				$file   = basename((string)($this->input->post('file') ?? ''));
				$tables = $this->input->post('tables');
				$tables = is_array($tables) ? array_map([$this->sanitizer, 'name'], $tables) : [];
				if (empty($tables)) {
					$this->error('No tables selected for partial restore.');
					break;
				}
				$result = $this->partialRestoreBackup($file, $tables);
				if ($result['success']) {
					$this->message('Partial restore complete: ' . implode(', ', $result['restored_tables']));
					if (!empty($result['pre_backup'])) {
						$this->message('Pre-restore backup: ' . $result['pre_backup']);
					}
				} else {
					$this->error('Partial restore failed: ' . $result['error']);
				}
				break;

			case 'set_label':
				$file  = basename((string)($this->input->post('file') ?? ''));
				$label = $this->sanitizer->text($this->input->post('label'));
				$meta  = $this->getMeta();
				if (isset($meta[$file])) {
					$meta[$file]['label'] = $label;
					$metaPath = $this->wire('config')->paths->assets . self::META_FILE;
					file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
					$this->message('Label saved.');
				}
				break;

			case 'run_migration':
				$file = basename((string)($this->input->post('migration_file') ?? ''));
				$result = $this->runMigration($file);
				if ($result['success']) {
					$this->message('Migration applied: ' . $file);
					if (!empty($result['pre_backup'])) {
						$this->message('Pre-migration backup saved: ' . $result['pre_backup']);
					}
					if (!empty($result['message'])) {
						$this->message($result['message']);
					}
				} else {
					$this->error('Migration failed: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'create_migration':
				$result = $this->createMigrationFileFromInput();
				if ($result['success']) {
					$this->message('Migration file created: ' . $result['filename']);
				} else {
					$this->error('Migration file was not created: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'upload_migration_file':
				$result = $this->uploadMigrationFile();
				if ($result['success']) {
					$this->message('Migration file uploaded: ' . $result['filename']);
				} else {
					$this->error('Migration file was not uploaded: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'create_schema_snapshot':
				$result = $this->createSchemaSnapshot();
				if ($result['success']) {
					$this->message('Schema snapshot created: ' . $result['filename']);
				} else {
					$this->error('Schema snapshot was not created: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'generate_migration_from_diff':
				$snapshotFile = basename((string)($this->input->post('snapshot_file') ?? ''));
				$result = $this->createMigrationFromSchemaDiff($snapshotFile);
				if ($result['success']) {
					$this->message('Migration file created from schema diff: ' . $result['filename']);
					if (!empty($result['manual_count'])) {
						$this->warning($result['manual_count'] . ' changed/removed schema item(s) need manual review.');
					}
				} else {
					$this->error('Migration file was not created: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'delete_pending_migration':
				$file = basename((string)($this->input->post('migration_file') ?? ''));
				$result = $this->deletePendingMigrationFile($file);
				if ($result['success']) {
					$this->message('Migration file deleted: ' . $file);
				} else {
					$this->error('Migration file was not deleted: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';

			case 'delete_snapshot':
				$file = basename((string)($this->input->post('snapshot_file') ?? ''));
				$result = $this->deleteSnapshotFile($file);
				if ($result['success']) {
					$this->message('Schema snapshot deleted: ' . $file);
				} else {
					$this->error('Schema snapshot was not deleted: ' . $result['error']);
				}
				$this->session->redirect($this->page->url . '?action=migrations');
				return '';
		}

		$this->session->redirect($this->page->url);
		return '';
	}

	// ── Dashboard render ──────────────────────────────────────────────────────

	public function runCli(array $argv): int {
		$parsed = $this->parseCliArgs(array_slice($argv, 1));
		$args = $parsed['args'];
		$options = $parsed['options'];
		$command = array_shift($args) ?: 'help';

		try {
			switch ($command) {
				case 'help':
				case '--help':
				case '-h':
					$this->cliWrite($this->getCliHelpText());
					return 0;

				case 'backup:create':
					$type = (string)($options['type'] ?? 'regular');
					$result = $this->createBackup($type);
					if (!$result['success']) return $this->cliError($result['error'] ?? 'Backup failed.');
					$this->cliWrite('Backup created: ' . ($result['filename'] ?? 'unknown'));
					return 0;

				case 'migrations:status':
					return $this->cliMigrationStatus();

				case 'migrations:run':
					return $this->cliRunMigrations($args, $options);

				case 'schema:snapshot':
					$result = $this->createSchemaSnapshot();
					if (!$result['success']) return $this->cliError($result['error'] ?? 'Schema snapshot failed.');
					$this->cliWrite('Schema snapshot created: ' . ($result['filename'] ?? 'unknown'));
					return 0;

				case 'schema:diff':
					return $this->cliSchemaDiff($options, false);

				case 'schema:preview':
					return $this->cliSchemaDiff($options, true);

				case 'schema:generate':
					$result = $this->createMigrationFromSchemaDiff((string)($options['baseline'] ?? ''));
					if (!$result['success']) return $this->cliError($result['error'] ?? 'Migration generation failed.');
					$this->cliWrite('Migration created: ' . ($result['filename'] ?? 'unknown'));
					if (!empty($result['manual_count'])) {
						$this->cliWrite('Manual changes not generated: ' . (int)$result['manual_count']);
					}
					return 0;
			}
		} catch (\Throwable $e) {
			return $this->cliError($e->getMessage());
		}

		return $this->cliError('Unknown command: ' . $command . "\n\n" . $this->getCliHelpText());
	}

	protected function parseCliArgs(array $args): array {
		$positionals = [];
		$options = [];

		foreach ($args as $arg) {
			$arg = (string)$arg;
			if (strpos($arg, '--') === 0) {
				$eq = strpos($arg, '=');
				if ($eq !== false) {
					$options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
				} else {
					$options[substr($arg, 2)] = true;
				}
				continue;
			}
			$positionals[] = $arg;
		}

		return ['args' => $positionals, 'options' => $options];
	}

	protected function cliMigrationStatus(): int {
		$migrations = $this->getMigrationStatusList();
		$pending = 0;
		$applied = 0;

		foreach ($migrations as $migration) {
			if (!empty($migration['applied'])) $applied++;
			else $pending++;
		}

		$this->cliWrite("Migrations: {$pending} pending, {$applied} applied");
		if (empty($migrations)) {
			$this->cliWrite('No migration files found.');
			return 0;
		}

		foreach ($migrations as $migration) {
			$status = !empty($migration['applied']) ? 'applied' : 'pending';
			if (!empty($migration['checksum_mismatch'])) $status = 'applied, changed';
			$lint = !empty($migration['lint_valid']) ? 'php ok' : 'php error';
			$this->cliWrite(sprintf('%-18s %-12s %s', $status, $lint, $migration['filename']));
		}

		return 0;
	}

	protected function cliRunMigrations(array $args, array $options): int {
		if (empty($options['yes'])) {
			return $this->cliError('Refusing to run migrations without --yes.');
		}

		if ($this->isProductionEnvironment()) {
			$confirm = trim((string)($options['confirm'] ?? ''));
			if ($confirm !== 'RUN ON PRODUCTION') {
				return $this->cliError('Production requires --confirm="RUN ON PRODUCTION".');
			}
			$this->input->post->set('production_confirm', 'RUN ON PRODUCTION');
		}

		$targets = [];
		if (!empty($options['all'])) {
			foreach ($this->getMigrationStatusList() as $migration) {
				if (empty($migration['applied'])) $targets[] = $migration['filename'];
			}
		} elseif (!empty($args[0])) {
			$targets[] = basename((string)$args[0]);
		} else {
			return $this->cliError('Pass a migration filename or --all.');
		}

		if (empty($targets)) {
			$this->cliWrite('No pending migrations.');
			return 0;
		}

		foreach ($targets as $filename) {
			$this->cliWrite('Running migration: ' . $filename);
			$result = $this->runMigration($filename);
			if (!$result['success']) return $this->cliError($filename . ': ' . ($result['error'] ?? 'Migration failed.'));
			$message = trim((string)($result['message'] ?? ''));
			$this->cliWrite('Applied: ' . $filename . ($message !== '' ? ' - ' . $message : ''));
		}

		return 0;
	}

	protected function cliSchemaDiff(array $options, bool $previewCode): int {
		$baseline = $this->getCliSchemaBaseline((string)($options['baseline'] ?? ''));
		if (!$baseline) return $this->cliError('No schema snapshot found.');

		$diff = $this->diffSchemaSnapshot($baseline['path']);
		if (empty($diff)) {
			$this->cliWrite('No schema changes found against ' . $baseline['filename'] . '.');
			return 0;
		}

		$current = $this->getCurrentSchemaSnapshot();
		$auto = array_values(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		$manual = array_values(array_filter($diff, fn($item) => !$this->isAutoGeneratableSchemaDiffItem($item)));

		if ($previewCode) {
			if (empty($auto)) return $this->cliError('No safe auto-generatable schema changes found.');
			$this->cliWrite($this->buildSchemaDiffMigrationCode($auto, $manual, $current, $baseline['filename']));
			return 0;
		}

		$this->cliWrite('Baseline: ' . $baseline['filename']);
		$this->cliWrite('Changes: ' . count($diff) . ' total, ' . count($auto) . ' auto, ' . count($manual) . ' manual');
		foreach ($diff as $item) {
			$kind = $this->isAutoGeneratableSchemaDiffItem($item) ? 'auto' : 'manual';
			$this->cliWrite(sprintf('%-8s %-16s %-10s %s', $kind, $item['scope'] ?? '', $item['type'] ?? '', $item['name'] ?? ''));
		}

		return 0;
	}

	protected function getCliSchemaBaseline(string $filename = ''): ?array {
		if ($filename !== '') return $this->getSchemaSnapshotByFilename($filename);
		$snapshots = $this->getSchemaSnapshotFiles();
		return $snapshots[0] ?? null;
	}

	protected function cliWrite(string $message = '', bool $stderr = false): void {
		fwrite($stderr ? STDERR : STDOUT, $message . PHP_EOL);
	}

	protected function cliError(string $message): int {
		$this->cliWrite('Error: ' . $message, true);
		return 1;
	}

	protected function getCliHelpText(): string {
		$displayCommand = trim($this->getCliDisplayCommand());
		return trim("
ProcessDbBackup CLI

Usage:
  {$displayCommand} --root=/path/to/processwire <command> [options]

Commands:
  help                                      Show this help
  backup:create [--type=regular]            Create a database backup
  migrations:status                         List migration files and applied state
  migrations:run <file.php> --yes            Run one pending migration
  migrations:run --all --yes                Run all pending migrations
  schema:snapshot                           Save current fields/templates/roles/permissions JSON snapshot
  schema:diff [--baseline=schema-file.json] Show schema changes against a snapshot
  schema:preview [--baseline=schema-file.json]
                                            Print generated migration PHP for safe schema changes
  schema:generate [--baseline=schema-file.json]
                                            Create migration file from safe schema changes

Production:
  migrations:run also requires --confirm=\"RUN ON PRODUCTION\" when the module environment is production.
");
	}

	protected function renderDashboard(): string {
		$allBackups = $this->getBackupList();
		$diskUsed   = $this->getDiskUsage();
		$csrf       = $this->session->CSRF->renderInput();
		$pageUrl    = $this->page->url;

		// Active type filter
		$allowedTypes = ['all', 'regular', 'weekly', 'monthly', 'uploaded'];
		$filterType   = in_array($this->input->get('type'), $allowedTypes) ? $this->input->get('type') : 'all';
		$backups = $filterType === 'all'
			? $allBackups
			: array_values(array_filter($allBackups, fn($b) => ($b['type'] ?? 'regular') === $filterType));

		// Sorting
		$allowedSortFields = ['filename', 'date', 'size', 'type', 'label'];
		$sortBy  = in_array($this->input->get('sort'), $allowedSortFields) ? $this->input->get('sort') : 'date';
		$sortDir = $this->input->get('dir') === 'asc' ? 'asc' : 'desc';
		usort($backups, function($a, $b) use ($sortBy, $sortDir) {
			// Use raw bytes for size sort to avoid lexicographic ordering
			$key = $sortBy === 'size' ? 'size_raw' : $sortBy;
			$va  = $a[$key] ?? '';
			$vb  = $b[$key] ?? '';
			$cmp = is_int($va) ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
			return $sortDir === 'asc' ? $cmp : -$cmp;
		});

		$count = count($allBackups);

		$cronLabels = [
			'never'          => 'manual only',
			'every30Seconds' => 'Every 30s',
			'everyMinute'    => 'Every 1m',
			'every2Minutes'  => 'Every 2m',
			'every3Minutes'  => 'Every 3m',
			'every4Minutes'  => 'Every 4m',
			'every5Minutes'  => 'Every 5m',
			'every10Minutes' => 'Every 10m',
			'every15Minutes' => 'Every 15m',
			'every30Minutes' => 'Every 30m',
			'every45Minutes' => 'Every 45m',
			'everyHour'      => 'Every 1h',
			'every2Hours'    => 'Every 2h',
			'every4Hours'    => 'Every 4h',
			'every6Hours'    => 'Every 6h',
			'every12Hours'   => 'Every 12h',
			'everyDay'       => 'Every day',
			'every2Days'     => 'Every 2d',
			'every4Days'     => 'Every 4d',
			'everyWeek'      => 'Every week',
			'every2Weeks'    => 'Every 2w',
			'every4Weeks'    => 'Every 4w',
		];
		$cronInterval = $this->cron_interval ?: 'never';
		$retentionCount = (int)($this->retention_count ?: 0);
		$cronDisabled = $this->isCronDisabledByConfig();
		$cronLabel  = $cronDisabled ? 'disabled by config' : ($cronLabels[$cronInterval] ?? 'manual only');
		$retention  = $retentionCount > 0 ? $retentionCount : '&infin;';
		$b2Tag      = $this->b2_enabled ? "<span class=\"uk-badge uk-margin-small-left\">B2</span>" : '';

		// Type counts for tabs
		$typeCounts = ['all' => count($allBackups), 'regular' => 0, 'weekly' => 0, 'monthly' => 0, 'uploaded' => 0];
		foreach ($allBackups as $b) $typeCounts[$b['type'] ?? 'regular'] = ($typeCounts[$b['type'] ?? 'regular'] ?? 0) + 1;

		// ── Stats cards ───────────────────────────────────────────────────────
		$html = $this->renderSectionNav('backups') . "
		<div class=\"uk-grid-small uk-child-width-auto uk-margin-medium-bottom\" uk-grid>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$count}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Backups</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$diskUsed}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Disk used</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$retention}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Retention</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$cronLabel} {$b2Tag}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Schedule</div>
			</div></div>
		</div>";

		if ($cronDisabled) {
			$html .= '
			<div class="uk-alert uk-alert-primary" uk-alert>
				<p class="uk-margin-remove">Scheduled backups are disabled by <code>$config->processDbBackupDisableCron = true;</code>. Manual backup, restore, upload, and migration tools remain available.</p>
			</div>';
		}

		// ── Create buttons ────────────────────────────────────────────────────
		$weeklyEnabled  = $this->cron_weekly  && $this->cron_weekly  !== 'never';
		$monthlyEnabled = $this->cron_monthly && $this->cron_monthly !== 'never';

		$html .= '
		<div class="uk-margin-medium-bottom">
			<div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap:8px">
				<button id="pdb-create-btn" class="uk-button uk-button-primary" onclick="pdbCreateBackup(this, \'regular\')">
					<span uk-icon="icon: plus; ratio:.8"></span>&nbsp; Create Regular
				</button>';

		if ($weeklyEnabled) {
			$html .= '
				<form method="post" action="' . $pageUrl . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="create_typed">
					<input type="hidden" name="backup_type" value="weekly">
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: calendar; ratio:.8"></span>&nbsp; Create Weekly
					</button>
				</form>';
		}

		if ($monthlyEnabled) {
			$html .= '
				<form method="post" action="' . $pageUrl . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="create_typed">
					<input type="hidden" name="backup_type" value="monthly">
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: star; ratio:.8"></span>&nbsp; Create Monthly
					</button>
				</form>';
		}

		$html .= '
				<div id="pdb-progress" class="uk-hidden" style="flex:1;min-width:200px;max-width:300px">
					<progress class="uk-progress" id="pdb-progress-bar" value="0" max="100" style="margin:0"></progress>
				</div>
				<span id="pdb-progress-msg" class="uk-text-small uk-text-muted"></span>
			</div>
		</div>';

		// ── Compute next backup estimates ────────────────────────────────────────
		$intervalSeconds = [
			'every30Seconds' => 30,        'everyMinute'    => 60,
			'every2Minutes'  => 120,       'every3Minutes'  => 180,
			'every4Minutes'  => 240,       'every5Minutes'  => 300,
			'every10Minutes' => 600,       'every15Minutes' => 900,
			'every30Minutes' => 1800,      'every45Minutes' => 2700,
			'everyHour'      => 3600,      'every2Hours'    => 7200,
			'every4Hours'    => 14400,     'every6Hours'    => 21600,
			'every12Hours'   => 43200,     'everyDay'       => 86400,
			'every2Days'     => 172800,    'every4Days'     => 345600,
			'everyWeek'      => 604800,    'every2Weeks'    => 1209600,
			'every4Weeks'    => 2419200,
		];

		$estimateLabel = function(string $cronKey, string $type) use ($allBackups, $intervalSeconds): string {
			if (!$cronKey || $cronKey === 'never') return '';
			$interval = $intervalSeconds[$cronKey] ?? 0;
			if (!$interval) return '';

			// Find latest backup of this type
			$latest = null;
			foreach ($allBackups as $b) {
				if (($b['type'] ?? 'regular') === $type) { $latest = $b; break; }
			}

			if (!$latest) {
				return '<span class="uk-text-muted uk-text-small"> · Next: now</span>';
			}

			$lastTs  = strtotime($latest['date']);
			$nextTs  = $lastTs + $interval;
			$diff    = $nextTs - time();

			if ($diff <= 0) {
				return '<span class="uk-text-muted uk-text-small"> · Next: on next page load</span>';
			}

			if ($diff < 3600)       $eta = round($diff / 60) . 'm';
			elseif ($diff < 86400)  $eta = round($diff / 3600, 1) . 'h';
			elseif ($diff < 604800) $eta = round($diff / 86400, 1) . 'd';
			else                    $eta = round($diff / 604800, 1) . 'w';

			return '<span class="uk-text-muted uk-text-small"> · Next: ~' . $eta . '</span>';
		};

		// ── Type filter tabs ───────────────────────────────────────────────────
		$tabDefs = [
			'all'      => ['label' => 'All',      'estimate' => ''],
			'regular'  => ['label' => 'Regular',  'estimate' => ''],
			'weekly'   => ['label' => 'Weekly',   'estimate' => $estimateLabel($this->cron_weekly ?? 'never', 'weekly')],
			'monthly'  => ['label' => 'Monthly',  'estimate' => $estimateLabel($this->cron_monthly ?? 'never', 'monthly')],
			'uploaded' => ['label' => 'Uploaded', 'estimate' => ''],
		];
		$tabHtml = '<ul class="uk-tab uk-margin-small-bottom">';
		foreach ($tabDefs as $tab => $def) {
			$cnt    = $typeCounts[$tab] ?? 0;
			$active = $filterType === $tab ? ' class="uk-active"' : '';
			$url    = $pageUrl . '?type=' . $tab;
			$tabHtml .= "<li{$active}><a href=\"{$url}\">{$def['label']} <span class=\"uk-badge\">{$cnt}</span>{$def['estimate']}</a></li>";
		}
		$tabHtml .= '</ul>';
		$html .= $tabHtml;

		// ── Sort helper ────────────────────────────────────────────────────────
		$sortIcon = fn($col) => $sortBy === $col
			? '<span uk-icon="icon: ' . ($sortDir === 'asc' ? 'arrow-up' : 'arrow-down') . '; ratio:.7"></span>'
			: '';
		$sortUrl = fn($col) => $pageUrl . '?type=' . $filterType . '&sort=' . $col . '&dir=' . ($sortBy === $col && $sortDir === 'asc' ? 'desc' : 'asc');

		// ── Backup table ───────────────────────────────────────────────────────
		if (empty($backups)) {
			$html .= '<p class="uk-text-muted"><span uk-icon="icon: info"></span> No backups found.</p>';
		} else {
			$html .= '
			<div class="uk-overflow-auto">
			<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
				<thead><tr>
					<th><a href="' . $sortUrl('filename') . '">Filename ' . $sortIcon('filename') . '</a></th>
					<th>Label</th>
					<th>Type</th>
					<th><a href="' . $sortUrl('date') . '">Date ' . $sortIcon('date') . '</a></th>
					<th><a href="' . $sortUrl('size') . '">Size ' . $sortIcon('size') . '</a></th>
					<th>Storage</th>
					<th class="uk-text-right">Actions</th>
				</tr></thead>
				<tbody>';

			foreach ($backups as $b) {
				$filename = htmlspecialchars($b['filename']);
				$date     = htmlspecialchars($b['date']);
				$size     = htmlspecialchars($b['size']);
				$label    = htmlspecialchars($b['label'] ?? '');
				$btype    = $b['type'] ?? 'regular';
				$hasLocal = !empty($b['local']);
				$hasB2    = !empty($b['b2']);

				// Type badge
				$typeBadge = match($btype) {
					'weekly'   => '<span class="uk-label uk-label-success">weekly</span>',
					'monthly'  => '<span class="uk-label" style="background:#8e44ad">monthly</span>',
					'uploaded' => '<span class="uk-label uk-label-warning">uploaded</span>',
					default    => '<span class="uk-label">regular</span>',
				};

				if ($hasLocal && $hasB2) {
					$storageBadge = '<span class="uk-label uk-label-success">Local + B2</span>';
				} elseif ($hasB2) {
					$storageBadge = '<span class="uk-label uk-label-warning">B2 only</span>';
				} else {
					$storageBadge = '<span class="uk-label">Local</span>';
				}

				$confirmRestore = "Restore from {$filename}?\nThe current database will be overwritten.";
				$confirmDelete  = "Delete backup {$filename}?";

				$dlBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=download&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"cloud-download\" title=\"Download\"></a>"
					: '<span class="uk-icon-button" uk-icon="cloud-download" uk-tooltip="File stored on B2 only" style="opacity:.3;cursor:default"></span>';

				$verifyBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=verify&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"check\" uk-tooltip=\"Verify integrity\"></a>"
					: '';

				$partialBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=partial&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"table\" uk-tooltip=\"Partial restore\"></a>"
					: '';

				if ($hasLocal) {
					$restoreBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
						. $csrf
						. '<input type="hidden" name="action" value="restore">'
						. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
						. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"refresh\" uk-tooltip=\"Restore\""
						. " onclick=\"return confirm('" . addslashes($confirmRestore) . "')\" ></button>"
						. '</form>';
				} else {
					$restoreBtn = '<span class="uk-icon-button" uk-icon="refresh" uk-tooltip="Download from B2 to restore" style="opacity:.3;cursor:default"></span>';
				}

				$deleteBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
					. $csrf
					. '<input type="hidden" name="action" value="delete">'
					. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
					. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"trash\" uk-tooltip=\"Delete\""
					. " onclick=\"return confirm('" . addslashes($confirmDelete) . "')\" ></button>"
					. '</form>';

				// Inline label editor
				$labelCell = "<span class=\"pdb-label\" data-file=\"{$filename}\" uk-tooltip=\"Click to edit\""
					. " style=\"cursor:pointer;color:" . ($label ? 'inherit' : '#aaa') . "\">"
					. ($label ?: '<em>add label</em>') . "</span>";

				$html .= "<tr>"
					. "<td class=\"uk-text-small uk-text-nowrap\"><span uk-icon=\"icon: file-text; ratio:.8\" class=\"uk-margin-small-right\"></span>{$filename}</td>"
					. "<td class=\"uk-text-small\">{$labelCell}</td>"
					. "<td>{$typeBadge}</td>"
					. "<td class=\"uk-text-small uk-text-muted uk-text-nowrap\">{$date}</td>"
					. "<td class=\"uk-text-small uk-text-nowrap\">{$size}</td>"
					. "<td>{$storageBadge}</td>"
					. "<td class=\"uk-text-right uk-text-nowrap\">{$dlBtn} {$verifyBtn} {$partialBtn} {$restoreBtn} {$deleteBtn}</td>"
					. "</tr>";
			}

			$html .= "</tbody></table></div>";
		}

		// ── Database table sizes ───────────────────────────────────────────────
		$tableSizes     = $this->getTableSizeList();
		$excludedTables = array_flip($this->getExcludedTables());
		$totalDbBytes   = array_sum(array_map(fn($t) => (int)($t['total_length'] ?? 0), $tableSizes));
		$totalDbSize    = $this->formatBytes((int)$totalDbBytes);

		$html .= '
		<hr class="uk-margin-medium">
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Database Table Sizes</h3>';

		if (empty($tableSizes)) {
			$html .= '<p class="uk-text-small uk-text-muted">Table size information is not available for this database user.</p>';
		} else {
			$html .= '
			<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
				<p class="uk-text-small uk-text-muted uk-margin-remove">All ' . count($tableSizes) . ' tables by storage size. Estimated total: <strong>' . $totalDbSize . '</strong>.</p>
				<a href="' . $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className() . '#Inputfield_exclude_tables" class="uk-button uk-button-default">
					<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Exclude tables
				</a>
			</div>
			<div class="uk-overflow-auto">
			<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
				<thead><tr>
					<th>Table</th>
					<th class="uk-text-right">Rows</th>
					<th class="uk-text-right">Data</th>
					<th class="uk-text-right">Index</th>
					<th class="uk-text-right">Total</th>
					<th class="uk-text-right">Backup</th>
				</tr></thead>
				<tbody>';

			foreach ($tableSizes as $table) {
				$name       = (string)($table['table_name'] ?? '');
				$isExcluded = isset($excludedTables[$name]);
				$rowClass   = $isExcluded ? ' class="uk-text-muted"' : '';
				$status     = $isExcluded
					? '<span class="uk-label">Excluded</span>'
					: '<span class="uk-label uk-label-success">Included</span>';

				$html .= '<tr' . $rowClass . '>'
					. '<td class="uk-text-small"><code>' . htmlspecialchars($name) . '</code></td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . number_format((int)($table['table_rows'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . $this->formatBytes((int)($table['data_length'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . $this->formatBytes((int)($table['index_length'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap"><strong>' . $this->formatBytes((int)($table['total_length'] ?? 0)) . '</strong></td>'
					. '<td class="uk-text-right">' . $status . '</td>'
					. '</tr>';
			}

			$html .= '</tbody></table></div>';
		}

		// ── Chunked upload form ────────────────────────────────────────────────
		$html .= '
		<hr class="uk-margin-medium">
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Upload Backup File</h3>
		<p class="uk-text-small uk-text-muted">Upload a <code>.sql.gz</code> file from your computer. Large files are sent in chunks — no <code>upload_max_filesize</code> limit applies.</p>
		<div class="uk-grid-small uk-flex-middle" uk-grid>
			<div class="uk-width-expand">
				<div uk-form-custom="target: true">
					<input type="file" id="pdb-upload-file" accept=".gz">
					<input class="uk-input" type="text" placeholder="Select .sql.gz file..." readonly>
				</div>
			</div>
			<div>
				<label class="uk-text-small">
					<input type="checkbox" id="pdb-restore-after" class="uk-checkbox">
					&nbsp;Restore immediately after upload
				</label>
			</div>
			<div>
				<button class="uk-button uk-button-default" onclick="pdbChunkedUpload(this)">
					<span uk-icon="icon: upload; ratio:.8"></span>&nbsp; Upload
				</button>
			</div>
		</div>
			<div id="pdb-upload-progress" class="uk-hidden uk-margin-small-top">
				<progress class="uk-progress" id="pdb-upload-bar" value="0" max="100" style="margin:0 0 4px"></progress>
				<span id="pdb-upload-msg" class="uk-text-small uk-text-muted"></span>
			</div>
			<div class="uk-margin-large-top uk-text-right">
				<a href="' . $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className() . '" class="uk-button uk-button-default">
					<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Module settings
				</a>
			</div>';

		$html .= $this->renderScripts($pageUrl);

		return $html;
	}

	// ── Migrations dashboard ─────────────────────────────────────────────────

	protected function renderSectionNav(string $active): string {
		$pageUrl = $this->page->url;
		$backupsActive = $active === 'backups' ? ' class="uk-active"' : '';
		$migrationsActive = $active === 'migrations' ? ' class="uk-active"' : '';
		$cliActive = $active === 'cli' ? ' class="uk-active"' : '';

		return "
		<ul class=\"uk-tab uk-margin-medium-bottom\">
			<li{$backupsActive}><a href=\"{$pageUrl}\"><span uk-icon=\"icon: database; ratio:.8\"></span>&nbsp; Backups</a></li>
			<li{$migrationsActive}><a href=\"{$pageUrl}?action=migrations\"><span uk-icon=\"icon: git-branch; ratio:.8\"></span>&nbsp; Migrations</a></li>
			<li{$cliActive}><a href=\"{$pageUrl}?action=cli\"><span uk-icon=\"icon: code; ratio:.8\"></span>&nbsp; CLI</a></li>
		</ul>";
	}

	protected function renderCliDashboard(): string {
		$root = $this->wire('config')->paths->root;
		$displayCommand = $this->getCliDisplayCommand();
		$rootCommand = $displayCommand . '--root=' . escapeshellarg(rtrim($root, '/')) . ' ';
		$storage = $this->getRelativeAssetsPath(self::STORAGE_DIR);

		$examples = [
			'migrations:status' => 'Check what will run before touching the database.',
			'schema:snapshot' => 'Save current ProcessWire fields, templates, roles, and permissions.',
			'schema:diff --baseline=schema-2026_07_01_120000.json' => 'Compare the current site structure with a saved snapshot.',
			'schema:preview --baseline=schema-2026_07_01_120000.json' => 'Print generated migration PHP without creating a file.',
			'schema:generate --baseline=schema-2026_07_01_120000.json' => 'Create a pending migration from safe schema changes.',
			'migrations:run --all --yes' => 'Run all pending migrations on a non-production environment.',
			'migrations:run --all --yes --confirm="RUN ON PRODUCTION"' => 'Run pending migrations when environment is set to production.',
			'backup:create --type=regular' => 'Create a regular database backup from the terminal.',
		];

		$exampleCards = '';
		foreach ($examples as $command => $note) {
			$exampleCards .= '
				<div class="pdb-cli-example">
					<code>' . htmlspecialchars($displayCommand . $command) . '</code>
					<div class="uk-text-small uk-text-muted uk-margin-small-top">' . htmlspecialchars($note) . '</div>
				</div>';
		}

		return $this->renderSectionNav('cli') . '
		<style>
			.pdb-cli-dashboard code {
				overflow-wrap: anywhere;
				white-space: normal;
			}
			.pdb-cli-command {
				margin: 0;
				overflow-x: auto;
				white-space: pre;
			}
			.pdb-cli-command code {
				display: block;
				white-space: pre;
			}
			.pdb-cli-example {
				border-top: 1px solid #e5e5e5;
				padding: 14px 0;
			}
			.pdb-cli-example:first-child {
				border-top: 0;
				padding-top: 0;
			}
			.pdb-cli-steps {
				margin: 0;
				padding-left: 20px;
			}
			.pdb-cli-steps li {
				margin-bottom: 8px;
			}
		</style>
		<div class="pdb-cli-dashboard">
			<div class="uk-alert uk-alert-primary" uk-alert>
				<p class="uk-margin-remove">CLI commands use the same module settings, backup storage, migration table, and <code>' . htmlspecialchars($storage) . '</code> files as the admin UI.</p>
			</div>

			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Typical workflow</h3>
			<div class="uk-grid-small uk-child-width-1-2@m uk-margin-medium-bottom" uk-grid>
				<div>
					<h4 class="uk-margin-small-bottom">Local development</h4>
					<ol class="uk-text-small uk-text-muted pdb-cli-steps">
						<li>Create a schema snapshot before changing fields or templates.</li>
						<li>Make your ProcessWire structure changes locally.</li>
						<li>Run <code>schema:diff</code> to see what changed.</li>
						<li>Run <code>schema:preview</code>, then <code>schema:generate</code> when the generated PHP looks right.</li>
						<li>Commit the migration file from <code>site/assets/ProcessDbBackup/migrations/</code>.</li>
					</ol>
				</div>
				<div>
					<h4 class="uk-margin-small-bottom">Server deployment</h4>
					<ol class="uk-text-small uk-text-muted pdb-cli-steps">
						<li>Upload or pull the new migration files to the server.</li>
						<li>Run <code>migrations:status</code> first. It should show only the migrations you expect.</li>
						<li>Run <code>migrations:run --all --yes</code> after confirming the list.</li>
						<li>On production, add <code>--confirm="RUN ON PRODUCTION"</code>.</li>
					</ol>
				</div>
			</div>

			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Command</h3>
			<p class="uk-text-small uk-text-muted">From the ProcessWire root:</p>
			<pre class="uk-padding-small uk-background-muted pdb-cli-command"><code>cd ' . htmlspecialchars(rtrim($root, '/')) . '
' . htmlspecialchars($displayCommand . 'help') . '</code></pre>
			<p class="uk-text-small uk-text-muted">From any folder:</p>
			<pre class="uk-padding-small uk-background-muted pdb-cli-command"><code>' . htmlspecialchars($rootCommand . 'help') . '</code></pre>

			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Examples</h3>
			<div class="uk-card uk-card-default uk-card-small uk-card-body">' . $exampleCards . '</div>

			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Notes</h3>
			<ul class="uk-list uk-list-bullet uk-text-small uk-text-muted">
				<li><code>schema:snapshot</code>, <code>schema:diff</code>, and <code>schema:generate</code> work with site structure only. They do not store page content or field values.</li>
				<li><code>migrations:run</code> requires <code>--yes</code>. Production additionally requires <code>--confirm="RUN ON PRODUCTION"</code>.</li>
				<li>Use <code>schema:preview</code> before <code>schema:generate</code> when you want to inspect the generated PHP first.</li>
			</ul>
		</div>';
	}

	protected function getCliDisplayCommand(): string {
		$moduleFile = (string)$this->wire('modules')->getModuleFile($this->className());
		$script = dirname($moduleFile) . '/bin/pdb';
		$root = (string)$this->wire('config')->paths->root;

		if ($root !== '' && str_starts_with($script, $root)) {
			$script = ltrim(substr($script, strlen($root)), '/\\');
		}

		return 'php ' . escapeshellarg($script) . ' ';
	}

	protected function renderMigrationsDashboard(): string {
		$this->ensureMigrationStore();

		$pageUrl    = $this->page->url;
		$csrf       = $this->session->CSRF->renderInput();
		$migrations = $this->getMigrationStatusList();
		$pending    = array_values(array_filter($migrations, fn($m) => !$m['applied']));
		$applied    = array_values(array_filter($migrations, fn($m) => $m['applied']));

		$html = $this->renderSectionNav('migrations');
		$html .= '
		<style>
			.pdb-migrations-dashboard code {
				overflow-wrap: anywhere;
				white-space: normal;
			}
			.pdb-migration-tools .uk-form-custom {
				display: block;
			}
			.pdb-migration-tools .uk-form-custom .uk-input {
				width: 100%;
			}
			.pdb-migration-guide {
				border-top: 1px solid #e5e5e5;
				border-bottom: 1px solid #e5e5e5;
				padding: 18px 0;
			}
			.pdb-migration-guide ol {
				margin: 0;
				padding-left: 20px;
			}
			.pdb-migration-guide li {
				margin-bottom: 8px;
			}
		</style>
		<div class="pdb-migrations-dashboard">
		<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-medium-bottom pdb-migration-status" style="gap:12px">
			<div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap:8px">
				<span class="uk-label uk-label-warning">' . count($pending) . ' pending</span>
				<span class="uk-label uk-label-success">' . count($applied) . ' applied</span>
				<span class="uk-text-small uk-text-muted">Storage: <code>' . htmlspecialchars($this->getRelativeAssetsPath(self::MIGRATIONS_DIR)) . '</code></span>
			</div>
			<a href="' . $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className() . '" class="uk-button uk-button-default">
				<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Settings
			</a>
		</div>

		<div class="uk-alert uk-alert-primary uk-margin-small-bottom" uk-alert>
			<p class="uk-margin-remove">Migrations are PHP files stored in <code>site/assets/ProcessDbBackup/migrations/</code>. Use them for schema/deployment changes, not for overwriting live content.</p>
		</div>

		<div class="pdb-migration-guide uk-margin-medium-bottom">
			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">How to use migrations</h3>
			<div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
				<div>
					<h4 class="uk-margin-small-bottom">Create a migration</h4>
					<ol class="uk-text-small uk-text-muted">
						<li>Before changing fields/templates, create a schema snapshot.</li>
						<li>Make the schema changes locally in ProcessWire.</li>
						<li>Use schema diff to review what changed.</li>
						<li>Create a migration file from safe diff items, or create one manually from the form below.</li>
						<li>Commit or upload the generated <code>.php</code> file from the migrations folder.</li>
					</ol>
				</div>
				<div>
					<h4 class="uk-margin-small-bottom">Apply a migration</h4>
					<ol class="uk-text-small uk-text-muted">
						<li>Check pending files in the Migration Files table.</li>
						<li>Open View to inspect the PHP and impact preview.</li>
						<li>Apply only pending migrations you recognize.</li>
						<li>Do not use migrations to move live page content or overwrite field values.</li>
						<li>On production, type <code>RUN ON PRODUCTION</code> before applying.</li>
					</ol>
				</div>
			</div>
		</div>';
		if ($this->isProductionEnvironment()) {
			$html .= '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove"><strong>Production environment.</strong> Running a migration requires typing <code>RUN ON PRODUCTION</code>.</p></div>';
		}
		$lockStatus = $this->getMigrationLockStatus();
		if ($lockStatus['locked']) {
			$html .= '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove"><strong>Migration lock active.</strong> Another migration appears to be running. Lock age: ' . (int)$lockStatus['age'] . 's.</p></div>';
		}

		$html .= $this->renderMigrationTools($csrf);
		$html .= $this->renderSchemaSnapshots($csrf);
		$html .= $this->renderMigrationScripts();

		if (empty($migrations)) {
			$html .= '
			<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Migration Files</h3>
			<p class="uk-text-muted"><span uk-icon="icon: info"></span> No migration files found.</p>
			<p class="uk-text-small uk-text-muted">Create a PHP file in <code>' . htmlspecialchars($this->getRelativeAssetsPath(self::MIGRATIONS_DIR)) . '</code>, for example <code>2026_06_21_1530_recipes.php</code>.</p>
			</div>';
			return $html;
		}

		$html .= '
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Migration Files</h3>
		<div class="uk-overflow-auto">
		<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
			<thead><tr>
				<th>Migration</th>
				<th>Status</th>
				<th>Preflight</th>
				<th>Checksum</th>
				<th>Applied at</th>
				<th>Pre-backup</th>
				<th class="uk-text-right">Action</th>
			</tr></thead>
			<tbody>';

		foreach ($migrations as $m) {
			$file = htmlspecialchars($m['filename']);
			$checksumShort = htmlspecialchars(substr($m['checksum'], 0, 12));
			if ($m['applied'] && $m['checksum_mismatch']) {
				$status = '<span class="uk-label uk-label-danger">Applied, changed</span>';
			} elseif ($m['applied']) {
				$status = '<span class="uk-label uk-label-success">Applied</span>';
			} else {
				$status = '<span class="uk-label uk-label-warning">Pending</span>';
			}
			$appliedAt = $m['applied_at'] ? htmlspecialchars($m['applied_at']) : '<span class="uk-text-muted">-</span>';
			$preBackup = $m['pre_backup'] ? '<code>' . htmlspecialchars($m['pre_backup']) . '</code>' : '<span class="uk-text-muted">-</span>';
			$lintBadge = $m['lint_valid']
				? '<span class="uk-label uk-label-success">PHP OK</span>'
				: '<span class="uk-label uk-label-danger" uk-tooltip="' . htmlspecialchars($m['lint_output']) . '">PHP error</span>';

			if ($m['applied']) {
				$action = '<a href="' . $pageUrl . '?action=view_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: search; ratio:.7"></span>&nbsp; View
					</a>
					<a href="' . $pageUrl . '?action=download_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download
					</a>
					<a href="' . $pageUrl . '?action=migration_details&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: info; ratio:.7"></span>&nbsp; Details
					</a>';
			} elseif (!$m['lint_valid']) {
				$action = '<a href="' . $pageUrl . '?action=view_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: search; ratio:.7"></span>&nbsp; View
					</a>
					<a href="' . $pageUrl . '?action=download_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download
					</a>
					' . $this->renderDeleteMigrationForm($m['filename'], $csrf) . '
					<span class="uk-text-small uk-text-danger uk-margin-small-left">Fix syntax first</span>';
			} else {
				$confirm = "Apply migration {$m['filename']}?\nA pre-migration backup will be created if that setting is enabled.";
				$productionConfirm = $this->renderProductionConfirmInput();
				$action = '<a href="' . $pageUrl . '?action=view_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: search; ratio:.7"></span>&nbsp; View
					</a>
					<a href="' . $pageUrl . '?action=download_migration&file=' . rawurlencode($m['filename']) . '" class="uk-button uk-button-default">
						<span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download
					</a>
					' . $this->renderDeleteMigrationForm($m['filename'], $csrf) . '
				<form method="post" action="' . $pageUrl . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="run_migration">
					<input type="hidden" name="migration_file" value="' . $file . '">
					' . $productionConfirm . '
					<button type="submit" class="uk-button uk-button-primary" onclick="return confirm(\'' . addslashes($confirm) . '\')">
						<span uk-icon="icon: play; ratio:.7"></span>&nbsp; Run
					</button>
				</form>';
			}

			$html .= '<tr>'
				. '<td class="uk-text-small uk-text-nowrap"><span uk-icon="icon: file-text; ratio:.8" class="uk-margin-small-right"></span><code>' . $file . '</code></td>'
				. '<td>' . $status . '</td>'
				. '<td>' . $lintBadge . '</td>'
				. '<td class="uk-text-small"><code>' . $checksumShort . '</code></td>'
				. '<td class="uk-text-small uk-text-muted uk-text-nowrap">' . $appliedAt . '</td>'
				. '<td class="uk-text-small uk-text-nowrap">' . $preBackup . '</td>'
				. '<td class="uk-text-right uk-text-nowrap">' . $action . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table></div></div>';
		return $html;
	}

	protected function renderMigrationTools(string $csrf): string {
		return '
		<div class="uk-margin-medium-top uk-margin-medium-bottom pdb-migration-tools">
			<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-small-bottom" style="gap:12px">
				<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted uk-margin-remove">Migration Tools</h3>
			</div>
			<ul uk-tab="connect: #pdb-migration-tools; animation: uk-animation-fade" class="uk-margin-small-bottom">
				<li class="uk-active"><a href="#"><span uk-icon="icon: file-edit; ratio:.75"></span>&nbsp; Create</a></li>
				<li><a href="#"><span uk-icon="icon: upload; ratio:.75"></span>&nbsp; Upload</a></li>
			</ul>
			<ul id="pdb-migration-tools" class="uk-switcher uk-margin">
				<li>' . $this->renderMigrationGenerator($csrf) . '</li>
				<li>' . $this->renderMigrationUpload($csrf) . '</li>
			</ul>
		</div>';
	}

	protected function renderMigrationGenerator(string $csrf): string {
		$fieldOptions = $this->renderSelectOptions($this->getFieldSelectOptions());
		$templateOptions = $this->renderSelectOptions($this->getTemplateSelectOptions());
		$fieldTypeOptions = $this->renderSelectOptions($this->getFieldtypeSelectOptions(), 'FieldtypeText');
		$moduleOptions = $this->renderSelectOptions($this->getInstallableModuleSelectOptions());
		$templateFieldOptions = $this->renderSelectOptions($this->getFieldSelectOptions(), 'title');

		return '
		<form method="post" action="' . $this->page->url . '" class="uk-form-stacked">
			' . $csrf . '
			<input type="hidden" name="action" value="create_migration">
			<div class="uk-grid-small" uk-grid>
				<div class="uk-width-1-1">
					<label class="uk-form-label" for="pdb-migration-title">Name</label>
					<div class="uk-form-controls">
						<input id="pdb-migration-title" class="uk-input" name="migration_title" type="text" placeholder="Add recipe fields" required>
						<p class="uk-text-meta uk-margin-small-top">Human-readable title for this migration file. It is also used to build the filename.</p>
					</div>
				</div>
				<div class="uk-width-1-1">
					<label class="uk-form-label">Operation</label>
					<div class="uk-form-controls">
						<div class="uk-grid-small uk-child-width-1-2@s uk-child-width-1-3@m" uk-grid>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="create_field" checked> Create field</label>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="create_template"> Create template</label>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="add_field_to_template"> Add field to template</label>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="install_module"> Install module</label>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="create_permission"> Create permission</label>
							<label><input class="uk-radio pdb-migration-type" type="radio" name="migration_type" value="create_role"> Create role</label>
						</div>
						<p class="uk-text-meta uk-margin-small-top">Choose the schema change you want to generate. Migrations should change structure, not overwrite page content or field values.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-field-name-new">
					<label class="uk-form-label" for="pdb-field-name-new">New field name</label>
					<div class="uk-form-controls">
						<input id="pdb-field-name-new" class="uk-input" name="field_name_new" type="text" placeholder="recipe_time">
						<p class="uk-text-meta uk-margin-small-top">Machine name for a field that does not exist yet. Use lowercase letters, numbers, and underscores.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-field-name-existing">
					<label class="uk-form-label" for="pdb-field-name-existing">Field</label>
					<div class="uk-form-controls">
						<select id="pdb-field-name-existing" class="uk-select" name="field_name_existing">
							<option value="">Select field...</option>
							' . $fieldOptions . '
						</select>
						<p class="uk-text-meta uk-margin-small-top">Existing ProcessWire field to attach to a template. This does not create a new field.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-field-type">
					<label class="uk-form-label" for="pdb-field-type">Field type</label>
					<div class="uk-form-controls">
						<select id="pdb-field-type" class="uk-select" name="field_type">
							' . $fieldTypeOptions . '
						</select>
						<p class="uk-text-meta uk-margin-small-top">Storage type for the new field. Use Text for simple strings, Textarea for longer text, Page for page references, etc.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-field-label">
					<label class="uk-form-label" for="pdb-field-label">Field label</label>
					<div class="uk-form-controls">
						<input id="pdb-field-label" class="uk-input" name="field_label" type="text" placeholder="Recipe time">
						<p class="uk-text-meta uk-margin-small-top">Editor-facing label shown in the ProcessWire admin. If blank, the field name is used.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-template-name-new">
					<label class="uk-form-label" for="pdb-template-name-new">New template name</label>
					<div class="uk-form-controls">
						<input id="pdb-template-name-new" class="uk-input" name="template_name_new" type="text" placeholder="recipe">
						<p class="uk-text-meta uk-margin-small-top">Machine name for a template that does not exist yet. The migration also creates its fieldgroup.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-template-name-existing">
					<label class="uk-form-label" for="pdb-template-name-existing">Template</label>
					<div class="uk-form-controls">
						<select id="pdb-template-name-existing" class="uk-select" name="template_name_existing">
							<option value="">Select template...</option>
							' . $templateOptions . '
						</select>
						<p class="uk-text-meta uk-margin-small-top">Existing template that should receive the selected field. Page content is not changed.</p>
					</div>
				</div>
				<div class="uk-width-2-3@m pdb-generator-field pdb-template-fields">
					<label class="uk-form-label" for="pdb-template-fields">Template fields</label>
					<div class="uk-form-controls">
						<select id="pdb-template-fields" class="uk-select" name="template_fields[]" multiple size="8">
							' . $templateFieldOptions . '
						</select>
						<p class="uk-text-meta uk-margin-small-top">Fields to add to the new template fieldgroup. Hold Cmd/Ctrl to select more than one.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-module-name">
					<label class="uk-form-label" for="pdb-module-name">Module</label>
					<div class="uk-form-controls">
						<select id="pdb-module-name" class="uk-select" name="module_name">
							<option value="">Select installable module...</option>
							' . $moduleOptions . '
						</select>
						<p class="uk-text-meta uk-margin-small-top">Module to install during deployment if it is not installed yet. Only installable modules are listed.</p>
					</div>
				</div>
				<div class="uk-width-1-3@m pdb-generator-field pdb-access-name">
					<label class="uk-form-label" for="pdb-permission-name">Permission / role</label>
					<div class="uk-form-controls">
						<input id="pdb-permission-name" class="uk-input" name="access_name" type="text" placeholder="recipe-editor">
						<p class="uk-text-meta uk-margin-small-top">Machine name for a new permission or role. Use lowercase words separated by hyphens.</p>
					</div>
				</div>
				<div class="uk-width-1-1">
					<div class="uk-margin-small-bottom">
						<button type="button" class="uk-button uk-button-default" id="pdb-advanced-toggle" aria-expanded="false" aria-controls="pdb-advanced-options">
							<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Advanced options
						</button>
						<div id="pdb-advanced-options" class="uk-margin-small-top" hidden>
							<label class="uk-form-label" for="pdb-migration-message">Return message</label>
							<div class="uk-form-controls">
								<input id="pdb-migration-message" class="uk-input" name="migration_message" type="text" placeholder="Recipe schema migrated.">
								<p class="uk-text-meta uk-margin-small-top">Optional success message shown after the migration runs. Leave blank to use the migration name.</p>
							</div>
						</div>
					</div>
					<button type="submit" class="uk-button uk-button-primary">
						<span uk-icon="icon: file-edit; ratio:.7"></span>&nbsp; Create migration file
					</button>
				</div>
			</div>
		</form>';
		}

	protected function renderSelectOptions(array $options, string $selected = ''): string {
		$html = '';
		foreach ($options as $value => $label) {
			$value = (string)$value;
			$html .= '<option value="' . htmlspecialchars($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . htmlspecialchars((string)$label) . '</option>';
		}
		return $html;
	}

	protected function getFieldSelectOptions(): array {
		$options = [];
		foreach ($this->wire('fields') as $field) {
			if (!$field->name) continue;
			$label = (string)$field->label;
			$options[$field->name] = $label !== '' && $label !== $field->name ? $field->name . ' - ' . $label : $field->name;
		}
		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
		return $options;
	}

	protected function getTemplateSelectOptions(): array {
		$options = [];
		foreach ($this->wire('templates') as $template) {
			if (!$template->name) continue;
			$label = (string)$template->label;
			$options[$template->name] = $label !== '' && $label !== $template->name ? $template->name . ' - ' . $label : $template->name;
		}
		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
		return $options;
	}

	protected function getFieldtypeSelectOptions(): array {
		$options = [];
		foreach ($this->wire('modules')->findByPrefix('Fieldtype') as $name) {
			$options[$name] = $name;
		}
		if (empty($options)) {
			$options = [
				'FieldtypeText'     => 'FieldtypeText',
				'FieldtypeTextarea' => 'FieldtypeTextarea',
				'FieldtypeInteger'  => 'FieldtypeInteger',
				'FieldtypePage'     => 'FieldtypePage',
			];
		}
		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
		return $options;
	}

	protected function getInstallableModuleSelectOptions(): array {
		$options = [];
		foreach ($this->wire('modules')->getInstallable() as $name => $path) {
			$options[$name] = $name;
		}
		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
		return $options;
	}

	protected function renderDeleteMigrationForm(string $filename, string $csrf): string {
		$filenameEsc = htmlspecialchars($filename);
		$confirm = "Delete pending migration {$filename}?";
		return '
		<form method="post" action="' . $this->page->url . '" class="uk-display-inline">
			' . $csrf . '
			<input type="hidden" name="action" value="delete_pending_migration">
			<input type="hidden" name="migration_file" value="' . $filenameEsc . '">
			<button type="submit" class="uk-button uk-button-default" onclick="return confirm(\'' . addslashes($confirm) . '\')">
				<span uk-icon="icon: trash; ratio:.7"></span>&nbsp; Delete
			</button>
		</form>';
	}

	protected function renderMigrationUpload(string $csrf): string {
		return '
		<form method="post" action="' . $this->page->url . '" enctype="multipart/form-data" class="uk-form-stacked">
			' . $csrf . '
			<input type="hidden" name="action" value="upload_migration_file">
			<div class="uk-grid-small uk-flex-middle" uk-grid>
				<div class="uk-width-expand">
					<div uk-form-custom="target: true">
						<input type="file" name="migration_upload" accept=".php" required>
						<input class="uk-input" type="text" placeholder="Select migration .php file..." readonly>
					</div>
				</div>
				<div>
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: upload; ratio:.7"></span>&nbsp; Upload migration
					</button>
				</div>
			</div>
		</form>';
	}

	protected function renderMigrationScripts(): string {
		return <<<HTML
		<script>
		(function() {
			const types = Array.from(document.querySelectorAll('.pdb-migration-type'));
			if (!types.length) return;
			const groups = {
				create_field: ['pdb-field-name-new', 'pdb-field-type', 'pdb-field-label'],
				create_template: ['pdb-template-name-new', 'pdb-template-fields'],
				add_field_to_template: ['pdb-field-name-existing', 'pdb-template-name-existing'],
				install_module: ['pdb-module-name'],
				create_permission: ['pdb-access-name'],
				create_role: ['pdb-access-name']
			};
			const title = document.getElementById('pdb-migration-title');
			let titleTouched = title && title.value.trim() !== '';
			const prettify = value => (value || '')
				.replace(/[_-]+/g, ' ')
				.replace(/\b\w/g, letter => letter.toUpperCase())
				.trim();
			const selectedValue = selector => {
				const el = document.querySelector(selector);
				return el ? el.value.trim() : '';
			};
			const selectedValues = selector => {
				const el = document.querySelector(selector);
				return el ? Array.from(el.selectedOptions).map(option => option.value.trim()).filter(Boolean) : [];
			};
			const suggestTitle = active => {
				switch (active) {
					case 'create_field': {
						const field = selectedValue('#pdb-field-name-new');
						return field ? 'Create ' + prettify(field) + ' field' : 'Create field';
					}
					case 'create_template': {
						const template = selectedValue('#pdb-template-name-new');
						const fields = selectedValues('#pdb-template-fields');
						if (template && fields.length) return 'Create ' + prettify(template) + ' template with fields';
						return template ? 'Create ' + prettify(template) + ' template' : 'Create template';
					}
					case 'add_field_to_template': {
						const field = selectedValue('#pdb-field-name-existing');
						const template = selectedValue('#pdb-template-name-existing');
						if (field && template) return 'Add ' + prettify(field) + ' to ' + prettify(template);
						return 'Add field to template';
					}
					case 'install_module': {
						const module = selectedValue('#pdb-module-name');
						return module ? 'Install ' + module : 'Install module';
					}
					case 'create_permission': {
						const permission = selectedValue('#pdb-permission-name');
						return permission ? 'Create ' + prettify(permission) + ' permission' : 'Create permission';
					}
					case 'create_role': {
						const role = selectedValue('#pdb-permission-name');
						return role ? 'Create ' + prettify(role) + ' role' : 'Create role';
					}
					default:
						return '';
				}
			};
			const updateTitle = active => {
				if (!title || titleTouched) return;
				const suggestion = suggestTitle(active);
				if (suggestion) title.value = suggestion;
			};
			const update = () => {
				const checked = document.querySelector('.pdb-migration-type:checked');
				const active = checked ? checked.value : 'create_field';
				document.querySelectorAll('.pdb-generator-field').forEach(el => {
					el.classList.add('uk-hidden');
					el.querySelectorAll('input, select, textarea').forEach(input => input.disabled = true);
				});
				(groups[active] || []).forEach(cls => {
					document.querySelectorAll('.' + cls).forEach(el => {
						el.classList.remove('uk-hidden');
						el.querySelectorAll('input, select, textarea').forEach(input => input.disabled = false);
					});
				});
				updateTitle(active);
			};
			if (title) {
				title.addEventListener('input', () => {
					titleTouched = title.value.trim() !== '';
				});
			}
			const advancedToggle = document.getElementById('pdb-advanced-toggle');
			const advancedPanel = document.getElementById('pdb-advanced-options');
			const syncAdvanced = expanded => {
				if (!advancedToggle || !advancedPanel) return;
				advancedPanel.hidden = !expanded;
				advancedToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				advancedPanel.querySelectorAll('input, select, textarea').forEach(input => input.disabled = !expanded);
			};
			if (advancedToggle && advancedPanel) {
				syncAdvanced(false);
				advancedToggle.addEventListener('click', () => {
					syncAdvanced(advancedToggle.getAttribute('aria-expanded') !== 'true');
				});
			}
			document.querySelectorAll('.pdb-generator-field input, .pdb-generator-field select').forEach(input => {
				input.addEventListener('input', update);
				input.addEventListener('change', update);
			});
			types.forEach(type => type.addEventListener('change', update));
			update();
		})();
		</script>
HTML;
	}

	protected function renderMigrationPreview(string $filename): string {
		$filename = basename($filename);
		$backUrl = '<p><a href="' . $this->page->url . '?action=migrations" class="uk-button uk-button-default"><span uk-icon="icon: arrow-left; ratio:.7"></span>&nbsp; Back to migrations</a></p>';
		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-danger" uk-alert>Invalid migration filename.</div>';
		}

		$path = $this->getMigrationsDir() . $filename;
		if (!is_file($path)) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-danger" uk-alert>Migration file not found.</div>';
		}

		$statuses = $this->getMigrationStatusList();
		$status = null;
		foreach ($statuses as $candidate) {
			if ($candidate['filename'] === $filename) {
				$status = $candidate;
				break;
			}
		}

		$code = file_get_contents($path);
		if ($code === false) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-danger" uk-alert>Could not read migration file.</div>';
		}

		$csrf = $this->session->CSRF->renderInput();
		$checksum = hash_file('sha256', $path) ?: '';
		$lint = $this->lintMigrationFile($path);
		$impact = $this->analyzeMigrationImpact($code);
		$isApplied = (bool)($status['applied'] ?? false);
		$hasManualReview = str_contains($code, 'Manual review required');
		$statusBadge = $isApplied
			? '<span class="uk-label uk-label-success">Applied</span>'
			: '<span class="uk-label uk-label-warning">Pending</span>';
		$lintBadge = $lint['valid']
			? '<span class="uk-label uk-label-success">PHP OK</span>'
			: '<span class="uk-label uk-label-danger">PHP error</span>';

		$runForm = '';
		if (!$isApplied && $lint['valid']) {
			$confirm = "Apply migration {$filename}?\nA pre-migration backup will be created if that setting is enabled.";
			$productionConfirm = $this->renderProductionConfirmInput();
			$runForm = '
			<form method="post" action="' . $this->page->url . '" class="uk-display-inline">
				' . $csrf . '
				<input type="hidden" name="action" value="run_migration">
				<input type="hidden" name="migration_file" value="' . htmlspecialchars($filename) . '">
				' . $productionConfirm . '
				<button type="submit" class="uk-button uk-button-primary" onclick="return confirm(\'' . addslashes($confirm) . '\')">
					<span uk-icon="icon: play; ratio:.7"></span>&nbsp; Run migration
				</button>
			</form>';
		}

		$manualAlert = $hasManualReview
			? '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove">This generated migration contains manual-review comments. Review changed/removed schema items before running it on production.</p></div>'
			: '';
		$lintAlert = !$lint['valid']
			? '<div class="uk-alert uk-alert-danger" uk-alert><p class="uk-margin-remove"><strong>PHP syntax check failed.</strong></p><pre class="uk-text-small uk-margin-small-top">' . htmlspecialchars($lint['output']) . '</pre></div>'
			: '';
		$impactHtml = $this->renderMigrationImpact($impact);

		return $this->renderSectionNav('migrations') . $backUrl . '
		<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-small-bottom" style="gap:8px">
			<div>
				<h3 class="uk-heading-divider uk-margin-remove-bottom">' . htmlspecialchars($filename) . '</h3>
				<p class="uk-text-small uk-text-muted uk-margin-small-top">Checksum: <code>' . htmlspecialchars(substr($checksum, 0, 16)) . '</code> · ' . $statusBadge . ' · ' . $lintBadge . '</p>
			</div>
			<div>
				<a href="' . $this->page->url . '?action=download_migration&file=' . rawurlencode($filename) . '" class="uk-button uk-button-default"><span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download</a>
				' . $runForm . '
			</div>
		</div>
		' . $lintAlert . '
		' . $manualAlert . '
		' . $impactHtml . '
		<pre class="uk-text-small" style="max-height:70vh;overflow:auto"><code>' . htmlspecialchars($code) . '</code></pre>';
	}

	protected function renderMigrationDetails(string $filename): string {
		$filename = basename($filename);
		$backUrl = '<p><a href="' . $this->page->url . '?action=migrations" class="uk-button uk-button-default"><span uk-icon="icon: arrow-left; ratio:.7"></span>&nbsp; Back to migrations</a></p>';
		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-danger" uk-alert>Invalid migration filename.</div>';
		}

		$applied = $this->getAppliedMigrations();
		$row = $applied[$filename] ?? null;
		if (!$row) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-warning" uk-alert>Migration has not been applied yet.</div>';
		}

		$path = $this->getMigrationsDir() . $filename;
		$currentChecksum = is_file($path) ? (hash_file('sha256', $path) ?: '') : '';
		$storedChecksum = (string)($row['checksum'] ?? '');
		$checksumChanged = $currentChecksum !== '' && $storedChecksum !== '' && !hash_equals($storedChecksum, $currentChecksum);
		$checksumAlert = $checksumChanged
			? '<div class="uk-alert uk-alert-danger" uk-alert><p class="uk-margin-remove">The migration file has changed since it was applied. Review the file before reusing this deployment history.</p></div>'
			: '';

		$preBackup = (string)($row['pre_backup'] ?? '');
		$preBackupHtml = $preBackup !== ''
			? '<code>' . htmlspecialchars($preBackup) . '</code>'
			: '<span class="uk-text-muted">-</span>';
		$message = trim((string)($row['message'] ?? ''));
		$messageHtml = $message !== ''
			? '<pre class="uk-text-small uk-margin-remove">' . htmlspecialchars($message) . '</pre>'
			: '<span class="uk-text-muted">-</span>';

		return $this->renderSectionNav('migrations') . $backUrl . '
		<h3 class="uk-heading-divider">' . htmlspecialchars($filename) . '</h3>
		' . $checksumAlert . '
		<table class="uk-table uk-table-small uk-table-divider">
			<tbody>
				<tr><th>Applied at</th><td>' . htmlspecialchars((string)($row['applied_at'] ?? '')) . '</td></tr>
				<tr><th>Applied by</th><td>' . htmlspecialchars((string)($row['applied_by'] ?? '')) . '</td></tr>
				<tr><th>Stored checksum</th><td><code>' . htmlspecialchars($storedChecksum) . '</code></td></tr>
				<tr><th>Current checksum</th><td>' . ($currentChecksum ? '<code>' . htmlspecialchars($currentChecksum) . '</code>' : '<span class="uk-text-muted">File missing</span>') . '</td></tr>
				<tr><th>Pre-backup</th><td>' . $preBackupHtml . '</td></tr>
				<tr><th>Message</th><td>' . $messageHtml . '</td></tr>
			</tbody>
		</table>
		<p>
			<a href="' . $this->page->url . '?action=view_migration&file=' . rawurlencode($filename) . '" class="uk-button uk-button-default"><span uk-icon="icon: search; ratio:.7"></span>&nbsp; View migration file</a>
			<a href="' . $this->page->url . '?action=download_migration&file=' . rawurlencode($filename) . '" class="uk-button uk-button-default"><span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download</a>
		</p>';
	}

	protected function renderSchemaSnapshots(string $csrf): string {
		$snapshots = $this->getSchemaSnapshotFiles();
		$latest = $snapshots[0] ?? null;
		$selectedSnapshotFile = basename((string)($this->input->get('compare_snapshot') ?? ''));
		$baseline = $selectedSnapshotFile !== '' ? $this->getSchemaSnapshotByFilename($selectedSnapshotFile) : $latest;
		if (!$baseline) $baseline = $latest;
		$diff = $baseline ? $this->diffSchemaSnapshot($baseline['path']) : [];

		$html = '
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Schema Diff</h3>
		<div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-small-bottom" style="gap:8px">
			<p class="uk-text-small uk-text-muted uk-margin-remove">Compare the current site structure against a saved snapshot. Snapshots include fields, templates, permissions, and roles, but not page content or field values.</p>
			<form method="post" action="' . $this->page->url . '" class="uk-display-inline">
				' . $csrf . '
				<input type="hidden" name="action" value="create_schema_snapshot">
				<button type="submit" class="uk-button uk-button-default">
					<span uk-icon="icon: camera; ratio:.7"></span>&nbsp; Save current structure
				</button>
			</form>
		</div>
		<p class="uk-text-small uk-text-muted">Folder: <code>' . htmlspecialchars($this->getRelativeAssetsPath(self::SNAPSHOTS_DIR)) . '</code></p>';

		if (!$latest) {
			return $html . '
			<div class="uk-alert uk-alert-primary" uk-alert>
				<p class="uk-margin-remove"><strong>No schema baseline yet.</strong> Save the current structure before changing fields, templates, permissions, or roles. After your local changes, this section will show what changed and can generate a migration for newly added schema.</p>
			</div>';
		}

		$html .= $this->renderSchemaBaselineForm($snapshots, $baseline['filename']);

		$canGenerate = !empty(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		if ($canGenerate) {
			$html .= $this->renderSchemaGenerationPlan($diff);
			$html .= '
			<div class="uk-flex uk-flex-middle uk-flex-wrap uk-margin-small-bottom" style="gap:8px">
				<a href="' . $this->page->url . '?action=preview_schema_diff_migration&snapshot_file=' . rawurlencode($baseline['filename']) . '" class="uk-button uk-button-default">
					<span uk-icon="icon: search; ratio:.7"></span>&nbsp; Preview generated migration
				</a>
				<form method="post" action="' . $this->page->url . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="generate_migration_from_diff">
					<input type="hidden" name="snapshot_file" value="' . htmlspecialchars($baseline['filename']) . '">
					<button type="submit" class="uk-button uk-button-primary">
						<span uk-icon="icon: code; ratio:.7"></span>&nbsp; Create migration file
					</button>
				</form>
			</div>';
		}

		$html .= '
		<div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
			<div>
				<table class="uk-table uk-table-small uk-table-divider uk-table-hover">
					<thead><tr><th>Snapshot</th><th class="uk-text-right">Size</th><th class="uk-text-right">Action</th></tr></thead>
					<tbody>';

		foreach (array_slice($snapshots, 0, 5) as $snapshot) {
			$html .= '<tr>'
				. '<td class="uk-text-small"><code>' . htmlspecialchars($snapshot['filename']) . '</code></td>'
				. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . $this->formatBytes((int)$snapshot['size']) . '</td>'
				. '<td class="uk-text-right"><a href="' . $this->page->url . '?action=download_snapshot&file=' . rawurlencode($snapshot['filename']) . '" class="uk-button uk-button-default"><span uk-icon="icon: download; ratio:.7"></span>&nbsp; Download</a> ' . $this->renderDeleteSnapshotForm($snapshot['filename'], $csrf) . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table></div><div>';
		$html .= $this->renderSchemaDiffSummary($diff, $baseline['filename']);
		$html .= '</div></div>';

		return $html;
	}

	protected function renderSchemaGenerationPlan(array $diff): string {
		$auto = array_values(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		if (empty($auto)) return '';

		$manual = array_values(array_filter($diff, fn($item) => !$this->isAutoGeneratableSchemaDiffItem($item)));
		$counts = [];
		foreach ($auto as $item) {
			$scope = $this->formatSchemaDiffScope((string)($item['scope'] ?? 'schema'));
			$counts[$scope] = ($counts[$scope] ?? 0) + 1;
		}

		$parts = [];
		foreach ($counts as $scope => $count) {
			$parts[] = $count . ' ' . $scope;
		}

		$message = 'Auto-generation will include ' . implode(', ', $parts) . '.';
		if (!empty($manual)) {
			$message .= ' ' . count($manual) . ' changed/removed item(s) will be added as manual-review comments.';
		}

		return '<div class="uk-alert uk-alert-primary" uk-alert><p class="uk-margin-remove"><strong>Generation plan.</strong> ' . htmlspecialchars($message) . '</p></div>';
	}

	protected function formatSchemaDiffScope(string $scope): string {
		return match($scope) {
			'template_fields' => 'template field assignment(s)',
			'field_labels' => 'field label update(s)',
			'template_labels' => 'template label update(s)',
			default => $scope,
		};
	}

	protected function isAutoGeneratableSchemaDiffItem(array $item): bool {
		$scope = (string)($item['scope'] ?? '');
		$type = (string)($item['type'] ?? '');
		if ($type === 'added') return true;
		return $type === 'changed' && in_array($scope, ['field_labels', 'template_labels'], true);
	}

	protected function renderSchemaBaselineForm(array $snapshots, string $selectedFilename): string {
		$options = '';
		foreach ($snapshots as $snapshot) {
			$filename = $snapshot['filename'];
			$label = $filename . ' (' . date('Y-m-d H:i', (int)$snapshot['mtime']) . ')';
			$options .= '<option value="' . htmlspecialchars($filename) . '"' . ($filename === $selectedFilename ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
		}

		return '
		<form method="get" action="' . $this->page->url . '" class="uk-form-stacked uk-margin-small-bottom">
			<input type="hidden" name="action" value="migrations">
			<label class="uk-form-label" for="pdb-schema-baseline">Compare current structure to</label>
			<div class="uk-grid-small uk-flex-middle" uk-grid>
				<div class="uk-width-expand">
					<select id="pdb-schema-baseline" class="uk-select" name="compare_snapshot">
						' . $options . '
					</select>
					<p class="uk-text-meta uk-margin-small-top">Use the snapshot from before your local schema changes as the baseline.</p>
				</div>
				<div>
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: refresh; ratio:.7"></span>&nbsp; Compare
					</button>
				</div>
			</div>
		</form>';
	}

	protected function renderSchemaDiffSummary(array $diff, string $filename): string {
		if (empty($diff)) {
			return '<div class="uk-alert uk-alert-success" uk-alert><p class="uk-margin-remove">Current schema matches latest snapshot <code>' . htmlspecialchars($filename) . '</code>.</p></div>';
		}

		$addedCount = count(array_filter($diff, fn($item) => ($item['type'] ?? '') === 'added'));
		$changedCount = count(array_filter($diff, fn($item) => ($item['type'] ?? '') === 'changed'));
		$removedCount = count(array_filter($diff, fn($item) => ($item['type'] ?? '') === 'removed'));
		$manualCount = count(array_filter($diff, fn($item) => !$this->isAutoGeneratableSchemaDiffItem($item)));

		$html = '
		<div class="uk-flex uk-flex-middle uk-flex-wrap uk-margin-small-bottom" style="gap:8px">
			<span class="uk-label uk-label-success">' . $addedCount . ' added</span>
			<span class="uk-label uk-label-warning">' . $changedCount . ' changed</span>
			<span class="uk-label uk-label-danger">' . $removedCount . ' removed</span>
		</div>';

		if ($manualCount > 0) {
			$html .= '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove">Changed and removed schema items are shown for review, but are not auto-generated into migration code because they can be destructive.</p></div>';
		}
		$autoCount = count(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		if ($autoCount === 0) {
			$html .= '<div class="uk-alert uk-alert-primary" uk-alert><p class="uk-margin-remove">No safe auto-generatable schema changes found. Review the changed/removed items manually.</p></div>';
		}

		$html .= '<table class="uk-table uk-table-small uk-table-divider uk-table-hover">
			<thead><tr><th>Change</th><th>Item</th></tr></thead><tbody>';

		foreach (array_slice($diff, 0, 20) as $item) {
			$label = match($item['type']) {
				'added'   => '<span class="uk-label uk-label-success">Added</span>',
				'removed' => '<span class="uk-label uk-label-danger">Removed</span>',
				default   => '<span class="uk-label uk-label-warning">Changed</span>',
			};
			$details = $this->renderSchemaDiffDetails($item);
			$html .= '<tr>'
				. '<td>' . $label . '</td>'
				. '<td class="uk-text-small"><code>' . htmlspecialchars($this->formatSchemaDiffScope((string)$item['scope'])) . '</code> ' . htmlspecialchars($this->formatSchemaDiffName($item)) . $details . '</td>'
				. '</tr>';
		}

		$remaining = count($diff) - 20;
		if ($remaining > 0) {
			$html .= '<tr><td colspan="2" class="uk-text-small uk-text-muted">+' . $remaining . ' more changes</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	protected function formatSchemaDiffName(array $item): string {
		if (($item['scope'] ?? '') === 'template_fields') {
			$template = (string)($item['template'] ?? '');
			$field = (string)($item['field'] ?? '');
			if ($template !== '' && $field !== '') return $field . ' on ' . $template;
		}
		return (string)($item['name'] ?? '');
	}

	protected function renderSchemaDiffDetails(array $item): string {
		$type = $item['type'] ?? '';
		$before = $item['before'] ?? null;
		$after = $item['after'] ?? null;

		if ($type === 'added') {
			$text = $this->formatSchemaDiffValue($after);
			return $text !== '' ? '<div class="uk-text-meta uk-margin-small-top">Added: ' . htmlspecialchars($text) . '</div>' : '';
		}
		if ($type === 'removed') {
			$text = $this->formatSchemaDiffValue($before);
			return $text !== '' ? '<div class="uk-text-meta uk-margin-small-top">Removed: ' . htmlspecialchars($text) . '</div>' : '';
		}

		$beforeText = $this->formatSchemaDiffValue($before);
		$afterText = $this->formatSchemaDiffValue($after);
		if ($beforeText === '' && $afterText === '') return '';
		return '<div class="uk-text-meta uk-margin-small-top">Before: ' . htmlspecialchars($beforeText ?: '-')
			. '<br>After: ' . htmlspecialchars($afterText ?: '-') . '</div>';
	}

	protected function formatSchemaDiffValue($value): string {
		if (!is_array($value)) return trim((string)$value);

		if (isset($value['template']) || isset($value['field'])) {
			$parts = [];
			if (!empty($value['field'])) $parts[] = 'field=' . $value['field'];
			if (!empty($value['template'])) $parts[] = 'template=' . $value['template'];
			return implode(', ', $parts);
		}

		if (isset($value['type']) || isset($value['label'])) {
			$parts = [];
			if (!empty($value['type'])) $parts[] = 'type=' . $value['type'];
			if (!empty($value['label'])) $parts[] = 'label=' . $value['label'];
			return implode(', ', $parts);
		}

		if (isset($value['fields']) && is_array($value['fields'])) {
			$parts = [];
			if (!empty($value['label'])) $parts[] = 'label=' . $value['label'];
			$parts[] = 'fields=' . implode(', ', $value['fields']);
			return implode(', ', $parts);
		}

		return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
	}

	protected function renderSchemaDiffMigrationPreview($snapshotFile): string {
		$filename = basename((string)$snapshotFile);
		$backUrl = '<p><a href="' . $this->page->url . '?action=migrations' . ($filename ? '&compare_snapshot=' . rawurlencode($filename) : '') . '" class="uk-button uk-button-default"><span uk-icon="icon: arrow-left; ratio:.7"></span>&nbsp; Back to migrations</a></p>';
		$baseline = $filename !== '' ? $this->getSchemaSnapshotByFilename($filename) : null;
		if (!$baseline) {
			return $this->renderSectionNav('migrations') . $backUrl . '<div class="uk-alert uk-alert-danger" uk-alert>Schema snapshot not found.</div>';
		}

		$diff = $this->diffSchemaSnapshot($baseline['path']);
		$current = $this->getCurrentSchemaSnapshot();
		$auto = array_values(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		$manual = array_values(array_filter($diff, fn($item) => !$this->isAutoGeneratableSchemaDiffItem($item)));
		if (empty($auto)) {
			return $this->renderSectionNav('migrations') . $backUrl . '
			<div class="uk-alert uk-alert-primary" uk-alert>
				<p class="uk-margin-remove">No safe auto-generatable schema changes found in selected diff.</p>
			</div>
			' . $this->renderSchemaDiffSummary($diff, $baseline['filename']);
		}

		$code = $this->buildSchemaDiffMigrationCode($auto, $manual, $current, $baseline['filename']);
		$dependencyWarnings = $this->getSchemaDiffDependencyWarnings($auto, $current);
		$dependencyCheck = $this->renderSchemaDiffDependencyCheck($dependencyWarnings);
		$csrf = $this->session->CSRF->renderInput();
		$manualAlert = !empty($manual)
			? '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove">' . count($manual) . ' changed/removed schema item(s) will be included as manual-review comments.</p></div>'
			: '';
		$createButtonClass = empty($dependencyWarnings) ? 'uk-button uk-button-primary' : 'uk-button uk-button-default';
		$readyNote = empty($dependencyWarnings)
			? '<p class="uk-text-small uk-text-success">Ready to create. Dependency check passed.</p>'
			: '<p class="uk-text-small uk-text-warning">Review dependency warnings before creating the migration file.</p>';

		return $this->renderSectionNav('migrations') . $backUrl . '
		<h3 class="uk-heading-divider">Schema diff migration preview</h3>
		<p class="uk-text-small uk-text-muted">Baseline snapshot: <code>' . htmlspecialchars($baseline['filename']) . '</code></p>
		' . $this->renderSchemaGenerationPlan($diff) . '
		' . $dependencyCheck . '
		' . $manualAlert . '
		<form method="post" action="' . $this->page->url . '" class="uk-margin-small-bottom">
			' . $csrf . '
			<input type="hidden" name="action" value="generate_migration_from_diff">
			<input type="hidden" name="snapshot_file" value="' . htmlspecialchars($baseline['filename']) . '">
			<button type="submit" class="' . $createButtonClass . '">
				<span uk-icon="icon: code; ratio:.7"></span>&nbsp; Create migration file
			</button>
			' . $readyNote . '
		</form>
		<pre class="uk-text-small" style="max-height:70vh;overflow:auto"><code>' . htmlspecialchars($code) . '</code></pre>';
	}

	protected function getSchemaDiffDependencyWarnings(array $auto, array $current): array {
		$warnings = [];

		foreach ($auto as $item) {
			$scope = (string)($item['scope'] ?? '');
			if ($scope === 'fields') {
				$name = (string)($item['name'] ?? '');
				$fieldType = (string)($current['fields'][$name]['type'] ?? '');
				if ($fieldType !== '' && !$this->wire('modules')->isInstalled($fieldType)) {
					$warnings[] = 'Field <code>' . htmlspecialchars($name) . '</code> uses fieldtype <code>' . htmlspecialchars($fieldType) . '</code>, which is not installed locally.';
				}
			}

			if ($scope === 'template_fields') {
				$fieldName = (string)($item['field'] ?? '');
				$templateName = (string)($item['template'] ?? '');
				if ($fieldName !== '' && empty($current['fields'][$fieldName])) {
					$warnings[] = 'Template assignment references missing field <code>' . htmlspecialchars($fieldName) . '</code>.';
				}
				if ($templateName !== '' && empty($current['templates'][$templateName])) {
					$warnings[] = 'Template assignment references missing template <code>' . htmlspecialchars($templateName) . '</code>.';
				}
			}

			if ($scope === 'templates') {
				$templateName = (string)($item['name'] ?? '');
				foreach (($current['templates'][$templateName]['fields'] ?? []) as $fieldName) {
					if (empty($current['fields'][$fieldName])) {
						$warnings[] = 'Template <code>' . htmlspecialchars($templateName) . '</code> references missing field <code>' . htmlspecialchars($fieldName) . '</code>.';
					}
				}
			}
		}

		return array_values(array_unique($warnings));
	}

	protected function renderSchemaDiffDependencyCheck(array $warnings): string {
		if (empty($warnings)) {
			return '<div class="uk-alert uk-alert-success" uk-alert><p class="uk-margin-remove"><strong>Dependency check passed.</strong> Referenced local fields, templates, and fieldtype modules are available.</p></div>';
		}

		return '<div class="uk-alert uk-alert-warning" uk-alert><p><strong>Dependency check warnings.</strong></p><ul><li>'
			. implode('</li><li>', $warnings)
			. '</li></ul></div>';
	}

	protected function renderDeleteSnapshotForm(string $filename, string $csrf): string {
		$filenameEsc = htmlspecialchars($filename);
		$confirm = "Delete schema snapshot {$filename}?";
		return '
		<form method="post" action="' . $this->page->url . '" class="uk-display-inline">
			' . $csrf . '
			<input type="hidden" name="action" value="delete_snapshot">
			<input type="hidden" name="snapshot_file" value="' . $filenameEsc . '">
			<button type="submit" class="uk-button uk-button-default" onclick="return confirm(\'' . addslashes($confirm) . '\')">
				<span uk-icon="icon: trash; ratio:.7"></span>&nbsp; Delete
			</button>
		</form>';
	}
	// ── Create backup ─────────────────────────────────────────────────────────

	public function createBackup(string $type = 'regular'): array {
		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create backup directory.'];
		}

		// Lock file — prevent concurrent backups
		$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-' . $type . '.lock';
		if (file_exists($lockFile)) {
			$lockAge = time() - (int)file_get_contents($lockFile);
			if ($lockAge < 3600) {
				return ['success' => false, 'error' => 'Another backup is already running (lock age: ' . $lockAge . 's).'];
			}
			// Stale lock — remove it
			unlink($lockFile);
		}
		file_put_contents($lockFile, time());

		$typePrefix = match($type) {
			'weekly'  => 'db-weekly-',
			'monthly' => 'db-monthly-',
			default   => 'db-',
		};
		$filename = $typePrefix . date('Y-m-d_His') . self::BACKUP_EXT;
		$filepath = $dir . $filename;

		// Try mysqldump first, fall back to PHP PDO
		$result = $this->dumpViaMysqldump($filepath);
		if (!$result['success']) {
			$result = $this->dumpViaPdo($filepath);
		}

		if (!$result['success']) {
			// Release lock before returning failure
			if (file_exists($lockFile)) unlink($lockFile);
			return $result;
		}

		// Capture size before any potential deletion
		$fileSize = filesize($filepath);

		// Upload to B2 if configured
		$b2Uploaded = false;
		if ($this->b2_enabled) {
			$b2Result = $this->uploadToB2($filepath, $filename);
			$b2Uploaded = $b2Result['success'];
			if ($b2Uploaded) {
				// Delete local file only if "keep local" is NOT checked
				if (!$this->b2_keep_local && file_exists($filepath)) {
					unlink($filepath);
				}
			} else {
				$this->log()->save(self::LOG_NAME, 'B2 upload failed: ' . $b2Result['error']);
			}
		}

		// Save meta — always, even if local file was deleted
		$this->saveMeta($filename, [
			'filename'    => $filename,
			'date'        => date('Y-m-d H:i:s'),
			'size'        => $fileSize,
			'b2'          => $b2Uploaded,
			'local'       => !$this->b2_enabled || !$b2Uploaded || (bool)$this->b2_keep_local,
			'method'      => $result['method'],
			'type'        => $type,
			'label'       => match($type) {
				'weekly'  => 'Week ' . date('W') . ', ' . date('Y'),
				'monthly' => date('F Y'),
				default   => '',
			},
		]);

		// Release lock BEFORE retention (retention may call B2 delete = slow network)
		if (file_exists($lockFile)) unlink($lockFile);

		// Enforce retention per type
		$this->enforceRetention($type);

		$this->log()->save(self::LOG_NAME, "Backup created: {$filename} (type: {$type}, method: {$result['method']}, b2: " . ($b2Uploaded ? 'yes' : 'no') . ')');

		return ['success' => true, 'filename' => $filename];
	}

	// ── mysqldump ─────────────────────────────────────────────────────────────

	protected function dumpViaMysqldump(string $filepath): array {
		$binary = $this->findCliBinary('mysqldump');
		if (!$binary) return ['success' => false, 'error' => 'mysqldump not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		// Write credentials to temp file — avoids password visible in 'ps aux'
		$cnfFile = tempnam(sys_get_temp_dir(), 'pwdb_');
		file_put_contents($cnfFile, '[client]' . PHP_EOL . 'password=' . $cfg->dbPass . PHP_EOL, LOCK_EX);
		chmod($cnfFile, 0600);
		$passCnf = $cfg->dbPass ? '--defaults-extra-file=' . escapeshellarg($cnfFile) : '';
		$pass    = ''; // password now in cnf file
		$name    = escapeshellarg($cfg->dbName);
		$out     = escapeshellarg($filepath);

		// Build --ignore-table args for excluded tables
		$excludeArgs = '';
		if ($this->exclude_tables) {
			foreach (array_filter(array_map('trim', explode("\n", $this->exclude_tables))) as $tbl) {
				$tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
				if ($tbl) $excludeArgs .= ' --ignore-table=' . escapeshellarg($cfg->dbName . '.' . $tbl);
			}
		}

		$gzip = $this->findCliBinary('gzip');
		if (!$gzip) {
			if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);
			return ['success' => false, 'error' => 'gzip not found'];
		}

		$cmd = escapeshellarg($binary) . " {$passCnf} -h {$host} {$port} -u {$user} "
			 . "--single-transaction --quick --lock-tables=false "
			 . "--add-drop-table --routines --triggers{$excludeArgs} {$name} | "
			 . escapeshellarg($gzip) . " -c > {$out}";

		$run = $this->runShellCommand($cmd);
		if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);

		if ($run['exitCode'] !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
			if (file_exists($filepath)) unlink($filepath);
			return ['success' => false, 'error' => 'mysqldump error: ' . $run['output']];
		}

		return ['success' => true, 'method' => 'mysqldump'];
	}

	// ── PHP PDO fallback dump ─────────────────────────────────────────────────

	protected function dumpViaPdo(string $filepath): array {
		try {
			$cfg  = $this->wire('config');
			$dsn  = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo  = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$fh   = gzopen($filepath, 'wb9');
			$bufferedQueryAttr = $this->getPdoMysqlUseBufferedQueryAttribute();

			if (!$fh) return ['success' => false, 'error' => 'Cannot open output file.'];

			gzwrite($fh, "-- ProcessWire DB Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n");
			gzwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

			// Get tables, minus excluded ones
			$allTables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
			$excluded  = [];
			if ($this->exclude_tables) {
				$excluded = array_filter(array_map('trim', explode("\n", $this->exclude_tables)));
			}
			$tables = array_values(array_diff($allTables, $excluded));

			foreach ($tables as $table) {
				$escapedTable = '`' . str_replace('`', '``', $table) . '`';

				// DROP + CREATE
				gzwrite($fh, "DROP TABLE IF EXISTS {$escapedTable};\n");
				$createRow = $pdo->query("SHOW CREATE TABLE {$escapedTable}")->fetch(\PDO::FETCH_NUM);
				gzwrite($fh, $createRow[1] . ";\n\n");

				// Data — unbuffered row-by-row to avoid loading entire table into memory
				if ($bufferedQueryAttr !== null) {
					$pdo->setAttribute($bufferedQueryAttr, false);
				}
				$stmt = $pdo->query("SELECT * FROM {$escapedTable}");
				if ($bufferedQueryAttr !== null) {
					$pdo->setAttribute($bufferedQueryAttr, true);
				}
				$firstRow  = true;
				$batchVals = [];
				$batchSize = 100;
				$columns   = null;
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					if ($columns === null) {
						$columns = '`' . implode('`, `', array_keys($row)) . '`';
					}
					$batchVals[] = '(' . implode(', ', array_map(
						fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v),
						array_values($row)
					)) . ')';
					if (count($batchVals) >= $batchSize) {
						if ($firstRow) { gzwrite($fh, "INSERT INTO {$escapedTable} ({$columns}) VALUES\n"); $firstRow = false; }
						gzwrite($fh, implode(",\n", $batchVals) . ";\n");
						$batchVals = [];
					}
				}
				if ($batchVals && $columns) {
					if ($firstRow) gzwrite($fh, "INSERT INTO {$escapedTable} ({$columns}) VALUES\n");
					gzwrite($fh, implode(",\n", $batchVals) . ";\n\n");
				} elseif (!$firstRow) {
					gzwrite($fh, "\n");
				}
			}

			gzwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
			gzclose($fh);

			return ['success' => true, 'method' => 'pdo'];

		} catch (\Exception $e) {
			// Re-enable buffered queries if exception during unbuffered fetch
			try {
				if (isset($bufferedQueryAttr) && $bufferedQueryAttr !== null && isset($pdo)) {
					$pdo->setAttribute($bufferedQueryAttr, true);
				}
				if (isset($stmt)) $stmt->closeCursor();
			} catch (\Throwable $ignored) {}
			if (isset($fh) && $fh) gzclose($fh);
			return ['success' => false, 'error' => 'PDO dump error: ' . $e->getMessage()];
		}
	}

	// ── Restore ───────────────────────────────────────────────────────────────

	public function restoreBackup(string $filename): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			return ['success' => false, 'error' => 'Backup file not found.'];
		}

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid filename.'];
		}

		// Verify integrity before touching the database
		$verify = $this->verifyBackup($filepath);
		if (!$verify['valid']) {
			return ['success' => false, 'error' => 'Backup verification failed: ' . $verify['error']];
		}

		// Pre-restore backup if configured
		$preBackup = null;
		if ($this->pre_restore_backup) {
			$preResult = $this->createBackup('regular');
			if ($preResult['success']) {
				$preBackup = $preResult['filename'];
			} else {
				$this->log()->save(self::LOG_NAME, 'Pre-restore backup failed: ' . $preResult['error']);
			}
		}

		// Try mysql CLI first
		$result = $this->restoreViaMysql($filepath);
		if (!$result['success']) {
			$result = $this->restoreViaPdo($filepath);
		}

		if ($result['success']) {
			$result['pre_backup'] = $preBackup;
			$this->log()->save(self::LOG_NAME, "Database restored from: {$filename} (method: {$result['method']})");
		}

		return $result;
	}

	protected function restoreViaMysql(string $filepath): array {
		set_time_limit(0); // Restore may take long on large databases
		$binary = $this->findCliBinary('mysql');
		if (!$binary) return ['success' => false, 'error' => 'mysql CLI not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		// Write credentials to temp file — avoids password visible in 'ps aux'
		$cnfFile = tempnam(sys_get_temp_dir(), 'pwdb_');
		file_put_contents($cnfFile, '[client]' . PHP_EOL . 'password=' . $cfg->dbPass . PHP_EOL, LOCK_EX);
		chmod($cnfFile, 0600);
		$passCnf = $cfg->dbPass ? '--defaults-extra-file=' . escapeshellarg($cnfFile) : '';
		$pass    = '';
		$name    = escapeshellarg($cfg->dbName);
		$src     = escapeshellarg($filepath);

		// Use gunzip -c as it's more portable than zcat (available on macOS/Linux/BSD)
		$gunzip = $this->findCliBinary('gunzip');
		$gzip   = $this->findCliBinary('gzip');
		if ($gunzip) {
			$decomp = escapeshellarg($gunzip) . " -c {$src}";
		} elseif ($gzip) {
			$decomp = escapeshellarg($gzip) . " -dc {$src}";
		} else {
			if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);
			return ['success' => false, 'error' => 'gunzip/gzip not found'];
		}
		$cmd = "{$decomp} | " . escapeshellarg($binary) . " {$passCnf} -h {$host} {$port} -u {$user} {$name}";
		$run = $this->runShellCommand($cmd);
		if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);

		if ($run['exitCode'] !== 0) {
			return ['success' => false, 'error' => 'mysql CLI error: ' . $run['output']];
		}

		return ['success' => true, 'method' => 'mysql-cli'];
	}

	protected function restoreViaPdo(string $filepath): array {
		set_time_limit(0); // Restore may take long on large databases
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

			$fh = gzopen($filepath, 'rb');
			if (!$fh) return ['success' => false, 'error' => 'Cannot open backup file.'];

			$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

			// Stream line-by-line — avoids loading entire dump into memory
			$stmt    = '';
			while (!gzeof($fh)) {
				$line = gzgets($fh, 1048576); // 1MB max line (handles large INSERT chunks)
				if ($line === false) break;

				$trimmed = ltrim($line);
				// Skip empty lines and pure comments
				if ($trimmed === '' || str_starts_with($trimmed, '-- ') || $trimmed === "--\n") continue;

				$stmt .= $line;

				// Execute when we hit a statement terminator at end of line
				$rtrimmed = rtrim($line);
				if (str_ends_with($rtrimmed, ';')) {
					$execStmt = trim(rtrim($stmt, "\n\r"));
					if ($execStmt !== '' && !str_starts_with(ltrim($execStmt), '--')) {
						$pdo->exec($execStmt);
					}
					$stmt = '';
				}
			}
			// Execute any remaining statement
			if (($execStmt = trim($stmt)) !== '') $pdo->exec($execStmt);

			gzclose($fh);
			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

			return ['success' => true, 'method' => 'pdo-stream'];

		} catch (\Exception $e) {
			if (isset($fh) && $fh) gzclose($fh);
			try { if (isset($pdo)) $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $ignored) {}
			return ['success' => false, 'error' => 'PDO restore error: ' . $e->getMessage()];
		}
	}

	protected function findCliBinary(string $name): string {
		if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) return '';
		$binary = trim(shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null') ?? '');
		return $binary !== '' && is_executable($binary) ? $binary : '';
	}

	protected function runShellCommand(string $cmd): array {
		$wrapped = '/bin/bash -o pipefail -c ' . escapeshellarg($cmd) . ' 2>&1';
		$output = [];
		$exitCode = 0;
		exec($wrapped, $output, $exitCode);
		return [
			'exitCode' => $exitCode,
			'output'   => trim(implode("\n", $output)),
		];
	}

	protected function getPdoMysqlUseBufferedQueryAttribute(): ?int {
		if (defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')) {
			return constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY');
		}
		$legacyConstant = implode('::', ['PDO', 'MYSQL_ATTR_USE_BUFFERED_QUERY']);
		if (defined($legacyConstant)) {
			return constant($legacyConstant);
		}
		return null;
	}

	// ── Verify backup integrity ─────────────────────────────────────────────────

	public function verifyBackup(string $filepath): array {
		if (!file_exists($filepath)) {
			return ['valid' => false, 'error' => 'File not found.'];
		}

		// Check gzip magic bytes
		$fh    = fopen($filepath, 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			return ['valid' => false, 'error' => 'Not a valid gzip file.'];
		}

		// Test gzip integrity (zcat to /dev/null)
		$src      = escapeshellarg($filepath);
		$gzipBin  = trim(shell_exec('which gzip 2>/dev/null') ?? '');
		if ($gzipBin) {
			exec("{$gzipBin} -t {$src} 2>&1", $out, $code);
			if ($code !== 0) {
				return ['valid' => false, 'error' => 'gzip integrity check failed: ' . implode(' ', $out)];
			}
		}

		// Read first chunk of SQL and check for expected markers
		$fh  = gzopen($filepath, 'rb');
		$sql = gzread($fh, 8192);
		gzclose($fh);

		if (empty($sql)) {
			return ['valid' => false, 'error' => 'Backup file is empty.'];
		}

		// Expect at least one CREATE TABLE or INSERT or DROP TABLE statement
		if (!preg_match('/(CREATE TABLE|INSERT INTO|DROP TABLE)/i', $sql)) {
			return ['valid' => false, 'error' => 'File does not appear to contain valid SQL.'];
		}

		return ['valid' => true];
	}

	// ── List tables inside a backup ───────────────────────────────────────────

	public function getBackupTables(string $filename): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;
		if (!file_exists($filepath)) return [];

		$tables = [];
		$fh     = gzopen($filepath, 'rb');
		if (!$fh) return [];

		// Read in chunks looking for CREATE TABLE statements
		$buffer = '';
		while (!gzeof($fh)) {
			$buffer .= gzread($fh, 65536);
			preg_match_all('/CREATE TABLE `([^`]+)`/i', $buffer, $matches);
			foreach ($matches[1] as $tbl) {
				$tables[$tbl] = true;
			}
			// Keep last 8KB to catch CREATE TABLE statements spanning chunk boundaries
			$buffer = substr($buffer, -8192);
		}
		gzclose($fh);

		$result = array_keys($tables);
		sort($result);
		return $result;
	}

	// ── Partial restore ───────────────────────────────────────────────────────

	public function partialRestoreBackup(string $filename, array $tables): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			return ['success' => false, 'error' => 'Backup file not found.'];
		}

		// Verify integrity first
		$verify = $this->verifyBackup($filepath);
		if (!$verify['valid']) {
			return ['success' => false, 'error' => 'Verification failed: ' . $verify['error']];
		}

		// Pre-restore backup if configured
		$preBackup = null;
		if ($this->pre_restore_backup) {
			$preResult = $this->createBackup('regular');
			if ($preResult['success']) $preBackup = $preResult['filename'];
		}

		// Read entire SQL from gzip — note: loads full dump into memory
		// Acceptable for partial restore (needs full parse); ensure memory_limit is adequate
		$fh  = gzopen($filepath, 'rb');
		if (!$fh) return ['success' => false, 'error' => 'Cannot open backup file.'];
		$sql = '';
		while (!gzeof($fh)) $sql .= gzread($fh, 65536);
		gzclose($fh);
		if (empty($sql)) return ['success' => false, 'error' => 'Backup file is empty.'];

		// Parse SQL into per-table blocks
		// Each block: DROP TABLE + CREATE TABLE + INSERT statements for that table
		$tableBlocks = [];
		$currentTable = null;
		$currentBlock = '';

		$statements = preg_split('/;[ \t]*\n/', $sql);
		foreach ($statements as $stmt) {
			$stmt = trim($stmt);
			if (empty($stmt) || str_starts_with($stmt, '--')) continue;

			if (preg_match('/^DROP TABLE.*`([^`]+)`/i', $stmt, $m)) {
				$currentTable = $m[1];
				$currentBlock = $stmt . ";\n";
			} elseif (preg_match('/^CREATE TABLE `([^`]+)`/i', $stmt, $m)) {
				$currentTable = $m[1];
				$currentBlock .= $stmt . ";\n";
				// Save immediately — table may be empty (no INSERT follows)
				$tableBlocks[$currentTable] = $currentBlock;
			} elseif (preg_match('/^INSERT INTO `([^`]+)`/i', $stmt, $m)) {
				if ($m[1] === $currentTable) {
					$currentBlock .= $stmt . ";\n";
				}
				if ($currentTable && !isset($tableBlocks[$currentTable])) {
					$tableBlocks[$currentTable] = '';
				}
				$tableBlocks[$currentTable] = $currentBlock;
			} elseif ($currentTable) {
				$currentBlock .= $stmt . ";\n";
				$tableBlocks[$currentTable] = $currentBlock;
			}
		}

		// Execute only requested tables
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

			$restored = [];
			foreach ($tables as $tbl) {
				if (!isset($tableBlocks[$tbl])) continue;
				$stmts = preg_split('/;[ \t]*\n/', $tableBlocks[$tbl]);
				foreach ($stmts as $s) {
					$s = trim($s);
					if ($s && !str_starts_with($s, '--')) $pdo->exec($s);
				}
				$restored[] = $tbl;
			}

			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

			$this->log()->save(self::LOG_NAME,
				"Partial restore from {$filename}: " . implode(', ', $restored)
			);

			return ['success' => true, 'restored_tables' => $restored, 'pre_backup' => $preBackup];

		} catch (\Exception $e) {
			try { if (isset($pdo)) $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $ignored) {}
			return ['success' => false, 'error' => 'PDO error: ' . $e->getMessage()];
		}
	}

	// ── Delete backup ─────────────────────────────────────────────────────────

	public function deleteBackup(string $filename): array {
		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid filename.'];
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (file_exists($filepath)) {
			unlink($filepath);
		}

		// Delete from B2 if applicable
		$meta = $this->getMeta();
		if (isset($meta[$filename]['b2']) && $meta[$filename]['b2']) {
			$this->deleteFromB2($filename);
			// Note: deleteFromB2 logs errors internally but we proceed with meta removal.
			// B2 orphans are preferable to UI entries with no local file and no B2 tracking.
		}

		$this->removeMeta($filename);

		return ['success' => true];
	}

	// ── AJAX: create backup with JSON response ──────────────────────────────────

	protected function ajaxJson(array $data): void {
		// Kill all output buffers (Tracy, PW debug, etc.) before sending JSON
		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/json');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($data);
		exit;
	}

	protected function handleAjaxCreate(): void {
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token sent as GET param in AJAX request
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->get($csrfName) ?? '';
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$type   = $this->input->get('type') ?? 'regular';
		$type   = in_array($type, ['regular', 'weekly', 'monthly']) ? $type : 'regular';
		$result = $this->createBackup($type);
		$this->ajaxJson($result);
	}

	protected function handleAjaxLabel(): void {
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token sent as GET param in AJAX request
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->get($csrfName) ?? '';
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$file  = basename((string)($this->input->get('file') ?? ''));
		$label = $this->sanitizer->text($this->input->get('label') ?? '');
		$meta  = $this->getMeta();
		$ok    = false;
		if (isset($meta[$file])) {
			$meta[$file]['label'] = $label;
			$metaPath = $this->wire('config')->paths->assets . self::META_FILE;
			file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
			$ok = true;
		}
		$this->ajaxJson(['success' => $ok]);
	}

	// ── Chunked upload ────────────────────────────────────────────────────────

	protected function handleChunkUpload(): void {
		// Auth check — must be logged-in admin with db-backup permission
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token from POST FormData
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->post($csrfName) ?? ($_POST[$csrfName] ?? '');
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$chunkDir = $this->wire('config')->paths->assets . self::CHUNK_DIR;
		if (!is_dir($chunkDir)) wireMkdir($chunkDir, true);

		$uploadId  = preg_replace('/[^a-f0-9]/', '', $this->input->post('upload_id') ?? '');
		$chunkIdx  = max(0, min((int)($this->input->post('chunk_index') ?? 0), 9999));
		$totalChunks = min((int)($this->input->post('total_chunks') ?? 1), 10000); // max 10k chunks = ~20GB
		$origName  = basename($this->input->post('filename') ?? '');
		$restoreAfter = (bool)($this->input->post('restore_after_upload') ?? false);

		if (!$uploadId || !isset($_FILES['chunk'])) {
			$this->ajaxJson(['success' => false, 'error' => 'Missing chunk data.']);
		}

		$chunkFile = $chunkDir . $uploadId . '_' . str_pad($chunkIdx, 5, '0', STR_PAD_LEFT);
		if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
			$this->ajaxJson(['success' => false, 'error' => 'Failed to save chunk.']);
		}

		// Check if all chunks arrived
		$arrived = count(glob($chunkDir . $uploadId . '_*') ?: []);
		if ($arrived < $totalChunks) {
			$this->ajaxJson(['success' => true, 'status' => 'chunk_received', 'received' => $arrived, 'total' => $totalChunks]);
		}

		// All chunks here — assemble
		$filename = preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $origName)
			? $origName
			: 'db-uploaded-' . date('Y-m-d_His') . self::BACKUP_EXT;

		$backupDir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($backupDir)) wireMkdir($backupDir, true);
		$finalPath = $backupDir . $filename;

		// If file already exists, add timestamp suffix to avoid silent overwrite
		if (file_exists($finalPath)) {
			$filename  = 'db-uploaded-' . date('Y-m-d_His') . self::BACKUP_EXT;
			$finalPath = $backupDir . $filename;
		}
		$out = fopen($finalPath, 'wb');
		if (!$out) {
			$this->ajaxJson(['success' => false, 'error' => 'Cannot create output file. Check permissions.']);
		}
		for ($i = 0; $i < $totalChunks; $i++) {
			$cf = $chunkDir . $uploadId . '_' . str_pad($i, 5, '0', STR_PAD_LEFT);
			if (!file_exists($cf)) {
				fclose($out);
				unlink($finalPath);
				$this->ajaxJson(['success' => false, 'error' => "Missing chunk {$i}."]); 
			}
			$in = fopen($cf, 'rb');
			while (!feof($in)) fwrite($out, fread($in, 65536));
			fclose($in);
			unlink($cf);
		}
		fclose($out);

		// Validate gzip
		$fh    = fopen($finalPath, 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			unlink($finalPath);
			$this->ajaxJson(['success' => false, 'error' => 'Assembled file is not a valid gzip archive.']);
		}

		$this->saveMeta($filename, [
			'filename' => $filename,
			'date'     => date('Y-m-d H:i:s'),
			'size'     => filesize($finalPath),
			'b2'       => false,
			'local'    => true,
			'type'     => 'uploaded',
			'label'    => '',
			'method'   => 'chunked-upload',
		]);

		$this->log()->save(self::LOG_NAME, "Chunked upload complete: {$filename}");

		$restored      = false;
		$restoreError  = null;
		if ($restoreAfter) {
			$restoreResult = $this->restoreBackup($filename);
			$restored      = $restoreResult['success'];
			if (!$restored) $restoreError = $restoreResult['error'] ?? 'Restore failed.';
		}

		$this->ajaxJson(['success' => true, 'status' => 'complete', 'filename' => $filename,
			'restored' => $restored, 'restore_error' => $restoreError]);
	}

	// ── Upload backup from local computer ───────────────────────────────────────

	protected function uploadBackup(): array {
		$upload = $_FILES['backup_file'] ?? null;

		if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
			$codes = [
				UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize.',
				UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE.',
				UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
				UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			];
			$code = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
			return ['success' => false, 'error' => $codes[$code] ?? 'Upload error code ' . $code];
		}

		$origName = basename($upload['name']);

		if (!str_ends_with($origName, '.sql.gz')) {
			return ['success' => false, 'error' => 'Only .sql.gz files are accepted.'];
		}

		// Validate gzip magic bytes
		$fh    = fopen($upload['tmp_name'], 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			return ['success' => false, 'error' => 'File does not appear to be a valid gzip archive.'];
		}

		// Keep original filename if it matches naming convention, otherwise generate one
		$filename = preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $origName)
			? $origName
			: 'db-uploaded-' . date('Y-m-d_His') . '.sql.gz';

		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create backup directory.'];
		}

		$filepath = $dir . $filename;

		if (!move_uploaded_file($upload['tmp_name'], $filepath)) {
			return ['success' => false, 'error' => 'Failed to move uploaded file.'];
		}

		$this->saveMeta($filename, [
			'filename' => $filename,
			'date'     => date('Y-m-d H:i:s'),
			'size'     => filesize($filepath),
			'b2'       => false,
			'local'    => true,
			'type'     => 'uploaded',
			'label'    => '',
			'method'   => 'uploaded',
		]);

		$this->log()->save(self::LOG_NAME, "Backup uploaded: {$filename}");

		$restored = false;
		if ($this->input->post('restore_after_upload')) {
			$restoreResult = $this->restoreBackup($filename);
			if (!$restoreResult['success']) {
				return ['success' => false, 'error' => 'File saved but restore failed: ' . $restoreResult['error']];
			}
			$restored = true;
		}

		return ['success' => true, 'filename' => $filename, 'restored' => $restored];
	}

	// ── Download ──────────────────────────────────────────────────────────────

	protected function doDownload(string $filename): void {
		$filename = basename((string)$filename);

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			return;
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			$this->error('File not found.');
			return;
		}

		// Discard any prior output so headers can be sent cleanly
		if (ob_get_level()) ob_end_clean();

		header('Content-Type: application/gzip');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($filepath));
		header('Cache-Control: no-cache, must-revalidate');
		header('Pragma: no-cache');
		readfile($filepath);
		exit;
	}

	protected function downloadMigrationFile(string $filename): void {
		$filename = basename((string)$filename);
		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			$this->error('Invalid migration filename.');
			return;
		}
		$this->downloadLocalFile($this->getMigrationsDir() . $filename, $filename, 'text/x-php');
	}

	protected function downloadSnapshotFile(string $filename): void {
		$filename = basename((string)$filename);
		if (!preg_match('/^[a-zA-Z0-9._-]+\.json$/', $filename)) {
			$this->error('Invalid snapshot filename.');
			return;
		}
		$this->downloadLocalFile($this->getSnapshotsDir() . $filename, $filename, 'application/json');
	}

	protected function downloadLocalFile(string $filepath, string $downloadName, string $contentType): void {
		if (!is_file($filepath)) {
			$this->error('File not found.');
			return;
		}

		if (ob_get_level()) ob_end_clean();
		header('Content-Type: ' . $contentType);
		header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
		header('Content-Length: ' . filesize($filepath));
		header('Cache-Control: no-cache, must-revalidate');
		header('Pragma: no-cache');
		readfile($filepath);
		exit;
	}

	// ── Retention ─────────────────────────────────────────────────────────────

	protected function enforceRetention(string $type = 'regular'): void {
		$max = match($type) {
			'weekly'  => (int)($this->retention_weekly  ?: 4),
			'monthly' => (int)($this->retention_monthly ?: 3),
			default   => (int)($this->retention_count   ?: 0),
		};
		if ($max <= 0) return;

		$backups = array_filter($this->getBackupList(), fn($b) => ($b['type'] ?? 'regular') === $type);
		$backups = array_values($backups); // newest first from getBackupList

		if (count($backups) > $max) {
			$toDelete = array_slice($backups, $max);
			foreach ($toDelete as $b) {
				$this->deleteBackup($b['filename']);
			}
		}
	}

	// ── Cron hook ─────────────────────────────────────────────────────────────

	public function cronBackup(HookEvent $event): void {
		$result = $this->createBackup('regular');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (regular) failed: ' . $result['error']);
			// Release stale lock if backup failed
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-regular.lock';
			if (file_exists($lockFile)) unlink($lockFile);
		}
	}

	public function cronBackupWeekly(HookEvent $event): void {
		$result = $this->createBackup('weekly');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (weekly) failed: ' . $result['error']);
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-weekly.lock';
			if (file_exists($lockFile)) unlink($lockFile);
		}
	}

	public function cronBackupMonthly(HookEvent $event): void {
		$result = $this->createBackup('monthly');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (monthly) failed: ' . $result['error']);
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-monthly.lock';
			if (file_exists($lockFile)) unlink($lockFile);
		}
	}

	// ── Backup list ───────────────────────────────────────────────────────────

	protected function getBackupList(): array {
		$meta = $this->getMeta();
		$dir  = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		$list = [];

		foreach ($meta as $filename => $m) {
			$localExists = file_exists($dir . $filename);
			// Show entry if file exists locally OR was uploaded to B2
			if (!$localExists && empty($m['b2'])) continue;

			$list[] = [
				'filename' => $filename,
				'date'     => $m['date'] ?? '',
				'size'     => $this->formatBytes((int)($m['size'] ?? 0)),
				'size_raw' => (int)($m['size'] ?? 0), // raw bytes for correct numeric sort
				'b2'       => !empty($m['b2']),
				'local'    => $localExists,
				'type'     => $m['type'] ?? 'regular',
				'label'    => $m['label'] ?? '',
			];
		}

		// Newest first
		usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));

		return $list;
	}

	protected function getDiskUsage(): string {
		// Sum sizes from meta, excluding orphaned entries (no local file, no B2)
		$meta = $this->getMeta();
		if ($meta) {
			$dir   = $this->wire('config')->paths->assets . self::BACKUP_DIR;
			$total = 0;
			foreach ($meta as $filename => $m) {
				if (!empty($m['b2']) || file_exists($dir . $filename)) {
					$total += (int)($m['size'] ?? 0);
				}
			}
			return $this->formatBytes($total);
		}
		$dir   = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir)) return '0 B';
		$files = glob($dir . 'db-*' . self::BACKUP_EXT) ?: [];
		$total = array_sum(array_map('filesize', $files));
		return $this->formatBytes((int)$total);
	}

	protected function getExcludedTables(): array {
		if (!$this->exclude_tables) return [];
		$tables = array_filter(array_map('trim', explode("\n", $this->exclude_tables)));
		return array_values(array_unique(array_filter(array_map(
			fn($table) => preg_replace('/[^a-zA-Z0-9_]/', '', $table),
			$tables
		))));
	}

	protected function getTableSizeList(): array {
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$stmt = $pdo->prepare("
				SELECT
					TABLE_NAME AS table_name,
					COALESCE(TABLE_ROWS, 0) AS table_rows,
					COALESCE(DATA_LENGTH, 0) AS data_length,
					COALESCE(INDEX_LENGTH, 0) AS index_length,
					COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS total_length
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = :schema
				ORDER BY total_length DESC, TABLE_NAME ASC
			");
			$stmt->execute(['schema' => $cfg->dbName]);
			return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		} catch (\Throwable $e) {
			$this->log()->save(self::LOG_NAME, 'Could not read table sizes: ' . $e->getMessage());
			return [];
		}
	}

	protected function formatBytes(int $bytes): string {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
		if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
		if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
		return $bytes . ' B';
	}

	// ── Meta migration ───────────────────────────────────────────────────────────

	protected function migrateMeta(): void {
		// Skip if already migrated (tracked in module data to avoid reading file every admin load)
		if ($this->wire('modules')->getConfig($this, 'meta_migrated')) return;

		$path = $this->wire('config')->paths->assets . self::META_FILE;
		if (!file_exists($path)) {
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
			return;
		}

		$json = json_decode(file_get_contents($path), true);
		if (!is_array($json)) return;

		$changed = false;
		foreach ($json as $filename => &$m) {
			// Infer type from filename prefix if missing
			if (!isset($m['type'])) {
				if (str_starts_with($filename, 'db-weekly-')) {
					$m['type'] = 'weekly';
				} elseif (str_starts_with($filename, 'db-monthly-')) {
					$m['type'] = 'monthly';
				} elseif (str_starts_with($filename, 'db-uploaded-')) {
					$m['type'] = 'uploaded';
				} else {
					$m['type'] = 'regular';
				}
				$changed = true;
			}
			if (!isset($m['label'])) {
				$m['label'] = '';
				$changed = true;
			}
			if (!isset($m['local'])) {
				$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
				$m['local'] = file_exists($dir . $filename);
				$changed = true;
			}
		}
		unset($m);

		if ($changed) {
			file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT), LOCK_EX);
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
		} else {
			// All entries already have required fields — mark migration complete
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
		}
	}

	// ── Meta store ────────────────────────────────────────────────────────────

	protected function getMeta(): array {
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		if (!file_exists($path)) return [];
		// Use shared lock to prevent reading partial writes
		$fh = fopen($path, 'r');
		if (!$fh) return [];
		flock($fh, LOCK_SH);
		$json = json_decode(stream_get_contents($fh), true);
		flock($fh, LOCK_UN);
		fclose($fh);
		return is_array($json) ? $json : [];
	}

	protected function saveMeta(string $filename, array $data): void {
		$meta = $this->getMeta();
		$meta[$filename] = $data;
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
	}

	protected function removeMeta(string $filename): void {
		$meta = $this->getMeta();
		unset($meta[$filename]);
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
	}

	// ── Migration store ──────────────────────────────────────────────────────

	protected function ensureMigrationStore(): bool {
		try {
			$sql = "CREATE TABLE IF NOT EXISTS `" . self::MIGRATION_TABLE . "` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`filename` VARCHAR(190) NOT NULL,
				`checksum` CHAR(64) NOT NULL,
				`applied_at` DATETIME NOT NULL,
				`applied_by` VARCHAR(190) NOT NULL DEFAULT '',
				`pre_backup` VARCHAR(190) NOT NULL DEFAULT '',
				`message` TEXT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `filename` (`filename`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
			$this->wire('database')->exec($sql);
			return true;
		} catch (\Throwable $e) {
			$this->log()->save(self::LOG_NAME, 'Could not ensure migration store: ' . $e->getMessage());
			return false;
		}
	}

	protected function getMigrationsDir(): string {
		return $this->wire('config')->paths->assets . self::MIGRATIONS_DIR;
	}

	protected function getRelativeAssetsPath(string $path): string {
		return 'site/assets/' . ltrim($path, '/');
	}

	protected function ensureRuntimeStorage(): void {
		$baseDir = $this->wire('config')->paths->assets . self::STORAGE_DIR;
		$migrationsDir = $this->getMigrationsDir();
		$snapshotsDir = $this->getSnapshotsDir();

		foreach ([$baseDir, $migrationsDir, $snapshotsDir] as $dir) {
			if (!is_dir($dir)) @wireMkdir($dir, true);
		}

		$htaccess = $baseDir . '.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\nRequire all denied\n", LOCK_EX);
		}

		$this->migrateLegacyRuntimeFiles($migrationsDir, $snapshotsDir);
	}

	protected function migrateLegacyRuntimeFiles(string $migrationsDir, string $snapshotsDir): void {
		$legacyMigrationsDir = __DIR__ . '/migrations/';
		$legacySnapshotsDir = __DIR__ . '/migrations/snapshots/';

		if (is_dir($legacyMigrationsDir)) {
			foreach (glob($legacyMigrationsDir . '*.php') ?: [] as $path) {
				$target = $migrationsDir . basename($path);
				if (!file_exists($target)) @rename($path, $target);
			}
		}

		if (is_dir($legacySnapshotsDir)) {
			foreach (glob($legacySnapshotsDir . '*.json') ?: [] as $path) {
				$target = $snapshotsDir . basename($path);
				if (!file_exists($target)) @rename($path, $target);
			}
		}
	}

	protected function getMigrationFiles(): array {
		$this->ensureRuntimeStorage();
		$dir = $this->getMigrationsDir();
		if (!is_dir($dir)) {
			@wireMkdir($dir, true);
		}
		if (!is_dir($dir)) return [];

		$files = glob($dir . '*.php') ?: [];
		$files = array_values(array_filter($files, function($path) {
			return is_file($path) && preg_match('/^[a-zA-Z0-9._-]+\.php$/', basename($path));
		}));
		sort($files, SORT_NATURAL);
		return $files;
	}

	protected function getAppliedMigrations(): array {
		if (!$this->ensureMigrationStore()) return [];

		try {
			$stmt = $this->wire('database')->query("SELECT * FROM `" . self::MIGRATION_TABLE . "` ORDER BY filename ASC");
			$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
			$indexed = [];
			foreach ($rows as $row) {
				$indexed[$row['filename']] = $row;
			}
			return $indexed;
		} catch (\Throwable $e) {
			$this->log()->save(self::LOG_NAME, 'Could not read migration store: ' . $e->getMessage());
			return [];
		}
	}

	protected function getMigrationStatusList(): array {
		$applied = $this->getAppliedMigrations();
		$list = [];

		foreach ($this->getMigrationFiles() as $path) {
			$filename = basename($path);
			$checksum = hash_file('sha256', $path) ?: '';
			$lint = $this->lintMigrationFile($path);
			$row = $applied[$filename] ?? null;
			$list[] = [
				'filename'          => $filename,
				'path'              => $path,
				'checksum'          => $checksum,
				'lint_valid'        => $lint['valid'],
				'lint_output'       => $lint['output'],
				'applied'           => (bool)$row,
				'checksum_mismatch' => $row && !hash_equals((string)$row['checksum'], $checksum),
				'applied_at'        => $row['applied_at'] ?? '',
				'applied_by'        => $row['applied_by'] ?? '',
				'pre_backup'        => $row['pre_backup'] ?? '',
				'message'           => $row['message'] ?? '',
			];
		}

		return $list;
	}

	protected function deletePendingMigrationFile(string $filename): array {
		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid migration filename.'];
		}
		$applied = $this->getAppliedMigrations();
		if (isset($applied[$filename])) {
			return ['success' => false, 'error' => 'Applied migrations cannot be deleted from the GUI.'];
		}
		$path = $this->getMigrationsDir() . $filename;
		if (!is_file($path)) {
			return ['success' => false, 'error' => 'Migration file not found.'];
		}
		return @unlink($path)
			? ['success' => true]
			: ['success' => false, 'error' => 'Could not delete migration file.'];
	}

	protected function deleteSnapshotFile(string $filename): array {
		if (!preg_match('/^[a-zA-Z0-9._-]+\.json$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid snapshot filename.'];
		}
		$path = $this->getSnapshotsDir() . $filename;
		if (!is_file($path)) {
			return ['success' => false, 'error' => 'Schema snapshot not found.'];
		}
		return @unlink($path)
			? ['success' => true]
			: ['success' => false, 'error' => 'Could not delete schema snapshot.'];
	}

	protected function uploadMigrationFile(): array {
		$upload = $_FILES['migration_upload'] ?? null;
		if (!$upload || !is_array($upload)) {
			return ['success' => false, 'error' => 'No upload received.'];
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return ['success' => false, 'error' => 'Upload error code: ' . (int)$upload['error']];
		}

		$filename = basename((string)($upload['name'] ?? ''));
		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			return ['success' => false, 'error' => 'Migration filename must be a safe .php filename.'];
		}

		$dir = $this->getMigrationsDir();
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create migrations directory.'];
		}

		$target = $dir . $filename;
		if (file_exists($target)) {
			return ['success' => false, 'error' => 'A migration with this filename already exists.'];
		}

		$tmp = (string)($upload['tmp_name'] ?? '');
		$contents = is_file($tmp) ? file_get_contents($tmp) : false;
		if ($contents === false || !str_starts_with(ltrim($contents), '<?php')) {
			return ['success' => false, 'error' => 'Migration file must start with <?php.'];
		}

		$tempPath = $dir . '.upload-' . uniqid('', true) . '.php';
		if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
			return ['success' => false, 'error' => 'Could not write temporary migration file.'];
		}
		$lint = $this->lintMigrationFile($tempPath);
		if (!$lint['valid']) {
			@unlink($tempPath);
			return ['success' => false, 'error' => 'PHP syntax check failed: ' . $lint['output']];
		}

		if (!@rename($tempPath, $target)) {
			@unlink($tempPath);
			return ['success' => false, 'error' => 'Could not save migration file.'];
		}

		return ['success' => true, 'filename' => $filename];
	}

	protected function createMigrationFileFromInput(): array {
		$type = (string)($this->input->post('migration_type') ?? '');
		$allowed = ['create_field', 'create_template', 'add_field_to_template', 'install_module', 'create_permission', 'create_role'];
		if (!in_array($type, $allowed, true)) {
			return ['success' => false, 'error' => 'Invalid migration operation.'];
		}

		$title = $this->sanitizer->text((string)($this->input->post('migration_title') ?? ''));
		$message = $this->sanitizer->text((string)($this->input->post('migration_message') ?? ''));
		if ($title === '') {
			return ['success' => false, 'error' => 'Migration name is required.'];
		}

		$fieldName = $this->input->post('field_name_existing') !== null
			? (string)$this->input->post('field_name_existing')
			: (string)($this->input->post('field_name_new') ?? $this->input->post('field_name') ?? '');
		$templateName = $this->input->post('template_name_existing') !== null
			? (string)$this->input->post('template_name_existing')
			: (string)($this->input->post('template_name_new') ?? $this->input->post('template_name') ?? '');
		$templateFieldsInput = $this->input->post('template_fields') ?? '';

		$data = [
			'type'            => $type,
			'title'           => $title,
			'message'         => $message,
			'field_name'      => $this->sanitizePwName($fieldName),
			'field_type'      => $this->sanitizeClassName((string)($this->input->post('field_type') ?? 'FieldtypeText')),
			'field_label'     => $this->sanitizer->text((string)($this->input->post('field_label') ?? '')),
			'template_name'   => $this->sanitizePwName($templateName),
			'template_fields' => $this->sanitizeNameList($templateFieldsInput),
			'module_name'     => $this->sanitizeClassName((string)($this->input->post('module_name') ?? '')),
			'access_name'     => $this->sanitizeAccessName((string)($this->input->post('access_name') ?? '')),
		];

		$validation = $this->validateMigrationGeneratorData($data);
		if (!$validation['success']) return $validation;

		$dir = $this->getMigrationsDir();
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create migrations directory.'];
		}

		$slug = $this->sanitizeMigrationSlug($title);
		$filename = date('Y_m_d_His') . '_' . $slug . '.php';
		$path = $dir . $filename;
		$counter = 2;
		while (file_exists($path)) {
			$filename = date('Y_m_d_His') . '_' . $slug . '_' . $counter . '.php';
			$path = $dir . $filename;
			$counter++;
		}

		$code = $this->buildMigrationCode($data);
		if (file_put_contents($path, $code, LOCK_EX) === false) {
			return ['success' => false, 'error' => 'Could not write migration file.'];
		}

		return ['success' => true, 'filename' => $filename];
	}

	protected function lintMigrationFile(string $path): array {
		if (!is_file($path)) {
			return ['valid' => false, 'output' => 'Migration file not found.'];
		}

		$cmd = 'php -l ' . escapeshellarg($path) . ' 2>&1';
		$output = [];
		$exitCode = 0;
		exec($cmd, $output, $exitCode);
		return [
			'valid'  => $exitCode === 0,
			'output' => trim(implode("\n", $output)),
		];
	}

	protected function analyzeMigrationImpact(string $code): array {
		$impact = [
			'fields'      => $this->extractMigrationReferences($code, 'fields'),
			'templates'   => $this->extractMigrationReferences($code, 'templates'),
			'modules'     => $this->extractMigrationReferences($code, 'modules', ['get', 'install']),
			'permissions' => $this->extractMigrationReferences($code, 'permissions'),
			'roles'       => $this->extractMigrationReferences($code, 'roles'),
			'warnings'    => [],
		];

		$dangerPatterns = [
			'/\bdelete\s*\(/i'        => 'delete() call detected',
			'/\bremove\s*\(/i'        => 'remove() call detected',
			'/\btruncate\b/i'         => 'TRUNCATE detected',
			'/\bDROP\s+TABLE\b/i'     => 'DROP TABLE detected',
			'/restoreBackup\s*\(/i'   => 'restoreBackup() call detected',
			'/partialRestoreBackup\s*\(/i' => 'partialRestoreBackup() call detected',
			'/->exec\s*\(/i'          => 'raw database exec() call detected',
			'/->query\s*\(/i'         => 'raw database query() call detected',
		];

		foreach ($dangerPatterns as $pattern => $message) {
			if (preg_match($pattern, $code)) $impact['warnings'][] = $message;
		}

		$impact['warnings'] = array_values(array_unique($impact['warnings']));
		return $impact;
	}

	protected function extractMigrationReferences(string $code, string $apiName, array $methods = ['get']): array {
		$refs = [];
		foreach ($methods as $method) {
			$pattern = '/\\$' . preg_quote($apiName, '/') . '->' . preg_quote($method, '/') . '\\(\\s*[\'"]([^\'"]+)[\'"]\\s*\\)/';
			if (preg_match_all($pattern, $code, $matches)) {
				foreach ($matches[1] as $name) {
					$refs[] = $method === 'get' ? $name : $method . ': ' . $name;
				}
			}
		}
		$refs = array_values(array_unique($refs));
		sort($refs, SORT_NATURAL);
		return $refs;
	}

	protected function renderMigrationImpact(array $impact): string {
		$rows = '';
		foreach (['fields', 'templates', 'modules', 'permissions', 'roles'] as $scope) {
			if (empty($impact[$scope])) continue;
			$items = implode(', ', array_map(fn($item) => '<code>' . htmlspecialchars($item) . '</code>', $impact[$scope]));
			$rows .= '<tr><td class="uk-text-small uk-text-uppercase uk-text-muted">' . htmlspecialchars($scope) . '</td><td class="uk-text-small">' . $items . '</td></tr>';
		}

		if ($rows === '' && empty($impact['warnings'])) {
			return '<div class="uk-alert uk-alert-primary" uk-alert><p class="uk-margin-remove">No obvious ProcessWire schema references detected. Review the code before running.</p></div>';
		}

		$warningHtml = '';
		if (!empty($impact['warnings'])) {
			$warningHtml = '<div class="uk-alert uk-alert-warning" uk-alert><p class="uk-margin-remove"><strong>Potentially destructive operations detected:</strong> '
				. htmlspecialchars(implode(', ', $impact['warnings']))
				. '</p></div>';
		}

		$table = $rows !== ''
			? '<table class="uk-table uk-table-small uk-table-divider uk-table-hover"><thead><tr><th>Scope</th><th>Detected references</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			: '';

		return '<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Impact Preview</h3>' . $warningHtml . $table;
	}

	protected function isProductionEnvironment(): bool {
		return ($this->deployment_environment ?: 'local') === 'production';
	}

	protected function renderProductionConfirmInput(): string {
		if (!$this->isProductionEnvironment()) return '';
		return '<input type="text" name="production_confirm" class="uk-input uk-form-small uk-form-width-medium uk-margin-small-right" placeholder="RUN ON PRODUCTION" autocomplete="off" required>';
	}

	protected function validateProductionConfirm(): array {
		if (!$this->isProductionEnvironment()) return ['success' => true];
		$value = trim((string)($this->input->post('production_confirm') ?? ''));
		if ($value !== 'RUN ON PRODUCTION') {
			return ['success' => false, 'error' => 'Production confirmation phrase is required. Type RUN ON PRODUCTION.'];
		}
		return ['success' => true];
	}

	protected function getMigrationLockPath(): string {
		return $this->wire('config')->paths->assets . self::MIGRATION_LOCK_FILE;
	}

	protected function getMigrationLockStatus(): array {
		$path = $this->getMigrationLockPath();
		if (!file_exists($path)) return ['locked' => false, 'age' => 0];
		$age = time() - (int)file_get_contents($path);
		if ($age > 3600) {
			@unlink($path);
			return ['locked' => false, 'age' => 0];
		}
		return ['locked' => true, 'age' => $age];
	}

	protected function acquireMigrationLock(): array {
		$status = $this->getMigrationLockStatus();
		if ($status['locked']) {
			return ['success' => false, 'error' => 'Another migration is already running (lock age: ' . (int)$status['age'] . 's).'];
		}

		$path = $this->getMigrationLockPath();
		$dir = dirname($path);
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create migration lock directory.'];
		}
		if (file_put_contents($path, time(), LOCK_EX) === false) {
			return ['success' => false, 'error' => 'Cannot create migration lock.'];
		}

		return ['success' => true];
	}

	protected function releaseMigrationLock(): void {
		$path = $this->getMigrationLockPath();
		if (file_exists($path)) @unlink($path);
	}

	protected function validateMigrationGeneratorData(array $data): array {
		switch ($data['type']) {
			case 'create_field':
				if ($data['field_name'] === '') return ['success' => false, 'error' => 'Field name is required.'];
				if ($data['field_type'] === '') return ['success' => false, 'error' => 'Field type is required.'];
				break;
			case 'create_template':
				if ($data['template_name'] === '') return ['success' => false, 'error' => 'Template name is required.'];
				break;
			case 'add_field_to_template':
				if ($data['field_name'] === '') return ['success' => false, 'error' => 'Field name is required.'];
				if ($data['template_name'] === '') return ['success' => false, 'error' => 'Template name is required.'];
				break;
			case 'install_module':
				if ($data['module_name'] === '') return ['success' => false, 'error' => 'Module name is required.'];
				break;
			case 'create_permission':
			case 'create_role':
				if ($data['access_name'] === '') return ['success' => false, 'error' => 'Permission / role name is required.'];
				break;
		}

		return ['success' => true];
	}

	protected function sanitizePwName(string $value): string {
		$value = strtolower(trim($value));
		$value = preg_replace('/[^a-z0-9_]/', '_', $value);
		$value = preg_replace('/_+/', '_', $value);
		return trim((string)$value, '_');
	}

	protected function sanitizeAccessName(string $value): string {
		$value = strtolower(trim($value));
		$value = preg_replace('/[^a-z0-9_-]/', '-', $value);
		$value = preg_replace('/-+/', '-', $value);
		return trim((string)$value, '-');
	}

	protected function sanitizeClassName(string $value): string {
		return preg_replace('/[^a-zA-Z0-9_]/', '', trim($value)) ?: '';
	}

	protected function sanitizeNameList($value): array {
		$raw = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
		$parts = $raw ?: [];
		$names = [];
		foreach ($parts as $part) {
			$name = $this->sanitizePwName((string)$part);
			if ($name !== '') $names[] = $name;
		}
		return array_values(array_unique($names));
	}

	protected function sanitizeMigrationSlug(string $value): string {
		$value = strtolower(trim($value));
		$value = preg_replace('/[^a-z0-9]+/', '_', $value);
		$value = trim((string)$value, '_');
		return $value !== '' ? substr($value, 0, 80) : 'migration';
	}

	protected function buildMigrationCode(array $data): string {
		$body = match($data['type']) {
			'create_field'          => $this->buildCreateFieldMigrationCode($data),
			'create_template'       => $this->buildCreateTemplateMigrationCode($data),
			'add_field_to_template' => $this->buildAddFieldToTemplateMigrationCode($data),
			'install_module'        => $this->buildInstallModuleMigrationCode($data),
			'create_permission'     => $this->buildCreatePermissionMigrationCode($data),
			'create_role'           => $this->buildCreateRoleMigrationCode($data),
			default                 => '',
		};

		$title = var_export($data['title'], true);
		$message = var_export($data['message'] ?: $data['title'] . ' migrated.', true);

		return <<<PHP
<?php namespace ProcessWire;

/**
 * {$data['title']}
 *
 * Generated by ProcessDbBackup migrations.
 */

\$messages = [];
\$messages[] = {$title};

{$body}

return {$message};
PHP;
	}

	protected function buildCreateFieldMigrationCode(array $data): string {
		$fieldName = var_export($data['field_name'], true);
		$fieldType = var_export($data['field_type'], true);
		$fieldLabel = var_export($data['field_label'] ?: $data['field_name'], true);

		return <<<PHP
\$field = \$fields->get({$fieldName});
if (!\$field->id) {
	if (!\$modules->isInstalled({$fieldType})) {
		\$modules->install({$fieldType});
	}
	\$fieldtype = \$modules->get({$fieldType});
	if (!\$fieldtype) {
		throw new WireException('Fieldtype module not available: ' . {$fieldType});
	}
	\$field = new Field();
	\$field->name = {$fieldName};
	\$field->type = \$fieldtype;
	\$field->label = {$fieldLabel};
	\$fields->save(\$field);
} else {
	\$changed = false;
	if ((string)\$field->label !== {$fieldLabel}) {
		\$field->label = {$fieldLabel};
		\$changed = true;
	}
	if (\$changed) \$fields->save(\$field);
}
PHP;
	}

	protected function buildCreateTemplateMigrationCode(array $data): string {
		$templateName = var_export($data['template_name'], true);
		$fieldsArray = var_export($data['template_fields'] ?: ['title'], true);

		return <<<PHP
\$missingFields = [];
foreach ({$fieldsArray} as \$fieldName) {
	\$field = \$fields->get(\$fieldName);
	if (!\$field->id) {
		\$missingFields[] = \$fieldName;
	}
}
if (!empty(\$missingFields)) {
	throw new WireException('Template ' . {$templateName} . ' references missing field(s): ' . implode(', ', \$missingFields));
}

\$template = \$templates->get({$templateName});
if (!\$template->id) {
	\$fieldgroup = \$fieldgroups->get({$templateName});
	if (!\$fieldgroup->id) {
		\$fieldgroup = new Fieldgroup();
		\$fieldgroup->name = {$templateName};
		\$fieldgroups->save(\$fieldgroup);
	}

	\$template = new Template();
	\$template->name = {$templateName};
	\$template->fieldgroup = \$fieldgroup;
	\$templates->save(\$template);
}

foreach ({$fieldsArray} as \$fieldName) {
	\$field = \$fields->get(\$fieldName);
	if (!\$template->fieldgroup->hasField(\$field)) {
		\$template->fieldgroup->add(\$field);
	}
}
\$fieldgroups->save(\$template->fieldgroup);
PHP;
	}

	protected function buildUpdateFieldLabelMigrationCode(array $data): string {
		$fieldName = var_export($data['field_name'], true);
		$fieldLabel = var_export($data['field_label'], true);

		return <<<PHP
\$field = \$fields->get({$fieldName});
if (!\$field->id) {
	throw new WireException('Field not found: ' . {$fieldName});
}
if ((string)\$field->label !== {$fieldLabel}) {
	\$field->label = {$fieldLabel};
	\$fields->save(\$field);
}
PHP;
	}

	protected function buildUpdateTemplateLabelMigrationCode(array $data): string {
		$templateName = var_export($data['template_name'], true);
		$templateLabel = var_export($data['template_label'], true);

		return <<<PHP
\$template = \$templates->get({$templateName});
if (!\$template->id) {
	throw new WireException('Template not found: ' . {$templateName});
}
if ((string)\$template->label !== {$templateLabel}) {
	\$template->label = {$templateLabel};
	\$templates->save(\$template);
}
PHP;
	}

	protected function buildAddFieldToTemplateMigrationCode(array $data): string {
		$fieldName = var_export($data['field_name'], true);
		$templateName = var_export($data['template_name'], true);

		return <<<PHP
\$field = \$fields->get({$fieldName});
if (!\$field->id) {
	throw new WireException('Field not found: ' . {$fieldName});
}

\$template = \$templates->get({$templateName});
if (!\$template->id) {
	throw new WireException('Template not found: ' . {$templateName});
}

if (!\$template->fieldgroup->hasField(\$field)) {
	\$template->fieldgroup->add(\$field);
	\$fieldgroups->save(\$template->fieldgroup);
}
PHP;
	}

	protected function buildInstallModuleMigrationCode(array $data): string {
		$moduleName = var_export($data['module_name'], true);

		return <<<PHP
if (!\$modules->isInstalled({$moduleName})) {
	\$modules->install({$moduleName});
}
PHP;
	}

	protected function buildCreatePermissionMigrationCode(array $data): string {
		$name = var_export($data['access_name'], true);

		return <<<PHP
\$permission = \$permissions->get({$name});
if (!\$permission->id) {
	\$permission = new Permission();
	\$permission->name = {$name};
	\$permissions->save(\$permission);
}
PHP;
	}

	protected function buildCreateRoleMigrationCode(array $data): string {
		$name = var_export($data['access_name'], true);

		return <<<PHP
\$role = \$roles->get({$name});
if (!\$role->id) {
	\$role = new Role();
	\$role->name = {$name};
	\$roles->save(\$role);
}
PHP;
	}

	protected function getSnapshotsDir(): string {
		return $this->wire('config')->paths->assets . self::SNAPSHOTS_DIR;
	}

	protected function getSchemaSnapshotFiles(): array {
		$this->ensureRuntimeStorage();
		$dir = $this->getSnapshotsDir();
		if (!is_dir($dir)) {
			@wireMkdir($dir, true);
		}
		if (!is_dir($dir)) return [];

		$files = glob($dir . '*.json') ?: [];
		$snapshots = [];
		foreach ($files as $path) {
			if (!is_file($path) || !preg_match('/^[a-zA-Z0-9._-]+\.json$/', basename($path))) continue;
			$snapshots[] = [
				'filename' => basename($path),
				'path'     => $path,
				'size'     => filesize($path),
				'mtime'    => filemtime($path),
			];
		}

		usort($snapshots, fn($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($b['filename'], $a['filename']));
		return $snapshots;
	}

	protected function getSchemaSnapshotByFilename(string $filename): ?array {
		$filename = basename($filename);
		if (!preg_match('/^schema-[a-zA-Z0-9._-]+\.json$/', $filename)) return null;
		foreach ($this->getSchemaSnapshotFiles() as $snapshot) {
			if ($snapshot['filename'] === $filename) return $snapshot;
		}
		return null;
	}

	protected function createSchemaSnapshot(): array {
		$dir = $this->getSnapshotsDir();
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create snapshots directory.'];
		}

		$snapshot = $this->getCurrentSchemaSnapshot();
		$filename = 'schema-' . date('Y_m_d_His') . '.json';
		$path = $dir . $filename;
		$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return ['success' => false, 'error' => 'Could not encode schema snapshot.'];
		}
		if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
			return ['success' => false, 'error' => 'Could not write schema snapshot.'];
		}

		return ['success' => true, 'filename' => $filename];
	}

	protected function getCurrentSchemaSnapshot(): array {
		$snapshot = [
			'meta' => [
				'generated_at' => date('c'),
				'module'       => $this->className(),
				'version'      => self::getModuleInfo()['version'] ?? null,
			],
			'fields'      => [],
			'templates'   => [],
			'permissions' => [],
			'roles'       => [],
		];

		foreach ($this->wire('fields') as $field) {
			if (!$field->name) continue;
			$snapshot['fields'][$field->name] = [
				'type'  => $field->type ? $field->type->className() : '',
				'label' => (string)$field->label,
			];
		}

		foreach ($this->wire('templates') as $template) {
			if (!$template->name) continue;
			$fieldNames = [];
			if ($template->fieldgroup) {
				foreach ($template->fieldgroup as $field) {
					if ($field->name) $fieldNames[] = $field->name;
				}
			}
			$snapshot['templates'][$template->name] = [
				'label'  => (string)$template->label,
				'fields' => $fieldNames,
			];
		}

		foreach ($this->wire('permissions') as $permission) {
			if ($permission->name) $snapshot['permissions'][] = $permission->name;
		}

		foreach ($this->wire('roles') as $role) {
			if ($role->name) $snapshot['roles'][] = $role->name;
		}

		$this->ksortRecursive($snapshot['fields']);
		$this->ksortRecursive($snapshot['templates']);
		sort($snapshot['permissions'], SORT_NATURAL);
		sort($snapshot['roles'], SORT_NATURAL);

		return $snapshot;
	}

	protected function diffSchemaSnapshot(string $path): array {
		$previous = json_decode((string)file_get_contents($path), true);
		if (!is_array($previous)) return [];

		$current = $this->getCurrentSchemaSnapshot();
		$diff = [];

		foreach (['fields', 'templates'] as $scope) {
			$before = $previous[$scope] ?? [];
			$after = $current[$scope] ?? [];
			foreach (array_diff(array_keys($after), array_keys($before)) as $name) {
				$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'added', 'after' => $after[$name] ?? null];
			}
			foreach (array_diff(array_keys($before), array_keys($after)) as $name) {
				$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'removed', 'before' => $before[$name] ?? null];
			}
			foreach (array_intersect(array_keys($before), array_keys($after)) as $name) {
				if ($scope === 'templates') {
					$beforeFields = $before[$name]['fields'] ?? [];
					$afterFields = $after[$name]['fields'] ?? [];
					foreach (array_diff($afterFields, $beforeFields) as $fieldName) {
						$diff[] = [
							'scope'    => 'template_fields',
							'name'     => $name . '.' . $fieldName,
							'type'     => 'added',
							'template' => $name,
							'field'    => $fieldName,
							'after'    => ['template' => $name, 'field' => $fieldName],
						];
					}
					foreach (array_diff($beforeFields, $afterFields) as $fieldName) {
						$diff[] = [
							'scope'    => 'template_fields',
							'name'     => $name . '.' . $fieldName,
							'type'     => 'removed',
							'template' => $name,
							'field'    => $fieldName,
							'before'   => ['template' => $name, 'field' => $fieldName],
						];
					}

					$beforeComparable = $before[$name];
					$afterComparable = $after[$name];
					if (($beforeComparable['label'] ?? '') !== ($afterComparable['label'] ?? '')) {
						$diff[] = [
							'scope'  => 'template_labels',
							'name'   => $name,
							'type'   => 'changed',
							'before' => ['label' => $beforeComparable['label'] ?? ''],
							'after'  => ['label' => $afterComparable['label'] ?? ''],
						];
					}
					unset($beforeComparable['fields'], $afterComparable['fields'], $beforeComparable['label'], $afterComparable['label']);
					if ($beforeComparable != $afterComparable) {
						$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'changed', 'before' => $before[$name] ?? null, 'after' => $after[$name] ?? null];
					}
				} elseif ($before[$name] != $after[$name]) {
					if ($scope === 'fields') {
						$beforeField = $before[$name];
						$afterField = $after[$name];
						if (($beforeField['label'] ?? '') !== ($afterField['label'] ?? '')) {
							$diff[] = [
								'scope'  => 'field_labels',
								'name'   => $name,
								'type'   => 'changed',
								'before' => ['label' => $beforeField['label'] ?? ''],
								'after'  => ['label' => $afterField['label'] ?? ''],
							];
						}
						unset($beforeField['label'], $afterField['label']);
						if ($beforeField != $afterField) {
							$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'changed', 'before' => $before[$name] ?? null, 'after' => $after[$name] ?? null];
						}
					} else {
						$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'changed', 'before' => $before[$name] ?? null, 'after' => $after[$name] ?? null];
					}
				}
			}
		}

		foreach (['permissions', 'roles'] as $scope) {
			$before = $previous[$scope] ?? [];
			$after = $current[$scope] ?? [];
			foreach (array_diff($after, $before) as $name) {
				$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'added', 'after' => $name];
			}
			foreach (array_diff($before, $after) as $name) {
				$diff[] = ['scope' => $scope, 'name' => $name, 'type' => 'removed', 'before' => $name];
			}
		}

		usort($diff, fn($a, $b) => strcmp($a['scope'] . $a['name'], $b['scope'] . $b['name']));
		return $diff;
	}

	protected function createMigrationFromSchemaDiff(string $snapshotFilename = ''): array {
		$baseline = $snapshotFilename !== '' ? $this->getSchemaSnapshotByFilename($snapshotFilename) : null;
		if (!$baseline) {
			$snapshots = $this->getSchemaSnapshotFiles();
			$baseline = $snapshots[0] ?? null;
		}
		if (!$baseline) {
			return ['success' => false, 'error' => 'No schema snapshot found.'];
		}

		$diff = $this->diffSchemaSnapshot($baseline['path']);
		$current = $this->getCurrentSchemaSnapshot();
		$auto = array_values(array_filter($diff, fn($item) => $this->isAutoGeneratableSchemaDiffItem($item)));
		$manual = array_values(array_filter($diff, fn($item) => !$this->isAutoGeneratableSchemaDiffItem($item)));
		if (empty($auto)) {
			return ['success' => false, 'error' => 'No safe auto-generatable schema changes found in selected diff.'];
		}

		$code = $this->buildSchemaDiffMigrationCode($auto, $manual, $current, $baseline['filename']);
		$dir = $this->getMigrationsDir();
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create migrations directory.'];
		}

		$filename = date('Y_m_d_His') . '_schema_diff.php';
		$path = $dir . $filename;
		$counter = 2;
		while (file_exists($path)) {
			$filename = date('Y_m_d_His') . '_schema_diff_' . $counter . '.php';
			$path = $dir . $filename;
			$counter++;
		}

		if (file_put_contents($path, $code, LOCK_EX) === false) {
			return ['success' => false, 'error' => 'Could not write migration file.'];
		}

		return ['success' => true, 'filename' => $filename, 'manual_count' => count($manual)];
	}

	protected function buildSchemaDiffMigrationCode(array $added, array $manual, array $current, string $snapshotFilename): string {
		$body = [];
		$fieldAdds = array_values(array_filter($added, fn($item) => $item['scope'] === 'fields'));
		$templateAdds = array_values(array_filter($added, fn($item) => $item['scope'] === 'templates'));
		$templateFieldAdds = array_values(array_filter($added, fn($item) => $item['scope'] === 'template_fields'));
		$fieldLabelUpdates = array_values(array_filter($added, fn($item) => $item['scope'] === 'field_labels'));
		$templateLabelUpdates = array_values(array_filter($added, fn($item) => $item['scope'] === 'template_labels'));
		$permissionAdds = array_values(array_filter($added, fn($item) => $item['scope'] === 'permissions'));
		$roleAdds = array_values(array_filter($added, fn($item) => $item['scope'] === 'roles'));

		foreach ($fieldAdds as $item) {
			$name = $item['name'];
			$field = $current['fields'][$name] ?? [];
			$body[] = $this->buildCreateFieldMigrationCode([
				'field_name'  => $name,
				'field_type'  => $field['type'] ?? 'FieldtypeText',
				'field_label' => $field['label'] ?? $name,
			]);
		}

		foreach ($templateAdds as $item) {
			$name = $item['name'];
			$template = $current['templates'][$name] ?? [];
			$body[] = $this->buildCreateTemplateMigrationCode([
				'template_name'   => $name,
				'template_fields' => $template['fields'] ?? ['title'],
			]);
		}

		foreach ($templateFieldAdds as $item) {
			$fieldName = (string)($item['field'] ?? '');
			$templateName = (string)($item['template'] ?? '');
			if ($fieldName === '' || $templateName === '') continue;
			$body[] = $this->buildAddFieldToTemplateMigrationCode([
				'field_name'    => $fieldName,
				'template_name' => $templateName,
			]);
		}

		foreach ($fieldLabelUpdates as $item) {
			$fieldName = (string)($item['name'] ?? '');
			$fieldLabel = (string)($item['after']['label'] ?? '');
			if ($fieldName === '') continue;
			$body[] = $this->buildUpdateFieldLabelMigrationCode([
				'field_name'  => $fieldName,
				'field_label' => $fieldLabel,
			]);
		}

		foreach ($templateLabelUpdates as $item) {
			$templateName = (string)($item['name'] ?? '');
			$templateLabel = (string)($item['after']['label'] ?? '');
			if ($templateName === '') continue;
			$body[] = $this->buildUpdateTemplateLabelMigrationCode([
				'template_name'  => $templateName,
				'template_label' => $templateLabel,
			]);
		}

		foreach ($permissionAdds as $item) {
			$body[] = $this->buildCreatePermissionMigrationCode(['access_name' => $item['name']]);
		}

		foreach ($roleAdds as $item) {
			$body[] = $this->buildCreateRoleMigrationCode(['access_name' => $item['name']]);
		}

		if (!empty($manual)) {
			$body[] = $this->buildManualReviewComment($manual);
		}

		$snapshot = var_export($snapshotFilename, true);
		$bodyCode = implode("\n\n", array_filter($body));

		return <<<PHP
<?php namespace ProcessWire;

/**
 * Schema diff migration
 *
 * Generated from latest schema snapshot {$snapshotFilename}.
 * Review before deploying to production.
 */

{$bodyCode}

return 'Schema diff migration applied from snapshot ' . {$snapshot};
PHP;
	}

	protected function buildManualReviewComment(array $manual): string {
		$lines = ["// Manual review required for changed/removed schema items:"];
		foreach ($manual as $item) {
			$scope = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$item['scope']);
			$type = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$item['type']);
			$name = str_replace(["\r", "\n"], '', (string)$item['name']);
			$lines[] = "// - {$type} {$scope}: {$name}";
		}
		return implode("\n", $lines);
	}

	protected function ksortRecursive(array &$array): void {
		ksort($array, SORT_NATURAL);
		foreach ($array as &$value) {
			if (is_array($value)) $this->ksortRecursive($value);
		}
		unset($value);
	}

	protected function runMigration(string $filename): array {
		if (!$this->ensureMigrationStore()) {
			return ['success' => false, 'error' => 'Migration store is not available. Check database permissions and module logs.'];
		}

		$productionConfirm = $this->validateProductionConfirm();
		if (!$productionConfirm['success']) return $productionConfirm;

		if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid migration filename.'];
		}

		$path = $this->getMigrationsDir() . $filename;
		if (!is_file($path)) {
			return ['success' => false, 'error' => 'Migration file not found.'];
		}

		$lint = $this->lintMigrationFile($path);
		if (!$lint['valid']) {
			return ['success' => false, 'error' => 'PHP syntax check failed: ' . $lint['output']];
		}

		$applied = $this->getAppliedMigrations();
		if (isset($applied[$filename])) {
			return ['success' => false, 'error' => 'Migration has already been applied.'];
		}

		$lock = $this->acquireMigrationLock();
		if (!$lock['success']) return $lock;

		$checksum = hash_file('sha256', $path) ?: '';
		$preBackup = '';
		if ($this->pre_restore_backup) {
			$preResult = $this->createBackup('regular');
			if ($preResult['success']) {
				$preBackup = $preResult['filename'];
			} else {
				$this->releaseMigrationLock();
				return ['success' => false, 'error' => 'Pre-migration backup failed: ' . $preResult['error']];
			}
		}

		$message = '';
		try {
			ob_start();
			$result = $this->includeMigrationFile($path);
			$output = trim((string)ob_get_clean());
			if (is_string($result)) {
				$message = $result;
			} elseif (is_array($result) && isset($result['message'])) {
				$message = (string)$result['message'];
			}
			if ($message === '' && $output !== '') {
				$message = $output;
			}

			$user = $this->wire('user');
			$stmt = $this->wire('database')->prepare("
				INSERT INTO `" . self::MIGRATION_TABLE . "`
					(filename, checksum, applied_at, applied_by, pre_backup, message)
				VALUES
					(:filename, :checksum, :applied_at, :applied_by, :pre_backup, :message)
			");
			$stmt->execute([
				'filename'   => $filename,
				'checksum'   => $checksum,
				'applied_at' => date('Y-m-d H:i:s'),
				'applied_by' => $user && $user->id ? (string)$user->name : '',
				'pre_backup' => $preBackup,
				'message'    => $message,
			]);

			$this->log()->save(self::LOG_NAME, "Migration applied: {$filename}" . ($preBackup ? " (pre-backup: {$preBackup})" : ''));
			$this->releaseMigrationLock();
			return ['success' => true, 'pre_backup' => $preBackup, 'message' => $message];
		} catch (\Throwable $e) {
			if (ob_get_level() > 0) ob_end_clean();
			$this->releaseMigrationLock();
			$this->log()->save(self::LOG_NAME, "Migration failed: {$filename}: " . $e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	protected function includeMigrationFile(string $path): mixed {
		$runner = function(string $migrationPath) {
			$wire = wire();
			$config = $wire->config;
			$database = $wire->database;
			$fields = $wire->fields;
			$fieldgroups = $wire->fieldgroups;
			$templates = $wire->templates;
			$pages = $wire->pages;
			$modules = $wire->modules;
			$permissions = $wire->permissions;
			$roles = $wire->roles;
			$sanitizer = $wire->sanitizer;

			return include $migrationPath;
		};

		return $runner($path);
	}

	// ── Backblaze B2 ─────────────────────────────────────────────────────────

	protected function getB2Token(): array|false {
		$keyId  = $this->b2_key_id;
		$appKey = $this->b2_app_key;

		if (!$keyId || !$appKey) return false;

		$ch = curl_init(self::B2_API_AUTH);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD        => "{$keyId}:{$appKey}",
		]);
		$resp = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200) return false;

		return json_decode($resp, true);
	}

	protected function uploadToB2(string $filepath, string $filename): array {
		$auth = $this->getB2Token();
		if (!$auth) return ['success' => false, 'error' => 'B2 auth failed'];

		$apiUrl   = $auth['apiInfo']['storageApi']['apiUrl'];
		$authToken = $auth['authorizationToken'];
		$bucketId = $this->b2_bucket_id;

		// Get upload URL
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_get_upload_url");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(['bucketId' => $bucketId]),
			CURLOPT_HTTPHEADER     => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		$resp = json_decode(curl_exec($ch), true);
		curl_close($ch);

		if (empty($resp['uploadUrl'])) {
			return ['success' => false, 'error' => 'Could not get B2 upload URL'];
		}

		$uploadUrl   = $resp['uploadUrl'];
		$uploadToken = $resp['authorizationToken'];
		$fileSize    = filesize($filepath);
		$prefix      = $this->b2_prefix ? rtrim($this->b2_prefix, '/') . '/' : '';

		// Stream file via CURLOPT_INFILE — avoids loading entire file into memory
		$sha1 = hash_file('sha1', $filepath);
		$fh   = fopen($filepath, 'rb');

		$ch = curl_init($uploadUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_PUT            => true,
			CURLOPT_INFILE         => $fh,
			CURLOPT_INFILESIZE     => $fileSize,
			CURLOPT_HTTPHEADER     => [
				"Authorization: {$uploadToken}",
				"X-Bz-File-Name: " . urlencode($prefix . $filename),
				"Content-Type: application/gzip",
				// Content-Length set automatically by curl via CURLOPT_INFILESIZE
				"X-Bz-Content-Sha1: {$sha1}",
			],
		]);
		$result = json_decode(curl_exec($ch), true);
		$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if (isset($fh) && is_resource($fh)) fclose($fh);

		if ($code !== 200) {
			return ['success' => false, 'error' => $result['message'] ?? 'B2 upload error'];
		}

		return ['success' => true, 'fileId' => $result['fileId']];
	}

	protected function deleteFromB2(string $filename): void {
		$auth = $this->getB2Token();
		if (!$auth) return;

		$apiUrl    = $auth['apiInfo']['storageApi']['apiUrl'];
		$authToken = $auth['authorizationToken'];
		$bucketId  = $this->b2_bucket_id;
		$prefix    = $this->b2_prefix ? rtrim($this->b2_prefix, '/') . '/' : '';
		$b2Name    = $prefix . $filename;

		// Search for file
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_list_file_names");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode([
				'bucketId'  => $bucketId,
				'prefix'    => $b2Name,
				'maxFileCount' => 1,
			]),
			CURLOPT_HTTPHEADER => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		$resp = json_decode(curl_exec($ch), true);
		curl_close($ch);

		if (empty($resp['files'][0])) return;

		$fileId = $resp['files'][0]['fileId'];

		// Delete
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_delete_file_version");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(['fileId' => $fileId, 'fileName' => $b2Name]),
			CURLOPT_HTTPHEADER     => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		$delResult = json_decode(curl_exec($ch), true);
		$delCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($delCode !== 200) {
			$this->log()->save(self::LOG_NAME, 'B2 delete failed for ' . $b2Name . ': ' . ($delResult['message'] ?? 'HTTP ' . $delCode));
		}
	}

	// ── Dashboard widget ────────────────────────────────────────────────────────

	public function renderWidget(HookEvent $event): void {
		if (!$this->wire('user')->hasPermission('db-backup')) return;

		$allBackups = $this->getBackupList();
		$backupPage = $this->wire('pages')->get('template=admin, name=db-backup');
		if (!$backupPage->id) return; // page not found — abort widget
		$pageUrl    = $backupPage->url;
		$csrf       = $this->wire('session')->CSRF->renderInput();
		$diskUsed   = $this->getDiskUsage();
		$totalCount = count($allBackups);

		// Group by type
		$byType = ['regular' => [], 'weekly' => [], 'monthly' => []];
		foreach ($allBackups as $b) {
			$t = $b['type'] ?? 'regular';
			if (isset($byType[$t])) $byType[$t][] = $b;
		}

		// Per-type config
		$typeConfig = [
			'regular' => [
				'label'    => 'Regular',
				'icon'     => 'history',
				'schedule' => $this->cron_interval ?? 'never',
				'maxAge'   => 86400 * 2, // warn if older than 2 days
			],
			'weekly' => [
				'label'    => 'Weekly',
				'icon'     => 'calendar',
				'schedule' => $this->cron_weekly ?? 'never',
				'maxAge'   => 86400 * 7,
			],
			'monthly' => [
				'label'    => 'Monthly',
				'icon'     => 'star',
				'schedule' => $this->cron_monthly ?? 'never',
				'maxAge'   => 86400 * 28,
			],
		];

		$cronLabels = [
			'never' => 'Not scheduled', 'every30Seconds' => 'Every 30s',
			'everyMinute' => 'Every 1m', 'every2Minutes' => 'Every 2m',
			'every5Minutes' => 'Every 5m', 'every10Minutes' => 'Every 10m',
			'every15Minutes' => 'Every 15m', 'every30Minutes' => 'Every 30m',
			'everyHour' => 'Every 1h', 'every2Hours' => 'Every 2h',
			'every6Hours' => 'Every 6h', 'every12Hours' => 'Every 12h',
			'everyDay' => 'Daily', 'every2Days' => 'Every 2d',
			'everyWeek' => 'Weekly', 'every2Weeks' => 'Every 2w',
			'every4Weeks' => 'Every 4w',
		];

		$cronDisabled = $this->isCronDisabledByConfig();

		// Build per-type rows
		$rows = '';
		foreach ($typeConfig as $type => $cfg) {
			$backupsOfType = $byType[$type];
			$latest        = $backupsOfType[0] ?? null;
			$count         = count($backupsOfType);
			$scheduleLabel = $cronDisabled ? 'Disabled by config' : ($cronLabels[$cfg['schedule']] ?? $cfg['schedule']);
			$isScheduled   = !$cronDisabled && $cfg['schedule'] !== 'never';

			// Status
			if (!$latest) {
				$statusColor = 'danger';
				$statusText  = 'No backups';
				$dateHtml    = '<span class="uk-text-muted uk-text-small">—</span>';
			} elseif (strtotime($latest['date']) < time() - $cfg['maxAge']) {
				$statusColor = 'warning';
				$statusText  = 'Outdated';
				$dateHtml    = '<span class="uk-text-small">' . htmlspecialchars($latest['date']) . '</span>';
			} else {
				$statusColor = 'success';
				$statusText  = 'OK';
				$dateHtml    = '<span class="uk-text-small">' . htmlspecialchars($latest['date']) . '</span>';
			}

			// Storage badge for latest
			$storageBadge = '';
			if ($latest) {
				if (!empty($latest['b2']) && !empty($latest['local'])) {
					$storageBadge = '<span class="uk-label uk-label-success" style="font-size:10px">Local+B2</span>';
				} elseif (!empty($latest['b2'])) {
					$storageBadge = '<span class="uk-label uk-label-warning" style="font-size:10px">B2 only</span>';
				} else {
					$storageBadge = '<span class="uk-label" style="font-size:10px">Local</span>';
				}
			}

			// Manual create button
			$createForm = '<form method="post" action="' . $pageUrl . '" class="uk-display-inline">'
				. $csrf
				. '<input type="hidden" name="action" value="create_typed">'
				. '<input type="hidden" name="backup_type" value="' . $type . '">'
				. '<button type="submit" class="uk-button uk-button-default">'
				. '<span uk-icon="icon: plus; ratio:.7"></span> Create now'
				. '</button></form>';

			$schedBadge = $isScheduled
				? '<span class="uk-label uk-label-success" style="font-size:10px">' . $scheduleLabel . '</span>'
				: '<span class="uk-label" style="font-size:10px;background:#aaa">' . $scheduleLabel . '</span>';

			$rows .= '
			<tr>
				<td>
					<span uk-icon="icon: ' . $cfg['icon'] . '; ratio:.85" class="uk-margin-small-right"></span>
					<strong>' . $cfg['label'] . '</strong>
				</td>
				<td><span class="uk-label uk-label-' . $statusColor . '" style="font-size:10px">' . $statusText . '</span></td>
				<td>' . $dateHtml . ' ' . $storageBadge . '</td>
				<td class="uk-text-center"><span class="uk-badge">' . $count . '</span></td>
				<td>' . $schedBadge . '</td>
				<td>' . $createForm . '</td>
			</tr>';
		}

		$widget = '
		<div class="uk-card uk-card-default uk-margin-bottom">
			<div class="uk-card-header uk-padding-small">
				<div class="uk-flex uk-flex-middle" style="gap:8px">
					<span uk-icon="icon: database; ratio:1"></span>
					<h3 class="uk-card-title uk-margin-remove uk-flex-1">
						<a href="' . $pageUrl . '">DB Backup</a>
					</h3>
					<span class="uk-text-small uk-text-muted">' . $totalCount . ' total &middot; ' . $diskUsed . '</span>
				</div>
			</div>
			<div class="uk-card-body uk-padding-small">
				<table class="uk-table uk-table-small uk-table-divider uk-margin-remove">
					<thead>
						<tr>
							<th class="uk-text-small">Type</th>
							<th class="uk-text-small">Status</th>
							<th class="uk-text-small">Latest backup</th>
							<th class="uk-text-small uk-text-center">Count</th>
							<th class="uk-text-small">Schedule</th>
							<th class="uk-text-small">Action</th>
						</tr>
					</thead>
					<tbody>' . $rows . '</tbody>
				</table>
			</div>
		</div>';

		$event->return = $widget . $event->return;
	}

	// ── Render: verify page ─────────────────────────────────────────────────────

	protected function renderVerify(string $filename): string {
		$filename = basename((string)$filename);
		$pageUrl  = $this->page->url;

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			$this->session->redirect($pageUrl);
			return '';
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;
		$result   = $this->verifyBackup($filepath);

		$this->headline('Verify: ' . $filename);
		$backUrl = "<a href=\"{$pageUrl}\" class=\"uk-button uk-button-default uk-margin-bottom\"><span uk-icon=\"icon: arrow-left; ratio:.8\"></span>&nbsp; Back</a>";

		if ($result['valid']) {
			// Count tables in backup
			$tables = $this->getBackupTables($filename);
			$tableCount = count($tables);
			$tableList  = implode(', ', array_map(fn($t) => "<code>{$t}</code>", $tables));

			return $backUrl . "
			<div class=\"uk-alert uk-alert-success\" uk-alert>
				<span uk-icon=\"check\"></span>
				<strong>Backup is valid.</strong> gzip integrity passed, SQL structure verified.
			</div>
			<div class=\"uk-card uk-card-default uk-card-body uk-margin\">
				<h3 class=\"uk-card-title\">Contents</h3>
				<p class=\"uk-text-small\"><strong>{$tableCount}</strong> tables found:</p>
				<p class=\"uk-text-small\">{$tableList}</p>
			</div>";
		} else {
			return $backUrl . "
			<div class=\"uk-alert uk-alert-danger\" uk-alert>
				<span uk-icon=\"warning\"></span>
				<strong>Backup verification failed:</strong> " . htmlspecialchars($result['error']) . "
			</div>";
		}
	}

	// ── Render: partial restore page ──────────────────────────────────────────

	protected function renderPartialRestore(string $filename): string {
		$filename = basename((string)$filename);
		$pageUrl  = $this->page->url;
		$csrf     = $this->session->CSRF->renderInput();

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			$this->session->redirect($pageUrl);
			return '';
		}

		$tables  = $this->getBackupTables($filename);
		$backUrl = "<a href=\"{$pageUrl}\" class=\"uk-button uk-button-default uk-margin-bottom\"><span uk-icon=\"icon: arrow-left; ratio:.8\"></span>&nbsp; Back</a>";

		if (empty($tables)) {
			return $backUrl . "<div class=\"uk-alert uk-alert-warning\" uk-alert>Could not read table list from this backup.</div>";
		}

		$this->headline('Partial Restore: ' . $filename);

		// Get current DB tables for comparison
		$cfg = $this->wire('config');
		try {
			$dsn     = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo     = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass);
			$current = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
		} catch (\Exception $e) {
			$current = [];
		}

		$rows = '';
		foreach ($tables as $tbl) {
			$inCurrent = in_array($tbl, $current) ? '<span class="uk-label">exists</span>' : '<span class="uk-label uk-label-warning">new</span>';
			$rows .= "
			<tr>
				<td><label><input type=\"checkbox\" name=\"tables[]\" value=\"{$tbl}\" class=\"uk-checkbox\" checked>&nbsp; <code>{$tbl}</code></label></td>
				<td>{$inCurrent}</td>
			</tr>";
		}

		$preNote = $this->pre_restore_backup
			? '<p class="uk-text-small uk-text-muted"><span uk-icon="icon: info; ratio:.8"></span> A pre-restore backup will be created automatically before restoring.</p>'
			: '';

		return $backUrl . "
		<div class=\"uk-alert uk-alert-warning\" uk-alert>
			<span uk-icon=\"warning\"></span>
			Selected tables will be <strong>dropped and recreated</strong> from the backup. All current data in those tables will be lost.
		</div>
		{$preNote}
		<form method=\"post\" action=\"{$pageUrl}\">
			{$csrf}
			<input type=\"hidden\" name=\"action\" value=\"partial_restore\">
			<input type=\"hidden\" name=\"file\" value=\"{$filename}\">
			<div class=\"uk-overflow-auto uk-margin\">
			<table class=\"uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped\">
				<thead>
					<tr>
						<th><label><input type=\"checkbox\" class=\"uk-checkbox\" id=\"pdb-select-all\"> &nbsp;Table</label></th>
						<th>Status in current DB</th>
					</tr>
				</thead>
				<tbody>{$rows}</tbody>
			</table>
			</div>
			<button type=\"submit\" class=\"uk-button uk-button-danger\"
				onclick=\"return confirm('Restore selected tables? Current data in those tables will be overwritten.')\">
				<span uk-icon=\"icon: history; ratio:.8\"></span>&nbsp; Restore Selected Tables
			</button>
		</form>
		<script>
		document.getElementById('pdb-select-all').addEventListener('change', function() {
			document.querySelectorAll('input[name=\"tables[]\"]').forEach(cb => cb.checked = this.checked);
		});
		</script>";
	}

	// ── CSS + JS ──────────────────────────────────────────────────────────────

	protected function renderStyles(): string {
		// All styling delegated to UIkit — no custom CSS needed
		return '';
	}

	protected function renderScripts(string $pageUrl): string {
		// Pre-compute CSRF values — complex expressions can't be interpolated in heredoc
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		return <<<HTML
		<script>
		// CSRF token embedded from PHP — avoids fragile DOM selector
		const pdbCsrfName  = '{$csrfName}';
		const pdbCsrfToken = '{$csrfValue}';

		// ── Create backup with progress bar ─────────────────────────────────────
		function pdbCreateBackup(btn, type) {
			type = type || 'regular';
			btn.disabled = true;
			const origHtml = btn.innerHTML;
			btn.innerHTML = '<span uk-spinner="ratio:.6"></span>&nbsp; Creating...';
			document.getElementById('pdb-progress').classList.remove('uk-hidden');
			const bar = document.getElementById('pdb-progress-bar');
			const msg = document.getElementById('pdb-progress-msg');
			let pct = 0;
			const ticker = setInterval(() => {
				pct = Math.min(pct + 2, 90);
				bar.value = pct;
			}, 300);
			fetch('{$pageUrl}?pdb_ajax=create&type=' + type + '&' + pdbCsrfName + '=' + pdbCsrfToken)
				.then(r => r.json())
				.then(data => {
					clearInterval(ticker);
					bar.value = 100;
					if (data.success) {
						msg.textContent = '✓ ' + data.filename;
						setTimeout(() => location.reload(), 800);
					} else {
						msg.textContent = '✗ ' + (data.error || 'Unknown error');
						btn.disabled = false;
						btn.innerHTML = origHtml;
					}
				})
				.catch(e => {
					clearInterval(ticker);
					msg.textContent = '✗ ' + e.message;
					btn.disabled = false;
					btn.innerHTML = origHtml;
				});
		}

		// ── Inline label editing ─────────────────────────────────────────────────
		document.querySelectorAll('.pdb-label').forEach(el => {
			el.addEventListener('click', function() {
				const file    = this.dataset.file;
				const current = this.querySelector('em') ? '' : this.textContent;
				const input   = document.createElement('input');
				input.type    = 'text';
				input.value   = current;
				input.className = 'uk-input uk-form-small';
				input.style.width = '140px';
				this.replaceWith(input);
				input.focus();
				let pdbSaving = false;
				const save = () => {
					if (pdbSaving) return;
					pdbSaving = true;
					const label = input.value.trim();
					fetch('{$pageUrl}?pdb_ajax=label&file=' + encodeURIComponent(file) + '&label=' + encodeURIComponent(label) + '&' + pdbCsrfName + '=' + pdbCsrfToken)
						.then(() => location.reload())
						.catch(() => { pdbSaving = false; });
				};
				input.addEventListener('blur', save);
				input.addEventListener('keydown', e => {
					if (e.key === 'Enter') { e.preventDefault(); save(); }
					if (e.key === 'Escape') location.reload();
				});
			});
		});

		// ── Chunked upload ───────────────────────────────────────────────────────
		const PDB_CHUNK_SIZE = 2 * 1024 * 1024; // 2MB chunks

		function pdbChunkedUpload(btn) {
			const file = document.getElementById('pdb-upload-file').files[0];
			if (!file) { alert('Please select a .sql.gz file.'); return; }
			if (!file.name.endsWith('.sql.gz') && !file.name.endsWith('.gz')) {
				alert('Only .sql.gz files are accepted.'); return;
			}

			const restoreAfter = document.getElementById('pdb-restore-after').checked ? '1' : '0';
			const totalChunks  = Math.ceil(file.size / PDB_CHUNK_SIZE);
			const uploadId     = Math.random().toString(16).slice(2) + Date.now().toString(16);
			const bar          = document.getElementById('pdb-upload-bar');
			const msg          = document.getElementById('pdb-upload-msg');

			document.getElementById('pdb-upload-progress').classList.remove('uk-hidden');
			btn.disabled = true;

			function sendChunk(idx) {
				const start  = idx * PDB_CHUNK_SIZE;
				const end    = Math.min(start + PDB_CHUNK_SIZE, file.size);
				const slice  = file.slice(start, end);
				const fd     = new FormData();
				fd.append('chunk', slice);
				fd.append('upload_id', uploadId);
				fd.append('chunk_index', idx);
				fd.append('total_chunks', totalChunks);
				fd.append('filename', file.name);
				fd.append('restore_after_upload', restoreAfter);
				if (pdbCsrfName) fd.append(pdbCsrfName, pdbCsrfToken);

				bar.value = Math.round((idx / totalChunks) * 100);
				msg.textContent = 'Uploading chunk ' + (idx + 1) + ' / ' + totalChunks + '...';

				fetch('{$pageUrl}?pdb_ajax=chunk', { method: 'POST', body: fd })
					.then(r => r.json())
					.then(data => {
						if (!data.success) {
							msg.textContent = '✗ ' + (data.error || 'Upload failed');
							btn.disabled = false;
							return;
						}
						if (data.status === 'complete') {
							bar.value = 100;
							if (data.restore_error) {
								msg.textContent = '⚠ Uploaded but restore failed: ' + data.restore_error;
							} else {
								msg.textContent = data.restored
									? '✓ Uploaded and restored: ' + data.filename
									: '✓ Uploaded: ' + data.filename;
							}
							setTimeout(() => location.reload(), 1500);
						} else {
							sendChunk(idx + 1);
						}
					})
					.catch(e => {
						msg.textContent = '✗ ' + e.message;
						btn.disabled = false;
					});
			}
			sendChunk(0);
		}
		</script>
HTML;
	}

	// ── Module config (static) ────────────────────────────────────────────────

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules  = wire('modules');
		$wrapper  = new InputfieldWrapper();

		// ── Environment ───────────────────────────────────────────────────────
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'deployment_environment');
		$f->label = 'Deployment environment';
		$f->description = 'Used by migration safety checks. Production requires an explicit confirmation phrase before running migrations.';
		$f->addOptions([
			'local'      => 'Local',
			'staging'    => 'Staging',
			'production' => 'Production',
		]);
		$f->attr('value', $data['deployment_environment'] ?? 'local');
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Retention ──────────────────────────────────────────────────────────
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_count');
		$f->label = 'Max backups to keep (retention)';
		$f->description = 'Oldest backups are deleted automatically. 0 = unlimited.';
		$f->attr('value', $data['retention_count'] ?? 10);
		$f->min = 0;
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Pre-restore backup ────────────────────────────────────────────────────
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'pre_restore_backup');
		$f->label = 'Auto-backup before restore';
		$f->description = 'Creates a backup of the current database before any restore operation.';
		$f->attr('checked', !empty($data['pre_restore_backup']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Exclude tables ────────────────────────────────────────────────────────
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'exclude_tables');
		$f->label = 'Exclude tables from backup';
		$f->description = 'One table name per line. These tables will be skipped during backup.';
		$f->placeholder = "cache\nsessions\nwire_cache";
		$f->attr('value', $data['exclude_tables'] ?? '');
		$f->attr('rows', 4);
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Cron interval ──────────────────────────────────────────────────────
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_interval');
		$f->label = 'Auto-backup schedule (LazyCron)';
		$f->addOptions([
			'never'          => 'Never (manual only)',
			'every30Seconds' => 'Every 30 seconds',
			'everyMinute'    => 'Every minute',
			'every2Minutes'  => 'Every 2 minutes',
			'every3Minutes'  => 'Every 3 minutes',
			'every4Minutes'  => 'Every 4 minutes',
			'every5Minutes'  => 'Every 5 minutes',
			'every10Minutes' => 'Every 10 minutes',
			'every15Minutes' => 'Every 15 minutes',
			'every30Minutes' => 'Every 30 minutes',
			'every45Minutes' => 'Every 45 minutes',
			'everyHour'      => 'Every hour',
			'every2Hours'    => 'Every 2 hours',
			'every4Hours'    => 'Every 4 hours',
			'every6Hours'    => 'Every 6 hours',
			'every12Hours'   => 'Every 12 hours',
			'everyDay'       => 'Every day',
			'every2Days'     => 'Every 2 days',
			'every4Days'     => 'Every 4 days',
			'everyWeek'      => 'Every week',
			'every2Weeks'    => 'Every 2 weeks',
			'every4Weeks'    => 'Every 4 weeks',
		]);
		$f->attr('value', $data['cron_interval'] ?? 'never');
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Weekly schedule ───────────────────────────────────────────────────────
		$fsw = $modules->get('InputfieldFieldset');
		$fsw->label = 'Weekly Backups';
		$fsw->description = 'Independent schedule with its own retention. Use for longer-term snapshots.';
		$fsw->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_weekly');
		$f->label = 'Weekly backup schedule';
		$f->addOptions(['never' => 'Never', 'everyWeek' => 'Every week', 'every2Weeks' => 'Every 2 weeks', 'every4Weeks' => 'Every 4 weeks']);
		$f->attr('value', $data['cron_weekly'] ?? 'never');
		$f->columnWidth = 50;
		$fsw->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_weekly');
		$f->label = 'Keep (weekly backups)';
		$f->description = '0 = unlimited';
		$f->attr('value', $data['retention_weekly'] ?? 4);
		$f->min = 0;
		$f->columnWidth = 50;
		$fsw->add($f);
		$wrapper->add($fsw);

		// ── Monthly schedule ──────────────────────────────────────────────────────
		$fsm = $modules->get('InputfieldFieldset');
		$fsm->label = 'Monthly Backups';
		$fsm->description = 'Long-term archival backups on a monthly schedule.';
		$fsm->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_monthly');
		$f->label = 'Monthly backup schedule';
		$f->addOptions(['never' => 'Never', 'every4Weeks' => 'Every 4 weeks']);
		$f->attr('value', $data['cron_monthly'] ?? 'never');
		$f->columnWidth = 50;
		$fsm->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_monthly');
		$f->label = 'Keep (monthly backups)';
		$f->description = '0 = unlimited';
		$f->attr('value', $data['retention_monthly'] ?? 3);
		$f->min = 0;
		$f->columnWidth = 50;
		$fsm->add($f);
		$wrapper->add($fsm);

		// ── B2 fieldset ────────────────────────────────────────────────────────
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = 'Backblaze B2';
		$fs->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'b2_enabled');
		$f->label = 'Enable Backblaze B2 upload';
		$f->attr('checked', !empty($data['b2_enabled']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'b2_keep_local');
		$f->label = 'Keep local copy when B2 is enabled';
		$f->description = 'If unchecked, local file is deleted after successful B2 upload.';
		$f->attr('checked', !empty($data['b2_keep_local']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$f->showIf = 'b2_enabled=1';
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_key_id');
		$f->label = 'Application Key ID';
		$f->attr('value', $data['b2_key_id'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_app_key');
		$f->label = 'Application Key (secret)';
		$f->attr('type', 'password');
		$f->attr('value', $data['b2_app_key'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_bucket_id');
		$f->label = 'Bucket ID';
		$f->attr('value', $data['b2_bucket_id'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_prefix');
		$f->label = 'Path prefix in bucket (optional)';
		$f->placeholder = 'e.g. mysite/db-backups';
		$f->attr('value', $data['b2_prefix'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$wrapper->add($fs);

		return $wrapper;
	}

	// ── Install / Uninstall ───────────────────────────────────────────────────

	public function ___install(): void {
		parent::___install();

		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir)) wireMkdir($dir, true);
		$this->ensureRuntimeStorage();
		$this->ensureMigrationStore();

		// .htaccess protection for local backups
		$htaccess = dirname($dir) . '/.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents(
				$this->wire('config')->paths->assets . 'backups/.htaccess',
				"Order Deny,Allow\nDeny from all\n"
			);
		}
	}

	public function ___uninstall(): void {
		parent::___uninstall();
	}
}
