<?php namespace ProcessWire;

/**
 * Loads the former standalone modules as internal Jigsaw feature controllers.
 *
 * Feature entry files deliberately do not use the .module.php suffix, so the
 * ProcessWire module scanner discovers Jigsaw—and only Jigsaw.
 */
final class JigsawFeatureRegistry {

	private Jigsaw $jigsaw;
	private array $instances = [];
	private array $initialized = [];
	private array $readied = [];

	private const DEFINITIONS = [
		'diagnostics' => [
			'class' => JigsawDiagnosticsFeature::class,
			'file' => 'ProcessJigsawDiagnostics/DiagnosticsFeature.php',
			'title' => 'Diagnostics',
			'group' => 'Diagnostics',
			'description' => 'Read-only page tree, duplicate-title and URL diagnostics.',
			'permission' => 'jigsaw-diagnostics',
			'admin' => true,
		],
		'backup' => [
			'class' => JigsawBackupFeature::class,
			'file' => 'ProcessDbBackup/ProcessDbBackupFeature.php',
			'title' => 'DB Backup',
			'group' => 'Operations',
			'description' => 'Database backups, restores, migrations and schema snapshots.',
			'permission' => 'db-backup',
			'init' => true,
			'admin' => true,
			'config' => 'static',
		],
		'ssl' => [
			'class' => JigsawSslFeature::class,
			'file' => 'ProcessSsl/SslFeature.php',
			'title' => 'SSL Manager',
			'group' => 'Operations',
			'description' => 'SSL certificate monitoring and certificate tools.',
			'permission' => 'ssl-manager',
			'init' => true,
			'admin' => true,
			'config' => 'instance',
		],
		'warmup' => [
			'class' => JigsawWarmUpFeature::class,
			'file' => 'WarmUp/WarmUpFeature.php',
			'title' => 'WarmUp',
			'group' => 'Operations',
			'description' => 'Email warmup scheduling, integrations and statistics.',
			'permission' => 'warmup-admin',
			'ready' => true,
			'admin' => true,
			'config' => 'instance',
		],
		'announcement' => [
			'class' => JigsawAnnouncementBarFeature::class,
			'file' => 'WireAnnouncementBar/AnnouncementBarFeature.php',
			'title' => 'Announcement Bar',
			'group' => 'Site',
			'description' => 'Dismissible frontend announcement bar.',
			'init' => true,
			'config' => 'static',
		],
		'adminbar' => [
			'class' => JigsawAdminBarFeature::class,
			'file' => 'AdminBar/AdminBarFeature.php',
			'title' => 'Admin Bar',
			'group' => 'Site',
			'description' => 'Frontend administration shortcuts for editors.',
			'init' => true,
			'config' => 'static',
		],
		'languages' => [
			'class' => JigsawLanguageAccessFeature::class,
			'file' => 'LanguageAccessManager/LanguageAccessManagerFeature.php',
			'title' => 'Language Access',
			'group' => 'Access',
			'description' => 'Language editing permissions and role assignments.',
			'permission' => 'language-access-manager',
			'init' => true,
			'config' => 'static',
		],
		'editor' => [
			'class' => JigsawEditorFeature::class,
			'file' => 'Editor/EditorFeature.php',
			'title' => 'Editor',
			'group' => 'Developer',
			'description' => 'Template file browser and editor.',
			'permission' => 'editor',
			'admin' => true,
			'config' => 'static',
		],
		'uninstaller' => [
			'class' => JigsawUninstallerFeature::class,
			'file' => 'Uninstaller/UninstallerFeature.php',
			'title' => 'Uninstaller',
			'group' => 'Developer',
			'description' => 'Audited module removal with dependency discovery.',
			'permission' => 'jigsaw',
			'admin' => true,
		],
		'fields' => [
			'class' => JigsawFieldAuditFeature::class,
			'file' => 'ProcessFieldAudit/ProcessFieldAuditFeature.php',
			'title' => 'Field Audit',
			'group' => 'Developer',
			'description' => 'Field, fieldtype and Repeater Matrix inventory.',
			'permission' => 'jigsaw',
			'admin' => true,
		],
		'context' => [
			'class' => JigsawContextFeature::class,
			'file' => 'Context/ContextFeature.php',
			'title' => 'Context',
			'group' => 'Developer',
			'description' => 'AI-ready exports, prompts, CLI tools and provider gateway.',
			'permission' => 'context-admin',
			'init' => true,
			'ready' => true,
			'admin' => true,
			'config' => 'static',
		],
	];

	public function __construct(Jigsaw $jigsaw) {
		$this->jigsaw = $jigsaw;
		$this->registerFeaturePaths();
	}

	public static function definitions(): array {
		return self::DEFINITIONS;
	}

	public function isEnabled(string $key): bool {
		$name = "feature_{$key}_enabled";
		$config = (array)$this->jigsaw->wire('modules')->getModuleConfigData($this->jigsaw);
		return array_key_exists($name, $config) ? (bool)$config[$name] : true;
	}

