/* global PHPReady */
(function () {
	'use strict';

	var form = document.getElementById('phpready-form');
	var box = document.getElementById('phpready-progress');
	var bar = document.getElementById('phpready-bar');
	var txt = document.getElementById('phpready-text');
	var btnCancel = document.getElementById('phpready-cancel');
	var stopped = false;

	var rv = document.querySelector('[data-phpready-review]');
	if (rv) {
		rv.addEventListener('click', function () {
			var b = document.getElementById('phpready-review');
			if (b) { b.style.display = 'none'; }
		});
	}

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', PHPReady.nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(PHPReady.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (j) {
				if (!j.success) { throw new Error(j.data || 'error'); }
				return j.data;
			});
	}

	function show(d) {
		box.style.display = '';
		var pct = d.total ? Math.round(d.index * 100 / d.total) : 0;
		bar.style.width = pct + '%';
		txt.textContent = d.item_name + ' (' + d.item_num + ' / ' + d.items + ') · ' + d.index + ' / ' + d.total + ' ' + PHPReady.i18n.files;
	}

	function step(retries) {
		if (stopped) { return; }
		post('phpready_step').then(function (d) {
			show(d);
			if (d.done) {
				window.location.reload();
			} else if (d.wait) {
				// Otra pestaña está corriendo la tanda: esperamos y volvemos a preguntar.
				setTimeout(function () { step(0); }, 2000);
			} else {
				step(0);
			}
		}).catch(function (e) {
			// Si el servidor cortó la petición (500/timeout), reintentamos: la próxima tanda
			// salta el archivo culpable gracias al cursor. Tres fallos seguidos = paramos.
			if (retries < 3) {
				setTimeout(function () { step(retries + 1); }, 1500);
			} else {
				txt.textContent = PHPReady.i18n.error + ' ' + e.message;
			}
		});
	}

	if (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			stopped = false;
			var btn = form.querySelector('button, input[type=submit]');
			btn.disabled = true;
			var inact = form.querySelector('[name=phpready_inactive]');
			var worg = form.querySelector('[name=phpready_wporg]');
			post('phpready_start', { target: form.querySelector('[name=phpready_target]').value, inactive: (inact && inact.checked) ? 1 : 0, wporg: (worg && worg.checked) ? 1 : 0 })
				.then(function () {
					show({ item_name: '…', item_num: 1, items: '?', index: 0, total: 0 });
					step(0);
				})
				.catch(function (e) {
					btn.disabled = false;
					txt.textContent = PHPReady.i18n.error + ' ' + e.message;
					box.style.display = '';
				});
		});
	}

	if (btnCancel) {
		btnCancel.addEventListener('click', function () {
			stopped = true;
			post('phpready_cancel')
				.then(function () { window.location.reload(); })
				.catch(function (e) { txt.textContent = PHPReady.i18n.error + ' ' + e.message; });
		});
	}

	// Si al cargar la página hay un escaneo a medias, lo retomamos solos.
	if (PHPReady.resume) {
		show(PHPReady.resume);
		step(0);
	}

	// Copiar el resumen en texto plano (para el mail al cliente o el ticket del hosting).
	var btnCopy = document.getElementById('phpready-copy');
	var summary = document.getElementById('phpready-summary');
	if (btnCopy && summary) {
		btnCopy.addEventListener('click', function () {
			var label = btnCopy.textContent;
			var done = function () {
				btnCopy.textContent = PHPReady.i18n.copied;
				setTimeout(function () { btnCopy.textContent = label; }, 2000);
			};
			var fallback = function () {
				summary.style.display = '';
				summary.focus();
				summary.select();
				try { if (document.execCommand('copy')) { done(); } } catch (e) {}
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(summary.value).then(done, fallback);
			} else {
				fallback();
			}
		});
	}

	// Consulta opcional a WordPress.org: de a pocos plugins por pedido; al terminar recarga una vez.
	if (PHPReady.wporg > 0 && !PHPReady.resume) {
		var st = document.getElementById('phpready-wporg-status');
		if (st) { st.textContent = PHPReady.i18n.checking; }
		var guard = 0;
		var more = function () {
			post('phpready_wporg').then(function (d) {
				if (d.left > 0 && ++guard < 50) { more(); } else { window.location.reload(); }
			}).catch(function () { if (st) { st.textContent = ''; } });
		};
		more();
	}

	// Filas de detalle: clic en la fila del plugin abre/cierra su detalle.
	document.querySelectorAll('tr.phpready-row').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('a')) { return; }
			var det = document.getElementById(row.dataset.detail);
			if (det) { det.style.display = det.style.display === 'none' ? '' : 'none'; }
		});
	});

	// Enlace desde la pantalla Plugins (#phpready-det-N): abre ese detalle y lo muestra.
	if (location.hash.indexOf('#phpready-det-') === 0) {
		var det = document.getElementById(location.hash.slice(1));
		var row = det ? document.querySelector('tr.phpready-row[data-detail="' + det.id + '"]') : null;
		if (det && row) {
			det.style.display = '';
			row.scrollIntoView({ block: 'center' });
			row.style.outline = '2px solid #2271b1';
		}
	}
})();
