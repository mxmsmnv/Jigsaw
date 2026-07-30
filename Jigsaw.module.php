<?php namespace ProcessWire;

/**
 * Jigsaw
 *
 * ProcessWire operations and developer toolkit.
 *
 * @author Maxim Semenov <maxim@smnv.org>
 * @version 2.0.2
 */
class Jigsaw extends Process implements Module {

	private const COMPONENTS = [
		'ProcessJigsawDiagnostics' => [
			'group' => 'Diagnostics',
			'description' => 'Read-only page tree, duplicate-title and URL diagnostics.',
		],
		'ProcessDbBackup' => [
			'group' => 'Operations',
			'description' => 'Database backups, restores, migrations and schema snapshots.',
		],
		'ProcessSsl' => [
			'group' => 'Operations',
			'description' => 'SSL certificate monitoring and certificate tools.',
		],
		'WarmUp' => [
			'group' => 'Operations',
			'description' => 'Email warmup scheduling, integrations and statistics.',
		],
		'WireAnnouncementBar' => [
			'group' => 'Site',
			'description' => 'Dismissible frontend announcement bar.',
		],
		'AdminBar' => [
			'group' => 'Site',
			'description' => 'Frontend administration shortcuts for editors.',
		],
		'LanguageAccessManager' => [
			'group' => 'Access',
			'description' => 'Language editing permissions and role assignments.',
		],
		'Editor' => [
			'group' => 'Developer',
			'description' => 'Template file browser and editor.',
		],
		'Uninstaller' => [
			'group' => 'Developer',
			'description' => 'Audited module removal with dependency discovery.',
		],
		'ProcessFieldAudit' => [
			'group' => 'Developer',
			'description' => 'Field, fieldtype and Repeater Matrix inventory.',
		],
		'Context' => [
			'group' => 'Developer',
			'description' => 'AI-ready site structure and configuration exports.',
		],
	];

	public static function getModuleInfo(): array {
		return [
			'title' => 'Jigsaw',
			'summary' => 'ProcessWire operations and developer toolkit.',
			'version' => 202,
			'author' => 'Maxim Semenov',
			'href' => 'https://smnv.org',
			'icon' => 'puzzle-piece',
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.2'],
			'installs' => array_keys(self::COMPONENTS),
			'page' => [
				'name' => 'jigsaw',
				'parent' => 'setup',
				'title' => 'Jigsaw',
			],
			'permission' => 'jigsaw',
			'permissions' => [
				'jigsaw' => 'View the Jigsaw toolkit dashboard',
			],
			'singular' => true,
			'autoload' => false,
		];
	}

	public function ___execute(): string {
		$this->headline('Jigsaw');
		$this->browserTitle('Jigsaw');

		$components = $this->componentData();
		$installed = count(array_filter($components, static fn(array $item): bool => $item['installed']));
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

	private function componentData(): array {
		$modules = $this->wire('modules');
		$items = [];

		foreach (self::COMPONENTS as $name => $definition) {
			$installed = $modules->isInstalled($name);
			$info = $modules->getModuleInfo($name);
			$items[] = [
				'name' => $name,
				'title' => (string)($info['title'] ?? $name),
				'version' => (string)($info['version'] ?? ''),
				'group' => $definition['group'],
				'description' => $definition['description'],
				'installed' => $installed,
				'url' => $installed ? $this->moduleUrl($name, $info) : '',
			];
		}

		return $items;
	}

	private function groupComponents(array $components): array {
		$groups = [];
		foreach ($components as $component) {
			$groups[$component['group']][] = $component;
		}
		return $groups;
	}

	private function renderSummary(int $installed, int $total): string {
		$status = $installed === $total ? 'All components are available.' : "$installed of $total components are installed.";
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
		$statusClass = $item['installed'] ? 'is-ready' : 'is-missing';
		$statusLabel = $item['installed'] ? 'Ready' : 'Missing';
		$title = $this->e($item['title']);
		$version = $item['version'] !== '' ? '<small>v' . $this->e($item['version']) . '</small>' : '';
		$action = $item['url'] !== ''
			? '<a class="jigsaw-action" href="' . $this->e($item['url']) . '">Open</a>'
			: '<span class="jigsaw-action is-disabled">Refresh modules</span>';

		return
			'<article class="jigsaw-card ' . $statusClass . '">' .
				'<div class="jigsaw-card-top"><span class="jigsaw-status">' . $statusLabel . '</span>' . $version . '</div>' .
				'<h3>' . $title . '</h3>' .
				'<p>' . $this->e($item['description']) . '</p>' .
				$action .
			'</article>';
	}

	private function moduleUrl(string $name, array $info): string {
		$admin = (string)$this->wire('config')->urls->admin;
		$page = $info['page'] ?? null;

		if (is_array($page) && !empty($page['name'])) {
			$parent = trim((string)($page['parent'] ?? ''), '/');
			$prefix = $parent !== '' && $parent !== 'admin' ? $parent . '/' : '';
			return $admin . $prefix . trim((string)$page['name'], '/') . '/';
		}

		return $admin . 'module/edit?name=' . rawurlencode($name);
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