	public function feature(string $key, bool $allowDisabled = false): object {
		if (!isset(self::DEFINITIONS[$key])) {
			throw new WireException("Unknown Jigsaw feature: {$key}");
		}
		if (!$allowDisabled && !$this->isEnabled($key)) {
			throw new Wire404Exception("Jigsaw feature is disabled: {$key}");
		}
		if (isset($this->instances[$key])) return $this->instances[$key];

		$definition = self::DEFINITIONS[$key];
		require_once $this->featureRoot() . $definition['file'];
		$class = $definition['class'];
		$instance = $this->jigsaw->wire(new $class());
		if (method_exists($class, 'getDefaultData')) {
			foreach ((array)$class::getDefaultData() as $name => $value) $instance->set($name, $value);
		}
		$this->hydrate($key, $instance);
		$this->instances[$key] = $instance;
		return $instance;
	}

	public function initAutoloadFeatures(): void {
		foreach (self::DEFINITIONS as $key => $definition) {
			if (!empty($definition['init']) && $this->isEnabled($key)) {
				$this->initFeature($key);
			}
		}
	}

	public function readyAutoloadFeatures(): void {
		foreach (self::DEFINITIONS as $key => $definition) {
			if (!empty($definition['ready']) && $this->isEnabled($key)) {
				$this->readyFeature($key);
			}
		}
	}

	public function initFeature(string $key): object {
		$feature = $this->feature($key);
		if (!isset($this->initialized[$key]) && method_exists($feature, 'init')) {
			$feature->init();
			$this->initialized[$key] = true;
		}
		return $feature;
	}

	public function readyFeature(string $key): object {
		$feature = $this->initFeature($key);
		if (!isset($this->readied[$key]) && method_exists($feature, 'ready')) {
			$feature->ready();
			$this->readied[$key] = true;
		}
		return $feature;
	}

	public function dashboardData(): array {
		$items = [];
		foreach (self::DEFINITIONS as $key => $definition) {
			$items[] = [
				'key' => $key,
				'title' => $definition['title'],
				'group' => $definition['group'],
				'description' => $definition['description'],
				'enabled' => $this->isEnabled($key),
				'admin' => !empty($definition['admin']),
			];
		}
		return $items;
	}

	public function migrateLegacyModuleConfig(): void {
		$legacyModules = [
			'adminbar' => 'AdminBar',
			'context' => 'Context',
			'editor' => 'Editor',
			'languages' => 'LanguageAccessManager',
			'backup' => 'ProcessDbBackup',
			'ssl' => 'ProcessSsl',
			'warmup' => 'WarmUp',
			'announcement' => 'WireAnnouncementBar',
		];
		$modules = $this->jigsaw->wire('modules');
		$current = (array)$modules->getModuleConfigData($this->jigsaw);
		$changed = false;

		foreach ($legacyModules as $key => $legacyName) {
			$legacy = (array)$modules->getConfig($legacyName);
			foreach ($legacy as $name => $value) {
				$target = "feature_{$key}__{$name}";
				if (!array_key_exists($target, $current)) {
					$current[$target] = $value;
					$changed = true;
				}
			}
			$enabled = "feature_{$key}_enabled";
			if (!array_key_exists($enabled, $current)) {
				$current[$enabled] = 1;
				$changed = true;
			}
		}

		if ($changed) $modules->saveConfig($this->jigsaw, $current);
	}

	private function hydrate(string $key, object $feature): void {
		$config = (array)$this->jigsaw->wire('modules')->getModuleConfigData($this->jigsaw);
		$prefix = "feature_{$key}__";
		$featureConfig = [];
		foreach ($config as $name => $value) {
			if (str_starts_with((string)$name, $prefix)) {
				$featureConfig[substr((string)$name, strlen($prefix))] = $value;
			}
		}
		if (method_exists($feature, 'setJigsawConfig')) {
			$feature->setJigsawConfig($featureConfig);
		} else {
			foreach ($featureConfig as $name => $value) $feature->set($name, $value);
		}
	}

	private function registerFeaturePaths(): void {
		$config = $this->jigsaw->wire('config');
		$basePath = $this->featureRoot();
		$baseUrl = rtrim((string)$config->urls->Jigsaw, '/') . '/src/Features/';
		foreach (self::DEFINITIONS as $definition) {
			$class = ltrim($definition['class'], '\\');
			$folder = dirname($definition['file']) . '/';
			$config->paths->set($class, $basePath . $folder);
			$config->urls->set($class, $baseUrl . $folder);
		}
		$config->paths->set('Editor', $basePath . 'Editor/');
		$config->urls->set('Editor', $baseUrl . 'Editor/');
	}

	private function featureRoot(): string {
		return __DIR__ . '/Features/';
	}
}
