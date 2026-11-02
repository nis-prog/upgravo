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
			post('phpready_start', { target: form.querySelector('[name=phpready_target]').value, inactive: (inact && inact.checked) ? 1 : 0 })
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

	// Filas de detalle: clic en la fila del plugin abre/cierra su detalle.
	document.querySelectorAll('tr.phpready-row').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('a')) { return; }
			var det = document.getElementById(row.dataset.detail);
			if (det) { det.style.display = det.style.display === 'none' ? '' : 'none'; }
		});
	});
})();
