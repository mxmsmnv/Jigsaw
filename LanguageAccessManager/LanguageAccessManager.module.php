<?php namespace ProcessWire;

/**
 * LanguageAccessManager
 *
 * Manages ProcessWire language edit permissions (page-edit-lang-*) from the
 * module configuration screen.
 */
class LanguageAccessManager extends WireData implements Module, ConfigurableModule {

	const PERM_PREFIX = 'page-edit-lang-';
	const CONFIG_PERMISSION = 'language-access-manager';

	public static function getModuleInfo() {
		return [
			'title' => 'Language Access Manager',
			'summary' => 'Manage page-edit-lang-* permissions and assign controlled languages to roles.',
			'version' => 100,
			'author' => 'Maxim Semenov',
			'href' => 'https://smnv.org',
			'requires' => ['LanguageSupport', 'LanguageSupportFields'],
			'icon' => 'language',
			'autoload' => 'template=admin',
			'permission' => self::CONFIG_PERMISSION,
		];
	}

	public function __construct() {
		parent::__construct();
		$this->set('controlled_languages', []);
		$this->set('active_languages', []);
	}

	public static function getModuleConfigInputfields(array $data) {
		$modules = wire('modules');
		$languages = wire('languages');
		$roles = wire('roles');
		$permissions = wire('permissions');
		$sanitizer = wire('sanitizer');
		$user = wire('user');

		/** @var InputfieldWrapper $fields */
		$fields = new InputfieldWrapper();

		if(!$user->isSuperuser() && !$user->hasPermission(self::CONFIG_PERMISSION)) {
			/** @var InputfieldMarkup $denied */
			$denied = $modules->get('InputfieldMarkup');
			$denied->label = 'Access denied';
			$denied->value = '<p>You need the <code>' . self::CONFIG_PERMISSION . '</code> permission to configure this module.</p>';
			$fields->add($denied);
			return $fields;
		}

		/** @var InputfieldMarkup $configAssets */
		$configAssets = $modules->get('InputfieldMarkup');
		$configAssets->name = '_language_access_manager_config_assets';
		$configAssets->label = '';
		$configAssets->collapsed = Inputfield::collapsedNo;
		$configAssets->wrapAttr('style', 'display:none');
		$configAssets->value = self::renderConfigAssets();
		$fields->add($configAssets);

		$existingLangPerms = [];
		foreach($permissions as $perm) {
			if(strpos($perm->name, self::PERM_PREFIX) === 0) {
				$existingLangPerms[] = str_replace(self::PERM_PREFIX, '', $perm->name);
			}
			}
			$systemActive = !empty($existingLangPerms);
			$activeLanguages = isset($data['active_languages']) ? (array) $data['active_languages'] : [];
			$activeLanguages = self::filterLanguageNames($activeLanguages, $languages);
			$configuredControlled = isset($data['controlled_languages']) ? (array) $data['controlled_languages'] : [];
			$configuredControlled = self::filterLanguageNames($configuredControlled, $languages);

			/** @var InputfieldMarkup $status */
			$status = $modules->get('InputfieldMarkup');
			$status->label = 'System status';
			$status->value = self::renderStatusMarkup($systemActive, $existingLangPerms, $configuredControlled, $activeLanguages, $languages, $sanitizer);
			$fields->add($status);

		/** @var InputfieldCheckboxes $activeField */
		$activeField = $modules->get('InputfieldCheckboxes');
		$activeField->name = 'active_languages';
		$activeField->label = 'Active content languages shown in page editor';
		$activeField->description = 'Choose which content languages should be visible while editing pages. Hidden languages remain installed in ProcessWire and can be enabled later. If nothing is selected, all languages are shown.';
		$activeField->notes = 'This is an editor UI filter. It does not delete languages and does not replace frontend language-switcher logic.';
		$activeField->optionColumns = 2;
		$activeField->columns = 2;

			foreach($languages as $lang) {
			$nameLabel = $lang->name === 'default' ? 'default / base content language' : $lang->name;
			$name = $sanitizer->entities($nameLabel);
			$title = $sanitizer->entities($lang->title ?: $lang->name);
			$activeField->addOption($lang->name, $title . ' (' . $name . ')');
		}
		$activeField->value = $activeLanguages;
		$fields->add($activeField);

		/** @var InputfieldCheckboxes $langField */
		$langField = $modules->get('InputfieldCheckboxes');
		$langField->name = 'controlled_languages';
		$langField->label = 'Step 1 - Content languages controlled by permissions';
		$langField->description = 'Checked content languages require explicit role permission. ProcessWire language named default is the base content language, not the user-selected admin/profile language. When at least one language is controlled, default is automatically controlled too and granted to roles that have page-edit.';
		$langField->optionColumns = 2;
		$langField->columns = 2;

			$controlled = $systemActive ? $existingLangPerms : $configuredControlled;
		$controlled = self::filterLanguageNames($controlled, $languages);

		foreach($languages as $lang) {
			$nameLabel = $lang->name === 'default' ? 'default / base content language' : $lang->name;
			$name = $sanitizer->entities($nameLabel);
			$title = $sanitizer->entities($lang->title ?: $lang->name);
			$langField->addOption($lang->name, $title . ' (' . $name . ')');
		}
		$langField->value = $controlled;
		$fields->add($langField);

		/** @var InputfieldFieldset $fieldset */
		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->label = 'Step 2 - Role access';
		$fieldset->description = 'For each role, select the content languages it may edit. Superusers always have access to all languages.';

		foreach($roles as $role) {
			if(in_array($role->name, ['guest', 'superuser'], true)) continue;

			$currentRoleLangs = [];
			foreach($role->permissions as $perm) {
				if(strpos($perm->name, self::PERM_PREFIX) === 0) {
					$currentRoleLangs[] = str_replace(self::PERM_PREFIX, '', $perm->name);
				}
			}

			/** @var InputfieldCheckboxes $roleField */
			$roleField = $modules->get('InputfieldCheckboxes');
			$roleField->name = 'role_langs_' . $role->id;
			$roleField->label = $sanitizer->entities($role->name);
			$roleField->optionColumns = 2;
			$roleField->columns = 2;

			foreach($languages as $lang) {
				$nameLabel = $lang->name === 'default' ? 'default / base content language' : $lang->name;
				$name = $sanitizer->entities($nameLabel);
				$title = $sanitizer->entities($lang->title ?: $lang->name);
				$roleField->addOption($lang->name, $title . ' (' . $name . ')');
			}

			$savedRoleLangs = isset($data[$roleField->name]) ? (array) $data[$roleField->name] : [];
			$savedRoleLangs = self::filterLanguageNames($savedRoleLangs, $languages);
			$roleField->value = !empty($currentRoleLangs) ? $currentRoleLangs : $savedRoleLangs;
			$fieldset->add($roleField);
		}

		$fields->add($fieldset);

		/** @var InputfieldMarkup $resetInfo */
		$resetInfo = $modules->get('InputfieldMarkup');
		$resetInfo->label = 'Disable language permission control';
		$resetInfo->value = '<p style="color:#666;font-size:13px">To fully disable language permission control, uncheck all languages in Step 1 and save. All <code>page-edit-lang-*</code> permissions will be removed from roles and deleted.</p>';
		$fields->add($resetInfo);

		return $fields;
	}

