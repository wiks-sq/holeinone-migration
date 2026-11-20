/* Hole-in-One Migration – admin UI.
 *
 * The browser drives the migration: it calls the server again and again, and
 * every call does a few seconds of work and saves a checkpoint. Failed calls
 * (timeouts, 5xx, network loss) are retried with exponential backoff; the
 * server then resumes from the last checkpoint with smaller chunks.
 */
(function () {
	'use strict';

	var C = window.HIO || {};
	var T = C.i18n || {};
	// wp_localize_script() turns numbers into strings.
	C.chunk = parseInt(C.chunk, 10) || 1048576;
	C.pause = parseInt(C.pause, 10) || 0;
	var running = 0;
	var wakeLock = null;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function fmt(s) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(s).replace(/%(?:(\d+)\$)?[sd]/g, function (m, n) { return n ? args[n - 1] : args[i++]; });
	}
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function size(b) {
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
		return (i ? b.toFixed(1) : b) + ' ' + u[i];
	}

	// Timers in background tabs are throttled hard by browsers; timers inside
	// a Web Worker are not, so the migration keeps its pace in a hidden tab.
	var timerWorker = null, timerSeq = 0, timerCbs = {};
	try {
		var src = 'onmessage=function(e){setTimeout(function(){postMessage(e.data.id)},e.data.ms)}';
		timerWorker = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
		timerWorker.onmessage = function (e) { var cb = timerCbs[e.data]; delete timerCbs[e.data]; if (cb) { cb(); } };
	} catch (e) { timerWorker = null; }

	function sleep(ms) {
		return new Promise(function (resolve) {
			if (ms <= 0) { resolve(); return; }
			if (timerWorker) {
				var id = ++timerSeq;
				timerCbs[id] = resolve;
				timerWorker.postMessage({ id: id, ms: ms });
			} else {
				setTimeout(resolve, ms);
			}
		});
	}

	function busy(on) {
		running += on ? 1 : -1;
		if (running > 0 && 'wakeLock' in navigator && !wakeLock) {
			navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }).catch(function () {});
		}
		if (running <= 0 && wakeLock) {
			wakeLock.release().catch(function () {});
			wakeLock = null;
		}
	}
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible' && running > 0 && 'wakeLock' in navigator) {
			navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }).catch(function () {});
		}
	});
	window.addEventListener('beforeunload', function (e) {
		if (running > 0) { e.preventDefault(); e.returnValue = T.leave; return T.leave; }
	});

	/**
	 * POST to admin-ajax. Resolves to {ok, data, code, http} and never rejects.
	 */
	function api(action, fields, timeoutMs) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', C.nonce);
		Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, timeoutMs || 180000) : null;

		return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
			.then(function (res) {
				return res.text().then(function (text) {
					var json = null;
					try { json = JSON.parse(text); } catch (e) {
						// Other plugins sometimes print notices before our JSON.
						var a = text.indexOf('{"success"'), b = text.lastIndexOf('}');
						if (a !== -1 && b > a) { try { json = JSON.parse(text.slice(a, b + 1)); } catch (e2) { json = null; } }
					}
					if (text.trim() === '-1' || text.trim() === '0') {
						return { ok: false, code: 'auth', http: res.status, message: T.expired };
					}
					if (!json) {
						return { ok: false, code: 'http', http: res.status, message: 'HTTP ' + res.status };
					}
					if (!json.success) {
						var d = json.data || {};
						return { ok: false, code: d.code || 'error', http: res.status, message: d.message || 'Error' };
					}
					return { ok: true, data: json.data, http: res.status };
				});
			})
			.catch(function (err) {
				return { ok: false, code: 'network', http: 0, message: (err && err.name === 'AbortError') ? 'Request timed out' : 'Network error' };
			})
			.then(function (r) { if (timer) { clearTimeout(timer); } return r; });
	}

	/* ------------------------------------------------------------------ */
	/* Progress panel                                                      */
	/* ------------------------------------------------------------------ */

	function Panel(container, title) {
		var el = document.createElement('div');
		el.className = 'hio-card hio-progress';
		el.innerHTML =
			'<h2>' + esc(title) + '</h2>' +
			'<div class="hio-bar"><span></span><em>0%</em></div>' +
			'<p class="hio-msg"></p>' +
			'<p class="hio-note" hidden></p>' +
			'<div class="hio-result" hidden></div>' +
			'<details class="hio-logbox"><summary>Log</summary><pre class="hio-log"></pre><p><a class="hio-loglink" href="#">Download full log</a></p></details>' +
			'<p class="hio-buttons"></p>';
		container.innerHTML = '';
		container.appendChild(el);
		el.scrollIntoView({ behavior: 'smooth', block: 'start' });
		this.el = el;
		this.lines = 0;
	}
	Panel.prototype.progress = function (pct, msg) {
		pct = Math.max(0, Math.min(100, pct | 0));
		$('.hio-bar span', this.el).style.width = pct + '%';
		$('.hio-bar em', this.el).textContent = pct + '%';
		if (msg) { $('.hio-msg', this.el).textContent = msg; }
	};
	Panel.prototype.note = function (msg, kind) {
		var n = $('.hio-note', this.el);
		n.hidden = !msg;
		n.className = 'hio-note' + (kind ? ' hio-note-' + kind : '');
		n.textContent = msg || '';
	};
	Panel.prototype.log = function (lines, warnings) {
		if (!lines || !lines.length) { return; }
		var pre = $('.hio-log', this.el);
		pre.textContent += lines.join('\n') + '\n';
		pre.scrollTop = pre.scrollHeight;
		this.lines += lines.length;
		$('summary', this.el).textContent = 'Log' + (warnings ? ' (' + warnings + ' warnings)' : '');
	};
	Panel.prototype.logUrl = function (url) { if (url) { $('.hio-loglink', this.el).href = url; } };
	Panel.prototype.buttons = function (list) {
		var p = $('.hio-buttons', this.el);
		p.innerHTML = '';
		(list || []).forEach(function (b) {
			var x = document.createElement(b.href ? 'a' : 'button');
			x.className = 'button ' + (b.primary ? 'button-primary' : '') + (b.danger ? ' button-link-delete' : '');
			x.textContent = b.label;
			if (b.href) { x.href = b.href; if (b.download) { x.setAttribute('download', ''); } }
			else { x.type = 'button'; x.addEventListener('click', b.click); }
			p.appendChild(x);
		});
	};
	Panel.prototype.result = function (html) {
		var r = $('.hio-result', this.el);
		r.hidden = !html;
		r.innerHTML = html || '';
	};

	/* ------------------------------------------------------------------ */
	/* Job runner                                                          */
	/* ------------------------------------------------------------------ */

	function cancelButton(panel, job) {
		return {
			label: 'Cancel', danger: true, click: function () {
				if (!window.confirm(T.confirmCancel)) { return; }
				panel.cancelled = true;
				api('hio_cancel', { job: job.id }).then(function () {
					panel.progress(0, 'Cancelled.');
					panel.note('');
					panel.buttons([]);
				});
			}
		};
	}

	function runJob(job, panel, resume) {
		var attempt = 0, retry = false, waits = 0;
		busy(true);
		panel.logUrl(job.logUrl);
		panel.buttons([cancelButton(panel, job)]);

		function done() { busy(false); }

		function loop() {
			if (panel.cancelled) { done(); return; }
			api('hio_step', { job: job.id, retry: retry ? 1 : 0, resume: resume ? 1 : 0 }).then(function (r) {
				resume = false;
				if (panel.cancelled) { done(); return; }

				if (!r.ok) {
					if (r.code === 'auth') {
						// After the database swap the old login session no longer exists.
						// The request doing the swap may still be finishing on the
						// server, so keep asking the (login-free) status endpoint.
						var polls = 0;
						var poll = function () {
							api('hio_status', { job: job.id }).then(function (st) {
								if (st.ok && st.data.phase === 'done') { finished(st.data, panel); done(); return; }
								if (st.ok && st.data.status === 'error') { panel.note(T.expired, 'error'); panel.buttons([]); done(); return; }
								if (++polls > 40) { panel.note(T.expired, 'error'); panel.buttons([]); done(); return; }
								panel.note(T.busy, 'warn');
								sleep(3000).then(poll);
							});
						};
						poll();
						return;
					}
					if (r.code === 'gone') { panel.note(r.message, 'error'); panel.buttons([]); done(); return; }
					attempt++;
					if (attempt > 12) {
						panel.note(T.gaveUp + ' (' + r.message + ')', 'error');
						panel.buttons([
							{ label: 'Retry', primary: true, click: function () { panel.note(''); attempt = 0; retry = true; busy(true); panel.buttons([cancelButton(panel, job)]); loop(); } },
							cancelButton(panel, job)
						]);
						done();
						return;
					}
					var wait = Math.min(60, Math.pow(2, attempt));
					panel.note(fmt(T.retrying, wait, attempt) + ' [' + r.message + ']', 'warn');
					retry = true;
					sleep(wait * 1000).then(loop);
					return;
				}

				var d = r.data;
				if (d.status === 'busy') {
					waits++;
					panel.note(T.busy, 'warn');
					sleep(3000).then(loop);
					return;
				}
				attempt = 0; waits = 0; retry = false;
				panel.note('');
				panel.progress(d.progress, d.message);
				panel.log(d.log, d.warnings);

				if (d.status === 'error') {
					panel.note(d.error, 'error');
					panel.buttons([
						{ label: 'Retry', primary: true, click: function () { panel.note(''); resume = true; busy(true); panel.buttons([cancelButton(panel, job)]); loop(); } },
						cancelButton(panel, job)
					]);
					done();
					return;
				}
				if (d.phase === 'done') {
					finished(d, panel);
					done();
					return;
				}
				sleep(C.pause || 0).then(loop);
			});
		}
		loop();
	}

	function finished(d, panel) {
		panel.progress(100, d.message);
		panel.buttons([]);
		var warn = d.warnings ? '<p class="hio-note hio-note-warn">' + esc(d.warnings) + ' warning(s) – see the log below. Usually these are unreadable cache or temporary files and can be ignored.</p>' : '';
		if (d.kind === 'exp' && d.result) {
			panel.result(
				'<p class="hio-success">✔ Your archive is ready: <code>' + esc(d.result.file) + '</code> (' + esc(d.result.size) + ')</p>' + warn +
				'<p><a class="button button-primary button-hero" href="' + esc(d.result.direct) + '" download>Download archive</a> ' +
				'<a class="button" href="' + esc(d.result.php) + '">Alternative download</a></p>' +
				'<p><strong>Next:</strong> install a fresh WordPress at your new host, install Hole-in-One Migration there, and open Migration → Import.</p>'
			);
		} else if (d.kind === 'imp' && d.result) {
			if (d.result.relogin) {
				running = 0;
				panel.result(
					'<p class="hio-success">✔ Your site has been migrated!</p>' + warn +
					'<p>You have been logged out because the users of the old site were imported. <strong>Log in with the username and password of the OLD site.</strong></p>' +
					'<p><a class="button button-primary button-hero" href="' + esc(d.result.login) + '">Log in</a> <a class="button" href="' + esc(d.result.home) + '" target="_blank">View site</a></p>'
				);
			} else {
				panel.result('<p class="hio-success">✔ Files imported.</p>' + warn + '<p><a class="button" href="' + esc(d.result.home) + '" target="_blank">View site</a></p>');
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Upload (chunked, resumable, adaptive)                               */
	/* ------------------------------------------------------------------ */

	function upload(job, file, panel, startAt) {
		var offset = Number(startAt) || 0, chunk = C.chunk, attempt = 0, t0 = Date.now(), sent0 = offset;
		busy(true);
		panel.logUrl(job.logUrl);
		panel.buttons([cancelButton(panel, job)]);

		function next() {
			if (panel.cancelled) { busy(false); return; }
			if (offset >= file.size) { busy(false); runJob(job, panel, false); return; }
			var blob = file.slice(offset, Math.min(file.size, offset + chunk));
			api('hio_upload_chunk', { job: job.id, offset: offset, chunk: blob }, 300000).then(function (r) {
				if (panel.cancelled) { busy(false); return; }
				if (!r.ok) {
					if (r.code === 'auth') { panel.note(T.expired, 'error'); busy(false); return; }
					if (r.code === 'gone') { panel.note(r.message, 'error'); busy(false); return; }
					attempt++;
					// 413 = request too large (e.g. nginx client_max_body_size): send smaller pieces.
					if ((r.http === 413 || r.code === 'network' || r.code === 'chunk') && chunk > 131072) {
						chunk = Math.max(131072, Math.floor(chunk / 2));
					}
					if (attempt > 15) {
						panel.note(T.gaveUp + ' (' + r.message + ')', 'error');
						panel.buttons([
							{ label: 'Retry', primary: true, click: function () { attempt = 0; panel.note(''); busy(true); panel.buttons([cancelButton(panel, job)]); next(); } },
							cancelButton(panel, job)
						]);
						busy(false);
						return;
					}
					var wait = Math.min(60, Math.pow(2, attempt));
					panel.note(fmt(T.retrying, wait, attempt) + ' [' + r.message + ']', 'warn');
					sleep(wait * 1000).then(next);
					return;
				}
				if (r.data.busy) { sleep(2000).then(next); return; }
				attempt = 0;
				panel.note('');
				offset = Number(r.data.received);
				var secs = Math.max(1, (Date.now() - t0) / 1000);
				var speed = (offset - sent0) / secs;
				var eta = speed > 0 ? Math.round((file.size - offset) / speed) : 0;
				panel.progress(Math.floor(10 * offset / file.size),
					'Uploading: ' + size(offset) + ' of ' + size(file.size) + ' (' + size(speed) + '/s' + (eta > 0 ? ', about ' + Math.ceil(eta / 60) + ' min left' : '') + ')');
				next();
			});
		}
		panel.progress(Math.floor(10 * offset / file.size), 'Uploading…');
		next();
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	function start(action, fields, container, title, onJob) {
		var panel = new Panel(container, title);
		panel.progress(0, 'Starting…');
		api(action, fields).then(function (r) {
			if (!r.ok) {
				panel.note(r.message, 'error');
				return;
			}
			onJob(r.data, panel);
		});
		return panel;
	}

	// Export.
	var form = $('#hio-export-form');
	if (form) {
		var toggle = function () {
			var v = $('input[name="contents"]:checked', form).value;
			$('.hio-files-only', form).hidden = (v === 'db');
		};
		$all('input[name="contents"]', form).forEach(function (i) { i.addEventListener('change', toggle); });
		toggle();
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var fields = {
				contents: $('input[name="contents"]:checked', form).value,
				format: $('input[name="format"]:checked', form).value,
				exclude_uploads: $('input[name="exclude_uploads"]', form).checked ? '1' : '0'
			};
			$('#hio-export-btn').disabled = true;
			start('hio_export_start', fields, $('#hio-export-progress'), 'Exporting', function (job, panel) {
				runJob(job, panel, false);
			});
		});
	}

	// Import: upload.
	var pendingUpload = null; // Job waiting for the user to re-select the file.
	var upBtn = $('#hio-upload-btn');
	if (upBtn) {
		upBtn.addEventListener('click', function () {
			var input = $('#hio-file');
			var file = input.files && input.files[0];
			if (!file) { window.alert(T.chooseFile); return; }

			if (pendingUpload) {
				var job = pendingUpload;
				if (!job.upload || job.upload.size !== file.size) {
					window.alert(fmt(T.wrongFile, job.upload ? job.upload.name + ' (' + size(job.upload.size) + ')' : ''));
					return;
				}
				pendingUpload = null;
				var p = new Panel($('#hio-import-progress'), 'Importing');
				upload(job, file, p, job.upload.received || 0);
				return;
			}
			if (!window.confirm(T.confirmImport)) { return; }
			upBtn.disabled = true;
			start('hio_import_start', { source: 'upload', name: file.name, size: file.size }, $('#hio-import-progress'), 'Importing', function (job, panel) {
				upload(job, file, panel, 0);
			});
		});
	}

	// Import: file on server.
	$all('.hio-import-server').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!window.confirm(T.confirmImport)) { return; }
			$all('.hio-import-server').forEach(function (x) { x.disabled = true; });
			start('hio_import_start', { source: 'server', file: b.getAttribute('data-file') }, $('#hio-import-progress'), 'Importing', function (job, panel) {
				runJob(job, panel, false);
			});
		});
	});

	// Delete backups.
	$all('.hio-delete-backup').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!window.confirm(T.confirmDelete)) { return; }
			b.disabled = true;
			api('hio_delete_backup', { file: b.getAttribute('data-file') }).then(function (r) {
				if (r.ok) { var tr = b.closest('tr'); if (tr) { tr.remove(); } } else { window.alert(r.message); b.disabled = false; }
			});
		});
	});

	// Unfinished jobs: offer resume / cancel.
	var box = $('#hio-resume');
	if (box && C.jobs && C.jobs.length) {
		box.hidden = false;
		C.jobs.forEach(function (job) {
			var row = document.createElement('div');
			row.className = 'hio-card hio-resume-item';
			var what = job.kind === 'exp' ? 'Export' : 'Import';
			var when = new Date(job.created * 1000).toLocaleString();
			row.innerHTML = '<p><strong>Unfinished ' + what + '</strong> (started ' + esc(when) + ') – ' + esc(job.message || job.phase) +
				(job.status === 'error' ? '<br><span class="hio-note-error">' + esc(job.error) + '</span>' : '') + '</p>' +
				'<p class="hio-buttons"></p><div class="hio-resume-panel"></div>';
			box.appendChild(row);
			var btns = $('.hio-buttons', row);
			var resumeBtn = document.createElement('button');
			resumeBtn.type = 'button';
			resumeBtn.className = 'button button-primary';
			resumeBtn.textContent = job.phase === 'upload' ? 'Resume upload' : 'Resume';
			resumeBtn.addEventListener('click', function () {
				if (job.phase === 'upload') {
					pendingUpload = job;
					var msg = fmt(T.reselect, job.upload ? job.upload.name : '');
					if (!$('#hio-file')) {
						window.location.href = window.location.pathname + '?page=hole-in-one-migration&tab=import';
						return;
					}
					if (upBtn) { upBtn.textContent = 'Resume upload'; upBtn.disabled = false; }
					window.alert(msg);
					$('#hio-file').focus();
					return;
				}
				btns.innerHTML = '';
				var panel = new Panel($('.hio-resume-panel', row), what);
				panel.progress(job.progress, job.message);
				panel.log(job.log, job.warnings);
				runJob(job, panel, true);
			});
			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'button button-link-delete';
			cancelBtn.textContent = 'Cancel';
			cancelBtn.addEventListener('click', function () {
				if (!window.confirm(T.confirmCancel)) { return; }
				api('hio_cancel', { job: job.id }).then(function () { row.remove(); });
			});
			btns.appendChild(resumeBtn);
			btns.appendChild(document.createTextNode(' '));
			btns.appendChild(cancelBtn);
			var a = document.createElement('a');
			a.href = job.logUrl;
			a.textContent = 'Log';
			a.className = 'hio-loglink';
			btns.appendChild(document.createTextNode(' '));
			btns.appendChild(a);
		});
	}
})();
