/**
 * Wicket Importer Admin Script
 *
 * Task 7: Upload Type toggle (CSV / Manual show-hide).
 * Task 8: CSV drag-drop zone + file preview + fetch upload + Proceed binding.
 *
 * REST config is on window.WicketImportAdmin (localized by Assets.php):
 *   { restRoot, restNonce, screen, sessionId, maxFileSize, confirmationUrl }
 *
 * Strings use the native WP JS i18n package (wp-i18n): wp.i18n.__() and
 * wp.i18n.sprintf() / wp.i18n._n() with the 'wicket-wp-importer' text domain.
 * Translations are loaded via wp_set_script_translations() in Assets.php.
 */
(function($) {
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

	$(function() {
		var $page = $('.wicket-importer');
		if (!$page.length) {
			return;
		}

		bindUploadTypeToggle($page);
		bindDropzone($page);
		bindProceedButton($page);
		bindRestartButton($page);
		bindIndividualForm($page);
	});

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
		var $notices = $('.wicket-importer-notices');
		if (!$notices.length) {
			return;
		}
		// Clear previous notices of the same type to avoid stacking.
		$notices.find('.notice-' + type).remove();
		$('<div class="notice notice-' + type + '"><p></p></div>')
			.find('p').text(message).end()
			.appendTo($notices);
		$notices[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	};

	// ------------------------------------------------------------------
	// Task 7: Upload Type toggle
	// ------------------------------------------------------------------

	function bindUploadTypeToggle($page) {
		var $toggle = $('input[name="wicket_import_upload_type"]');
		if (!$toggle.length) {
			return;
		}
		var showSection = function(value) {
			$('#wicket-import-csv').prop('hidden', value !== 'csv');
			$('#wicket-import-manual').prop('hidden', value !== 'manual');
		};
		$toggle.on('change', function() {
			showSection($('input[name="wicket_import_upload_type"]:checked').val());
		});
		showSection($('input[name="wicket_import_upload_type"]:checked').val());
	}

	// ------------------------------------------------------------------
	// Task 8.1 + 8.2: Dropzone + file preview + fetch upload
	// ------------------------------------------------------------------

	function bindDropzone($page) {
		var $zone = $('#wicket-import-dropzone');
		if (!$zone.length) {
			return;
		}

		var $input = $('#wicket-import-file-input');
		var $preview = $('#wicket-import-file-preview');
		var $name = $preview.find('.wicket-importer-file-name');
		var $size = $preview.find('.wicket-importer-file-size');
		var $rows = $preview.find('.wicket-importer-file-rows');
		var $uploadBtn = $preview.find('.wicket-importer-upload-btn');
		var $clearBtn = $preview.find('.wicket-importer-clear-btn');
		var selectedFile = null;
		// Monotonic token so a stale FileReader (from a cleared / re-selected
		// file) can't overwrite the current preview (nit 3).
		var readToken = 0;

		// --- click / keyboard to browse ---------------------------------
		$zone.on('click', function() {
			$input.trigger('click');
		});
		$zone.on('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$input.trigger('click');
			}
		});

		// --- drag events ------------------------------------------------
		$zone.on('dragover', function(e) {
			e.preventDefault();
			$zone.addClass('is-dragover');
		});
		$zone.on('dragleave drop', function(e) {
			e.preventDefault();
			$zone.removeClass('is-dragover');
		});
		$zone.on('drop', function(e) {
			var files = e.originalEvent.dataTransfer.files;
			if (files && files.length) {
				handleFileSelected(files[0]);
			}
		});

		// --- file input change ------------------------------------------
		$input.on('change', function() {
			if (this.files && this.files.length) {
				handleFileSelected(this.files[0]);
			}
		});

		// --- clear ------------------------------------------------------
		$clearBtn.on('click', function() {
			readToken++; // cancel any pending row-count read (nit 3)
			selectedFile = null;
			$input.val('');
			$preview.prop('hidden', true);
		});

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

			readToken++; // invalidate any prior pending read (nit 3)
			selectedFile = file;
			$name.text(file.name);
			$size.text(formatBytes(file.size));
			$rows.text(''); // populated after the row-count read
			$preview.prop('hidden', false);

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
				$rows.text(label);
			});
		}

		// --- upload confirm (8.2) ---------------------------------------
		$uploadBtn.on('click', function() {
			if (!selectedFile) {
				return;
			}
			var url = $uploadBtn.data('upload-url');
			var validationUrl = $uploadBtn.data('validation-url');
			var originalText = $uploadBtn.text();

			setBusy(true);
			// S1: no empty-info notice here. Stale errors are cleared by the
			// success-path redirect; on failure WicketImportShowNotice replaces.

			var data = new FormData();
			data.append('file', selectedFile);

			fetch(url, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.restNonce },
				body: data,
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function(result) {
					if (result && result.session_id) {
						// Redirect to the validation screen with the new session.
						window.location.href = validationUrl + '&session_id=' + encodeURIComponent(result.session_id);
						return;
					}
					throw new Error(extractErrorMessage(result) || t('Upload failed. Please try again.'));
				})
				.catch(function(err) {
					setBusy(false);
					window.WicketImportShowNotice(err.message, 'error');
				});
		});

		// Disable the zone + buttons while a request is in flight (nit 5):
		// prevents dropping/selecting a second file whose preview would then
		// disagree with the file actually being uploaded.
		function setBusy(busy) {
			$uploadBtn.prop('disabled', busy);
			if (busy) {
				$uploadBtn.text(t('Uploading\u2026'));
				$zone.addClass('is-busy');
			} else {
				$uploadBtn.text(t('Validate & Upload'));
				$zone.removeClass('is-busy');
			}
		}
	}

	// ------------------------------------------------------------------
	// Task 8.2: Proceed button (validation screen -> POST /run -> confirmation)
	// ------------------------------------------------------------------

	function bindProceedButton($page) {
		$page.on('click', '.wicket-importer-proceed', function() {
			var $btn = $(this);
			var sessionId = $btn.data('session-id');
			var runUrl = $btn.data('run-url');
			if (!runUrl || !sessionId) {
				return;
			}

			var originalText = $btn.text();
			$btn.prop('disabled', true).text(t('Processing\u2026'));
			$('.wicket-importer-restart').prop('disabled', true);
			window.WicketImportShowNotice(t('Importing rows \u2014 this may take a moment.'), 'info');

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
						window.location.href = cfg.confirmationUrl + '&session_id=' + encodeURIComponent(sessionId);
						return;
					}
					throw new Error(extractErrorMessage(result) || t('Import run failed. See the error below.'));
				})
				.catch(function(err) {
					$btn.prop('disabled', false).text(originalText);
					$('.wicket-importer-restart').prop('disabled', false);
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
	function readRowCount(file, cb) {
		var token = ++readRowCount._token;
		var reader = new FileReader();
		reader.onload = function() {
			if (token !== readRowCount._token) {
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
			if (token !== readRowCount._token) { return; }
			cb({ count: 0, truncated: file.size > SCAN_LIMIT });
		};
		var slice = file.slice ? file.slice(0, SCAN_LIMIT) : file;
		reader.readAsText(slice);
	}
	readRowCount._token = 0;
	/**
	 * Invalidate any in-flight row-count read (nit 3). Bumps the shared
	 * generation so pending FileReader callbacks no-op on completion.
	 */
	readRowCount.cancel = function() { readRowCount._token++; };

	function formatBytes(bytes) {
		if (!bytes) { return '0 B'; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		if (i >= units.length) { i = units.length - 1; }
		return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
	}

	// TODO Task 9.3: Restart button -> DELETE /session/{id} -> redirect to upload.

	// ------------------------------------------------------------------
	// Task 11.3 + 11.4: Individual form submit -> validate + import -> confirmation
	// ------------------------------------------------------------------

	function bindIndividualForm($page) {
		var $form = $('#wicket-import-individual-form');
		if (!$form.length) {
			return;
		}

		$form.on('submit', function(e) {
			e.preventDefault();

			var $btn = $form.find('.wicket-importer-individual-submit');
			var url = $btn.data('individual-url');
			var confirmationUrl = $btn.data('confirmation-url');
			var originalText = $btn.text();

			// Clear previous inline field errors.
			$form.find('.wicket-importer-field-error').remove();
			$form.find('.has-error').removeClass('has-error');

			// Gather all named form fields into a flat key=>value object.
			// NOTE: serializeArray keeps only the LAST value when multiple inputs
			// share a name (checkbox groups, repeaters). Multi-value fields should
			// use name[] and a custom collector — flagged for OBA Task 33.1's
			// dynamic state rows.
			var fields = {};
			$.each($form.serializeArray(), function(i, pair) {
				fields[pair.name] = pair.value;
			});

			$btn.prop('disabled', true).text(t('Processing\u2026'));

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
					$btn.prop('disabled', false).text(originalText);

					// WP REST 400 carries field_errors: pin them to the matching inputs.
					var fieldErrors = err.data && err.data.field_errors;
					if (fieldErrors && typeof fieldErrors === 'object') {
						Object.keys(fieldErrors).forEach(function(key) {
							var $input = $form.find('[name="' + CSS.escape(key) + '"]');
							if ($input.length) {
								$input.closest('.wicket-importer-form-field').addClass('has-error');
								$('<p class="wicket-importer-field-error">' + $('<span>').text(fieldErrors[key]).html() + '</p>')
									.insertAfter($input);
							}
						});
						return;
					}

					// Non-field error: show as an inline notice.
					window.WicketImportShowNotice(err.message, 'error');
				});
		});
	}
	function bindRestartButton($page) {
		$page.on('click', '.wicket-importer-restart', function() {
			var $btn = $(this);
			var clearUrl = $btn.data('clear-url');
			var uploadUrl = $btn.data('upload-url');
			if (!clearUrl) {
				return;
			}

			var originalText = $btn.text();
			$btn.prop('disabled', true).text(t('Restarting\u2026'));
			$('.wicket-importer-proceed').prop('disabled', true);

			fetch(clearUrl, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': cfg.restNonce },
				credentials: 'same-origin'
			})
				.then(toJson)
				.then(function() {
					// Session cleared server-side; back to a fresh upload.
					window.location.href = uploadUrl || cfg.uploadUrl || '';
				})
				.catch(function(err) {
					$btn.prop('disabled', false).text(originalText);
					$('.wicket-importer-proceed').prop('disabled', false);
					window.WicketImportShowNotice(err.message, 'error');
				});
		});
	}
})(jQuery);
