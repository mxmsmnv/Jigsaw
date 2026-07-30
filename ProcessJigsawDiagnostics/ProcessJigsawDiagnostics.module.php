<?php namespace ProcessWire;

/**
 * ProcessJigsawDiagnostics
 *
 * Read-only ProcessWire site diagnostics.
 *
 * @author Maxim Semenov <maxim@smnv.org>
 * @version 1.0.0
 */
class ProcessJigsawDiagnostics extends Process implements Module {

	private const PAGE_LIMIT = 10000;
	private const RESULT_LIMIT = 200;
	private bool $scanTruncated = false;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Jigsaw Diagnostics',
			'summary' => 'Read-only page tree, duplicate-title and URL diagnostics.',
			'version' => 100,
			'author' => 'Maxim Semenov',
			'href' => 'https://smnv.org',
			'icon' => 'stethoscope',
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.2', 'Jigsaw'],
			'page' => [
				'name' => 'jigsaw-diagnostics',
				'parent' => 'setup',
				'title' => 'Jigsaw Diagnostics',
			],
			'permission' => 'jigsaw-diagnostics',
			'permissions' => [
				'jigsaw-diagnostics' => 'View read-only Jigsaw diagnostics',
			],
			'singular' => true,
			'autoload' => false,
		];
	}

	public function ___execute(): string {
		$this->setTitles('Overview');
		$pages = $this->loadPages();
		$counts = [
			'Pages scanned' => count($pages),
			'Templates' => count($this->wire('templates')),
			'Fields' => count($this->wire('fields')),
			'Hidden' => 0,
			'Unpublished' => 0,
			'Trashed' => 0,
		];

		foreach ($pages as $page) {
			if ($page->isHidden()) $counts['Hidden']++;
			if ($page->isUnpublished()) $counts['Unpublished']++;
			if ($page->isTrash()) $counts['Trashed']++;
		}

		$out = $this->renderNav('overview');
		$out .= $this->renderNotice();
		$out .= '<div class="jdiag-stats">';
		foreach ($counts as $label => $value) {
			$out .= '<div><span>' . $this->e($label) . '</span><strong>' . number_format((int)$value) . '</strong></div>';
		}
		$out .= '</div>';
		$out .= '<div class="uk-card uk-card-default uk-card-body">';
		$out .= '<h2 class="uk-card-title">Safe diagnostics</h2>';
		$out .= '<p>This component only reads ProcessWire pages and metadata. It has no save, delete, cleanup, API, or filesystem actions.</p>';
		$out .= '<ul class="uk-list uk-list-bullet">';
		$out .= '<li><strong>Page tree</strong> shows hierarchy, templates, status and edit links.</li>';
		$out .= '<li><strong>Duplicate titles</strong> groups exact titles after case and whitespace normalization.</li>';
		$out .= '<li><strong>URL anomalies</strong> finds page names ending in digits when the title does not.</li>';
		$out .= '</ul></div>';
		return $out . $this->styles();
	}

	public function ___executeTree(): string {
		$this->setTitles('Page tree');
		$pages = $this->loadPages();
		$children = [];
		$known = [];

		foreach ($pages as $page) {
			$known[(int)$page->id] = true;
			$children[(int)$page->parent_id][] = $page;
		}

		$roots = [];
		foreach ($pages as $page) {
			$parentId = (int)$page->parent_id;
			if ($parentId === 0 || !isset($known[$parentId])) $roots[] = $page;
		}

		$rows = '';
		$visited = [];
		foreach ($roots as $root) {
			$rows .= $this->renderTreeRows($root, $children, $visited, 0);
		}

		$out = $this->renderNav('tree') . $this->renderNotice();
		$out .= '<div class="uk-overflow-auto"><table class="uk-table uk-table-small uk-table-divider uk-table-hover">';
		$out .= '<thead><tr><th>Page</th><th>Template</th><th>Status</th><th class="uk-text-right">ID</th></tr></thead>';
		$out .= '<tbody>' . $rows . '</tbody></table></div>';
		return $out . $this->styles();
	}

	public function ___executeDuplicates(): string {
		$this->setTitles('Duplicate titles');
		$pages = $this->loadPages();
		$groups = [];

		foreach ($pages as $page) {
			$title = trim((string)$page->title);
			if ($title === '') continue;
			$key = $this->normalizeTitle($title);
			$groups[$key][] = $page;
		}

		$groups = array_filter($groups, static fn(array $items): bool => count($items) > 1);
		uasort($groups, static fn(array $a, array $b): int => count($b) <=> count($a));
		$totalGroups = count($groups);
		$groups = array_slice($groups, 0, self::RESULT_LIMIT, true);

		$out = $this->renderNav('duplicates') . $this->renderNotice();
		$out .= '<p><strong>' . number_format($totalGroups) . '</strong> duplicate-title groups found.';
		if ($totalGroups > self::RESULT_LIMIT) {
			$out .= ' Showing the first ' . self::RESULT_LIMIT . '.';
		}
		$out .= '</p>';

		if (!$groups) {
			$out .= $this->emptyState('No exact duplicate titles found.');
			return $out . $this->styles();
		}

		foreach ($groups as $items) {
			$out .= '<section class="uk-card uk-card-default uk-card-body uk-margin">';
			$out .= '<h3 class="uk-card-title">' . $this->e((string)$items[0]->title);
			$out .= ' <span class="uk-badge">' . count($items) . '</span></h3>';
			$out .= '<ul class="uk-list uk-list-divider">';
			foreach ($items as $page) {
				$out .= '<li>' . $this->pageLink($page);
				$out .= ' <span class="uk-text-meta">' . $this->e($page->path) . ' · ' . $this->e($page->template->name) . ' · ID ' . (int)$page->id . '</span></li>';
			}
			$out .= '</ul></section>';
		}

		return $out . $this->styles();
	}

	public function ___executeUrls(): string {
		$this->setTitles('URL anomalies');
		$pages = $this->loadPages();
		$matches = [];

		foreach ($pages as $page) {
			$name = (string)$page->name;
			$title = trim((string)$page->title);
			if ($name === '' || !preg_match('/\d+$/', $name)) continue;
			if ($title !== '' && preg_match('/\d{1,3}$/u', $title)) continue;
			$matches[] = $page;
		}

		$total = count($matches);
		$matches = array_slice($matches, 0, self::RESULT_LIMIT);
		$out = $this->renderNav('urls') . $this->renderNotice();
		$out .= '<p><strong>' . number_format($total) . '</strong> numeric URL suffixes need review.';
		if ($total > self::RESULT_LIMIT) {
			$out .= ' Showing the first ' . self::RESULT_LIMIT . '.';
		}
		$out .= '</p>';

		if (!$matches) {
			$out .= $this->emptyState('No suspicious numeric URL suffixes found.');
			return $out . $this->styles();
		}

		$out .= '<div class="uk-overflow-auto"><table class="uk-table uk-table-small uk-table-divider uk-table-hover">';
		$out .= '<thead><tr><th>Page</th><th>Name</th><th>Path</th><th>Template</th><th class="uk-text-right">ID</th></tr></thead><tbody>';
		foreach ($matches as $page) {
			$out .= '<tr><td>' . $this->pageLink($page) . '</td>';
			$out .= '<td><code>' . $this->e($page->name) . '</code></td>';
			$out .= '<td class="uk-text-meta">' . $this->e($page->path) . '</td>';
			$out .= '<td>' . $this->e($page->template->name) . '</td>';
			$out .= '<td class="uk-text-right">' . (int)$page->id . '</td></tr>';
		}
		$out .= '</tbody></table></div>';
		return $out . $this->styles();
	}

	private function loadPages(): array {
		$items = [];
		$result = $this->wire('pages')->find(
			'template!=admin, include=all, sort=parent_id, sort=sort, limit=' . (self::PAGE_LIMIT + 1)
		);
		foreach ($result as $page) {
			if (count($items) >= self::PAGE_LIMIT) {
				$this->scanTruncated = true;
				break;
			}
			$items[] = $page;
		}
		return $items;
	}

	private function renderTreeRows(Page $page, array $children, array &$visited, int $depth): string {
		$id = (int)$page->id;
		if (isset($visited[$id]) || $depth > 50) return '';
		$visited[$id] = true;

		$status = [];
		if ($page->isTrash()) $status[] = 'Trashed';
		if ($page->isUnpublished()) $status[] = 'Unpublished';
		if ($page->isHidden()) $status[] = 'Hidden';
		if (!$status) $status[] = 'Published';

		$out = '<tr><td><span class="jdiag-depth" style="--depth:' . $depth . '">';
		$out .= $this->pageLink($page) . '</span></td>';
		$out .= '<td>' . $this->e($page->template->name) . '</td>';
		$out .= '<td>' . $this->e(implode(', ', array_unique($status))) . '</td>';
		$out .= '<td class="uk-text-right">' . $id . '</td></tr>';

		foreach ($children[$id] ?? [] as $child) {
			$out .= $this->renderTreeRows($child, $children, $visited, $depth + 1);
		}
		return $out;
	}

	private function normalizeTitle(string $title): string {
		$title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
		return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
	}

	private function pageLink(Page $page): string {
		$title = trim((string)$page->title);
		if ($title === '') $title = $page->name !== '' ? $page->name : 'Page ' . (int)$page->id;
		return '<a href="' . $this->e($page->editUrl) . '">' . $this->e($title) . '</a>';
	}

	private function renderNav(string $active): string {
		$base = rtrim((string)$this->page->url, '/') . '/';
		$items = [
			'overview' => ['Overview', $base],
			'tree' => ['Page tree', $base . 'tree/'],
			'duplicates' => ['Duplicate titles', $base . 'duplicates/'],
			'urls' => ['URL anomalies', $base . 'urls/'],
		];

		$out = '<ul class="uk-tab uk-margin-medium-bottom">';
		foreach ($items as $key => [$label, $url]) {
			$class = $key === $active ? ' class="uk-active"' : '';
			$out .= '<li' . $class . '><a href="' . $this->e($url) . '">' . $this->e($label) . '</a></li>';
		}
		return $out . '</ul>';
	}

	private function renderNotice(): string {
		if (!$this->scanTruncated) return '';
		return '<div class="uk-alert-warning" uk-alert>Only the first ' . number_format(self::PAGE_LIMIT) . ' pages were scanned.</div>';
	}

	private function emptyState(string $message): string {
		return '<div class="uk-placeholder uk-text-center">' . $this->e($message) . '</div>';
	}

	private function setTitles(string $section): void {
		$this->headline('Jigsaw Diagnostics');
		$this->browserTitle($section . ' · Jigsaw Diagnostics');
	}

	private function e(string $value): string {
		return (string)$this->wire('sanitizer')->entities($value);
	}

	private function styles(): string {
		return <<<'HTML'
<style>
.jdiag-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;margin:0 0 1.5rem;background:#d8dee8;border:1px solid #d8dee8;border-radius:10px;overflow:hidden}.jdiag-stats div{padding:1rem;background:#fff}.jdiag-stats span,.jdiag-stats strong{display:block}.jdiag-stats span{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#687387}.jdiag-stats strong{margin-top:.2rem;font-size:1.5rem}.jdiag-depth{display:inline-block;padding-left:calc(var(--depth) * 1.25rem)}@media(max-width:650px){.jdiag-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
HTML;
	}
}
