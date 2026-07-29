/**
 * Editor — admin JavaScript
 * Depends on: CodeMirror (loaded from CDN below)
 */

/* global CodeMirror */

(function () {
	'use strict';

	// ------------------------------------------------------------------ //
	//  CodeMirror — local vendor files                                    //
	// ------------------------------------------------------------------ //

	function loadScript(src, cb) {
		if (document.querySelector('script[src="' + src + '"]')) { if (cb) cb(); return; }
		const s = document.createElement('script');
		s.src = src;
		s.onload = cb || null;
		s.onerror = function () { console.error('[Editor] Failed to load:', src); if (cb) cb(); };
		document.head.appendChild(s);
	}

	function loadLink(href) {
		if (document.querySelector('link[href="' + href + '"]')) return;
		const l = document.createElement('link');
		l.rel = 'stylesheet'; l.href = href;
		document.head.appendChild(l);
	}

	function loadCodeMirror(baseUrl, cb) {
		loadLink(baseUrl + 'vendor/codemirror/codemirror.css');
		loadLink(baseUrl + 'vendor/codemirror/theme/dracula.css');
		loadScript(baseUrl + 'vendor/codemirror/codemirror.js', function () {
			// autorefresh addon — fixes blank editor in hidden containers (iOS Safari)
			loadScript(baseUrl + 'vendor/codemirror/autorefresh.js', function () {
				const modes = ['php', 'javascript', 'css', 'xml', 'htmlmixed', 'clike'];
				let loaded = 0;
				modes.forEach(function (m) {
					loadScript(baseUrl + 'vendor/codemirror/mode/' + m + '/' + m + '.js', function () {
						if (++loaded === modes.length) cb();
					});
				});
			});
		});
	}

	// ------------------------------------------------------------------ //
	//  Module
	// ------------------------------------------------------------------ //

	const Editor = {

		cfg: null,
		editor: null,
		currentPath: null,
		dirty: false,

		init: function (cfg) {
			this.cfg = cfg;
			loadCodeMirror(cfg.moduleUrl, this.setup.bind(this));
		},

		setup: function () {
			this.editor = CodeMirror.fromTextArea(document.getElementById('fe-editor'), {
				theme: 'dracula',
				lineNumbers: true,
				tabSize: 4,
				indentWithTabs: true,
				lineWrapping: false,
				autofocus: false,
				autoRefresh: true,
			});

			this.editor.on('change', function () {
				if (Editor._loading) return;
				Editor.dirty = true;
			});

			// Set real viewport height CSS variable (fixes mobile Safari 100vh issue)
			function setVh() {
				document.documentElement.style.setProperty('--fe-vh', window.innerHeight + 'px');
			}
			setVh();
			window.addEventListener('resize', setVh);
			window.addEventListener('orientationchange', function () {
				setTimeout(setVh, 100);
			});

			// Notification stack container
			if (!document.getElementById('fe-notices')) {
				const nc = document.createElement('div');
				nc.id = 'fe-notices';
				document.body.appendChild(nc);
			}

			this.bindTree();
			this.bindToolbar();
			this.bindContextMenu();

			// Refresh CodeMirror on window resize / orientation change
			window.addEventListener('resize', function () {
				if (Editor.editor) Editor.editor.refresh();
			});

			// Expand root nodes automatically
			document.querySelectorAll('.fe-root > .fe-dir-label').forEach(function (el) {
				el.click();
			});
		},

		// --------------------------------------------------------------- //
		//  Tree
		// --------------------------------------------------------------- //

		bindTree: function () {
			document.getElementById('fe-tree').addEventListener('click', function (e) {
				const dirLabel  = e.target.closest('.fe-dir-label');
				const fileLabel = e.target.closest('.fe-file-label');

				if (dirLabel) Editor.onDirClick(dirLabel);
				if (fileLabel) Editor.onFileClick(fileLabel);
			});

			// Sidebar "New File" button
			const btnNew = document.getElementById('fe-btn-new');
			if (btnNew) {
				btnNew.addEventListener('click', function () {
					Editor.newFileFromSidebar();
				});
			}

			// Sidebar "Upload" button
			const btnUpload = document.getElementById('fe-btn-upload');
			const uploadInput = document.getElementById('fe-upload-input');
			if (btnUpload && uploadInput) {
				btnUpload.addEventListener('click', function () {
					Editor.triggerUpload();
				});
				uploadInput.addEventListener('change', function () {
					if (uploadInput.files.length) Editor.uploadFiles(uploadInput.files);
					uploadInput.value = '';
				});
			}

			// Drag-and-drop onto sidebar
			const sidebar = document.getElementById('fe-sidebar');
			sidebar.addEventListener('dragover', function (e) {
				e.preventDefault();
				sidebar.classList.add('fe-drop-active');
			});
			sidebar.addEventListener('dragleave', function (e) {
				if (!sidebar.contains(e.relatedTarget)) sidebar.classList.remove('fe-drop-active');
			});
			sidebar.addEventListener('drop', function (e) {
				e.preventDefault();
				sidebar.classList.remove('fe-drop-active');
				const files = e.dataTransfer.files;
				if (files.length) Editor.uploadFiles(files);
			});

			// Ctrl/Cmd+N => new file in active directory
			document.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
					e.preventDefault();
					Editor.newFileFromSidebar();
				}
			});
		},

		uploadToDir: function (dir) {
			this._uploadDir = dir;
			document.getElementById('fe-upload-input').click();
		},

		triggerUpload: function () {
			const roots = this.cfg.roots || [];
			// If multiple roots and no current file, ask which root
			if (roots.length > 1 && !this.currentPath) {
				this.showRootPicker(function (dir) {
					if (!dir) return;
					Editor._uploadDir = dir;
					document.getElementById('fe-upload-input').click();
				});
				return;
			}
			const dir = this.getActiveDir();
			if (!dir) { this.notice('Select a folder first.', 'info'); return; }
			this._uploadDir = dir;
			document.getElementById('fe-upload-input').click();
		},

		uploadFiles: function (files) {
			const dir = this._uploadDir || this.getActiveDir();
			if (!dir) { this.notice('Select a folder first.', 'info'); return; }

			const formData = new FormData();
			formData.append(this.cfg.csrfName, this.cfg.csrfVal);
			formData.append('dir', dir);
			Array.from(files).forEach(function (f) {
				formData.append('files[]', f);
			});

			// Progress bar — remove any existing one first
			const existingPW = document.getElementById('fe-upload-progress-wrap');
			if (existingPW) existingPW.remove();
			const progressWrap = document.createElement('div');
			progressWrap.id = 'fe-upload-progress-wrap';
			progressWrap.innerHTML =
				'<div id="fe-upload-label">Uploading ' + files.length + ' file' + (files.length > 1 ? 's' : '') + '...</div>' +
				'<div id="fe-upload-bar-wrap"><div id="fe-upload-bar"></div></div>';
			const feTree = document.getElementById('fe-tree');
			feTree.parentNode.insertBefore(progressWrap, feTree);

			const xhr = new XMLHttpRequest();
			xhr.open('POST', this.cfg.ajaxUrl + 'upload/');
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

			xhr.upload.addEventListener('progress', function (e) {
				if (!e.lengthComputable) return;
				const pct = Math.round(e.loaded / e.total * 100);
				document.getElementById('fe-upload-bar').style.width = pct + '%';
				document.getElementById('fe-upload-label').textContent =
					'Uploading... ' + pct + '% (' + Editor.formatSize(e.loaded) + ' / ' + Editor.formatSize(e.total) + ')';
			});

			xhr.addEventListener('load', function () {
				progressWrap.remove();
				Editor._uploadDir = null;
				let data;
				try { data = JSON.parse(xhr.responseText); }
				catch (e) {
					// Empty response usually means post_max_size exceeded
					const hint = xhr.responseText.length === 0
						? ' (file may exceed PHP post_max_size limit)'
						: ': ' + xhr.responseText.substring(0, 120);
					Editor.notice('Upload failed' + hint, 'err');
					return;
				}
				if (data.error) { Editor.notice(data.error, 'err'); return; }

				const results = data.results || [];
				const ok  = results.filter(function (r) { return r.ok; });
				const bad = results.filter(function (r) { return r.error; });

				if (ok.length) {
					Editor.notice('Uploaded ' + ok.length + ' file' + (ok.length > 1 ? 's' : '') + '.', 'ok');
					Editor.reloadContainingDir(dir);
				}
				bad.forEach(function (r) { Editor.notice(r.name + ': ' + r.error, 'err'); });
			});

			xhr.addEventListener('error', function () {
				progressWrap.remove();
				Editor._uploadDir = null;
				Editor.notice('Upload failed: network error', 'err');
			});

			xhr.send(formData);
		},

		getActiveDir: function () {
			if (this.currentPath) {
				const sep = this.currentPath.includes('/') ? '/' : '\\';
				const dir = this.currentPath.substring(0, this.currentPath.lastIndexOf(sep));
				if (dir) return dir;
			}
			// Fallback: first root
			const root = document.querySelector('.fe-root[data-path]');
			return root ? root.dataset.path : null;
		},

		newFileFromSidebar: function () {
			const roots = this.cfg.roots || [];
			const labels = this.cfg.rootLabels || [];
			// If multiple roots and no current file open, ask which root
			if (roots.length > 1 && !this.currentPath) {
				this.showRootPicker(function (dir) {
					if (dir) Editor.promptCreate(dir, 'file');
				});
				return;
			}
			const dir = this.getActiveDir();
			if (!dir) { this.notice('Open a folder first.', 'info'); return; }
			this.promptCreate(dir, 'file');
		},

		showRootPicker: function (cb) {
			const roots = this.cfg.roots || [];
			const labels = this.cfg.rootLabels || [];
			const overlay = document.createElement('div');
			overlay.className = 'fe-modal-overlay';
			const modal = document.createElement('div');
			modal.className = 'fe-modal';
			let html = '<h3>Select destination:</h3><div class="fe-root-picker">';
			roots.forEach(function (r, i) {
				html += '<button class="uk-button uk-button-default uk-button-small fe-root-btn" data-path="' + r + '">' + (labels[i] || r) + '</button>';
			});
			html += '</div><div class="fe-modal-actions"><button class="uk-button uk-button-default uk-button-small" id="fe-rp-cancel">Cancel</button></div>';
			modal.innerHTML = html;
			overlay.appendChild(modal);
			document.body.appendChild(overlay);
			modal.querySelectorAll('.fe-root-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					overlay.remove();
					cb(btn.dataset.path);
				});
			});
			modal.querySelector('#fe-rp-cancel').addEventListener('click', function () { overlay.remove(); cb(null); });
		},

		onDirClick: function (label) {
			const li       = label.parentElement;
			const children = li.querySelector('.fe-children');
			if (!li.classList.contains('loaded') && !li.classList.contains('loading')) {
				li.classList.add('loading');
				this.loadDir(li.dataset.path, children, function () {
					li.classList.remove('loading');
					li.classList.add('loaded');
				});
			}

			const open = children.style.display !== 'none';
			children.style.display = open ? 'none' : '';

			// Rotate chevron
			const chev = label.querySelector('.fe-chevron');
			if (chev) chev.classList.toggle('fe-chevron-open', !open);

			// Swap folder icon
			const folderIcon = label.querySelector('.fe-item-icon');
			if (folderIcon) {
				const newIcon = Editor.mkIcon(open ? 'folder' : 'folder-open');
				newIcon.classList.add('fe-item-icon');
				folderIcon.replaceWith(newIcon);
			}
		},

		loadDir: function (path, container, cb) {
			// Compute nesting depth (0 = direct root children)
			var depth = -1, el = container;
			while ((el = el.parentElement)) {
				if (el.classList && el.classList.contains('fe-children')) depth++;
			}
			if (depth < 0) depth = 0;

			container.innerHTML = '<li class="fe-loading">Loading…</li>';
			this.ajax('list', { path: path }, function (data) {
				if (data.error) { Editor.notice(data.error, 'err'); return; }
				container.innerHTML = '';
				(data.items || []).forEach(function (item) {
					const li    = document.createElement('li');
					const label = document.createElement('span');
					label.className = item.isDir ? 'fe-dir-label' : 'fe-file-label';
					label.dataset.path = item.path;
					label.dataset.name = item.name;
					label.dataset.depth = depth;
					if (!item.isDir) label.dataset.ext = item.ext;

					// Indent spacer
					if (depth > 0) {
						const indent = document.createElement('span');
						indent.className = 'fe-indent';
						indent.style.width = (depth * 14) + 'px';
						label.appendChild(indent);
					}

					// Chevron for dirs, fixed-width spacer for files
					if (item.isDir) {
						const chev = Editor.mkIcon('chevron-right');
						chev.classList.add('fe-chevron');
						label.appendChild(chev);
					} else {
						const spacer = document.createElement('span');
						spacer.className = 'fe-chev-spacer';
						label.appendChild(spacer);
					}

					// Item icon
					const icon = Editor.mkIcon(item.isDir ? 'folder' : Editor.extIcon(item.ext));
					icon.classList.add('fe-item-icon');
					label.appendChild(icon);
					label.appendChild(document.createTextNode(' ' + item.name));
					li.appendChild(label);

					if (item.isDir) {
						li.classList.add('fe-dir');
						li.dataset.path = item.path;
						const sub = document.createElement('ul');
						sub.className = 'fe-children';
						sub.style.display = 'none';
						li.appendChild(sub);
					}

					container.appendChild(li);
				});
				if (cb) cb();
			});
		},

		onFileClick: function (label) {
			if (this.dirty && this.currentPath) {
				if (!confirm('Unsaved changes will be lost. Continue?')) return;
			}
			document.querySelectorAll('.fe-file-label.active').forEach(function (el) {
				el.classList.remove('active');
			});
			label.classList.add('active');
			this.openFile(label.dataset.path, label.dataset.ext);
		},

		// ── Heroicons 2.2 outline (inline SVG sprites) ─────────────────────
		_HI: {
  'folder': '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>',
  'folder-open': '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/>',
  'document-text': '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
  'photo': '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>',
  'cog-6-tooth': '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/> <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
  'document': '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
  'plus': '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>',
  'pencil-square': '<path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>',
  'trash': '<path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>',
  'chevron-right': '<path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>',
  'code-bracket': '<path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5"/>',
  'x-mark': '<path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>',
  'folder-plus': '<path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>',
  'arrow-up-tray': '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>',
},

		mkIcon: function (name, cls) {
			const paths = this._HI[name] || this._HI['document'];
			const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			svg.setAttribute('fill', 'none');
			svg.setAttribute('stroke', 'currentColor');
			svg.setAttribute('stroke-width', '1.5');
			svg.setAttribute('aria-hidden', 'true');
			// SVGAnimatedString: cannot assign className directly on SVG elements
			svg.classList.add('fe-icon');
			if (cls) cls.split(' ').forEach(function(c) { if (c) svg.classList.add(c); });
			svg.innerHTML = paths;
			return svg;
		},

		extIcon: function (ext) {
			const map = {
				php: 'document-text', js: 'code-bracket',  css: 'code-bracket',
				html: 'code-bracket', htm: 'code-bracket', json: 'code-bracket',
				svg: 'code-bracket', md: 'document-text', txt: 'document-text',
				xml: 'code-bracket', htaccess: 'cog-6-tooth', ini: 'cog-6-tooth',
			};
			return map[ext] || 'document';
		},

		// --------------------------------------------------------------- //
		//  Editor
		// --------------------------------------------------------------- //

		openFile: function (path, ext) {
			this.ajax('read', { path: path }, function (data) {
				if (data.error) { Editor.notice(data.error, 'err'); return; }

				Editor.currentPath = path;
				Editor.dirty       = false;

				const name     = path.split(/[\/\\]/).pop();
				const sizeStr  = data.size ? ' (' + Editor.formatSize(data.size) + ')' : '';
				document.getElementById('fe-filename').textContent = name + sizeStr;
				document.getElementById('fe-toolbar').classList.remove('fe-hidden');
				document.getElementById('fe-placeholder').classList.add('fe-hidden');
				document.getElementById('fe-wrap').classList.add('fe-editor-open');

				// Mobile: move toolbar+editor to body overlay (escapes PW transforms)
				if (window.innerWidth < 768) Editor._mobileOpen();

				if (data.type === 'binary') {
					// Show image/binary preview instead of editor
					document.getElementById('fe-editor-wrap').classList.add('fe-hidden');
					document.getElementById('fe-save').classList.add('fe-hidden');
					Editor.showBinaryPreview(data);
				} else {
					// Text file — show CodeMirror
					Editor.hideBinaryPreview();
					document.getElementById('fe-save').classList.remove('fe-hidden');
					document.getElementById('fe-editor-wrap').classList.remove('fe-hidden');
					const modeMap = {
						php: 'application/x-httpd-php', js: 'text/javascript',
						css: 'text/css', html: 'text/html', htm: 'text/html',
						json: 'application/json', xml: 'application/xml',
						svg: 'text/xml', md: 'text/plain', txt: 'text/plain',
					};
					const mode = modeMap[data.extension] || 'text/plain';
					Editor.editor.setOption('mode', mode);
					Editor._loading = true;
					Editor.editor.setValue(data.content);
					Editor.editor.clearHistory();
					Editor._loading = false;
					// Force height and refresh via JS on mobile
					Editor._doRefresh();
				}
			});
		},

		showBinaryPreview: function (data) {
			let el = document.getElementById('fe-binary-preview');
			if (!el) {
				el = document.createElement('div');
				el.id = 'fe-binary-preview';
				document.getElementById('fe-main').appendChild(el);
			}
			el.innerHTML = '';
			el.classList.remove('fe-hidden');
			el.classList.remove('fe-preview-transparent');

			const mime = data.mime || '';
			const src  = data.serveUrl || '';

			if (mime.startsWith('image/')) {
				const img = document.createElement('img');
				img.src = src;
				img.alt = '';
				img.className = 'fe-preview-img';
				// Show checkerboard for transparency
				if (['png','gif','webp','ico','avif'].indexOf(data.extension) !== -1) {
					el.classList.add('fe-preview-transparent');
				}
				el.appendChild(img);
			} else {
				const info = document.createElement('div');
				info.className = 'fe-preview-info';
				const iconSvg = Editor.mkIcon('document');
				info.appendChild(iconSvg);
				const p1 = document.createElement('p');
				p1.textContent = (data.extension || '').toUpperCase() + ' file';
				const p2 = document.createElement('p');
				p2.className = 'fe-preview-size';
				p2.textContent = Editor.formatSize(data.size);
				const dl = document.createElement('a');
				dl.href = src;
				dl.textContent = 'Download';
				dl.className = 'uk-button uk-button-default uk-button-small';
				info.appendChild(p1);
				info.appendChild(p2);
				info.appendChild(dl);
				el.appendChild(info);
			}
		},

		hideBinaryPreview: function () {
			const el = document.getElementById('fe-binary-preview');
			if (el) el.classList.add('fe-hidden');
		},

		formatSize: function (bytes) {
			if (!bytes) return '0 B';
			const units = ['B', 'KB', 'MB', 'GB'];
			const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
			return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
		},

		_mobileOpen: function () {
			// Remove stale overlay
			var ex = document.getElementById('fe-mobile-overlay');
			if (ex) ex.remove();
			// Build overlay directly on body (escapes any PW transform/overflow)
			var overlay = document.createElement('div');
			overlay.id = 'fe-mobile-overlay';
			var toolbar = document.getElementById('fe-toolbar');
			var edWrap  = document.getElementById('fe-editor-wrap');
			overlay.appendChild(toolbar);
			overlay.appendChild(edWrap);
			document.body.appendChild(overlay);
		},

		_mobileClose: function () {
			var overlay = document.getElementById('fe-mobile-overlay');
			if (!overlay) return;
			var main    = document.getElementById('fe-main');
			var toolbar = overlay.querySelector('#fe-toolbar');
			var edWrap  = overlay.querySelector('#fe-editor-wrap');
			var ph      = document.getElementById('fe-placeholder');
			if (toolbar) main.insertBefore(toolbar, main.firstChild);
			if (edWrap && ph) main.insertBefore(edWrap, ph);
			else if (edWrap) main.appendChild(edWrap);
			overlay.remove();
		},

		_doRefresh: function () {
			// setTimeout(1) lets browser paint DOM before CM measures dimensions
			setTimeout(function () {
				Editor.editor.refresh();
				Editor.editor.focus();
			}, 50);
		},

		saveFile: function () {
			if (!this.currentPath) return;
			// Don't save binary/preview files
			const preview = document.getElementById('fe-binary-preview');
			if (preview && !preview.classList.contains('fe-hidden')) return;
			this.ajax('save', { path: this.currentPath, content: this.editor.getValue() }, function (data) {
				if (data.error) { Editor.notice(data.error, 'err'); return; }
				Editor.dirty = false;
				Editor.notice('Saved.', 'ok');
			});
		},

		// --------------------------------------------------------------- //
		//  Toolbar
		// --------------------------------------------------------------- //

		bindToolbar: function () {
			document.getElementById('fe-save').addEventListener('click', function () {
				Editor.saveFile();
			});

			document.getElementById('fe-close').addEventListener('click', function () {
				if (Editor.dirty && !confirm('Unsaved changes. Close anyway?')) return;
				Editor.closeEditor();
			});

			// Mobile back button — returns to file tree
			const backBtn = document.getElementById('fe-back');
			if (backBtn) {
				backBtn.addEventListener('click', function () {
					if (Editor.dirty && !confirm('Unsaved changes. Go back anyway?')) return;
					Editor.closeEditor();
				});
			}

			// Ctrl/Cmd+S — blocked when modal is open
			document.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && e.key === 's') {
					e.preventDefault();
					if (!document.querySelector('.fe-modal-overlay')) Editor.saveFile();
				}
			});
		},

		closeEditor: function () {
			this.currentPath = null;
			this.dirty       = false;

			document.querySelectorAll('.fe-file-label.active').forEach(function (el) {
				el.classList.remove('active');
			});
			document.getElementById('fe-toolbar').classList.add('fe-hidden');
			document.getElementById('fe-editor-wrap').classList.add('fe-hidden');
			document.getElementById('fe-placeholder').classList.remove('fe-hidden');
			Editor.hideBinaryPreview();
			// Mobile: restore elements from body overlay
			if (window.innerWidth < 768) Editor._mobileClose();
			document.getElementById('fe-wrap').classList.remove('fe-editor-open');
		},

		// --------------------------------------------------------------- //
		//  Context menu
		// --------------------------------------------------------------- //

		bindContextMenu: function () {
			document.getElementById('fe-tree').addEventListener('contextmenu', function (e) {
				const label = e.target.closest('.fe-dir-label, .fe-file-label');
				if (!label) return;
				e.preventDefault();
				Editor.showContextMenu(e.clientX, e.clientY, label);
			});

			document.addEventListener('click', function () {
				Editor.hideContextMenu();
			});
		},

		showContextMenu: function (x, y, label) {
			this.hideContextMenu();
			const isDir = label.classList.contains('fe-dir-label');
			const isRoot = label.closest('.fe-root') === label.parentElement;
			const path   = label.dataset.path;
			const dir    = isDir ? path : (label.parentElement ? label.parentElement.closest('[data-path]')?.dataset.path || '' : '');

			const menu = document.createElement('div');
			menu.id = 'fe-ctx-menu';

			const items = [];
			if (isDir) {
				items.push({ icon: 'document-text', label: 'New File',   action: function () { Editor.promptCreate(path, 'file'); } });
				items.push({ icon: 'folder-plus',  label: 'New Folder', action: function () { Editor.promptCreate(path, 'dir'); } });
				items.push({ icon: 'arrow-up-tray', label: 'Upload',    action: function () { Editor.uploadToDir(path); } });
				items.push({ sep: true });
			}
			if (!isRoot) {
				items.push({ icon: 'pencil-square', label: 'Rename', action: function () { Editor.promptRename(label); } });
				items.push({ icon: 'trash', label: 'Delete', action: function () { Editor.confirmDelete(path); }, cls: 'danger' });
			}

			if (!items.length) return;

			items.forEach(function (item) {
				if (item.sep) {
					const hr = document.createElement('hr');
					menu.appendChild(hr);
				} else {
					const div = document.createElement('div');
					div.className = 'fe-ctx-item' + (item.cls ? ' ' + item.cls : '');
					if (item.icon) div.appendChild(Editor.mkIcon(item.icon));
					div.appendChild(document.createTextNode(' ' + item.label));
					div.addEventListener('click', function (e) {
						e.stopPropagation();
						Editor.hideContextMenu();
						item.action();
					});
					menu.appendChild(div);
				}
			});

			document.body.appendChild(menu);

			// Reposition if near edge
			const rect = menu.getBoundingClientRect();
			if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
			if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
			menu.style.left = x + 'px';
			menu.style.top  = y + 'px';
		},

		hideContextMenu: function () {
			const m = document.getElementById('fe-ctx-menu');
			if (m) m.remove();
		},

		// --------------------------------------------------------------- //
		//  Create
		// --------------------------------------------------------------- //

		promptCreate: function (dir, type) {
			const label = type === 'dir' ? 'New folder name:' : 'New file name (with extension):';
			this.showModal(label, type === 'php' ? 'new-template.php' : '', function (name) {
				if (!name) return;
				Editor.ajax('create', { dir: dir, name: name, type: type }, function (data) {
					if (data.error) { Editor.notice(data.error, 'err'); return; }
					Editor.notice((type === 'dir' ? 'Folder' : 'File') + ' created.', 'ok');
					Editor.reloadContainingDir(dir, function () {
						if (type !== 'dir' && data.path) {
							const ext = name.includes('.') ? name.split('.').pop() : '';
							Editor.currentPath = data.path;
							Editor.dirty = false;
							Editor.openFile(data.path, ext);
						}
					});
				});
			});
		},

		// --------------------------------------------------------------- //
		//  Rename
		// --------------------------------------------------------------- //

		promptRename: function (label) {
			const path = label.dataset.path;
			const currentName = path.split(/[\/\\]/).pop();
			this.showModal('Rename to:', currentName, function (name) {
				if (!name || name === currentName) return;
				Editor.ajax('rename', { path: path, name: name }, function (data) {
					if (data.error) { Editor.notice(data.error, 'err'); return; }
					Editor.notice('Renamed.', 'ok');
					if (Editor.currentPath === path) Editor.closeEditor();
					Editor.reloadContainingDir(path);
				});
			});
		},

		// --------------------------------------------------------------- //
		//  Delete
		// --------------------------------------------------------------- //

		confirmDelete: function (path) {
			const name = path.split(/[\/\\]/).pop();
			const safeMode = this.cfg.safeDelete;
			const msg = safeMode
				? 'Move "' + name + '" to trash?'
				: 'Permanently delete "' + name + '"? This cannot be undone.';
			if (!confirm(msg)) return;
			this.ajax('delete', { path: path }, function (data) {
				if (data.error) { Editor.notice(data.error, 'err'); return; }
				Editor.notice(data.trashed ? 'Moved to trash.' : 'Deleted.', 'ok');
				if (Editor.currentPath === path) Editor.closeEditor();
				Editor.reloadContainingDir(path);
			});
		},

		// --------------------------------------------------------------- //
		//  Helpers
		// --------------------------------------------------------------- //

		reloadContainingDir: function (path, cb) {
			// Normalize path separator
			const sep = path.includes('/') ? '/' : '\\';
			const normPath = path.replace(/\\/g, '/');

			// Find the deepest .fe-dir ancestor that contains this path
			const items = document.querySelectorAll('.fe-dir[data-path]');
			let best = null, bestLen = 0;
			items.forEach(function (li) {
				const p = (li.dataset.path || '').replace(/\\/g, '/');
				const normP = p.endsWith('/') ? p : p + '/';
				const normTarget = normPath.endsWith('/') ? normPath : normPath + '/';
				if (normTarget.startsWith(normP) && normP.length > bestLen) {
					best = li; bestLen = normP.length;
				}
			});

			// Fallback: if path IS a root dir itself, use it directly
			if (!best) {
				items.forEach(function (li) {
					const p = (li.dataset.path || '').replace(/\\/g, '/');
					if (p === normPath || p === normPath.replace(/\/$/, '')) {
						best = li;
					}
				});
			}

			if (!best) { if (cb) cb(); return; }
			const children = best.querySelector('.fe-children');
			if (!children) { if (cb) cb(); return; }
			best.classList.remove('loaded');
			Editor.loadDir(best.dataset.path, children, function () {
				best.classList.add('loaded');
				children.style.display = '';
				const dirLabel = best.querySelector(':scope > .fe-dir-label');
				if (dirLabel) {
					const chev = dirLabel.querySelector('.fe-chevron');
					if (chev) chev.classList.add('fe-chevron-open');
					const folderIcon = dirLabel.querySelector('.fe-item-icon');
					if (folderIcon) {
						const ni = Editor.mkIcon('folder-open');
						ni.classList.add('fe-item-icon');
						folderIcon.replaceWith(ni);
					}
				}
				if (cb) cb();
			});
		},

		ajax: function (action, params, cb) {
			const body = new FormData();
			body.append(this.cfg.csrfName, this.cfg.csrfVal);
			Object.keys(params).forEach(function (k) { body.append(k, params[k]); });

			const url = this.cfg.ajaxUrl + action + '/';

			fetch(url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { return r.json(); })
				.then(cb)
				.catch(function (err) {
					Editor.notice('Network error: ' + err.message, 'err');
				});
		},

		notice: function (msg, type) {
			const container = document.getElementById('fe-notices') || document.body;
			const el = document.createElement('div');
			el.className = 'fe-notice ' + (type || 'info');
			el.textContent = msg;
			container.appendChild(el);
			setTimeout(function () {
				el.style.opacity = '0';
				el.style.transition = 'opacity 0.2s';
				setTimeout(function () { el.remove(); }, 220);
			}, 3200);
		},

		showModal: function (label, defaultValue, cb) {
			// Close any existing modal first
			const existing = document.querySelector('.fe-modal-overlay');
			if (existing) existing.remove();

			const overlay = document.createElement('div');
			overlay.className = 'fe-modal-overlay';
			const modal = document.createElement('div');
			modal.className = 'fe-modal';
			const safeDefault = String(defaultValue).replace(/&/g,'&amp;').replace(/"/g,'&quot;');
			modal.innerHTML = '<h3>' + label + '</h3>' +
				'<input type="text" id="fe-modal-input" class="uk-input uk-form-small" value="' + safeDefault + '">' +
				'<div class="fe-modal-actions">' +
				'<button class="uk-button uk-button-default uk-button-small" id="fe-modal-cancel">Cancel</button>' +
				'<button class="uk-button uk-button-primary uk-button-small" id="fe-modal-ok">OK</button>' +
				'</div>';
			overlay.appendChild(modal);
			document.body.appendChild(overlay);

			const input = modal.querySelector('#fe-modal-input');
			input.focus();
			input.select();

			const ok = function () {
				const val = input.value.trim();
				overlay.remove();
				cb(val);
			};

			modal.querySelector('#fe-modal-ok').addEventListener('click', ok);
			modal.querySelector('#fe-modal-cancel').addEventListener('click', function () {
				overlay.remove();
			});
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') ok();
				if (e.key === 'Escape') overlay.remove();
			});
		},
	};

	window.Editor = Editor;

}());
