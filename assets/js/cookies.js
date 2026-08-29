/*
	DATAR Consulting — aviso de cookies
	-----------------------------------------------------------------
	Muestra el banner en la primera visita, guarda la elección en
	localStorage y expone  window.DATAR_COOKIE_CONSENT  ('accepted' | 'rejected').
	Textos configurables en assets/data/contenido.json -> "cookies".

	Para activar analítica solo con consentimiento:
		document.addEventListener('datar:cookie-consent', function (e) {
			if (e.detail === 'accepted') { ...cargar GA/Pixel... }
		});
		// o comprobar window.DATAR_COOKIE_CONSENT === 'accepted' al cargar.
*/
(function () {
	'use strict';

	var KEY = 'datar_cookie_consent';
	var C = (window.DATAR_CONTENIDO || {}).cookies || {};

	function leer() {
		try { return localStorage.getItem(KEY); } catch (e) { return null; }
	}
	function guardar(v) {
		try { localStorage.setItem(KEY, v); } catch (e) {}
	}

	window.DATAR_COOKIE_CONSENT = leer();

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	/* ------------------- Modal de política de cookies -------------- */

	function abrirPolitica() {
		var m = document.getElementById('cookie-modal');
		if (m) { m.hidden = false; return; }

		m = document.createElement('div');
		m.id = 'cookie-modal';
		m.className = 'cookie-modal';
		m.setAttribute('role', 'dialog');
		m.setAttribute('aria-modal', 'true');
		m.innerHTML =
			'<div class="cookie-modal__box">'
			+   '<button type="button" class="cookie-modal__close" aria-label="' + esc(C.cerrar || 'Cerrar') + '">&times;</button>'
			+   '<h3>' + esc(C.politicaTitulo || 'Política de cookies') + '</h3>'
			+   '<div class="cookie-modal__cuerpo">' + (C.politica || '') + '</div>'
			+ '</div>';
		document.body.appendChild(m);

		function cerrar() { m.hidden = true; }
		m.addEventListener('click', function (e) { if (e.target === m) cerrar(); });
		m.querySelector('.cookie-modal__close').addEventListener('click', cerrar);
		document.addEventListener('keyup', function (e) {
			if (e.key === 'Escape' && !m.hidden) cerrar();
		});
	}

	/* --------------------------- Banner --------------------------- */

	function quitarBanner() {
		document.body.classList.remove('con-cookie-banner');
		var b = document.getElementById('cookie-banner');
		if (!b) return;
		b.classList.remove('is-visible');
		setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 350);
	}

	function decidir(valor) {
		guardar(valor);
		window.DATAR_COOKIE_CONSENT = valor;
		try {
			document.dispatchEvent(new CustomEvent('datar:cookie-consent', { detail: valor }));
		} catch (e) {}
		quitarBanner();
	}

	function mostrarBanner() {
		if (document.getElementById('cookie-banner')) return;

		var b = document.createElement('div');
		b.id = 'cookie-banner';
		b.className = 'cookie-banner';
		b.setAttribute('role', 'region');
		b.setAttribute('aria-label', 'Aviso de cookies');
		b.innerHTML =
			'<p>' + esc(C.texto || 'Usamos cookies para el funcionamiento del sitio.') + ' '
			+   '<a href="#" class="cookie-banner__info">' + esc(C.masInfo || 'Más información') + '</a></p>'
			+ '<div class="cookie-banner__actions">'
			+   '<button type="button" class="cookie-banner__btn cookie-banner__btn--ghost" data-cookie="rejected">' + esc(C.rechazar || 'Rechazar') + '</button>'
			+   '<button type="button" class="cookie-banner__btn cookie-banner__btn--primary" data-cookie="accepted">' + esc(C.aceptar || 'Aceptar') + '</button>'
			+ '</div>';
		document.body.appendChild(b);
		document.body.classList.add('con-cookie-banner');
		void b.offsetWidth;
		b.classList.add('is-visible');

		b.querySelector('.cookie-banner__info').addEventListener('click', function (e) {
			e.preventDefault();
			abrirPolitica();
		});
		[].forEach.call(b.querySelectorAll('[data-cookie]'), function (btn) {
			btn.addEventListener('click', function () { decidir(btn.getAttribute('data-cookie')); });
		});
	}

	/* ---- Enlace permanente en el pie para reabrir la política ---- */

	function enlacePie() {
		var footer = document.getElementById('footer-text');
		if (!footer || document.getElementById('cookie-reabrir')) return;
		var a = document.createElement('a');
		a.id = 'cookie-reabrir';
		a.href = '#';
		a.className = 'cookie-reabrir';
		a.textContent = 'Cookies';
		a.addEventListener('click', function (e) { e.preventDefault(); abrirPolitica(); });
		footer.appendChild(document.createTextNode(' · '));
		footer.appendChild(a);
	}

	enlacePie();
	if (!window.DATAR_COOKIE_CONSENT) mostrarBanner();
})();
