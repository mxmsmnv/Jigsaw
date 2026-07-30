<?php namespace ProcessWire;

require_once __DIR__ . '/src/JigsawFeatureRegistry.php';
require_once __DIR__ . '/src/JigsawConfigBuilder.php';

/**
 * Jigsaw
 *
 * ProcessWire operations and developer toolkit.
 *
 * @author Maxim Semenov <maxim@smnv.org>
 * @version 3.0.0
 */
class Jigsaw extends Process implements Module, ConfigurableModule {

	public const VERSION = '3.0.0';
	private ?JigsawFeatureRegistry $features = null;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Jigsaw',
			'summary' => 'ProcessWire operations and developer toolkit.',
			'version' => 300,
			'author' => 'Maxim Semenov',
			'href' => 'https://smnv.org',
			'icon' => 'puzzle-piece',
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.2'],
			'page' => [
				'name' => 'jigsaw',
				'parent' => 'setup',
				'title' => 'Jigsaw',
			],
			'permission' => 'jigsaw',
			'permissions' => [
				'jigsaw' => 'View the Jigsaw toolkit dashboard',
				'jigsaw-diagnostics' => 'View read-only Jigsaw diagnostics',
				'db-backup' => 'Manage database backups',
				'ssl-manager' => 'Manage SSL certificates',
				'warmup-admin' => 'Administer email warmup',
				'language-access-manager' => 'Manage language access',
				'editor' => 'Use the Jigsaw file editor',
				'context-admin' => 'Administer Context exports and AI gateway',
			],
			'singular' => true,
			'autoload' => true,
		];
	}

	public function init(): void {
		parent::init();
		$this->wire('jigsaw', $this);
		$this->featureRegistry()->initAutoloadFeatures();
	}

	public function ready(): void {
		$this->featureRegistry()->readyAutoloadFeatures();
	}

	public function ___install(): void {
		parent::___install();
		$this->featureRegistry()->migrateLegacyModuleConfig();
		$this->installFeatureStorage();
	}

	public function ___upgrade($fromVersion, $toVersion): void {
		$this->featureRegistry()->migrateLegacyModuleConfig();
		$this->installFeatureStorage();
	}

	public function ___uninstall(): void {
		foreach (['ssl', 'languages'] as $key) {
			$feature = $this->featureRegistry()->feature($key, true);
			if (method_exists($feature, 'uninstallStorage')) $feature->uninstallStorage();
		}
		parent::___uninstall();
	}

	private function installFeatureStorage(): void {
		foreach (['backup', 'ssl', 'languages'] as $key) {
			$feature = $this->featureRegistry()->feature($key, true);
			if (method_exists($feature, 'installStorage')) $feature->installStorage();
		}
	}

	public function ___execute(): string {
		$this->headline('Jigsaw');
		$this->browserTitle('Jigsaw');

		$components = $this->featureRegistry()->dashboardData();
		$installed = count(array_filter($components, static fn(array $item): bool => $item['enabled']));
		$total = count($components);

		$out = $this->renderSummary($installed, $total);
		$out .= $this->renderEnvironment();

		foreach ($this->groupComponents($components) as $group => $items) {
			$out .= '<h2 class="jigsaw-heading">' . $this->e($group) . '</h2>';
			$out .= '<div class="jigsaw-grid">';
			foreach ($items as $item) {
				$out .= $this->renderComponent($item);
			}
			$out .= '</div>';
		}

		$out .= $this->styles();
		return $out;
	}

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		return JigsawConfigBuilder::build($data);
	}

	public function feature(string $key): object {
		return $this->featureRegistry()->feature($key);
	}

	public function ___executeDiagnostics(): string { return $this->dispatch('diagnostics', '___execute'); }
	public function ___executeDiagnosticsTree(): string { return $this->dispatch('diagnostics', '___executeTree'); }
	public function ___executeDiagnosticsDuplicates(): string { return $this->dispatch('diagnostics', '___executeDuplicates'); }
	public function ___executeDiagnosticsUrls(): string { return $this->dispatch('diagnostics', '___executeUrls'); }
	public function ___executeBackup(): string { return $this->dispatch('backup', '___execute'); }
	public function ___executeSsl(): string { return $this->dispatch('ssl', 'execute'); }
	public function ___executeSslAdd(): string { return $this->dispatch('ssl', 'executeAdd'); }
	public function ___executeSslDelete(): string { return $this->dispatch('ssl', 'executeDelete'); }
	public function ___executeSslCsr(): string { return $this->dispatch('ssl', 'executeCsr'); }
	public function ___executeSslSelfsigned(): string { return $this->dispatch('ssl', 'executeSelfsigned'); }
	public function ___executeSslCheck(): string { return $this->dispatch('ssl', 'executeCheck'); }
	public function ___executeSslCert(): string { return $this->dispatch('ssl', 'executeCert'); }
	public function ___executeSslExport(): string { return $this->dispatch('ssl', 'executeExport'); }
	public function ___executeSslView(): string { return $this->dispatch('ssl', 'executeView'); }
	public function ___executeEditor(): string { return $this->dispatch('editor', 'execute'); }
	public function ___executeEditorList(): string { return $this->dispatch('editor', 'executeList'); }
	public function ___executeEditorRead(): string { return $this->dispatch('editor', 'executeRead'); }
	public function ___executeEditorServe(): string { return $this->dispatch('editor', 'executeServe'); }
	public function ___executeEditorSave(): string { return $this->dispatch('editor', 'executeSave'); }
	public function ___executeEditorUpload(): string { return $this->dispatch('editor', 'executeUpload'); }
	public function ___executeEditorCreate(): string { return $this->dispatch('editor', 'executeCreate'); }
	public function ___executeEditorRename(): string { return $this->dispatch('editor', 'executeRename'); }
	public function ___executeEditorDelete(): string { return $this->dispatch('editor', 'executeDelete'); }
	public function ___executeUninstaller(): string { return $this->dispatch('uninstaller', 'execute'); }
	public function ___executeFields(): string { return $this->dispatch('fields', '___execute'); }
	public function ___executeContext(): string { return $this->dispatch('context', 'execute'); }
	public function ___executeContextExport(): string { return $this->dispatch('context', 'executeExport'); }
	public function ___executeContextDownload(): string { return $this->dispatch('context', 'executeDownload'); }
	public function ___executeContextAiTest(): string { return $this->dispatch('context', 'executeAiTest'); }

	public function ___executeWarmup(): string {
		$this->assertFeaturePermission('warmup');
		require_once __DIR__ . '/src/Features/WarmUp/WarmUpDashboard.php';
		$dashboard = $this->wire(new JigsawWarmUpDashboard());
		$dashboard->setWarmup($this->featureRegistry()->readyFeature('warmup'));
		$dashboard->init();
		return (string)$dashboard->___execute();
	}

	private function dispatch(string $key, string $method): string {
		$this->assertFeaturePermission($key);
		$feature = $this->featureRegistry()->initFeature($key);
		if (!is_callable([$feature, $method])) throw new Wire404Exception();
		return (string)$feature->$method();
	}

	private function assertFeaturePermission(string $key): void {
		$definition = JigsawFeatureRegistry::definitions()[$key] ?? null;
		$permission = $definition['permission'] ?? 'jigsaw';
		$user = $this->wire('user');
		if (!$user->isSuperuser() && !$user->hasPermission($permission)) {
			throw new WirePermissionException('You do not have permission to use this Jigsaw feature.');
		}
	}

	private function featureRegistry(): JigsawFeatureRegistry {
		return $this->features ??= new JigsawFeatureRegistry($this);
	}

	private function groupComponents(array $components): array {
		$groups = [];
		foreach ($components as $component) {
			$groups[$component['group']][] = $component;
		}
		return $groups;
	}

	private function renderSummary(int $installed, int $total): string {
		$status = $installed === $total ? 'All features are enabled.' : "$installed of $total features are enabled.";
		return
			'<div class="jigsaw-hero">' .
				'<div><span class="jigsaw-kicker">ProcessWire toolkit</span>' .
				'<h1>Operations, diagnostics and development tools in one package.</h1>' .
				'<p>' . $this->e($status) . '</p></div>' .
				'<div class="jigsaw-score"><strong>' . $installed . '</strong><span>/ ' . $total . ' ready</span></div>' .
			'</div>';
	}

	private function renderEnvironment(): string {
		$config = $this->wire('config');
		$pages = $this->wire('pages');
		$fields = $this->wire('fields');
		$templates = $this->wire('templates');

		$stats = [
			'ProcessWire' => (string)$config->version,
			'PHP' => PHP_VERSION,
			'Pages' => number_format((int)$pages->count('include=all')),
			'Templates' => number_format(count($templates)),
			'Fields' => number_format(count($fields)),
		];

		$out = '<div class="jigsaw-stats">';
		foreach ($stats as $label => $value) {
			$out .= '<div><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong></div>';
		}
		return $out . '</div>';
	}

	private function renderComponent(array $item): string {
		$statusClass = $item['enabled'] ? 'is-ready' : 'is-missing';
		$statusLabel = $item['enabled'] ? 'Enabled' : 'Disabled';
		$title = $this->e($item['title']);
		$version = '';
		$url = $item['admin'] && $item['enabled']
			? rtrim((string)$this->wire('page')->url, '/') . '/' . rawurlencode($item['key']) . '/'
			: '';
		$action = $url !== ''
			? '<a class="jigsaw-action" href="' . $this->e($url) . '">Open</a>'
			: '<span class="jigsaw-action is-disabled">' . ($item['enabled'] ? 'Background feature' : 'Disabled') . '</span>';

		return
			'<article class="jigsaw-card ' . $statusClass . '">' .
				'<div class="jigsaw-card-top"><span class="jigsaw-status">' . $statusLabel . '</span>' . $version . '</div>' .
				'<h3>' . $title . '</h3>' .
				'<p>' . $this->e($item['description']) . '</p>' .
				$action .
			'</article>';
	}

	private function e(string $value): string {
		return (string)$this->wire('sanitizer')->entities($value);
	}

	private function styles(): string {
		return <<<'HTML'
<style>
.jigsaw-hero{display:flex;justify-content:space-between;gap:2rem;align-items:center;padding:2rem;border-radius:12px;background:linear-gradient(135deg,#172033,#334765);color:#fff}.jigsaw-hero h1{max-width:760px;margin:.35rem 0;font-size:clamp(1.6rem,3vw,2.5rem);line-height:1.1;color:#fff}.jigsaw-hero p{margin:0;color:#dbe5f2}.jigsaw-kicker{text-transform:uppercase;letter-spacing:.12em;font-size:.75rem;color:#a9c7eb}.jigsaw-score{text-align:center;min-width:120px}.jigsaw-score strong{display:block;font-size:2.5rem}.jigsaw-score span{color:#dbe5f2}.jigsaw-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:1px;margin:1rem 0 2rem;background:#d8dee8;border:1px solid #d8dee8;border-radius:10px;overflow:hidden}.jigsaw-stats div{padding:1rem;background:#fff}.jigsaw-stats span,.jigsaw-stats strong{display:block}.jigsaw-stats span{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#687387}.jigsaw-stats strong{margin-top:.2rem;font-size:1.25rem}.jigsaw-heading{margin:1.8rem 0 .7rem}.jigsaw-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.jigsaw-card{position:relative;display:flex;flex-direction:column;min-height:170px;padding:1.2rem;border:1px solid #d8dee8;border-radius:10px;background:#fff}.jigsaw-card.is-missing{border-color:#dca8a8}.jigsaw-card-top{display:flex;justify-content:space-between;align-items:center}.jigsaw-status{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#267446}.is-missing .jigsaw-status{color:#a33333}.jigsaw-card small{color:#778195}.jigsaw-card h3{margin:.8rem 0 .35rem}.jigsaw-card p{flex:1;margin:0 0 1rem;color:#596579}.jigsaw-action{align-self:flex-start;font-weight:600}.jigsaw-action.is-disabled{color:#8c95a4}@media(max-width:900px){.jigsaw-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.jigsaw-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:600px){.jigsaw-hero{align-items:flex-start;flex-direction:column}.jigsaw-grid,.jigsaw-stats{grid-template-columns:1fr}}
</style>
HTML;
	}
}