	public function init() {
		$this->addHookAfter('Modules::saveModuleConfigData', $this, 'applyOnSave');
		if($this->wire('page')->template == 'admin') {
			$this->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'applyPageEditLanguageFilter');
			$this->addHookAfter('ProcessPageAdd::buildForm', $this, 'applyPageEditLanguageFilter');
			$this->addHookAfter('Page::render', $this, 'injectLanguageListDebugColumns');
			$this->addHookBefore('ProcessPageType::execute', $this, 'addLanguageListDebugFields');
			$this->addHookBefore('ProcessLanguage::execute', $this, 'addLanguageListDebugFields');
			$this->addHookAfter('ProcessLanguage::execute', $this, 'injectLanguageListDebugColumns');
		}
	}

	public function applyOnSave(HookEvent $event) {
		$moduleName = $event->arguments(0);
		$shortClassName = substr(strrchr('\\' . __CLASS__, '\\'), 1);
		if($moduleName !== $shortClassName && $moduleName !== __CLASS__) return;

		$data = $event->arguments(1);
		$permissions = $this->wire('permissions');
		$roles = $this->wire('roles');
		$languages = $this->wire('languages');

		$selectedLangs = isset($data['controlled_languages']) ? (array) $data['controlled_languages'] : [];
		$selectedLangs = self::filterLanguageNames($selectedLangs, $languages);
		if(!empty($selectedLangs) && $languages->get('default') && !in_array('default', $selectedLangs, true)) {
			$selectedLangs[] = 'default';
		}

		$roleLanguageMap = [];
		$input = $this->wire('input');
		foreach($roles as $role) {
			if(in_array($role->name, ['guest', 'superuser'], true)) continue;
			$key = 'role_langs_' . $role->id;
			$roleData = isset($data[$key]) ? $data[$key] : $input->post($key);
			$roleLanguageMap[$key] = self::filterLanguageNames((array) $roleData, $languages);
		}

		foreach($selectedLangs as $langName) {
			$permName = self::PERM_PREFIX . $langName;
			if(!$permissions->get($permName)) {
				$perm = $this->wire(new Permission());
				$perm->name = $permName;
				$lang = $languages->get($langName);
				$perm->title = 'Edit pages in language: ' . ($lang ? ($lang->title ?: $lang->name) : $langName);
				$perm->save();
				$this->message("Created permission: {$permName}");
			}
		}

		foreach($permissions->find('name^=' . self::PERM_PREFIX) as $perm) {
			$langName = str_replace(self::PERM_PREFIX, '', $perm->name);
			if(in_array($langName, $selectedLangs, true)) continue;

			foreach($roles as $role) {
				if($role->hasPermission($perm->name)) {
					$role->removePermission($perm);
					$role->save();
				}
			}
			$perm->delete();
			$this->message("Deleted permission: {$perm->name}");
		}

		$defaultPermName = self::PERM_PREFIX . 'default';
		$defaultPerm = $permissions->get($defaultPermName);

		foreach($roles as $role) {
			if(in_array($role->name, ['guest', 'superuser'], true)) continue;

			$key = 'role_langs_' . $role->id;
			$langsForRole = isset($roleLanguageMap[$key]) ? $roleLanguageMap[$key] : [];

			// page-edit-lang-default is required for reliable page creation/deletion.
			if($defaultPerm && $role->hasPermission('page-edit')) {
				if(!$role->hasPermission($defaultPermName)) {
					$role->addPermission($defaultPerm);
				}
				if(!in_array('default', $langsForRole, true)) {
					$langsForRole[] = 'default';
				}
			}

			foreach($selectedLangs as $langName) {
				$permName = self::PERM_PREFIX . $langName;
				$perm = $permissions->get($permName);
				if(!$perm) continue;

				if(in_array($langName, $langsForRole, true)) {
					if(!$role->hasPermission($permName)) {
						$role->addPermission($perm);
					}
				} elseif($role->hasPermission($permName)) {
					$role->removePermission($perm);
				}
			}

			$role->save();
		}
	}

	public function addLanguageListDebugFields(HookEvent $event) {
		if(strpos($this->wire('input')->url(), '/setup/languages/') === false) return;
		$process = $event->object;
		if(!$process instanceof ProcessLanguage && $this->wire('process') != 'ProcessLanguage') return;

		$showFields = (array) $process->get('showFields');
		$wanted = ['name', 'id', 'title', 'language_files', 'language_files_site'];
		$merged = [];
		foreach($wanted as $fieldName) {
			if(!in_array($fieldName, $merged, true)) $merged[] = $fieldName;
		}
		foreach($showFields as $fieldName) {
			if(!in_array($fieldName, $merged, true)) $merged[] = $fieldName;
		}
		$process->set('showFields', $merged);
	}

	public function injectLanguageListDebugColumns(HookEvent $event) {
		if(strpos($this->wire('input')->url(), '/setup/languages/') === false) return;

		$languages = $this->wire('languages');
		$permissions = $this->wire('permissions');
		$activeNames = $this->getConfiguredActiveLanguageNames();
		$activeLookup = array_fill_keys($activeNames, true);

		$controlledNames = [];
		foreach($permissions->find('name^=' . self::PERM_PREFIX) as $permission) {
			$controlledNames[] = str_replace(self::PERM_PREFIX, '', $permission->name);
		}
		$controlledLookup = array_fill_keys($controlledNames, true);

		$rows = [];
		foreach($languages as $language) {
			$permissionName = self::PERM_PREFIX . $language->name;
			$rows[$language->name] = [
				'id' => (int) $language->id,
				'name' => $language->name,
				'title' => $language->title ?: $language->name,
				'isDefault' => $language->name === 'default',
				'isActive' => empty($activeNames) || isset($activeLookup[$language->name]),
				'isControlled' => isset($controlledLookup[$language->name]),
				'permission' => $permissionName,
				'permissionExists' => (bool) $permissions->get($permissionName),
			];
		}

		$json = json_encode($rows);
		$script = <<<HTML
<style>
.lam-language-debug-col {
	white-space: nowrap;
	font-size: 12px;
	color: #555;
}
.lam-language-debug-badge {
	display: inline-block;
	padding: 2px 7px;
	border-radius: 10px;
	background: #eee;
	color: #333;
	font-size: 11px;
	line-height: 1.4;
}
.lam-language-debug-badge.is-on {
	background: #d4edda;
	color: #155724;
}
.lam-language-debug-badge.is-off {
	background: #f8d7da;
	color: #721c24;
}
</style>
<script>
(function() {
	var languages = {$json};

	function badge(value) {
		return '<span class="lam-language-debug-badge ' + (value ? 'is-on' : 'is-off') + '">' + (value ? 'yes' : 'no') + '</span>';
	}

	function addHeader(table) {
		var headerRow = table.querySelector('thead tr') || table.querySelector('tr');
		if(!headerRow || headerRow.dataset.lamDebugHeader) return;
		headerRow.dataset.lamDebugHeader = '1';
		['ID', 'Internal name', 'Default', 'Active UI', 'Permission controlled', 'Permission'].forEach(function(label) {
			var th = document.createElement(headerRow.children[0] && headerRow.children[0].tagName === 'TD' ? 'td' : 'th');
			th.className = 'lam-language-debug-col';
			th.textContent = label;
			headerRow.appendChild(th);
		});
	}

	function addCell(row, html) {
		var td = document.createElement('td');
		td.className = 'lam-language-debug-col';
		td.innerHTML = html;
		row.appendChild(td);
	}

	function getRowLanguageName(row) {
		var link = row.querySelector('a[href*="/setup/languages/"]') || row.querySelector('td a');
		if(!link) return '';
		return String(link.textContent || '').trim();
	}

	function enhanceTable(table) {
		addHeader(table);
		Array.prototype.forEach.call(table.querySelectorAll('tbody tr, tr'), function(row) {
			if(row.dataset.lamDebugRow) return;
			if(row.querySelector('th')) return;

			var name = getRowLanguageName(row);
			var language = languages[name];
			if(!language) return;

			row.dataset.lamDebugRow = '1';
			addCell(row, String(language.id));
			addCell(row, language.name);
			addCell(row, badge(language.isDefault));
			addCell(row, badge(language.isActive));
			addCell(row, badge(language.isControlled));
			addCell(row, language.permission + (language.permissionExists ? '' : ' (missing)'));
		});
	}

	function run() {
		document.querySelectorAll('table').forEach(function(table) {
			if(table.querySelector('a[href*="/setup/languages/"]')) enhanceTable(table);
		});
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
</script>
HTML;

		if(is_string($event->return)) {
			if(strpos($event->return, '</body>') !== false) {
				$event->return = str_replace('</body>', $script . '</body>', $event->return);
			} else {
				$event->return .= $script;
			}
		}
	}

	public function applyPageEditLanguageFilter(HookEvent $event) {
		$activeNames = $this->getConfiguredActiveLanguageNames();
		if(empty($activeNames)) return;

		/** @var InputfieldWrapper $form */
		$form = $event->return;
		if(!$form instanceof InputfieldWrapper) return;

		$languages = $this->wire('languages');
		$activeLookup = array_fill_keys($activeNames, true);
		$active = [];
		$inactive = [];

		foreach($languages as $lang) {
			$languageConfig = [
				'id' => (string) $lang->id,
				'name' => $lang->name,
				'title' => (string) ($lang->title ?: $lang->name),
				'label' => $lang->name === 'default' ? 'default' : $lang->name,
			];
			if(isset($activeLookup[$lang->name])) {
				$active[] = $languageConfig;
			} else {
				$inactive[] = $languageConfig;
			}
		}

		if(empty($inactive)) return;

		$this->markLanguageInputfields($form);

		/** @var InputfieldMarkup $filter */
		$filter = $this->wire('modules')->get('InputfieldMarkup');
		$filter->name = '_language_access_manager_filter';
		$filter->label = '';
		$filter->collapsed = Inputfield::collapsedNo;
		$filter->wrapAttr('style', 'display:none');
		$filter->value = $this->renderPageEditLanguageFilterAssets($active, $inactive);
		$form->prepend($filter);
	}

	protected function markLanguageInputfields(InputfieldWrapper $wrapper) {
		foreach($wrapper->children() as $inputfield) {
			if($inputfield instanceof InputfieldWrapper) {
				$this->markLanguageInputfields($inputfield);
			}

			if(!$this->isLanguageAwareInputfield($inputfield)) continue;

			$classes = trim((string) $inputfield->wrapClass);
			$inputfield->wrapClass = trim($classes . ' lam-language-filtered');
		}
	}

	protected function isLanguageAwareInputfield($inputfield) {
		if(!is_object($inputfield)) return false;
		$field = $inputfield->hasField;
		if(!$field) return false;
		if(!$field->type) return false;

		return $field->type instanceof FieldtypeLanguageInterface;
	}

	protected function renderPageEditLanguageFilterAssets(array $active, array $inactive) {
		$configJson = json_encode([
			'inactive' => $inactive,
			'active' => $active,
		]);

		return <<<HTML
<style>
.lam-language-filtered .lam-language-hidden,
	.lam-language-hidden {
		display: none !important;
	}
		.lam-single-language-mode .LanguageSupportTabs > li,
			.lam-single-language-mode .langTabs > li,
			.lam-single-language-mode .ui-tabs-nav > li,
			.lam-single-language-mode [role="tab"] {
				display: none !important;
			}
			.lam-language-debug .lam-language-filtered {
				outline: 2px dashed #0b7cff !important;
				outline-offset: -2px !important;
			}
			.lam-language-debug .lam-language-hidden {
				outline: 2px dashed #d33 !important;
			}
		.lam-single-language-mode .lam-language-active-panel {
			display: block !important;
			visibility: visible !important;
			opacity: 1 !important;
		}
		</style>
<script>
(function() {
			var config = {$configJson};
			var inactiveLabels = [];
			var activeLabels = [];
			var inactiveIds = [];
			var activeIds = [];
			var debugEnabled = window.location.search.indexOf('lamdebug=1') !== -1;

			if(debugEnabled) {
				document.documentElement.classList.add('lam-language-debug');
				console.info('[LanguageAccessManager] debug enabled', config);
			}

		function collectLanguageIdentifiers(language, labelList, idList) {
			[language.name, language.title, language.label].forEach(function(value) {
				if(!value) return;
				labelList.push(String(value).trim().toLowerCase());
			});
			if(language.id) idList.push(String(language.id));
		}

		config.inactive.forEach(function(language) {
			collectLanguageIdentifiers(language, inactiveLabels, inactiveIds);
		});

		config.active.forEach(function(language) {
			collectLanguageIdentifiers(language, activeLabels, activeIds);
		});

		if(debugEnabled) {
			console.info('[LanguageAccessManager] activeIds', activeIds, 'inactiveIds', inactiveIds);
			console.info('[LanguageAccessManager] activeLabels', activeLabels, 'inactiveLabels', inactiveLabels);
			var debugBox = document.createElement('pre');
			debugBox.style.cssText = 'position:fixed;z-index:99999;right:16px;bottom:16px;max-width:520px;max-height:260px;overflow:auto;padding:12px;background:#111;color:#0f0;font:12px/1.35 monospace;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.35)';
			debugBox.textContent = 'LanguageAccessManager debug\\n' + JSON.stringify({
				activeIds: activeIds,
				inactiveIds: inactiveIds,
				active: config.active,
				inactive: config.inactive
			}, null, 2);
			document.documentElement.appendChild(debugBox);
		}

		function textMatchesInactiveLanguage(text) {
			text = String(text || '').trim().toLowerCase();
			return inactiveLabels.indexOf(text) !== -1;
		}

			function textMatchesActiveLanguage(text) {
				text = String(text || '').trim().toLowerCase();
				return activeLabels.indexOf(text) !== -1;
			}

			function elementLanguageIds(element) {
				var ids = [
					element.getAttribute('data-language'),
					element.getAttribute('data-lang'),
					element.getAttribute('lang')
				].filter(Boolean).map(String);

				var id = element.getAttribute('id') || '';
				var suffixMatch = id.match(/_(\\d+)$/);
				if(suffixMatch) ids.push(suffixMatch[1]);

				return ids;
			}

			function elementMatchesIds(element, ids) {
				return elementLanguageIds(element).some(function(value) {
					return ids.indexOf(String(value)) !== -1;
				});
			}

			function elementMatchesInactiveLanguage(element) {
				var ids = elementLanguageIds(element);
				if(ids.length) {
					if(ids.some(function(value) { return activeIds.indexOf(String(value)) !== -1; })) return false;
					return ids.some(function(value) { return inactiveIds.indexOf(String(value)) !== -1; });
				}
				return textMatchesInactiveLanguage(element.textContent);
			}

			function elementMatchesActiveLanguage(element) {
				var ids = elementLanguageIds(element);
				if(ids.length) {
					if(ids.some(function(value) { return activeIds.indexOf(String(value)) !== -1; })) return true;
					return false;
				}
				return textMatchesActiveLanguage(element.textContent);
			}

	function hideTranslationLinks(root) {
		root.querySelectorAll('a, button').forEach(function(element) {
			var text = String(element.textContent || '').toLowerCase();
			if(text.indexOf('translate to all languages') === -1 && text.indexOf('translate from') === -1) return;
			element.style.display = 'none';
		});
	}

			function applySingleLanguageMode(root) {
				if(config.active.length !== 1) return;
			root.querySelectorAll('.lam-language-filtered').forEach(function(inputfield) {
				inputfield.classList.add('lam-single-language-mode');
				revealSingleActiveLanguagePanel(inputfield);
			});
		}

			function revealElement(element) {
				if(debugEnabled) console.info('[LanguageAccessManager] reveal', element);
				element.classList.add('lam-language-active-panel');
			element.classList.remove('lam-language-hidden');
			element.removeAttribute('hidden');
			element.removeAttribute('aria-hidden');
			element.style.display = '';
			element.style.visibility = '';
			element.style.opacity = '';
		}

			function revealSingleActiveLanguagePanel(inputfield) {
				var activeTab = Array.prototype.find.call(inputfield.querySelectorAll('.LanguageSupportTabs li, .langTabs li, .ui-tabs-nav li, [role="tab"]'), function(tab) {
					return elementMatchesActiveLanguage(tab);
				});

			if(activeTab) {
				var link = activeTab.matches('a') ? activeTab : activeTab.querySelector('a');
				var targetSelector = link ? link.getAttribute('href') : '';
				if(targetSelector && targetSelector.charAt(0) === '#') {
					try {
						var target = inputfield.querySelector(targetSelector);
						if(target) revealElement(target);
					} catch(e) {}
				}
			}

				inputfield.querySelectorAll('.LanguageSupport, [data-language], [data-lang], [lang]').forEach(function(panel) {
					if(panel.matches('.LanguageSupportTabs li, .langTabs li, .ui-tabs-nav li, [role="tab"]')) return;
					if(elementMatchesActiveLanguage(panel)) revealElement(panel);
				});
			}

		function markInactiveLanguageElement(element) {
			if(debugEnabled) console.info('[LanguageAccessManager] hide', element);
			element.classList.add('lam-language-hidden');
		element.setAttribute('aria-hidden', 'true');
		element.classList.remove('ui-tabs-active', 'InputfieldStateActive');
	}

	function hideInactiveTabs(root) {
		var selectors = [
			'.lam-language-filtered .LanguageSupportTabs li',
			'.lam-language-filtered .langTabs li',
			'.lam-language-filtered .ui-tabs-nav li',
			'.lam-language-filtered [role="tab"]'
		];

			root.querySelectorAll(selectors.join(',')).forEach(function(tab) {
				if(!elementMatchesInactiveLanguage(tab)) return;
				markInactiveLanguageElement(tab);
			});
		}

		function hideInactiveLanguagePanels(root) {
			root.querySelectorAll('.lam-language-filtered .LanguageSupport, .lam-language-filtered [data-language], .lam-language-filtered [lang]').forEach(function(panel) {
				if(elementMatchesInactiveLanguage(panel)) markInactiveLanguageElement(panel);
			});
		}

	function activateFirstVisibleTab(root) {
		root.querySelectorAll('.lam-language-filtered .LanguageSupportTabs, .lam-language-filtered .langTabs, .lam-language-filtered .ui-tabs-nav').forEach(function(tabList) {
			var activeTab = tabList.querySelector('.ui-tabs-active:not(.lam-language-hidden), .InputfieldStateActive:not(.lam-language-hidden)');
			if(activeTab) return;

			var firstVisibleTab = Array.prototype.find.call(tabList.querySelectorAll('li, [role="tab"]'), function(tab) {
				return !tab.classList.contains('lam-language-hidden');
			});
			if(!firstVisibleTab) return;

			var link = firstVisibleTab.matches('a') ? firstVisibleTab : firstVisibleTab.querySelector('a');
			if(link) link.click();
		});
	}

	function applyLanguageFilter() {
		applySingleLanguageMode(document);
		hideInactiveTabs(document);
		hideInactiveLanguagePanels(document);
		activateFirstVisibleTab(document);
		if(config.active.length === 1) hideTranslationLinks(document);
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', applyLanguageFilter);
	} else {
		applyLanguageFilter();
	}

	var observer = new MutationObserver(function() {
		applyLanguageFilter();
	});
	observer.observe(document.documentElement, { childList: true, subtree: true });
	})();
</script>
HTML;
	}

	public function getActiveLanguageNames() {
		$languages = $this->wire('languages');
		$active = $this->getConfiguredActiveLanguageNames();

		if(!empty($active)) return $active;

		foreach($languages as $lang) {
			$active[] = $lang->name;
		}

		return $active;
	}

	public function isLanguageActive($language) {
		if($language instanceof Language) {
			$name = $language->name;
		} else {
			$name = (string) $language;
		}

		return in_array($name, $this->getActiveLanguageNames(), true);
	}

	protected function getConfiguredActiveLanguageNames() {
		$languages = $this->wire('languages');
		$active = $this->get('active_languages');
		$active = is_array($active) ? $active : [];
		$active = self::filterLanguageNames($active, $languages);

		return $active;
	}

	protected static function filterLanguageNames(array $names, $languages) {
		$filtered = [];
		foreach($names as $name) {
			$name = (string) $name;
			if($name === '') continue;
			$lang = $languages->get($name);
			if(!$lang) continue;
			$name = $lang->name;
			if(!in_array($name, $filtered, true)) {
				$filtered[] = $name;
			}
		}
		return $filtered;
	}

	protected static function renderConfigAssets() {
		return <<<HTML
<style>
#wrap_Inputfield_active_languages .InputfieldContent > ul,
#wrap_Inputfield_controlled_languages .InputfieldContent > ul,
[id^="wrap_Inputfield_role_langs_"] .InputfieldContent > ul {
	display: grid !important;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
	column-gap: 32px !important;
	row-gap: 6px !important;
	width: 100% !important;
}

#wrap_Inputfield_active_languages .InputfieldContent > ul > li,
#wrap_Inputfield_controlled_languages .InputfieldContent > ul > li,
[id^="wrap_Inputfield_role_langs_"] .InputfieldContent > ul > li {
	float: none !important;
	clear: none !important;
	display: block !important;
	width: auto !important;
	margin: 0 !important;
}

#wrap_Inputfield_active_languages label,
#wrap_Inputfield_controlled_languages label,
[id^="wrap_Inputfield_role_langs_"] label {
	display: inline-flex !important;
	align-items: baseline !important;
	gap: 6px !important;
	max-width: 100% !important;
}

@media (max-width: 700px) {
	#wrap_Inputfield_active_languages .InputfieldContent > ul,
	#wrap_Inputfield_controlled_languages .InputfieldContent > ul,
	[id^="wrap_Inputfield_role_langs_"] .InputfieldContent > ul {
		grid-template-columns: 1fr !important;
	}
}
</style>
<script>
(function() {
	function cleanupLanguageAccessLabels() {
		document.querySelectorAll(
			'#wrap_Inputfield_active_languages label, ' +
			'#wrap_Inputfield_controlled_languages label, ' +
			'[id^="wrap_Inputfield_role_langs_"] label'
		).forEach(function(label) {
			label.childNodes.forEach(function(node) {
				if(node.nodeType !== Node.TEXT_NODE) return;
				node.nodeValue = node.nodeValue
					.replace(/\\s*<small[^>]*>/gi, ' (')
					.replace(/<\\/small>/gi, ')')
					.replace(/\\)\\)/g, ')');
			});
		});
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', cleanupLanguageAccessLabels);
	} else {
		cleanupLanguageAccessLabels();
	}
})();
</script>
HTML;
	}

	protected static function renderStatusMarkup($permissionSystemActive, array $permissionLangs, array $configuredControlled, array $activeLanguages, $languages, $sanitizer) {
		$rows = [];

		if(!empty($activeLanguages)) {
			$rows[] = [
				'type' => 'ok',
				'text' => 'Editor UI filter active. Visible content languages: <strong>' . implode(', ', self::languageTitles($activeLanguages, $languages, $sanitizer)) . '</strong>.',
			];
		} else {
			$rows[] = [
				'type' => 'note',
				'text' => 'Editor UI filter inactive. All installed languages are shown in page editor.',
			];
		}

		if($permissionSystemActive) {
			$rows[] = [
				'type' => 'ok',
				'text' => 'Permission control active. Controlled content languages: <strong>' . implode(', ', self::languageTitles($permissionLangs, $languages, $sanitizer)) . '</strong>.',
			];
		} elseif(!empty($configuredControlled)) {
			$rows[] = [
				'type' => 'warn',
				'text' => 'Permission control is configured but not active yet. Save this module again to create the matching <code>page-edit-lang-*</code> permissions.',
			];
		} else {
			$rows[] = [
				'type' => 'note',
				'text' => 'Permission control inactive. All users can edit all languages allowed by their regular page permissions.',
			];
		}

		$out = '';
		foreach($rows as $row) {
			$style = $row['type'] === 'ok'
				? 'background:#d4edda;color:#155724;border:1px solid #c3e6cb'
				: ($row['type'] === 'warn'
					? 'background:#fff3cd;color:#856404;border:1px solid #ffeeba'
					: 'background:#e8f4fd;color:#084c7c;border:1px solid #b8daf2');
			$out .= '<div style="padding:10px 14px;margin-bottom:8px;border-radius:5px;font-weight:500;' . $style . '">' . $row['text'] . '</div>';
		}

		return $out;
	}

	protected static function languageTitles(array $languageNames, $languages, $sanitizer) {
		$titles = [];
		foreach($languageNames as $name) {
			$lang = $languages->get($name);
			$titles[] = $sanitizer->entities($lang ? ($lang->title ?: $lang->name) : $name);
		}
		return $titles;
	}

	public function ___install() {
		$permissions = $this->wire('permissions');
		if(!$permissions->get(self::CONFIG_PERMISSION)) {
			$permission = $this->wire(new Permission());
			$permission->name = self::CONFIG_PERMISSION;
			$permission->title = 'Configure Language Access Manager';
			$permission->save();
		}
	}

	public function ___uninstall() {
		$permissions = $this->wire('permissions');
		$permission = $permissions->get(self::CONFIG_PERMISSION);
		if(!$permission || !$permission->id) return;

		foreach($this->wire('roles') as $role) {
			if($role->hasPermission($permission->name)) {
				$role->removePermission($permission);
				$role->save();
			}
		}

		$permission->delete();
	}
}
