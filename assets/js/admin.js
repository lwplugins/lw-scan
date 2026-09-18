/**
 * LW Scan — admin behaviour: run control + polling, finding state changes,
 * bundle/index maintenance, copy buttons.
 *
 * Every request goes to admin-ajax.php with the shared `lw_scan_admin`
 * nonce. Endpoints that do not exist yet answer with a bare "0", which
 * fails to parse as JSON — that is treated like any other failure and
 * reported in the panel's status line instead of breaking the page.
 */

(function () {
	'use strict';

	var cfg = window.lwScan || {};
	var i18n = cfg.i18n || {};
	var POLL_MS = cfg.pollMs || 1000;
	var ASSIST_AFTER = cfg.assistAfter || 3;
	var MAX_FAILURES = 5;

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');

		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];

			if (Array.isArray(value)) {
				value.forEach(function (item) {
					body.append(key + '[]', item);
				});
				return;
			}

			body.append(key, value);
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) {
				return response.json().catch(function () {
					throw new Error(i18n.failed || 'Request failed.');
				});
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(message(payload) || i18n.failed || 'Request failed.');
				}

				return payload.data || {};
			});
	}

	function message(payload) {
		if (!payload || !payload.data) {
			return '';
		}

		return typeof payload.data === 'string' ? payload.data : (payload.data.message || '');
	}

	function say(scope, text, isError) {
		var target = scope ? scope.querySelector('.lw-scan-feedback') : null;

		if (!target) {
			target = document.querySelector('.lw-scan-feedback');
		}

		if (!target) {
			return;
		}

		target.textContent = text || '';
		target.classList.toggle('is-error', !!isError);
	}

	function panel(element) {
		return element ? element.closest('.lw-scan-card') : null;
	}

	function busy(button, on) {
		if (button) {
			button.disabled = !!on;
		}
	}

	/* --- Scan tab: the start bar ---------------------------------------- */

	function initStarter() {
		var starter = document.querySelector('[data-lw-scan-starter]');

		if (!starter) {
			return;
		}

		var pathField = starter.querySelector('.lw-scan-path-field');

		starter.addEventListener('change', function (event) {
			if (!event.target.matches('input[name="lw_scan_scope"]')) {
				return;
			}

			starter.querySelectorAll('.lw-scan-seg label').forEach(function (label) {
				label.classList.toggle('is-on', label.contains(event.target));
			});

			if (pathField) {
				pathField.hidden = event.target.value !== 'path';
			}
		});

		starter.querySelectorAll('.lw-scan-start').forEach(function (button) {
			button.addEventListener('click', function () {
				start(starter, button);
			});
		});
	}

	function start(starter, button) {
		var scope = starter.querySelector('input[name="lw_scan_scope"]:checked');
		var path = starter.querySelector('.lw-scan-path');
		var heuristics = starter.querySelector('.lw-scan-heuristics');

		busy(button, true);
		say(starter, i18n.starting || '');

		post('lw_scan_start', {
			scope: scope ? scope.value : 'changed',
			path: path ? path.value : '',
			heuristics: heuristics && heuristics.checked ? '1' : '0',
			resume: button.getAttribute('data-resume') === '1' ? '1' : '0'
		})
			.then(function () {
				window.location.reload();
			})
			.catch(function (error) {
				busy(button, false);
				say(starter, error.message, true);
			});
	}

	/* --- Scan tab: the live run ----------------------------------------- */

	function initRun() {
		var run = document.querySelector('[data-lw-scan-run]');

		if (!run) {
			return;
		}

		var stop = run.querySelector('.lw-scan-stop');
		var failures = 0;
		var reloaded = false;

		/*
		 * When the cursor has no tick timestamp yet, "3 seconds since the
		 * last tick" is measured from the run card's own last tick — or,
		 * failing that, from the moment this page took over. Without that
		 * baseline a zero would read as 1970 and fire an assist on every
		 * single poll.
		 */
		var baseline = parseInt(run.getAttribute('data-last-tick'), 10) || now();
		var lastAssist = 0;

		if (stop) {
			stop.addEventListener('click', function () {
				busy(stop, true);
				say(run, i18n.stopping || '');

				post('lw_scan_stop', {}).catch(function (error) {
					busy(stop, false);
					say(run, error.message, true);
				});
			});
		}

		function tickIfStalled(status) {
			var lastTick = parseInt(status.last_tick_at, 10) || 0;
			var since = lastTick > 0 ? lastTick : baseline;
			var at = now();

			if (at - since <= ASSIST_AFTER || at - lastAssist <= ASSIST_AFTER) {
				return;
			}

			baseline = at;
			lastAssist = at;

			post('lw_scan_tick', {}).catch(function () {
				/* The poll loop keeps reporting; an assist failure is not fatal. */
			});
		}

		function finish() {
			if (reloaded) {
				return;
			}

			reloaded = true;
			window.location.reload();
		}

		function poll() {
			post('lw_scan_status', {})
				.then(function (status) {
					failures = 0;
					render(run, status);

					if (status.status === 'running') {
						tickIfStalled(status);
						window.setTimeout(poll, POLL_MS);
						return;
					}

					finish();
				})
				.catch(function (error) {
					failures += 1;
					say(run, error.message, true);

					if (failures < MAX_FAILURES) {
						window.setTimeout(poll, POLL_MS * failures);
					}
				});
		}

		window.setTimeout(poll, POLL_MS);
	}

	function render(run, status) {
		var done = parseInt(status.done, 10) || 0;
		var total = parseInt(status.total, 10) || 0;
		var bar = run.querySelector('[data-lw-scan-bar]');
		var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

		var elapsed = parseInt(status.elapsed, 10) || 0;

		setText(run, '[data-lw-scan-done]', done.toLocaleString());
		setText(run, '[data-lw-scan-total]', total.toLocaleString());
		setText(run, '[data-lw-scan-elapsed]', clock(elapsed));
		setText(run, '[data-lw-scan-eta]', eta(done, total, elapsed));

		if (bar) {
			bar.style.width = percent + '%';
			if (bar.parentElement) {
				bar.parentElement.setAttribute('aria-valuenow', String(percent));
			}
		}

		renderPhases(run, status.phase, done, total);
		renderFindings(status);
		renderFeed(status.feed);
	}

	function eta(done, total, elapsed) {
		if (done < 1 || total <= done || elapsed < 1) {
			return '';
		}

		return (i18n.remaining || '~%s remaining').replace('%s', duration(Math.round((elapsed / done) * (total - done))));
	}

	function duration(seconds) {
		if (seconds < 60) {
			return seconds + ' s';
		}

		if (seconds < 3600) {
			return Math.floor(seconds / 60) + ' m ' + (seconds % 60) + ' s';
		}

		return Math.floor(seconds / 3600) + ' h ' + Math.floor((seconds % 3600) / 60) + ' m';
	}

	function renderPhases(run, current, done, total) {
		var tiles = Array.prototype.slice.call(run.querySelectorAll('[data-lw-scan-phase]'));
		var index = -1;

		tiles.forEach(function (tile, position) {
			if (tile.getAttribute('data-lw-scan-phase') === current) {
				index = position;
			}
		});

		tiles.forEach(function (tile, position) {
			var isDone = index > -1 && position < index;
			var isActive = position === index;

			tile.classList.toggle('is-done', isDone);
			tile.classList.toggle('is-active', isActive);
			setText(tile, '[data-lw-scan-phase-d]', phaseDetail(isDone, isActive, done, total));

			if (isActive) {
				setText(document, '[data-lw-scan-phase-name]', phaseName(tile));
			}
		});
	}

	function phaseDetail(isDone, isActive, done, total) {
		if (isDone) {
			return i18n.phaseDone || 'done';
		}

		if (isActive && total > 0) {
			return done.toLocaleString() + ' / ' + total.toLocaleString();
		}

		return '';
	}

	function phaseName(tile) {
		var name = tile.querySelector('.lw-scan-phase-n');

		return name ? name.textContent.trim() : '';
	}

	function renderFindings(status) {
		var count = parseInt(status.findings_new, 10) || 0;
		var pill = document.querySelector('[data-lw-scan-found]');

		if (!pill) {
			return;
		}

		pill.textContent = count.toLocaleString();
		pill.classList.toggle('lw-scan-pill--alert', count > 0);
		pill.classList.toggle('lw-scan-pill--muted', count === 0);
	}

	function renderFeed(rows) {
		var feed = document.querySelector('[data-lw-scan-feed]');

		if (!feed || !Array.isArray(rows)) {
			return;
		}

		feed.textContent = '';

		rows.forEach(function (row) {
			var line = document.createElement('div');
			line.className = 'lw-scan-feed-row';

			line.appendChild(cell('span', 'lw-scan-pill lw-scan-pill--' + (row.severity || 'review'), row.severity || ''));
			line.appendChild(cell('span', '', row.type || ''));
			line.appendChild(cell('span', 'lw-scan-mono', row.locator || ''));
			line.appendChild(cell('span', 'lw-scan-muted', row.reason || ''));

			feed.appendChild(line);
		});
	}

	function cell(tag, className, text) {
		var node = document.createElement(tag);

		if (className) {
			node.className = className;
		}

		node.textContent = text;

		return node;
	}

	function setText(scope, selector, text) {
		var node = scope.querySelector(selector);

		if (node) {
			node.textContent = text;
		}
	}

	function now() {
		return Math.floor(Date.now() / 1000);
	}

	function clock(seconds) {
		var minutes = Math.floor(seconds / 60);
		var rest = seconds % 60;

		return minutes + ':' + (rest < 10 ? '0' : '') + rest;
	}

	/* --- Findings tab ---------------------------------------------------- */

	function initFindings() {
		var table = document.querySelector('[data-lw-scan-findings]');

		if (!table) {
			return;
		}

		var all = table.querySelector('.lw-scan-cb-all');

		if (all) {
			all.addEventListener('change', function () {
				table.querySelectorAll('.lw-scan-cb').forEach(function (box) {
					box.checked = all.checked;
				});
			});
		}

		table.addEventListener('click', function (event) {
			var button = event.target.closest('.lw-scan-state');

			if (!button) {
				return;
			}

			setState([button.getAttribute('data-id')], button.getAttribute('data-state'), button, null);
		});

		var apply = document.querySelector('.lw-scan-bulk-apply');

		if (apply) {
			apply.addEventListener('click', function () {
				bulk(table, apply);
			});
		}

		var clear = document.querySelector('.lw-scan-clear');

		if (clear) {
			clear.addEventListener('click', function () {
				clearAll(clear);
			});
		}
	}

	function clearAll(button) {
		var scope = panel(button) || button.parentElement;

		if (!window.confirm(i18n.confirmClear || '')) {
			return;
		}

		busy(button, true);
		say(scope, i18n.working || '');

		post('lw_scan_clear_findings', {})
			.then(function () {
				window.location.reload();
			})
			.catch(function (error) {
				busy(button, false);
				say(scope, error.message, true);
			});
	}

	function bulk(table, apply) {
		var select = document.querySelector('.lw-scan-bulk-action');
		var scope = panel(apply) || apply.parentElement;
		var state = select ? select.value : '';
		var ids = [];

		table.querySelectorAll('.lw-scan-cb:checked').forEach(function (box) {
			ids.push(box.value);
		});

		if (!state) {
			return;
		}

		if (!ids.length) {
			say(scope, i18n.noSelected || '', true);
			return;
		}

		if (!window.confirm(i18n.confirmAll || '')) {
			return;
		}

		setState(ids, state, apply, scope);
	}

	function setState(ids, state, button, scope) {
		busy(button, true);
		say(scope, i18n.working || '');

		post('lw_scan_finding_state', { ids: ids, state: state })
			.then(function () {
				window.location.reload();
			})
			.catch(function (error) {
				busy(button, false);
				say(scope, error.message, true);
			});
	}

	/* --- Health tab ------------------------------------------------------ */

	function initHealth() {
		var maintenance = document.querySelector('[data-lw-scan-maintenance]');

		if (maintenance) {
			maintenance.addEventListener('click', function (event) {
				var button = event.target.closest('button');

				if (!button) {
					return;
				}

				if (button.classList.contains('lw-scan-bundle')) {
					maintain('lw_scan_bundle', button, maintenance);
				} else if (button.classList.contains('lw-scan-index')) {
					maintain('lw_scan_index', button, maintenance);
				} else if (button.classList.contains('lw-scan-health')) {
					maintain('lw_scan_health', button, maintenance);
				}
			});
		}

		document.addEventListener('click', function (event) {
			var button = event.target.closest('.lw-scan-copy-btn');

			if (button) {
				copy(button);
			}
		});
	}

	function maintain(action, button, scope) {
		busy(button, true);
		say(scope, i18n.working || '');

		post(action, { op: button.getAttribute('data-op') || '' })
			.then(function () {
				window.location.reload();
			})
			.catch(function (error) {
				busy(button, false);
				say(scope, error.message, true);
			});
	}

	function copy(button) {
		var text = button.getAttribute('data-copy') || '';
		var original = button.textContent;

		function done() {
			button.textContent = i18n.copied || 'Copied';
			window.setTimeout(function () {
				button.textContent = original;
			}, 1500);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallbackCopy(text, done);
			});
			return;
		}

		fallbackCopy(text, done);
	}

	function fallbackCopy(text, done) {
		var field = document.createElement('textarea');
		field.value = text;
		field.setAttribute('readonly', 'readonly');
		field.style.position = 'absolute';
		field.style.left = '-9999px';
		document.body.appendChild(field);
		field.select();

		try {
			document.execCommand('copy');
			done();
		} catch (error) {
			/* Clipboard unavailable: the line stays selectable by hand. */
		}

		document.body.removeChild(field);
	}

	function init() {
		if (!cfg.ajaxUrl) {
			return;
		}

		initStarter();
		initRun();
		initFindings();
		initHealth();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
