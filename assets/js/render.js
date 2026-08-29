/*
	DATAR Consulting — render del contenido
	--------------------------------------------------------------
	Todo el texto de la empresa vive en  assets/data/contenido.json.
	Este script lo carga, construye el DOM (encabezado, menú, artículos,
	formulario y pie) y después carga main.js (plantilla Dimension) y
	contact.js (formulario). Editar el sitio = editar el JSON.
*/
(function () {
	'use strict';

	var DATA_URL = 'assets/data/contenido.json';
	var WA_BASE  = 'https://wa.me/';

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	function waUrl(numero, texto) {
		return WA_BASE + numero + '?text=' + encodeURIComponent(texto || '');
	}

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src;
			s.onload = function () { resolve(); };
			s.onerror = function () { reject(new Error('No se pudo cargar ' + src)); };
			document.body.appendChild(s);
		});
	}

	/* ---------------------- Acciones (ul.actions) -------------------- */

	function renderAccion(item, empresa) {
		var cls = 'button';
		if (item.primary) cls += ' primary';
		var attrs = '';
		var href = item.href || '#contacto';

		if (item.whatsapp) {
			cls += ' icon brands fa-whatsapp';
			href = waUrl(empresa.whatsapp, item.whatsapp);
			attrs = ' target="_blank" rel="noopener"';
		} else if (item.servicio) {
			cls += ' js-contact-service';
			attrs = ' data-service="' + esc(item.servicio) + '"';
			href = '#contacto';
		}
		return '<li><a class="' + cls + '"' + attrs + ' href="' + esc(href) + '">' + esc(item.texto) + '</a></li>';
	}

	function renderAcciones(items, empresa) {
		return '<ul class="actions">' + (items || []).map(function (i) {
			return renderAccion(i, empresa);
		}).join('') + '</ul>';
	}

	/* --------------------------- Bloques --------------------------- */

	function renderBloque(b, empresa, ui) {
		switch (b.tipo) {
			case 'parrafo':
				return '<p>' + (b.html || esc(b.texto)) + '</p>';
			case 'subtitulo':
				return '<h3>' + esc(b.texto) + '</h3>';
			case 'separador':
				return '<hr />';
			case 'lista':
				return '<ul>' + (b.items || []).map(function (i) { return '<li>' + i + '</li>'; }).join('') + '</ul>';
			case 'lista-alt':
				return '<ul class="alt">' + (b.items || []).map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
			case 'acciones':
				return renderAcciones(b.items, empresa);
			case 'servicio':
				var subs = '<ul>' + (b.subservicios || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>';
				var acc = renderAcciones([
					{ texto: ui.iconWhatsapp || 'WhatsApp', whatsapp: b.whatsappTexto, primary: true },
					{ texto: 'Formulario', servicio: b.servicioFormulario }
				], empresa);
				return '<section>'
					+ '<h3>' + esc(b.titulo) + '</h3>'
					+ '<details><summary>' + esc(ui.verSubservicios || 'Ver subservicios') + '</summary>' + subs + '</details>'
					+ acc
					+ '</section>';
			default:
				return '';
		}
	}

	function renderArticulo(a, empresa, ui) {
		return '<article id="' + esc(a.id) + '">'
			+ '<h2 class="major">' + esc(a.titulo) + '</h2>'
			+ (a.bloques || []).map(function (b) { return renderBloque(b, empresa, ui); }).join('')
			+ '</article>';
	}

	/* ------------------ Contacto (artículo + formulario) ----------- */

	function renderContacto(c, empresa, ui, sitekey) {
		var f = c.formulario || {};
		var campos = f.campos || {};
		var botones = f.botones || {};
		var L = (ui.infoLabels) || {};

		function campo(id, tipo, requerido) {
			var cfg = campos[id === 'phone' ? 'telefono' : (id === 'name' ? 'nombre' : (id === 'message' ? 'mensaje' : id))] || {};
			var ph = cfg.placeholder ? ' placeholder="' + esc(cfg.placeholder) + '"' : '';
			var req = requerido ? ' required' : '';
			var input = tipo === 'textarea'
				? '<textarea name="' + id + '" id="' + id + '" rows="5"' + ph + req + '></textarea>'
				: '<input type="' + tipo + '" name="' + id + '" id="' + id + '"' + ph + req + ' />';
			return '<label for="' + id + '">' + esc(cfg.label || id) + '</label>' + input;
		}

		var opciones = (f.servicios || []).map(function (s) {
			return '<option value="' + esc(s) + '">' + esc(s) + '</option>';
		}).join('');

		return '<article id="contacto">'
			+ '<h2 class="major">' + esc(c.titulo) + '</h2>'
			+ '<p>' + esc(c.intro) + '</p>'
			+ '<form id="contact-form" method="POST" action="send.php" data-turnstile-sitekey="' + esc(sitekey) + '">'
			+   '<input type="hidden" name="_subject" value="' + esc(f.asunto || '') + '" />'
			+   '<input type="hidden" name="_ts" id="_ts" value="" />'
			+   '<input type="text" name="_gotcha" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" />'
			+   '<div class="fields">'
			+     '<div class="field half">' + campo('name', 'text', true) + '</div>'
			+     '<div class="field half">' + campo('email', 'email', true) + '</div>'
			+     '<div class="field half">' + campo('phone', 'text', false) + '</div>'
			+     '<div class="field half">'
			+       '<label for="service">' + esc((campos.servicio && campos.servicio.label) || 'Servicio') + '</label>'
			+       '<select name="service" id="service">' + opciones + '</select>'
			+     '</div>'
			+     '<div class="field">' + campo('message', 'textarea', true) + '</div>'
			+   '</div>'
			+   '<div id="turnstile-slot"></div>'
			+   '<ul class="actions">'
			+     '<li><input type="submit" value="' + esc(botones.enviar || 'Enviar') + '" class="primary" /></li>'
			+     '<li><input type="reset" value="' + esc(botones.limpiar || 'Limpiar') + '" /></li>'
			+   '</ul>'
			+ '</form>'
			+ '<hr />'
			+ '<h3>' + esc(c.infoTitulo || 'Información de contacto') + '</h3>'
			+ '<ul class="alt">'
			+   '<li><strong>' + esc(L.telefono || 'Teléfono / WhatsApp:') + '</strong> ' + esc(empresa.telefono) + '</li>'
			+   '<li><strong>' + esc(L.email || 'Email:') + '</strong> ' + esc(empresa.email) + '</li>'
			+   '<li><strong>' + esc(L.sitioWeb || 'Sitio web:') + '</strong> ' + esc(empresa.sitioWeb) + '</li>'
			+   '<li><strong>' + esc(L.direccion || 'Dirección:') + '</strong> ' + esc(empresa.direccion) + '</li>'
			+ '</ul>'
			+ '<ul class="icons">'
			+   '<li><a target="_blank" rel="noopener" href="' + WA_BASE + esc(empresa.whatsapp) + '" class="icon brands fa-whatsapp"><span class="label">' + esc(ui.iconWhatsapp || 'WhatsApp') + '</span></a></li>'
			+   '<li><a target="_blank" rel="noopener" href="' + esc(empresa.sitioWebUrl) + '" class="icon solid fa-globe"><span class="label">' + esc(ui.iconSitioWeb || 'Sitio web') + '</span></a></li>'
			+ '</ul>'
			+ '</article>';
	}

	/* --------------------------- Render --------------------------- */

	function render(data) {
		var empresa = data.empresa || {};
		var ui = data.ui || {};

		if (data.meta) {
			if (data.meta.titulo) document.title = data.meta.titulo;
			var md = document.querySelector('meta[name="description"]');
			if (md && data.meta.descripcion) md.setAttribute('content', data.meta.descripcion);
		}

		var logoImg = document.querySelector('#header .logo img');
		if (logoImg && empresa.logoAlt) logoImg.alt = empresa.logoAlt;

		var hero = data.hero || {};
		document.getElementById('header-inner').innerHTML =
			'<h1>' + esc(hero.titulo) + '</h1>'
			+ '<p>' + (hero.html || '') + '</p>'
			+ renderAcciones(hero.acciones, empresa);

		document.getElementById('nav-list').innerHTML = (data.nav || []).map(function (n) {
			return '<li><a href="#' + esc(n.id) + '">' + esc(n.label) + '</a></li>';
		}).join('');

		var sitekey = '';
		var metaKey = document.querySelector('meta[name="turnstile-sitekey"]');
		if (metaKey) sitekey = metaKey.getAttribute('content') || '';

		var html = (data.articulos || []).map(function (a) { return renderArticulo(a, empresa, ui); }).join('');
		if (data.contacto) html += renderContacto(data.contacto, empresa, ui, sitekey);
		document.getElementById('main').innerHTML = html;

		var footer = document.getElementById('footer-text');
		if (footer && data.footer != null) footer.innerHTML = data.footer;

		var wa = document.getElementById('whatsapp-float');
		if (wa) {
			wa.href = waUrl(empresa.whatsapp, empresa.whatsappTextoGeneral);
			wa.textContent = ui.iconWhatsapp || 'WhatsApp';
		}

		var toastBox = document.querySelector('#form-toast .form-toast__box');
		if (toastBox && data.contacto && data.contacto.toast) toastBox.textContent = data.contacto.toast;
	}

	function fallo(err) {
		console.error('render.js:', err);
		var main = document.getElementById('main');
		if (main) {
			main.innerHTML = '<article id="inicio" class="active" style="display:block;opacity:1;transform:none">'
				+ '<h2 class="major">Contenido no disponible</h2>'
				+ '<p>No se pudo cargar el contenido del sitio. Recarga la página o escríbenos a '
				+ '<a href="mailto:info@datarconsult.com">info@datarconsult.com</a> / '
				+ '<a href="https://wa.me/50766015038">WhatsApp (507) 6601-5038</a>.</p></article>';
		}
		document.body.classList.remove('is-preload');
	}

	/* ---------------------------- Arranque ------------------------- */

	fetch(DATA_URL, { cache: 'no-cache' })
		.then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status + ' al cargar ' + DATA_URL);
			return r.json();
		})
		.then(function (data) {
			window.DATAR_CONTENIDO = data;
			render(data);
			return loadScript('assets/js/main.js');
		})
		.then(function () { return loadScript('assets/js/contact.js'); })
		.then(function () {
			document.body.classList.remove('is-preload');
			// Enlace directo a una sección (#servicios, #contacto, …).
			if (location.hash && location.hash.length > 1 && window.jQuery) {
				window.jQuery(window).trigger('hashchange');
			}
		})
		.catch(fallo);
})();
