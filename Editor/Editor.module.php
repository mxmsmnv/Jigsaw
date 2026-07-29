<?php namespace ProcessWire;

/**
 * Editor
 *
 * File manager and template editor for ProcessWire.
 * Allows superusers to browse, create, edit and delete files
 * in /site/templates/ and optionally /site/modules/.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @link    https://smnv.org
 * @link    https://github.com/mxmsmnv/Editor
 * @copyright 2026 smnv.org
 * @license MIT
 *
 * ProcessWire 3.x
 */
#[AllowDynamicProperties]
class Editor extends Process implements Module, ConfigurableModule {

	// Config properties (PHP 8.2: typed to avoid dynamic property deprecation)
	// Defaults are defined in getDefaultConfig() — PW sets these from saved module config
	protected int    $allow_modules_dir  = 0;
	protected int    $allow_delete       = 1;
	protected int    $safe_delete        = 0;  // off by default until explicitly saved
	protected string $allowed_extensions = '';

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Editor',
			'version'  => 120,
			'summary'  => 'Browse and edit template files directly from the admin.',
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'icon'     => 'code',
			'requires' => ['ProcessWire>=3.0.0', 'PHP>=8.2'],
			'permission' => 'editor',
			'permissions' => [
				'editor' => 'Use Editor (superuser only)',
			],
			'page' => [
				'name'   => 'editor',
				'parent' => 'setup',
				'title'  => 'Editor',
			],
		];
	}

	// --- Default config ---------------------------------------------------

	public static function getDefaultConfig(): array {
		return [
			'allow_modules_dir'  => 0,
			'allow_delete'       => 1,
			'safe_delete'        => 1,
			'allowed_extensions' => 'php,js,css,html,htm,json,txt,md,svg,xml,htaccess,ini,jpg,jpeg,png,gif,webp,ico,woff,woff2,ttf,eot',
		];
	}

	// --- Init -------------------------------------------------------------

	public function init(): void {
		parent::init();

		// Merge saved config into typed properties
		$cfg = array_merge(self::getDefaultConfig(), (array) $this->wire('modules')->getModuleConfigData($this));
		$this->allow_modules_dir  = (int) ($cfg['allow_modules_dir'] ?? 0);
		$this->allow_delete       = (int) ($cfg['allow_delete']      ?? 1);
		$this->safe_delete        = (int) ($cfg['safe_delete']       ?? 1);
		$this->allowed_extensions = (string) ($cfg['allowed_extensions'] ?? '');

		// Only superusers may use this module
		if (!$this->wire('user')->isSuperuser()) {
			throw new WirePermissionException($this->_('Editor is restricted to superusers.'));
		}

		$v = $this->getModuleInfo()['version'];
		$base = $this->wire('config')->urls->get('Editor');
		$this->wire('config')->styles->add($base . 'Editor.css?v=' . $v);
		$this->wire('config')->scripts->add($base . 'Editor.js?v=' . $v);
	}

	// --- Execute (main page) ----------------------------------------------

	public function execute(): string {
		$this->headline($this->_('Editor'));
		$this->browserTitle($this->_('Editor'));

		$roots = $this->getRoots();

		$out = '';

		// Safe delete info notice
		if ($this->allow_delete && $this->safe_delete) {
			$trashPath = '/site/assets/cache/.editor-trash/';
			$out .= '<p class="uk-text-small uk-text-muted" style="margin-bottom:8px">';
			$out .= '<span uk-icon="icon:info"></span> ';
			$out .= $this->_('Safe delete is enabled — deleted files are moved to') . ' ';
			$out .= '<code>' . htmlspecialchars($trashPath) . '</code>';
			$out .= '</p>';
		}

		$out .= '<div id="fe-wrap">';
		$out .= '<div id="fe-sidebar">';
		$uploadIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="fe-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>';
		$out .= '<div id="fe-sidebar-actions">';
		$out .= '<button id="fe-btn-new" class="uk-button uk-button-default uk-button-small" title="' . $this->_('New file (Ctrl+N)') . '">';
		$out .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="fe-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg> ' . $this->_('New File');
		$out .= '</button>';
		$out .= '<button id="fe-btn-upload" class="uk-button uk-button-default uk-button-small" title="' . $this->_('Upload files') . '">';
		$out .= $uploadIcon . ' ' . $this->_('Upload');
		$out .= '</button>';
		$out .= '<input type="file" id="fe-upload-input" multiple style="display:none" accept="*">';
		$out .= '</div>';
		$out .= '<div id="fe-tree">' . $this->renderTree($roots) . '</div>';
		$out .= '</div>';
		$out .= '<div id="fe-main">';
		$out .= '<div id="fe-toolbar" class="fe-hidden">';
		$backIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" class="fe-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>';
		$out .= '<button id="fe-back" class="uk-button uk-button-default uk-button-small" title="' . $this->_('Back to files') . '">' . $backIcon . '</button>';
		$out .= '<span id="fe-filename"></span>';
		$out .= '<button id="fe-save" class="uk-button uk-button-primary uk-button-small">' . $this->_('Save') . '</button>';
		$out .= '<button id="fe-close" class="uk-button uk-button-default uk-button-small">' . $this->_('Close') . '</button>';
		$out .= '</div>';
		$out .= '<div id="fe-editor-wrap" class="fe-hidden"><textarea id="fe-editor"></textarea></div>';
		$out .= '<div id="fe-placeholder"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" class="fe-icon fe-placeholder-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5"/></svg><p>' . $this->_('Select a file to edit') . '</p></div>';
		$out .= '</div>';
		$out .= '</div>';

		// Pass config to JS
		$config = [
			'ajaxUrl'    => $this->wire('page')->url,
			'moduleUrl'  => $this->wire('config')->urls->get('Editor'),
			'roots'      => array_values($roots),
			'rootLabels' => array_keys($roots),
			'csrfName'   => $this->wire('session')->CSRF->getTokenName(),
			'csrfVal'    => $this->wire('session')->CSRF->getTokenValue(),
			'safeDelete' => (int) $this->safe_delete,
		];
		$out .= '<script>Editor.init(' . json_encode($config) . ');</script>';

		return $out;
	}

	// --- AJAX: list directory ---------------------------------------------

	public function executeList(): string {
		$this->assertAjax();
		$path = $this->sanitizePath($this->wire('input')->post('path'));
		$items = $this->getDirectoryItems($path);
		return $this->jsonResponse(['items' => $items]);
	}

	// --- AJAX: read file --------------------------------------------------

	public function executeRead(): string {
		$this->assertAjax();
		$path = $this->sanitizePath($this->wire('input')->post('path'));
		$this->assertReadable($path);

		$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$size = (int) filesize($path);

		// Binary files: return serve URL, no file content in JSON
		if ($this->isBinaryExtension($ext)) {
			$serveUrl = $this->wire('page')->url . 'serve/?path=' . urlencode($path) . '&t=' . time();
			return $this->jsonResponse([
				'type'      => 'binary',
				'extension' => $ext,
				'mime'      => $this->getMime($ext),
				'size'      => $size,
				'serveUrl'  => $serveUrl,
			]);
		}

		// Text file: return content
		$content = file_get_contents($path);
		if ($content === false) {
			return $this->jsonResponse(['error' => $this->_('Cannot read file.')]);
		}

		return $this->jsonResponse([
			'type'      => 'text',
			'content'   => $content,
			'extension' => $ext,
			'size'      => $size,
		]);
	}

	// --- Serve binary file ------------------------------------------------

	public function executeServe(): string {
		$path = $this->sanitizePath($this->wire('input')->get('path'));
		$this->assertReadable($path);

		$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$mime = $this->getMime($ext);

		while (ob_get_level()) ob_end_clean();
		header('Content-Type: ' . $mime);
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: private, max-age=300');
		header('X-Content-Type-Options: nosniff');
		readfile($path);
		exit;
	}

	// --- AJAX: save file --------------------------------------------------

	public function executeSave(): string {
		$this->assertAjax();
		$this->verifyCsrf();

		$path = $this->sanitizePath($this->wire('input')->post('path'));

		// Block saving binary files via crafted POST request
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($this->isBinaryExtension($ext)) {
			return $this->jsonResponse(['error' => $this->_('Cannot overwrite binary files.')]);
		}

		$this->assertWritable($path);

		// Raw $_POST — PW sanitizers strip HTML tags which corrupts template/PHP code
		$content = isset($_POST['content']) ? $_POST['content'] : '';

		if (file_put_contents($path, $content) === false) {
			return $this->jsonResponse(['error' => $this->_('Cannot write file.')]);
		}

		$this->wire('log')->save('editor', "Saved: $path");
		return $this->jsonResponse(['ok' => true]);
	}

	// --- AJAX: upload files -----------------------------------------------

	public function executeUpload(): string {
		$this->assertAjax();
		$this->verifyCsrf();

		$dir = $this->sanitizePath($this->wire('input')->post('dir'));
		$this->assertDirAllowed($dir);

		if (empty($_FILES['files'])) {
			return $this->jsonResponse(['error' => $this->_('No files received.')]);
		}

		$results = [];
		$files   = $_FILES['files'];

		// Normalize to array (single vs multiple)
		$count = is_array($files['name']) ? count($files['name']) : 1;
		for ($i = 0; $i < $count; $i++) {
			$name  = is_array($files['name'])  ? $files['name'][$i]     : $files['name'];
			$tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
			$error = is_array($files['error']) ? $files['error'][$i]    : $files['error'];

			if ($error !== UPLOAD_ERR_OK) {
				$errMsg = match($error) {
					UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
					UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
					UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
					UPLOAD_ERR_NO_FILE    => 'No file uploaded',
					UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
					UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
					default               => 'Upload error code: ' . $error,
				};
				$results[] = ['name' => $name, 'error' => $errMsg];
				continue;
			}

			$name = basename($name);
			$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

			if (!$this->isAllowedExtension($ext)) {
				$results[] = ['name' => $name, 'error' => $this->_('Extension not allowed: .') . $ext];
				continue;
			}

			$dest = $dir . DIRECTORY_SEPARATOR . $name;

			// If file exists, add numeric suffix
			if (file_exists($dest)) {
				$base   = pathinfo($name, PATHINFO_FILENAME);
				$suffix = 1;
				do {
					$newName = $base . '_' . $suffix . ($ext ? '.' . $ext : '');
					$dest    = $dir . DIRECTORY_SEPARATOR . $newName;
					$suffix++;
				} while (file_exists($dest));
				$name = $newName;
			}

			if (!move_uploaded_file($tmp, $dest)) {
				$results[] = ['name' => $name, 'error' => $this->_('Could not save file.')];
				continue;
			}

			$this->wire('log')->save('editor', "Uploaded: $dest");
			$results[] = ['name' => $name, 'path' => $dest, 'ok' => true];
		}

		return $this->jsonResponse(['results' => $results]);
	}

	// --- AJAX: create file ------------------------------------------------

	public function executeCreate(): string {
		$this->assertAjax();
		$this->verifyCsrf();

		$dir  = $this->sanitizePath($this->wire('input')->post('dir'));
		$name = basename(str_replace(chr(0), '', (string) $this->wire('input')->post('name', 'filename')));
		$type = $this->wire('input')->post('type') === 'dir' ? 'dir' : 'file';

		$this->assertDirAllowed($dir);

		$target = $dir . DIRECTORY_SEPARATOR . $name;

		if (file_exists($target)) {
			return $this->jsonResponse(['error' => $this->_('Already exists.')]);
		}

		if ($type === 'dir') {
			if (!mkdir($target, 0755)) {
				return $this->jsonResponse(['error' => $this->_('Cannot create directory.')]);
			}
		} else {
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!$this->isAllowedExtension($ext)) {
				return $this->jsonResponse(['error' => $this->_('Extension not allowed.')]);
			}
			if (file_put_contents($target, '') === false) {
				return $this->jsonResponse(['error' => $this->_('Cannot create file.')]);
			}
		}

		$this->wire('log')->save('editor', "Created $type: $target");
		return $this->jsonResponse(['ok' => true, 'path' => $target]);
	}

	// --- AJAX: rename -----------------------------------------------------

	public function executeRename(): string {
		$this->assertAjax();
		$this->verifyCsrf();

		$path    = $this->sanitizePath($this->wire('input')->post('path'));
		$newName = basename(str_replace(chr(0), '', (string) $this->wire('input')->post('name', 'filename')));
		$newPath = dirname($path) . DIRECTORY_SEPARATOR . $newName;

		$this->assertDirAllowed(dirname($path));

		if (!file_exists($path)) {
			return $this->jsonResponse(['error' => $this->_('Source not found.')]);
		}
		if (file_exists($newPath)) {
			return $this->jsonResponse(['error' => $this->_('Target already exists.')]);
		}

		if (!rename($path, $newPath)) {
			return $this->jsonResponse(['error' => $this->_('Cannot rename.')]);
		}

		$this->wire('log')->save('editor', "Renamed: $path -> $newPath");
		return $this->jsonResponse(['ok' => true, 'path' => $newPath]);
	}

	// --- AJAX: delete -----------------------------------------------------

	public function executeDelete(): string {
		$this->assertAjax();
		$this->verifyCsrf();

		if (!$this->allow_delete) {
			return $this->jsonResponse(['error' => $this->_('Delete is disabled.')]);
		}

		$path = $this->sanitizePath($this->wire('input')->post('path'));
		$this->assertWritable($path);

		if ($this->safe_delete) {
			$trashBase = $this->wire('config')->paths->assets . 'cache' . DIRECTORY_SEPARATOR . '.editor-trash';
			if (!is_dir($trashBase) && !mkdir($trashBase, 0755, true)) {
				return $this->jsonResponse(['error' => $this->_('Cannot create trash directory.')]);
			}

			$rel = $path;
			foreach ($this->getRoots() as $root) {
				$root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
				if (strpos($path, $root) === 0) {
					$rel = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
					break;
				}
			}

			$ts      = date('Ymd_His');
			$dest    = $trashBase . DIRECTORY_SEPARATOR . $ts . DIRECTORY_SEPARATOR . $rel;
			$destDir = dirname($dest);
			if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
					return $this->jsonResponse(['error' => $this->_('Cannot create trash subdirectory.')]);
				}

			// rename() fails across filesystems — fall back to copy+delete
			if (!@rename($path, $dest)) {
				if (is_file($path)) {
					if (!copy($path, $dest) || !unlink($path)) {
						return $this->jsonResponse(['error' => $this->_('Cannot move to trash.')]);
					}
				} else {
					return $this->jsonResponse(['error' => $this->_('Cannot move directory to trash (cross-device).')]);
				}
			}

			$this->wire('log')->save('editor', "Trashed: $path -> $dest");
			return $this->jsonResponse(['ok' => true, 'trashed' => true]);
		}

		if (is_dir($path)) {
			if (!$this->rrmdir($path)) {
				return $this->jsonResponse(['error' => $this->_('Cannot delete directory.')]);
			}
		} else {
			if (!unlink($path)) {
				return $this->jsonResponse(['error' => $this->_('Cannot delete file.')]);
			}
		}

		$this->wire('log')->save('editor', "Deleted: $path");
		return $this->jsonResponse(['ok' => true]);
	}

	// --- Tree rendering ---------------------------------------------------

	protected function renderTree(array $roots): string {
		$out = '<ul class="fe-tree-root">';
		foreach ($roots as $label => $absPath) {
			$out .= '<li class="fe-dir fe-root" data-path="' . htmlspecialchars($absPath) . '">';
			$out .= '<span class="fe-dir-label"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" class="fe-icon fe-item-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg> ' . htmlspecialchars($label) . '</span>';
			$out .= '<ul class="fe-children" style="display:none"></ul>';
			$out .= '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	// --- Protected paths -----------------------------------------------

	protected function getProtectedPaths(): array {
		$paths = [];

		// Always hide the Editor module directory itself
		$selfDir = realpath(dirname(__FILE__));
		if ($selfDir) $paths[] = $selfDir;

		return $paths;
	}

	// --- Directory listing -----------------------------------------------

	protected function getDirectoryItems(string $dir): array {
		$this->assertDirAllowed($dir);

		$items = [];
		$entries = scandir($dir);
		if ($entries === false) return $items;

		$protected = $this->getProtectedPaths();

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			if (substr($entry, 0, 1) === '.' && !$this->wire('config')->debug) continue;

			$fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
			$isDir    = is_dir($fullPath);
			$ext      = $isDir ? '' : strtolower(pathinfo($entry, PATHINFO_EXTENSION));

			// Never expose the Editor module itself or other protected paths
			if (in_array(realpath($fullPath), $protected, true)) continue;

			if (!$isDir && !$this->isAllowedExtension($ext)) continue;

			$items[] = [
				'name'  => $entry,
				'path'  => $fullPath,
				'isDir' => $isDir,
				'ext'   => $ext,
				'size'   => $isDir ? null : filesize($fullPath),
				'mtime'  => filemtime($fullPath),
				'binary'  => $isDir ? false : $this->isBinaryExtension($ext),
				'isImage' => $isDir ? false : $this->isImageExtension($ext),
			];
		}

		usort($items, function($a, $b) {
			if ($a['isDir'] !== $b['isDir']) return $b['isDir'] - $a['isDir'];
			return strcasecmp($a['name'], $b['name']);
		});

		return $items;
	}

	// --- Security helpers ------------------------------------------------

	protected function getRoots(): array {
		$roots = [
			'templates' => $this->wire('config')->paths->templates,
		];
		if ($this->allow_modules_dir) {
			$roots['site/modules'] = $this->wire('config')->paths->siteModules;
		}
		return $roots;
	}

	protected function sanitizePath(string $raw): string {
		// Normalize and resolve
		$path = realpath(trim($raw));
		if ($path === false) {
			// Path may not exist yet (new file create) — normalize manually
			$path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($raw));
			$path = rtrim($path, DIRECTORY_SEPARATOR);
		}
		$this->assertPathAllowed($path);
		return $path;
	}

	protected function assertPathAllowed(string $path): void {
		$realPath = realpath($path) ?: $path;

		// Block access to the module's own directory at all times
		foreach ($this->getProtectedPaths() as $protected) {
			if (strpos($realPath, $protected) === 0) {
				throw new WirePermissionException('Access to this path is not allowed.');
			}
		}

		$allowed = false;
		foreach ($this->getRoots() as $root) {
			$root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
			if (strpos($realPath, $root) === 0) {
				$allowed = true;
				break;
			}
		}
		if (!$allowed) throw new WirePermissionException('Path not allowed: ' . $path);
	}

	protected function assertDirAllowed(string $dir): void {
		$this->assertPathAllowed($dir);
		if (!is_dir($dir)) throw new WireException('Not a directory: ' . $dir);
	}

	protected function assertReadable(string $path): void {
		$this->assertPathAllowed($path);
		if (!is_file($path)) throw new WireException('Not a file: ' . $path);
	}

	protected function assertWritable(string $path): void {
		$this->assertPathAllowed($path);
		if (!file_exists($path)) throw new WireException($this->_('Path does not exist: ') . basename($path));
		if (!is_writable($path)) throw new WireException($this->_('File is not writable: ') . basename($path));
	}

	protected function assertAjax(): void {
		// Non-AJAX requests to AJAX endpoints: return JSON error and exit
		if (!$this->wire('config')->ajax) {
			while (ob_get_level()) ob_end_clean();
			header('Content-Type: application/json');
			die(json_encode(['error' => 'AJAX only']));
		}
	}

	protected function verifyCsrf(): void {
		$this->wire('session')->CSRF->validate();
	}

	protected function isBinaryExtension(string $ext): bool {
		$binary = ['jpg','jpeg','png','gif','webp','ico','bmp','tiff','tif','avif','heic',
				'woff','woff2','ttf','eot','otf','pdf','zip','gz','tar'];
		return in_array($ext, $binary, true);
	}

	protected function isImageExtension(string $ext): bool {
		$images = ['jpg','jpeg','png','gif','webp','ico','bmp','tiff','tif','avif','heic','svg'];
		return in_array($ext, $images, true);
	}

	protected function getMime(string $ext): string {
		$map = [
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
			'gif' => 'image/gif',  'webp' => 'image/webp', 'ico'  => 'image/x-icon',
			'bmp' => 'image/bmp',  'tiff' => 'image/tiff', 'tif'  => 'image/tiff',
			'svg' => 'image/svg+xml',
			'woff' => 'font/woff', 'woff2' => 'font/woff2',
			'ttf'  => 'font/ttf',  'eot'   => 'application/vnd.ms-fontobject',
			'pdf'  => 'application/pdf',
			'zip'  => 'application/zip',
		];
		return $map[$ext] ?? 'application/octet-stream';
	}

	protected function isAllowedExtension(string $ext): bool {
		if ($ext === '') return true;
		$allowed = array_map('trim', explode(',', strtolower($this->allowed_extensions)));
		return in_array($ext, $allowed, true);
	}

	// --- Helpers ---------------------------------------------------------

	protected function jsonResponse(array $data): string {
		while (ob_get_level()) ob_end_clean();
		header('Content-Type: application/json');
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			$json = json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
		}
		die($json);
	}

	protected function rrmdir(string $dir): bool {
		if (!is_dir($dir)) return false;
		$items = scandir($dir);
		if ($items === false) return false;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				$this->rrmdir($path);
			} else {
				unlink($path);
			}
		}
		return rmdir($dir);
	}

	// --- Module config ---------------------------------------------------

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules  = wire('modules');
		$defaults = self::getDefaultConfig();
		$data     = array_merge($defaults, $data);
		$fields   = new InputfieldWrapper();

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allow_modules_dir');
		$f->label = __('Allow browsing site/modules/');
		$f->description = __('Enables the site/modules/ directory in the file tree. Use with caution.');
		$f->attr('checked', $data['allow_modules_dir'] ? 'checked' : '');
		$fields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allow_delete');
		$f->label = __('Allow file and directory deletion');
		$f->attr('checked', $data['allow_delete'] ? 'checked' : '');
		$fields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'safe_delete');
		$f->label = __('Safe delete (move to trash instead of permanent delete)');
		$f->description = __('Moves deleted files/folders to /site/assets/cache/.editor-trash/YYYYMMDD_HHMMSS/ — each delete gets its own timestamped folder, nothing is ever overwritten. Disable for permanent deletion.');
		$f->attr('checked', $data['safe_delete'] ? 'checked' : '');
		$f->showIf = 'allow_delete=1';
		$fields->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'allowed_extensions');
		$f->label = __('Allowed file extensions');
		$f->description = __('Comma-separated list of extensions that may be opened/created.');
		$f->attr('value', $data['allowed_extensions']);
		$fields->add($f);

		return $fields;
	}

	// --- Install / Uninstall ---------------------------------------------

	public function install(): void {
		$pages = $this->wire('pages');

		// Find the Setup parent page
		$parent = $pages->get('name=setup, template=admin');
		if (!$parent->id) {
			// Fallback: any admin page
			$parent = $pages->get('template=admin, name=admin');
		}

		// Check if page already exists
		$existing = $pages->get('name=editor, template=admin');
		if ($existing->id) return;

		$page = new Page();
		$page->template  = 'admin';
		$page->parent    = $parent;
		$page->name      = 'editor';
		$page->title     = 'Editor';
		$page->process   = $this;
		$page->status    = Page::statusOn;
		$page->save();
	}

	public function uninstall(): void {
		$page = $this->wire('pages')->get('name=editor, template=admin');
		if ($page->id) $this->wire('pages')->delete($page);
	}
}
