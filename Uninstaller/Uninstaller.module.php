<?php namespace ProcessWire;

/**
 * Uninstaller
 *
 * Safely uninstall a module along with its associated submodules, fields,
 * templates, and directories — all from a single admin UI.
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 * @version 100
 */
class Uninstaller extends Process implements Module {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Uninstaller',
            'version'  => 110,
            'summary'  => 'Uninstall a module together with its submodules, fields, templates and directories.',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon'     => 'trash',
            'singular' => true,
            'autoload' => false,
            'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1'],
            'page'     => [
                'name'   => 'uninstaller',
                'parent' => 'setup',
                'title'  => 'Uninstaller',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Execute
    // -------------------------------------------------------------------------

    public function execute(): string {
        $this->wire('modules')->get('JqueryUI')->use('modal');
        $this->browserTitle($this->_('Uninstaller'));
        $this->headline($this->_('Uninstaller'));

        if ($this->wire('input')->post('action') === 'scan') {
            return $this->executeScan();
        }

        if ($this->wire('input')->post('action') === 'uninstall') {
            return $this->executeUninstall();
        }

        return $this->renderSelectForm();
    }

    // -------------------------------------------------------------------------
    // Step 1 — Select module (grouped by folder)
    // -------------------------------------------------------------------------

    /**
     * Build a map: folder => [moduleName => info]
     * Folder is the module directory relative to site/modules/.
     * If the module file lives directly in site/modules/ (rare), folder = ''.
     */
    protected function buildModuleGroups(): array {
        $siteModules = $this->wire('config')->paths->siteModules;
        $wireModules = $this->wire('config')->paths->modules;
        $selfName    = $this->className();
        $realCore    = realpath($wireModules) ?: $wireModules;
        $realModules = realpath($siteModules) ?: $siteModules;
        $groups      = [];

        foreach ($this->wire('modules') as $module) {
            $name = $module->className();
            if ($name === $selfName) continue;

            $file     = $this->wire('modules')->getModuleFile($name);
            $realFile = $file ? (realpath($file) ?: $file) : '';

            // Skip core modules (wire/modules/)
            if ($realFile && str_starts_with($realFile, $realCore)) continue;

            $info = ['name' => $name, 'title' => '', 'version' => '', 'summary' => '', 'folder' => ''];

            try {
                // getModuleInfo is fast (uses cache); getModuleInfoVerbose reads files — too slow for 200+ modules
                $i = $this->wire('modules')->getModuleInfo($name);
                $info['title']   = !empty($i['title'])   ? $i['title']   : '';
                $info['version'] = !empty($i['version'])  ? $i['version']  : '';
                $info['summary'] = !empty($i['summary'])  ? $i['summary']  : '';
            } catch (\Throwable) {}

            // Detect folder
            $folder = '';
            if ($realFile) {
                $rel    = ltrim(str_replace($realModules, '', $realFile), '/\\');
                $folder = dirname(str_replace('\\', '/', $rel));
                if ($folder === '.') $folder = '';
            }
            $info['folder'] = $folder ?: $name;

            $groups[$info['folder']][] = $info;
        }

        ksort($groups);
        foreach ($groups as &$mods) {
            usort($mods, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        return $groups;
    }

    protected function renderSelectForm(): string {
        $groups = $this->buildModuleGroups();
        $csrf   = $this->wire('session')->CSRF->renderInput();

        $totalModules    = 0;
        $totalRealGroups = 0;
        foreach ($groups as $mods) {
            $totalModules += count($mods);
            if (count($mods) > 1) $totalRealGroups++;
        }

        $tbody = '';
        $gid   = 0;
        foreach ($groups as $folder => $mods) {
            $isGroup   = count($mods) > 1;
            $folderEsc = $this->wire('sanitizer')->entities($folder);
            $modNames  = array_column($mods, 'name');
            sort($modNames);
            $searchData = htmlspecialchars(strtolower(implode(' ', $modNames) . ' ' . implode(' ', array_column($mods, 'summary'))), ENT_QUOTES);

            if ($isGroup) {
                $gid++;
                $count = count($mods);
                // Pill badges for first 4 module names
                $pills = '';
                foreach (array_slice($mods, 0, 4) as $m) {
                    $pills .= '<span class="wu-pill">' . $this->wire('sanitizer')->entities($m['name']) . '</span>';
                }
                if ($count > 4) {
                    $pills .= '<span class="wu-pill wu-pill-more">+' . ($count - 4) . '</span>';
                }

                $tbody .= <<<HTML
                <tr class="wu-group-header wu-row" data-search="{$searchData}" data-gid="{$gid}">
                    <td style="width:32px;text-align:center;vertical-align:middle">
                        <i class="fa fa-chevron-right wu-chevron" style="font-size:11px;transition:transform .2s"></i>
                    </td>
                    <td style="vertical-align:middle;cursor:pointer" class="wu-group-toggle" data-gid="{$gid}">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <strong style="font-size:1em">{$folderEsc}</strong>
                            <span style="opacity:.75;font-size:.82em;font-weight:normal">{$count} {$this->_('modules')}</span>
                            <span class="wu-pills" style="display:flex;gap:4px;flex-wrap:wrap">{$pills}</span>
                        </div>
                    </td>
                    <td style="vertical-align:middle"></td>
                    <td style="width:130px;text-align:right;vertical-align:middle">
                        <button type="submit" name="module_name" value="{$folderEsc}"
                            class="uk-button uk-button-small wu-scan-btn">
                            <i class="fa fa-search"></i> {$this->_('Scan group')}
                        </button>
                    </td>
                </tr>
                HTML;

                $lastIdx = count($mods) - 1;
                foreach ($mods as $idx => $m) {
                    $tbody .= $this->renderModuleRow($m, true, $idx === $lastIdx, $gid);
                }
            } else {
                $tbody .= $this->renderModuleRow($mods[0], false);
            }
        }

        $labelSearch  = htmlspecialchars($this->_('Filter modules…'), ENT_QUOTES);
        $labelModules = $this->_('modules');
        $labelGroups  = $this->_('groups');

        return <<<HTML
        <style>
        .wu-row.wu-hidden { display:none; }

        /* Group header */
        .wu-group-header { background:var(--pw-main-color) !important; cursor:default; }
        .wu-group-header td { color:#fff !important; padding-top:9px !important; padding-bottom:9px !important; border-color:rgba(0,0,0,.1) !important; }
        .wu-group-header td * { color:#fff !important; }
        .wu-group-toggle { cursor:pointer; user-select:none; }
        .wu-group-header .wu-scan-btn { background:rgba(255,255,255,.15) !important; color:#fff !important; border-color:rgba(255,255,255,.3) !important; }
        .wu-group-header .wu-scan-btn:hover { background:rgba(255,255,255,.3) !important; }
        .wu-chevron { display:inline-block; }
        .wu-group-open .wu-chevron { transform:rotate(90deg); }

        /* Module pills in collapsed group header */
        .wu-pill { display:inline-block; background:rgba(255,255,255,.18); color:#fff; font-size:.75em; font-family:monospace; padding:1px 7px; border-radius:3px; white-space:nowrap; }
        .wu-pill-more { background:rgba(255,255,255,.1); opacity:.8; }

        /* Sub-rows (collapsed by default) */
        .wu-sub-row { display:none; }
        .wu-sub-row.wu-sub-visible { display:table-row; }
        .wu-sub-row td { background:var(--pw-blocks-background); }
        .wu-sub-row td:first-child { padding-left:14px !important; }
        .wu-sub-last td { border-bottom:1px solid var(--pw-border-color) !important; }

        /* Flat single rows */
        .wu-scan-btn { white-space:nowrap; }
        #wu-table th { color:var(--pw-muted-color); font-size:.78em; text-transform:uppercase; letter-spacing:.06em; }
        </style>

        <form method="post" action="./" id="wu-form">
            <input type="hidden" name="action" value="scan">
            {$csrf}

            <div class="uk-flex uk-flex-middle" style="gap:16px;margin-bottom:16px">
                <div class="uk-search uk-search-default">
                    <span uk-search-icon></span>
                    <input id="wu-filter" class="uk-search-input" type="search"
                        placeholder="{$labelSearch}" autocomplete="off" style="min-width:260px">
                </div>
                <span id="wu-count" class="uk-text-small uk-text-muted uk-margin-auto-left">
                    {$totalModules} {$labelModules} &middot; {$totalRealGroups} {$labelGroups}
                </span>
            </div>

            <table id="wu-table" class="uk-table uk-table-divider uk-table-small uk-table-hover" style="margin-top:0">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>{$this->_('Module / Summary')}</th>
                        <th style="width:90px">{$this->_('Version')}</th>
                        <th style="width:130px"></th>
                    </tr>
                </thead>
                <tbody id="wu-tbody">
                    {$tbody}
                </tbody>
            </table>
        </form>

        <script>
        (function() {
            var filter  = document.getElementById('wu-filter');
            var count   = document.getElementById('wu-count');
            var total   = {$totalModules};
            var groups  = {$totalRealGroups};

            // Toggle group open/close
            document.querySelectorAll('.wu-group-toggle').forEach(function(cell) {
                cell.addEventListener('click', function() {
                    var gid    = this.dataset.gid;
                    var header = this.closest('tr');
                    var isOpen = header.classList.toggle('wu-group-open');
                    document.querySelectorAll('.wu-sub-row[data-gid="' + gid + '"]').forEach(function(r) {
                        r.classList.toggle('wu-sub-visible', isOpen);
                    });
                    var pills = header.querySelector('.wu-pills');
                    if (pills) pills.style.display = isOpen ? 'none' : 'flex';
                });
            });

            // Filter
            filter.addEventListener('input', function() {
                var q = this.value.trim().toLowerCase();

                if (!q) {
                    // Restore: show all headers and flat rows, collapse sub-rows unless manually opened
                    document.querySelectorAll('#wu-tbody tr').forEach(function(r) {
                        r.classList.remove('wu-hidden');
                        if (r.classList.contains('wu-sub-row')) {
                            var gid    = r.dataset.gid;
                            var header = document.querySelector('.wu-group-header[data-gid="' + gid + '"]');
                            var manuallyOpen = header && header.classList.contains('wu-group-open') && !header.dataset.filterOpened;
                            if (!manuallyOpen) {
                                r.classList.remove('wu-sub-visible');
                            }
                            if (header) delete header.dataset.filterOpened;
                        }
                    });
                    count.innerHTML = total + ' {$labelModules} &middot; ' + groups + ' {$labelGroups}';
                    return;
                }

                // Hide all
                document.querySelectorAll('#wu-tbody tr').forEach(function(r) {
                    r.classList.add('wu-hidden');
                    // Only remove wu-sub-visible from sub-rows, not flat rows
                    if (r.classList.contains('wu-sub-row')) r.classList.remove('wu-sub-visible');
                });

                var visibleMods = 0;
                var openGroups  = new Set();

                // Match flat rows (no gid) and sub-rows
                document.querySelectorAll('.wu-row:not(.wu-group-header)').forEach(function(r) {
                    var hay = (r.dataset.search || r.dataset.name || '').toLowerCase();
                    if (!hay.includes(q)) return;
                    r.classList.remove('wu-hidden');
                    visibleMods++;
                    if (r.dataset.gid) {
                        r.classList.add('wu-sub-visible');
                        openGroups.add(r.dataset.gid);
                    }
                });

                // Show group headers that have matched sub-rows
                document.querySelectorAll('.wu-group-header').forEach(function(r) {
                    if (!openGroups.has(r.dataset.gid)) return;
                    r.classList.remove('wu-hidden');
                    r.classList.add('wu-group-open');
                    r.dataset.filterOpened = '1';
                    var pills = r.querySelector('.wu-pills');
                    if (pills) pills.style.display = 'none';
                });

                count.innerHTML = visibleMods + ' {$labelModules}';
            });

            filter.focus();
        })();
        </script>
        HTML;
    }

    protected function renderModuleRow(array $m, bool $isSub, bool $isLast = false, int $gid = 0): string {
        $esc     = $this->wire('sanitizer')->entities($m['name']);
        $version = $m['version'] ? $this->wire('sanitizer')->entities((string) $m['version']) : '';
        $summary = $this->wire('sanitizer')->entities($m['summary']);
        $folder  = $this->wire('sanitizer')->entities($m['folder']);

        $subClass  = $isSub ? 'wu-sub-row' . ($isLast ? ' wu-sub-last' : '') : '';
        $dataGroup = $isSub ? "data-group=\"{$folder}\" data-gid=\"{$gid}\"" : '';
        $dataSearch = 'data-search="' . htmlspecialchars(strtolower($m['name'] . ' ' . $m['title'] . ' ' . $m['summary']), ENT_QUOTES) . '"';

        $icon = $isSub
            ? '<i class="fa fa-puzzle-piece" style="color:var(--pw-muted-color);font-size:12px"></i>'
            : '<i class="fa fa-cube" style="color:var(--pw-muted-color);font-size:13px"></i>';

        $summaryHtml = $summary
            ? "<div class=\"uk-text-small uk-text-muted\" style=\"margin-top:2px\">{$summary}</div>"
            : '';

        $versionHtml = $version
            ? "<span class=\"uk-text-small uk-text-muted\">{$version}</span>"
            : '';

        return <<<HTML
        <tr class="wu-row {$subClass}" data-name="{$esc}" {$dataSearch} {$dataGroup}>
            <td style="width:32px;text-align:center;vertical-align:middle">{$icon}</td>
            <td style="vertical-align:middle">
                <code style="font-size:.9em">{$esc}</code>
                {$summaryHtml}
            </td>
            <td style="width:90px;vertical-align:middle">{$versionHtml}</td>
            <td style="width:130px;text-align:right;vertical-align:middle">
                <button type="submit" name="module_name" value="{$esc}"
                    class="uk-button uk-button-default uk-button-small wu-scan-btn">
                    <i class="fa fa-search"></i> {$this->_('Scan')}
                </button>
            </td>
        </tr>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Step 2 — Scan & confirm
    // -------------------------------------------------------------------------

    protected function executeScan(): string {
        $moduleName = $this->wire('sanitizer')->name($this->wire('input')->post('module_name'));
        if (!$moduleName) return $this->renderError($this->_('No module selected.'));

        // If the submitted name is a folder (Scan group), find the primary module:
        // the one whose name matches the folder exactly, or the first one in that folder.
        if (!$this->wire('modules')->isInstalled($moduleName)) {
            $groups = $this->buildModuleGroups();
            if (!empty($groups[$moduleName])) {
                // Pick the module whose name matches folder exactly, else first
                $primary = null;
                foreach ($groups[$moduleName] as $m) {
                    if ($m['name'] === $moduleName) { $primary = $m['name']; break; }
                }
                if (!$primary) $primary = $groups[$moduleName][0]['name'];
                $moduleName = $primary;
            } else {
                return $this->renderError(sprintf($this->_('Module "%s" is not installed.'), $moduleName));
            }
        }

        $deps = $this->scanDependencies($moduleName);
        return $this->renderConfirmForm($moduleName, $deps);
    }

    protected function renderConfirmForm(string $moduleName, array $deps): string {
        $csrf          = $this->wire('session')->CSRF->renderInput();
        $moduleNameEsc = $this->wire('sanitizer')->entities($moduleName);
        $rows          = $this->renderDepsTable($deps);

        if (!trim($rows)) {
            $rows = '<tr><td colspan="4" class="uk-text-muted"><em>' . $this->_('No associated resources detected.') . '</em></td></tr>';
        }

        $labelBack      = $this->_('Back');
        $labelSubmit    = $this->_('Uninstall selected');
        $labelWarning   = $this->_('This action is irreversible. Double-check before proceeding.');
        $labelKeep      = $this->_('Uncheck items you want to keep. The module itself will always be uninstalled.');
        $labelModule    = $this->_('Module:');
        $labelResources = $this->_('Resources to remove');
        $labelConfirm   = addslashes($this->_('Are you sure? This cannot be undone.'));

        return <<<HTML
        <div class="uk-alert uk-alert-warning" uk-alert style="margin-bottom:20px">
            <p><strong>{$labelModule}</strong> <code>{$moduleNameEsc}</code> — {$labelKeep}</p>
        </div>

        <form method="post" action="./">
            <input type="hidden" name="action" value="uninstall">
            <input type="hidden" name="module_name" value="{$moduleNameEsc}">
            {$csrf}

            <h3 class="uk-heading-divider" style="font-size:1em;text-transform:uppercase;letter-spacing:.05em;color:var(--pw-muted-color)">{$labelResources}</h3>

            <table class="uk-table uk-table-divider uk-table-small uk-table-hover" style="margin-top:0">
                <thead>
                    <tr>
                        <th style="width:44px"></th>
                        <th style="width:130px">{$this->_('Type')}</th>
                        <th>{$this->_('Name')}</th>
                        <th>{$this->_('Details')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>

            <div class="uk-alert uk-alert-danger" uk-alert style="margin-top:16px">
                <p><i class="fa fa-exclamation-triangle"></i> {$labelWarning}</p>
            </div>

            <div class="uk-flex uk-flex-middle" style="gap:10px;margin-top:20px">
                <button type="submit" class="uk-button uk-button-danger"
                    onclick="return confirm('{$labelConfirm}')">
                    <i class="fa fa-trash"></i> {$labelSubmit}
                </button>
                <a href="./" class="uk-button uk-button-default"><i class="fa fa-arrow-left"></i> {$labelBack}</a>
            </div>
        </form>
        HTML;
    }

    protected function renderDepsTable(array $deps): string {
        $rows = '';
        $rows .= $this->renderRow('module', $deps['module'], true, true);
        foreach ($deps['submodules'] as $name) {
            $rows .= $this->renderRow('submodule', $name, true);
        }
        foreach ($deps['fields'] as $name) {
            $f      = $this->wire('fields')->get($name);
            $detail = $f ? ($f->type ? (string) $f->getFieldtype() : 'unknown type') : '';
            $rows   .= $this->renderRow('field', $name, true, false, $detail);
        }
        foreach ($deps['templates'] as $name) {
            $t      = $this->wire('templates')->get($name);
            $detail = $t ? sprintf($this->_('%d pages'), $t->getNumPages()) : '';
            $rows   .= $this->renderRow('template', $name, true, false, $detail);
        }
        foreach ($deps['dirs'] as $path) {
            $rows .= $this->renderRow('directory', $path, true);
        }
        return $rows;
    }

    protected function renderRow(string $type, string $name, bool $checked, bool $disabled = false, string $detail = ''): string {
        $esc  = $this->wire('sanitizer')->entities($name);
        $key  = $type . '[]';
        $chk  = $checked ? 'checked' : '';
        $dis  = $disabled ? 'disabled' : '';
        $dis2 = $disabled
            ? '<input type="hidden" name="' . $this->wire('sanitizer')->entities($key) . '" value="' . $esc . '">'
            : '';

        [$icon, $typeLabel, $badgeClass] = match($type) {
            'module'    => ['<i class="fa fa-cube"></i>',         $this->_('Module'),    'uk-label'],
            'submodule' => ['<i class="fa fa-puzzle-piece"></i>', $this->_('Submodule'), 'uk-label'],
            'field'     => ['<i class="fa fa-tag"></i>',          $this->_('Field'),     'uk-label uk-label-success'],
            'template'  => ['<i class="fa fa-file-o"></i>',       $this->_('Template'),  'uk-label uk-label-warning'],
            'directory' => ['<i class="fa fa-folder-o"></i>',     $this->_('Directory'), 'uk-label uk-label-danger'],
            default     => ['',                                    $type,                 'uk-label'],
        };

        $detailHtml = $detail
            ? '<span class="uk-text-small uk-text-muted">' . $this->wire('sanitizer')->entities($detail) . '</span>'
            : '';

        return <<<HTML
        <tr>
            <td style="width:44px;text-align:center;vertical-align:middle">
                <input type="checkbox" class="uk-checkbox" name="{$key}" value="{$esc}" {$chk} {$dis}>
                {$dis2}
            </td>
            <td style="vertical-align:middle">
                <span class="{$badgeClass}">{$icon} {$typeLabel}</span>
            </td>
            <td style="vertical-align:middle">
                <code>{$esc}</code>
            </td>
            <td style="vertical-align:middle">{$detailHtml}</td>
        </tr>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Step 3 — Execute uninstall
    // -------------------------------------------------------------------------

    protected function executeUninstall(): string {
        // CSRF check
        if (!$this->wire('session')->CSRF->validate()) {
            return $this->renderError($this->_('Invalid CSRF token. Please reload and try again.'));
        }

        $input      = $this->wire('input');
        $moduleName = $this->wire('sanitizer')->name($input->post('module_name'));
        if (!$moduleName) return $this->renderError($this->_('No module name provided.'));

        // Prevent self-uninstall
        if ($moduleName === $this->className()) {
            return $this->renderError($this->_('Cannot uninstall Uninstaller itself.'));
        }

        // Prevent uninstalling a module that is not installed
        if (!$this->wire('modules')->isInstalled($moduleName)) {
            return $this->renderError(sprintf($this->_('Module "%s" is not installed.'), $moduleName));
        }

        $log = [];

        // 1. Fields first — Fieldtype module must still be installed when deleting fields
        foreach ((array) $input->post('field') as $fieldName) {
            $fieldName = $this->wire('sanitizer')->fieldName((string) $fieldName);
            if ($fieldName) $log[] = $this->doDeleteField($fieldName);
        }

        // 2. Templates (after fields, before modules)
        foreach ((array) $input->post('template') as $tplName) {
            $tplName = $this->wire('sanitizer')->name((string) $tplName);
            if ($tplName) $log[] = $this->doDeleteTemplate($tplName);
        }

        // 3. Submodules (after fields/templates so their Fieldtypes are still available above)
        foreach ((array) $input->post('submodule') as $sub) {
            $sub = $this->wire('sanitizer')->name((string) $sub);
            if ($sub) $log[] = $this->doUninstallModule($sub);
        }

        // 4. Main module
        $log[] = $this->doUninstallModule($moduleName);

        // 5. Directories (last — after all PHP files are no longer needed)
        foreach ((array) $input->post('directory') as $dir) {
            $dir = (string) $dir;
            if ($dir) $log[] = $this->doDeleteDirectory($dir);
        }

        return $this->renderLog($log, $moduleName);
    }

    // -------------------------------------------------------------------------
    // Dependency scanner
    // -------------------------------------------------------------------------

    protected function scanDependencies(string $moduleName): array {
        $deps = [
            'module'     => $moduleName,
            'submodules' => [],
            'fields'     => [],
            'templates'  => [],
            'dirs'       => [],
        ];

        $siteModules  = $this->wire('config')->paths->siteModules;
        $moduleFile   = $this->wire('modules')->getModuleFile($moduleName);
        $moduleFolder = '';

        if ($moduleFile) {
            $realFile    = realpath($moduleFile) ?: $moduleFile;
            $realModules = realpath($siteModules) ?: $siteModules;
            $rel         = ltrim(str_replace($realModules, '', $realFile), '/\\');
            $moduleFolder = dirname(str_replace('\\', '/', $rel));
            if ($moduleFolder === '.') $moduleFolder = '';
        }

        // --- Submodules: same folder + name-prefix + 'installs' key ---
        $seen = [$moduleName => true];
        foreach ($this->wire('modules') as $module) {
            $name = $module->className();
            if (isset($seen[$name])) continue;

            $isSub = false;

            // Name-prefix match (e.g. ProcessWire's Dashboard* panels)
            if (str_starts_with($name, $moduleName)) {
                $isSub = true;
            }

            // Same-folder match
            if (!$isSub && $moduleFolder) {
                $file = $this->wire('modules')->getModuleFile($name);
                if ($file) {
                    $realFile    = realpath($file) ?: $file;
                    $realModules = realpath($siteModules) ?: $siteModules;
                    $rel         = ltrim(str_replace($realModules, '', $realFile), '/\\');
                    $folder      = dirname(str_replace('\\', '/', $rel));
                    if ($folder === '.') $folder = '';
                    if ($folder === $moduleFolder) $isSub = true;
                }
            }

            if ($isSub) {
                $deps['submodules'][] = $name;
                $seen[$name] = true;
            }
        }

        // 'installs' key declared in getModuleInfo
        try {
            $info = $this->wire('modules')->getModuleInfo($moduleName);
            if (!empty($info['installs']) && is_array($info['installs'])) {
                foreach ($info['installs'] as $sub) {
                    if (!isset($seen[$sub])) {
                        $deps['submodules'][] = $sub;
                        $seen[$sub] = true;
                    }
                }
            }
        } catch (\Throwable) {}

        // --- Fields: name prefix matching, skip system fields ---
        $prefix = $this->guessFieldPrefix($moduleName);
        if ($prefix) {
            foreach ($this->wire('fields') as $field) {
                // Skip system fields (flags & Field::flagSystem)
                if ($field->flags & Field::flagSystem) continue;
                if (str_starts_with($field->name, $prefix)) {
                    $deps['fields'][] = $field->name;
                }
            }
        }

        // --- Templates: prefix match + repeater templates, skip system templates ---
        if ($prefix) {
            foreach ($this->wire('templates') as $tpl) {
                // Skip system templates (except repeater_ and repeatermatrix_ which belong to fields)
                if ($tpl->flags & Template::flagSystem) {
                    $isRepeater = str_starts_with($tpl->name, 'repeater_')
                        || str_starts_with($tpl->name, 'repeatermatrix_');
                    if (!$isRepeater) continue;
                }
                if (str_starts_with($tpl->name, $prefix)) {
                    $deps['templates'][] = $tpl->name;
                }
            }
        }

        // --- Directory: actual module folder or module name ---
        $dirToCheck = $moduleFolder ?: $moduleName;
        $moduleDir  = rtrim($siteModules, '/\\') . DIRECTORY_SEPARATOR . $dirToCheck;
        if (is_dir($moduleDir)) {
            $deps['dirs'][] = $moduleDir;
        }

        return $deps;
    }

    /**
     * Derive snake_case prefix from CamelCase module name.
     * e.g. "WireNPS" → "wire_nps_", "FieldtypeBookmarks" → "fieldtype_bookmarks_"
     * Used for field/template prefix detection.
     */
    protected function guessFieldPrefix(string $moduleName): string {
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $moduleName));
        $parts = array_filter(explode('_', $snake));

        // Single-word module (e.g. "Crisp", "AppApi" → 2 parts ok, "Collections" → 1 part)
        // Require at least 2 parts to avoid overly broad matching like "crisp_"
        if (count($parts) < 2) return '';

        return implode('_', array_slice(array_values($parts), 0, 2)) . '_';
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    protected function doUninstallModule(string $name): array {
        $modules = $this->wire('modules');
        if (!$modules->isInstalled($name)) {
            return ['type' => 'skip', 'msg' => sprintf($this->_('Module %s not installed, skipped.'), $name)];
        }
        try {
            $modules->uninstall($name);
            return ['type' => 'ok', 'msg' => sprintf($this->_('Module %s uninstalled.'), $name)];
        } catch (\Throwable $e) {
            return ['type' => 'err', 'msg' => sprintf($this->_('Module %s error: %s'), $name, $e->getMessage())];
        }
    }

    protected function doDeleteField(string $name): array {
        $field = $this->wire('fields')->get($name);
        if (!$field) {
            return ['type' => 'skip', 'msg' => sprintf($this->_('Field %s not found, skipped.'), $name)];
        }

        try {
            // If the field's Fieldtype module is missing (e.g. already uninstalled),
            // reassign to FieldtypeText so PW can process removal normally.
            $ft = $field->type;
            if (!$ft || !($ft instanceof Fieldtype)) {
                $fallback = $this->wire('modules')->get('FieldtypeText');
                if ($fallback) {
                    $field->type = $fallback;
                    $this->wire('fields')->save($field);
                }
            }

            // Remove from all fieldgroups first
            foreach ($this->wire('templates') as $tpl) {
                if ($tpl->fieldgroup->has($field)) {
                    $tpl->fieldgroup->remove($field);
                    $tpl->fieldgroup->save();
                }
            }

            $this->wire('fields')->delete($field);
            return ['type' => 'ok', 'msg' => sprintf($this->_('Field %s deleted.'), $name)];

        } catch (\Throwable $e) {
            // Last resort: delete directly from DB
            try {
                $db = $this->wire('database');
                $stmt = $db->prepare('DELETE FROM fields WHERE name=:name');
                $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
                $stmt->execute();
                // Also try to drop the field's data table if it exists
                $tableName = 'field_' . $name;
                $db->exec('DROP TABLE IF EXISTS `' . $db->escapeTable($tableName) . '`');
                return ['type' => 'ok', 'msg' => sprintf($this->_('Field %s force-deleted from database (type was missing).'), $name)];
            } catch (\Throwable $e2) {
                return ['type' => 'err', 'msg' => sprintf($this->_('Field %s error: %s'), $name, $e->getMessage())];
            }
        }
    }

    protected function doDeleteTemplate(string $name): array {
        $tpl = $this->wire('templates')->get($name);
        if (!$tpl) {
            return ['type' => 'skip', 'msg' => sprintf($this->_('Template %s not found, skipped.'), $name)];
        }

        // Delete pages using this template first
        $pages = $this->wire('pages')->find("template=$name, include=all, limit=500");
        $pagesDeleted = 0;
        foreach ($pages as $page) {
            try {
                $this->wire('pages')->delete($page, true);
                $pagesDeleted++;
            } catch (\Throwable) {}
        }

        try {
            $fg = $tpl->fieldgroup;
            $this->wire('templates')->delete($tpl);

            // Only delete fieldgroup if it's not shared with other templates
            if ($fg && $fg->name !== 'default') {
                $fgUsage = $this->wire('templates')->find("fieldgroups_id={$fg->id}");
                if (!$fgUsage->count()) {
                    try { $this->wire('fieldgroups')->delete($fg); } catch (\Throwable) {}
                }
            }

            $detail = $pagesDeleted ? sprintf($this->_(' (%d pages deleted)'), $pagesDeleted) : '';
            return ['type' => 'ok', 'msg' => sprintf($this->_('Template %s deleted.'), $name) . $detail];
        } catch (\Throwable $e) {
            return ['type' => 'err', 'msg' => sprintf($this->_('Template %s error: %s'), $name, $e->getMessage())];
        }
    }

    protected function doDeleteDirectory(string $path): array {
        // Safety: must be inside site/modules/
        $allowed = realpath($this->wire('config')->paths->siteModules);
        $real    = realpath($path);

        if (!$real) {
            // Directory may have already been removed (e.g. double submit or module uninstall cleaned it)
            return ['type' => 'skip', 'msg' => sprintf($this->_('Directory %s not found, skipped.'), $path)];
        }
        if (!$allowed || !str_starts_with($real . DIRECTORY_SEPARATOR, $allowed . DIRECTORY_SEPARATOR)) {
            return ['type' => 'err', 'msg' => sprintf($this->_('Directory %s is outside site/modules — skipped for safety.'), $path)];
        }
        try {
            $this->rmdirRecursive($real);
            return ['type' => 'ok', 'msg' => sprintf($this->_('Directory %s deleted.'), $real)];
        } catch (\Throwable $e) {
            return ['type' => 'err', 'msg' => sprintf($this->_('Directory %s error: %s'), $real, $e->getMessage())];
        }
    }

    protected function rmdirRecursive(string $dir): void {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Render helpers
    // -------------------------------------------------------------------------

    protected function renderLog(array $log, string $moduleName): string {
        $rows = '';
        foreach ($log as $entry) {
            [$icon, $rowClass] = match($entry['type']) {
                'ok'   => ['<i class="fa fa-check" style="color:var(--pw-alert-success)"></i>',   ''],
                'err'  => ['<i class="fa fa-exclamation-triangle" style="color:var(--pw-error-inline-text-color)"></i>', ''],
                'skip' => ['<i class="fa fa-minus-circle" style="color:var(--pw-muted-color)"></i>', ''],
                default => ['&middot;', ''],
            };
            $msg  = $this->wire('sanitizer')->entities($entry['msg']);
            $rows .= "<tr class=\"{$rowClass}\"><td style=\"width:32px;text-align:center\">{$icon}</td><td>{$msg}</td></tr>";
        }

        $hasErrors  = count(array_filter($log, fn($e) => $e['type'] === 'err')) > 0;
        $alertClass = $hasErrors ? 'uk-alert-warning' : 'uk-alert-success';
        $alertMsg   = $hasErrors
            ? $this->_('Completed with errors. Check the log below.')
            : $this->_('Uninstall completed successfully.');

        $modulesUrl = $this->wire('config')->urls->admin . 'module/';

        return <<<HTML
        <div class="uk-alert {$alertClass}" uk-alert>
            <p>{$alertMsg}</p>
        </div>

        <table class="uk-table uk-table-divider uk-table-small" style="margin-top:0">
            <tbody>{$rows}</tbody>
        </table>

        <div class="uk-flex" style="gap:10px;margin-top:20px">
            <a href="./" class="uk-button uk-button-default"><i class="fa fa-arrow-left"></i> {$this->_('Back')}</a>
            <a href="{$modulesUrl}" class="uk-button uk-button-primary"><i class="fa fa-cubes"></i> {$this->_('Modules')}</a>
        </div>
        HTML;
    }

    protected function renderError(string $msg): string {
        $esc = $this->wire('sanitizer')->entities($msg);
        return <<<HTML
        <div class="uk-alert uk-alert-danger" uk-alert>
            <p>{$esc}</p>
        </div>
        <p><a href="./" class="uk-button uk-button-default"><i class="fa fa-arrow-left"></i> {$this->_('Back')}</a></p>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public function ___install(): void {
        parent::___install();
    }

    public function ___uninstall(): void {
        parent::___uninstall();
    }
}
