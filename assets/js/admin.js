/**
 * Wicket Importer Admin Script
 *
 * Task 7: Upload Type toggle (CSV / Manual show-hide).
 * Task 8: CSV drag-drop zone + file preview + fetch upload + Proceed binding.
 * Task 11: Individual form submit + Restart binding.
 *
 * REST config is on window.WicketImportAdmin (localized by Assets.php):
 *   { restRoot, restNonce, screen, sessionId, maxFileSize, confirmationUrl, uploadUrl }
 *
 * Strings use the native WP JS i18n package (wp-i18n): wp.i18n.__() and
 * wp.i18n.sprintf() / wp.i18n._n() with the 'wicket-wp-importer' text domain.
 * Translations are loaded via wp_set_script_translations() in Assets.php.
 *
 * No jQuery: this plugin ships vanilla DOM only.
 */
(function() {
	'use strict';

	if (typeof WicketImportAdmin === 'undefined') {
		return;
	}

	var cfg = WicketImportAdmin;
	var DOMAIN = 'wicket-wp-importer';
	var SCAN_LIMIT = 2 * 1024 * 1024; // 2 MB row-count preview slice

	// wp-i18n shorthands (deps include wp-i18n). Plain-English fallbacks if
	// the package ever fails to load so the script degrades rather than dies.
	function t(s) {
		return (window.wp && wp.i18n) ? wp.i18n.__(s, DOMAIN) : s;
	}
	function tn(one, many, n) {
		return (window.wp && wp.i18n) ? wp.i18n._n(one, many, n, DOMAIN) : (n === 1 ? one : many);
	}
	function sprintf(fmt) {
		if (!window.wp || !wp.i18n || !wp.i18n.sprintf) { return fmt; }
		return wp.i18n.sprintf.apply(wp.i18n, arguments);
	}

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function() {
		var page = document.querySelector('.wicket-importer');
		if (!page) {
			return;
		}

		bindUploadTypeToggle();
		bindDropzone();
		bindProceedButton(page);
		bindRestartButton(page);
		bindIndividualForm();
		applyFrozenColumns();
		bindBatchLiveStatus(page);
		bindQueueCheckAll(page);
		window.addEventListener('resize', applyFrozenColumns);
		window.addEventListener('load', applyFrozenColumns);
	});

	/**
	 * Live batch status (WWID-2439). The old review page rendered once and
	 * left the user hitting reload (QA measured up to 12 per batch) to find
	 * out whether a background Action Scheduler run had finished.
	 *
	 * While the wrapper carries data-batch-status of a live state, poll the
	 * unified progress endpoint every 5s, move
	 * the counts and progress bar in place, and reload ONCE when the batch
	 * lands so the server-rendered tables paint the new state. A stalled run
	 * (no staging activity past the threshold) swaps the spinner copy for a
	 * resume banner backed by POST /kick, and drops to a slow poll so an
	 * externally fixed chain still gets noticed.
	 *
	 * Terminal states never poll: the page is static truth again.
	 */
	function bindBatchLiveStatus(page) {
		var status = page.dataset.batchStatus || '';
		var sessionId = page.dataset.sessionId || '';
		var LIVE = ['pending', 'running', 'phase2_running'];
		var TERMINAL = ['pending_review', 'processing_complete', 'completed', 'failed', 'cleared', 'abandoned'];
		var FAST_MS = 5000;
		var SLOW_MS = 30000;
		if (!sessionId || LIVE.indexOf(status) === -1 || !cfg.restRoot) {
			return;
		}

		var url = cfg.restRoot + '/session/' + encodeURIComponent(sessionId) + '/progress';
		var kickUrl = cfg.restRoot + '/session/' + encodeURIComponent(sessionId) + '/kick';
		var bar = page.querySelector('.wicket-importer-progress');
		var barFill = page.querySelector('.wicket-importer-progress__bar');
		var barText = page.querySelector('.wicket-importer-progress__text');
		var stateEl = page.querySelector('.wicket-importer-state');
		var interval = FAST_MS;
		var timer = null;
		var reloading = false;

		function stopPolling() {
			if (timer) { window.clearInterval(timer); timer = null; }
		}

		function settledCount(p) {
			if (p.status === 'phase2_running') {
				var counts = p.counts || {};
				return (counts.imported || 0) + (counts.failed || 0) + (counts.needs_review || 0);
			}
			var ph1 = p.phase1 || {};
			return (ph1.processed || 0) + (ph1.failed || 0) + (ph1.needs_review || 0);
		}

		function renderStalled(p) {
			var gate = page.querySelector('.wicket-importer-review-gate');
			if (!gate || gate.querySelector('.wicket-importer-stalled')) { return; }
			var box = document.createElement('div');
			box.className = 'wicket-importer-stalled';
			var msg = document.createElement('p');
			msg.textContent = t('Processing has paused. The background worker has not advanced for a while.');
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button';
			btn.textContent = t('Resume processing');
			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.textContent = t('Resuming…');
				fetch(kickUrl, {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.restNonce },
					credentials: 'same-origin'
				})
					.then(toJson)
					.then(function() {
						box.remove();
						interval = FAST_MS;
						startPolling();
					})
					.catch(function(err) {
						btn.disabled = false;
						btn.textContent = t('Resume processing');
						window.WicketImportShowNotice(err.message || t('The batch could not be resumed.'), 'error');
					});
			});
			box.appendChild(msg);
			box.appendChild(btn);
			gate.insertBefore(box, gate.firstChild);
		}

		function tick() {
			if (reloading) { return; }
			fetch(url, {
				headers: { 'X-WP-Nonce': cfg.restNonce },
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function(p) {
					if (!p || !p.status) { return; }
					if (TERMINAL.indexOf(p.status) !== -1) {
						// One reload paints the server-rendered review state;
						// the wrapper's data-batch-status ends the loop after it.
						reloading = true;
						stopPolling();
						window.location.reload();
						return;
					}
					if (stateEl) {
						stateEl.textContent = humanState(p.status);
					}
					var ph1 = p.phase1 || {};
					setCount('total', p.total_rows);
					setCount('processed', ph1.processed);
					setCount('failed', ph1.failed);
					setCount('needs_review', ph1.needs_review);
					var total = p.total_rows || 0;
					var settled = Math.min(total, settledCount(p));
					var pct = total > 0 ? Math.floor((settled / total) * 100) : 0;
					if (barFill) { barFill.style.width = pct + '%'; }
					if (bar) { bar.setAttribute('aria-valuenow', String(pct)); }
					if (barText) {
						barText.textContent = sprintf(t('%1$d of %2$d rows processed'), settled, total);
					}
					if (p.is_stalled) {
						renderStalled(p);
						if (interval !== SLOW_MS) {
							interval = SLOW_MS;
							stopPolling();
							startPolling();
						}
					}
				})
				.catch(function() {
					// A failed tick (nonce expiry, hiccup) is not fatal; keep the
					// last known state on screen rather than reloading over the user.
				});
		}

		function startPolling() {
			stopPolling();
			timer = window.setInterval(tick, interval);
		}

		startPolling();
	}

	/**
	 * Queue "select all" (WWID-2439). Vanilla DOM: the plugin ships no jQuery,
	 * so the header checkbox flips its row checkboxes with a change listener
	 * instead of an inline handler.
	 */
	function bindQueueCheckAll(page) {
		var master = page.querySelector('.wicket-importer-check-all');
		if (!master) { return; }
		master.addEventListener('change', function() {
			page.querySelectorAll('.wicket-importer-batch-check').forEach(function(box) {
				box.checked = master.checked;
			});
		});
	}

	function humanState(status) {
		var map = {
			pending: t('Queued'),
			running: t('Creating orders (step 1)'),
			pending_review: t('Ready for payment matching'),
			phase2_running: t('Matching payments (step 2)'),
			processing_complete: t('Completed, needs attention'),
			completed: t('Completed'),
			failed: t('Failed'),
			cleared: t('Cancelled'),
			abandoned: t('Cancelled')
		};
		return map[status] || status;
	}

	function setCount(key, value) {
		var el = document.querySelector('[data-count="' + key + '"]');
		if (el && typeof value === 'number') {
			el.textContent = String(value);
		}
	}

	/**
	 * Frozen columns on the flagged-rows table (WWID-2253): Line + the first
	 * two data columns stay visible while the admin scrolls right toward the
	 * Status/Reason columns. CSS makes the cells position:sticky; the sticky
	 * left offsets depend on the rendered column widths, so they are measured
	 * here from the header row and set inline. Reruns on resize/load.
	 */
	function applyFrozenColumns() {
		var table = document.querySelector('.wicket-importer-flagged-table');
		if (!table || !table.tHead || !table.tHead.rows.length) {
			return;
		}
		var headCells = table.tHead.rows[0].cells;
		var count = Math.min(3, headCells.length);
		var offsets = [];
		var left = 0;
		for (var i = 0; i < count; i++) {
			offsets.push(left);
			left += headCells[i].getBoundingClientRect().width;
		}
		var sections = [table.tHead].concat(Array.prototype.slice.call(table.tBodies));
		for (var s = 0; s < sections.length; s++) {
			var rows = sections[s].rows;
			for (var r = 0; r < rows.length; r++) {
				var cells = rows[r].cells;
				for (var c = 0; c < count && c < cells.length; c++) {
					cells[c].style.left = Math.round(offsets[c]) + 'px';
				}
			}
		}
	}

	// ------------------------------------------------------------------
	// Inline notice helper (shared by all screens)
	// ------------------------------------------------------------------

	/**
	 * Show an inline notice inside the .wicket-importer-notices container.
	 * No-op when message is empty (S1: never render an empty notice box).
	 * @param {string} message
	 * @param {string} [type='error']  error | warning | success | info
	 */
	window.WicketImportShowNotice = function(message, type) {
		type = type || 'error';
		if (!message) {
			return;
		}
		var notices = document.querySelector('.wicket-importer-notices');
		if (!notices) {
			return;
		}
		// Clear previous notices of the same type to avoid stacking.
		var existing = notices.querySelectorAll('.notice-' + type);
		existing.forEach(function(n) { n.remove(); });

		var div = document.createElement('div');
		div.className = 'notice notice-' + type;
		var p = document.createElement('p');
		p.textContent = message;
		div.appendChild(p);
		notices.appendChild(div);
		notices.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	};

	// ------------------------------------------------------------------
	// Run-in-progress banner (Proceed -> POST /run)
	// ------------------------------------------------------------------
	// The inline import sets ignore_user_abort(true), so it survives the user
	// leaving the page. Show a persistent banner that says so + links to History.
	function showRunInProgress() {
		if (!cfg.historyUrl) {
			window.WicketImportShowNotice(t('Importing rows \u2014 this may take a moment.'), 'info');
			return;
		}
		var notices = document.querySelector('.wicket-importer-notices');
		if (!notices) {
			return;
		}
		var prior = notices.querySelector('.wicket-importer-run-progress');
		if (prior) {
			prior.remove();
		}
		var div = document.createElement('div');
		div.className = 'notice notice-info wicket-importer-run-progress';
		var head = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = t('Import is processing in the background.');
		head.appendChild(strong);
		head.appendChild(document.createTextNode(' ' + t('You can safely leave this page; the import keeps running if you navigate away.')));
		var track = document.createElement('p');
		track.appendChild(document.createTextNode(t('Track progress on the') + ' '));
		var link = document.createElement('a');
		link.href = cfg.historyUrl;
		link.textContent = t('Import History');
		track.appendChild(link);
		track.appendChild(document.createTextNode(' ' + t('tab.')));
		div.appendChild(head);
		div.appendChild(track);
		notices.appendChild(div);
		notices.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	// ------------------------------------------------------------------
	// Task 7: Upload Type toggle
	// ------------------------------------------------------------------

	function bindUploadTypeToggle() {
		var radios = document.querySelectorAll('input[name="wicket_import_upload_type"]');
		if (!radios.length) {
			return;
		}
		var csvSection = document.getElementById('wicket-import-csv');
		var manualSection = document.getElementById('wicket-import-manual');

		function showSection(value) {
			if (csvSection) { csvSection.hidden = value !== 'csv'; }
			if (manualSection) { manualSection.hidden = value !== 'manual'; }
		}

		radios.forEach(function(radio) {
			radio.addEventListener('change', function() {
				var checked = document.querySelector('input[name="wicket_import_upload_type"]:checked');
				showSection(checked ? checked.value : '');
			});
		});

		var initial = document.querySelector('input[name="wicket_import_upload_type"]:checked');
		showSection(initial ? initial.value : '');
	}

	// ------------------------------------------------------------------
	// Task 8.1 + 8.2: Dropzone + file preview + fetch upload
	// ------------------------------------------------------------------

	function bindDropzone() {
		var zone = document.getElementById('wicket-import-dropzone');
		if (!zone) {
			return;
		}

		var input = document.getElementById('wicket-import-file-input');
		var preview = document.getElementById('wicket-import-file-preview');
		if (!input || !preview) {
			return;
		}
		var nameEl = preview.querySelector('.wicket-importer-file-name');
		var sizeEl = preview.querySelector('.wicket-importer-file-size');
		var rowsEl = preview.querySelector('.wicket-importer-file-rows');
		var uploadBtn = preview.querySelector('.wicket-importer-upload-btn');
		var clearBtn = preview.querySelector('.wicket-importer-clear-btn');
		var selectedFile = null;

		// --- click / keyboard to browse ---------------------------------
		zone.addEventListener('click', function() {
			input.click();
		});
		zone.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				input.click();
			}
		});

		// --- drag events ------------------------------------------------
		zone.addEventListener('dragover', function(e) {
			e.preventDefault();
			zone.classList.add('is-dragover');
		});
		zone.addEventListener('dragleave', function(e) {
			e.preventDefault();
			zone.classList.remove('is-dragover');
		});
		zone.addEventListener('drop', function(e) {
			e.preventDefault();
			zone.classList.remove('is-dragover');
			var files = e.dataTransfer && e.dataTransfer.files;
			if (files && files.length) {
				handleFileSelected(files[0]);
			}
		});

		// --- file input change ------------------------------------------
		input.addEventListener('change', function() {
			if (input.files && input.files.length) {
				handleFileSelected(input.files[0]);
			}
		});

		// --- clear ------------------------------------------------------
		if (clearBtn) {
			clearBtn.addEventListener('click', function() {
				cancelRowCount(); // cancel any pending row-count read (nit 3)
				selectedFile = null;
				input.value = '';
				preview.hidden = true;
			});
		}

		function handleFileSelected(file) {
			// Extension gate (client-side nicety; server is authoritative).
			var name = (file.name || '').toLowerCase();
			if (!name.endsWith('.csv')) { // nit 4: clearer than indexOf trick
				window.WicketImportShowNotice(t('Only .csv files are accepted.'), 'error');
				return;
			}
			// Size gate (client-side nicety; server enforces the real cap).
			if (cfg.maxFileSize && file.size > cfg.maxFileSize) {
				window.WicketImportShowNotice(
					sprintf(t('The file exceeds the maximum size of %s.'), formatBytes(cfg.maxFileSize)),
					'error'
				);
				return;
			}

			cancelRowCount(); // invalidate any prior pending read (nit 3)
			selectedFile = file;
			if (nameEl) { nameEl.textContent = file.name; }
			if (sizeEl) { sizeEl.textContent = formatBytes(file.size); }
			if (rowsEl) { rowsEl.textContent = ''; } // populated after the row-count read
			preview.hidden = false;

			// Read a slice to show an APPROXIMATE row count (S2): never exact
			// (quoted newlines, partial slice on big files), so label it so.
			readRowCount(file, function(info) {
				var label = sprintf(
					tn('~%d row (approx.)', '~%d rows (approx.)', info.count),
					info.count
				);
				if (info.truncated) {
					label += ' ' + t('(first 2 MB scanned)');
				}
				if (rowsEl) { rowsEl.textContent = label; }
			});
		}

		// --- upload confirm (8.2) ---------------------------------------
		// Import type (member vs cheque/lockbox, spec Story 1). The radios render
		// only when the site enables the cheque flow; absent radios = member.
		function getImportType() {
			var checked = document.querySelector('input[name="wicket_import_import_type"]:checked');
			return checked ? checked.value : 'member';
		}

		// Cheque uploads are CSV-only: hide the member-only upload-type toggle
		// (CSV / Manual Entry) while cheque is selected, restore it on member.
		// WWID-2439: also swap the dropzone prompt and the template download per
		// type. Before this, selecting "Cheque renewals" changed nothing: the
		// template button still served member columns, so the downloaded file
		// could not survive the cheque upload validation.
		function bindImportTypeToggle() {
			var radios = document.querySelectorAll('input[name="wicket_import_import_type"]');
			if (!radios.length) { return; }
			var uploadType = document.querySelector('.wicket-importer-upload-type');
			var templateBtn = document.querySelector('.wicket-importer-template-btn');
			var promptText = document.querySelector('.wicket-importer-dropzone-prompt-text');
			var PROMPTS = {
				member: t('Drop CSV file here, or click to browse'),
				cheque: t('Drop the lockbox cheque file here, or click to browse')
			};
			function sync() {
				var isCheque = getImportType() === 'cheque';
				if (uploadType) {
					uploadType.hidden = isCheque;
					if (isCheque) {
						var csvRadio = uploadType.querySelector('input[value="csv"]');
						if (csvRadio) { csvRadio.checked = true; }
					}
				}
				if (templateBtn) {
					var perType = isCheque ? templateBtn.dataset.templateCheque : templateBtn.dataset.templateMember;
					if (perType) { templateBtn.href = perType; }
				}
				if (promptText && PROMPTS[getImportType()]) {
					promptText.textContent = PROMPTS[getImportType()];
				}
			}
			radios.forEach(function(r) { r.addEventListener('change', sync); });
			sync();
		}
		bindImportTypeToggle();

		if (uploadBtn) {
			uploadBtn.addEventListener('click', function() {
				if (!selectedFile) {
					return;
				}
				var isCheque = getImportType() === 'cheque' && !!uploadBtn.dataset.chequeUploadUrl;
				var url = isCheque ? uploadBtn.dataset.chequeUploadUrl : uploadBtn.dataset.uploadUrl;
				var validationUrl = uploadBtn.dataset.validationUrl;

				setBusy(true);
				// S1: no empty-info notice here. Stale errors are cleared by the
				// success-path redirect; on failure WicketImportShowNotice replaces.

				var data = new FormData();
				data.append('file', selectedFile);
				var delim = document.querySelector('input[name="wicket_import_csv_delimiter"]:checked');
				if (delim) {
					data.append('delimiter', delim.value);
				}

				fetch(url, {
					method: 'POST',
					headers: { 'X-WP-Nonce': cfg.restNonce },
					body: data,
					credentials: 'same-origin'
				})
					.then(toJson)
					.then(function(result) {
						if (result && result.session_id) {
							// Redirect to the validation screen with the new session; a
							// cheque pass stays on its flow (?flow=cheque) so the wizard
							// renders the cheque run button and redirect.
							window.location.href = validationUrl + '&session_id=' + encodeURIComponent(result.session_id) + (isCheque ? '&flow=cheque' : '');
							return;
						}
						throw new Error(extractErrorMessage(result) || t('Upload failed. Please try again.'));
					})
					.catch(function(err) {
						setBusy(false);
						window.WicketImportShowNotice(err.message, 'error');
					});
			});
		}

		// Disable the zone + buttons while a request is in flight (nit 5):
		// prevents dropping/selecting a second file whose preview would then
		// disagree with the file actually being uploaded.
		function setBusy(busy) {
			uploadBtn.disabled = busy;
			if (busy) {
				uploadBtn.textContent = t('Uploading\u2026');
				zone.classList.add('is-busy');
			} else {
				uploadBtn.textContent = t('Validate & Upload');
				zone.classList.remove('is-busy');
			}
		}
	}

	// ------------------------------------------------------------------
	// Task 8.2: Proceed button (validation screen -> POST /run -> confirmation)
	// ------------------------------------------------------------------

	function bindProceedButton(page) {
		page.addEventListener('click', function(e) {
			var btn = e.target.closest && e.target.closest('.wicket-importer-proceed');
			if (!btn || !page.contains(btn)) {
				return;
			}
			var sessionId = btn.dataset.sessionId;
			var runUrl = btn.dataset.runUrl;
			if (!runUrl || !sessionId) {
				return;
			}

			var originalText = btn.textContent;
			btn.disabled = true;
			btn.textContent = t('Processing\u2026');
			var restartBtns = page.querySelectorAll('.wicket-importer-restart');
			restartBtns.forEach(function(b) { b.disabled = true; });
			showRunInProgress();

			fetch(runUrl, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.restNonce },
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function(result) {
					if (result && result.session_id) {
						// S5: confirmationUrl is always localized by Assets.php;
						// no silent relative-path fallback (fail visibly if absent).
						// Cheque runs (data-redirect) land on the Cheque Review tab
						// instead: the run is async on Action Scheduler.
						window.location.href = btn.dataset.redirect
							|| (cfg.confirmationUrl + '&session_id=' + encodeURIComponent(sessionId));
						return;
					}
					throw new Error(extractErrorMessage(result) || t('Import run failed. See the error below.'));
				})
				.catch(function(err) {
					btn.disabled = false;
					btn.textContent = originalText;
					restartBtns.forEach(function(b) { b.disabled = false; });
					window.WicketImportShowNotice(err.message, 'error');
				});
		});
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Parse a fetch Response, surfacing the REST error message on non-2xx.
	 * WP REST returns errors as { code, message, data: { status } }.
	 */
	function toJson(response) {
		return response.text().then(function(text) {
			var data = null;
			try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
			if (!response.ok) {
				var msg = extractErrorMessage(data) || 'HTTP ' + response.status;
				var err = new Error(msg);
				// Attach the REST payload's inner .data (where WP REST puts
				// status + custom keys like field_errors), so the catch can read
				// err.data.field_errors. WP REST error shape: {code, message, data:{...}}.
				err.data = (data && data.data) ? data.data : data;
				throw err;
			}
			return data;
		});
	}

	function extractErrorMessage(data) {
		if (!data) { return ''; }
		if (typeof data === 'string') { return data; }
		if (data.message) { return data.message; }
		if (data.data && data.data.message) { return data.data.message; }
		return '';
	}

	/**
	 * Approximate row count for the preview (S2). Returns {count, truncated}:
	 *   count     non-empty lines minus header
	 *   truncated true when only the first SCAN_LIMIT bytes were read
	 * Caller is responsible for labeling the result as approximate.
	 */
	var rowCountToken = 0;
	function readRowCount(file, cb) {
		var token = ++rowCountToken;
		var reader = new FileReader();
		reader.onload = function() {
			if (token !== rowCountToken) {
				return; // a newer selection / clear invalidated this read (nit 3)
			}
			var text = String(reader.result || '');
			var lines = text.split(/\r?\n/);
			var count = 0;
			for (var i = 0; i < lines.length; i++) {
				if (lines[i].trim() !== '') { count++; }
			}
			cb({ count: Math.max(0, count - 1), truncated: file.size > SCAN_LIMIT });
		};
		reader.onerror = function() {
			if (token !== rowCountToken) { return; }
			cb({ count: 0, truncated: file.size > SCAN_LIMIT });
		};
		var slice = file.slice ? file.slice(0, SCAN_LIMIT) : file;
		reader.readAsText(slice);
	}
	/**
	 * Invalidate any in-flight row-count read (nit 3). Bumps the shared
	 * generation so pending FileReader callbacks no-op on completion.
	 */
	function cancelRowCount() { rowCountToken++; }

	function formatBytes(bytes) {
		if (!bytes) { return '0 B'; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		if (i >= units.length) { i = units.length - 1; }
		return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
	}

	// bindRestartButton() (Task 9.3) implements the Restart -> DELETE /session/{id} flow.

	// ------------------------------------------------------------------
	// Task 11.3 + 11.4: Individual form submit -> validate + import -> confirmation
	// ------------------------------------------------------------------

	function bindIndividualForm() {
		var form = document.getElementById('wicket-import-individual-form');
		if (!form) {
			return;
		}

		form.addEventListener('submit', function(e) {
			e.preventDefault();

			var btn = form.querySelector('.wicket-importer-individual-submit');
			var url = btn ? btn.dataset.individualUrl : '';
			var confirmationUrl = btn ? btn.dataset.confirmationUrl : '';
			var originalText = btn ? btn.textContent : '';

			// Clear previous inline field errors.
			form.querySelectorAll('.wicket-importer-field-error').forEach(function(n) { n.remove(); });
			form.querySelectorAll('.has-error').forEach(function(n) { n.classList.remove('has-error'); });

			// Gather all named form fields into a flat key=>value object.
			// NOTE: FormData.entries() keeps only the LAST value when multiple
			// inputs share a name (checkbox groups, repeaters). Multi-value
			// fields should use name[] and a custom collector — flagged for
			// OBA Task 33.1's dynamic state rows.
			var fd = new FormData(form);
			var fields = {};
			fd.forEach(function(value, key) { fields[key] = value; });

			if (btn) {
				btn.disabled = true;
				btn.textContent = t('Processing\u2026');
			}

			fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce
				},
				body: JSON.stringify(fields),
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function(result) {
					if (result && result.session_id) {
						window.location.href = confirmationUrl + '&session_id=' + encodeURIComponent(result.session_id);
						return;
					}
					throw new Error(extractErrorMessage(result) || t('Submission failed. Please try again.'));
				})
				.catch(function(err) {
					if (btn) {
						btn.disabled = false;
						btn.textContent = originalText;
					}

					// WP REST 400 carries field_errors: pin them to the matching inputs.
					var fieldErrors = err.data && err.data.field_errors;
					if (fieldErrors && typeof fieldErrors === 'object') {
						Object.keys(fieldErrors).forEach(function(key) {
							var fieldInput = form.querySelector('[name="' + CSS.escape(key) + '"]');
							if (fieldInput) {
								var fieldEl = fieldInput.closest('.wicket-importer-form-field');
								if (fieldEl) { fieldEl.classList.add('has-error'); }
								var errP = document.createElement('p');
								errP.className = 'wicket-importer-field-error';
								errP.textContent = fieldErrors[key];
								fieldInput.insertAdjacentElement('afterend', errP);
							}
						});
						return;
					}

					// Non-field error: show as an inline notice.
					window.WicketImportShowNotice(err.message, 'error');
				});
		});
	}

	function bindRestartButton(page) {
		page.addEventListener('click', function(e) {
			var btn = e.target.closest && e.target.closest('.wicket-importer-restart');
			if (!btn || !page.contains(btn)) {
				return;
			}
			var clearUrl = btn.dataset.clearUrl;
			var uploadUrl = btn.dataset.uploadUrl || cfg.uploadUrl || '';
			if (!clearUrl) {
				return;
			}

			var originalText = btn.textContent;
			btn.disabled = true;
			btn.textContent = t('Restarting\u2026');
			page.querySelectorAll('.wicket-importer-proceed').forEach(function(b) { b.disabled = true; });

			fetch(clearUrl, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': cfg.restNonce },
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function() {
					// Session cleared server-side; back to a fresh upload.
					window.location.href = uploadUrl;
				})
				.catch(function(err) {
					btn.disabled = false;
					btn.textContent = originalText;
					page.querySelectorAll('.wicket-importer-proceed').forEach(function(b) { b.disabled = false; });
					window.WicketImportShowNotice(err.message, 'error');
				});
		});
	}
})();