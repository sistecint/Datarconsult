/*
	DATAR Consulting — formulario de contacto
	- Preselección del servicio al pulsar los botones "Formulario".
	- Envío por AJAX a send.php con mensaje flotante y recarga al inicio.
	- Marca de tiempo (_ts) y widget de Cloudflare Turnstile para el anti-abuso.
*/

(function () {
	// Textos configurables desde assets/data/contenido.json (los pone render.js).
	var CFG = ((window.DATAR_CONTENIDO || {}).contacto || {}).formulario || {};
	var TXT = {
		preseleccion: CFG.mensajePreseleccion || 'Hola DATAR, me interesa el servicio de {servicio}. ¿Podemos coordinar una reunión?',
		verificacion: CFG.verificacionPendiente || 'Completa la verificación de seguridad para enviar.',
		exito: ((window.DATAR_CONTENIDO || {}).contacto || {}).toast || 'Tu información fue enviada, pronto nos ponemos en contacto.'
	};

	var mensajeAutogenerado = ''; // último texto que pusimos nosotros en el mensaje

	function setService(serviceName) {
		var sel = document.getElementById('service');
		if (!sel || !serviceName) return;

		// Solo asigna si el servicio existe en el catálogo del <select>.
		var existe = false;
		for (var i = 0; i < sel.options.length; i++) {
			if (sel.options[i].value === serviceName) { existe = true; break; }
		}
		if (!existe) return;
		sel.value = serviceName;

		// Actualiza el mensaje sugerido, salvo que el usuario ya lo haya editado.
		var msg = document.getElementById('message');
		if (msg && (msg.value === '' || msg.value === mensajeAutogenerado)) {
			mensajeAutogenerado = TXT.preseleccion.replace('{servicio}', serviceName);
			msg.value = mensajeAutogenerado;
		}
	}

	// En fase de captura: la plantilla (Dimension) detiene la propagación
	// del clic dentro de cada <article>, así que un listener en burbuja
	// nunca se enteraría de los botones "Formulario".
	document.addEventListener('click', function (e) {
		var el = e.target;
		if (el && el.closest) el = el.closest('.js-contact-service');
		if (el && el.classList && el.classList.contains('js-contact-service')) {
			setService(el.getAttribute('data-service'));
		}
	}, true);

	// ---------------------------------------------------------------
	// Envío del formulario de contacto -> send.php -> info@datarconsult.com
	// Se envía por AJAX para conservar el mensaje flotante y la recarga
	// al inicio. Si send.php falla o no responde, se abre el gestor de
	// correo del visitante con el mensaje ya redactado como respaldo.
	// ---------------------------------------------------------------
	var DESTINATION_EMAIL = 'info@datarconsult.com';

	var form = document.getElementById('contact-form');
	var toast = document.getElementById('form-toast');

	// Marca de tiempo para el "time-trap" del servidor (anti-bots).
	var tsInput = document.getElementById('_ts');
	if (tsInput) tsInput.value = Date.now();

	// Cloudflare Turnstile: se carga solo si hay Site Key en el <form>.
	var siteKey = (form && form.getAttribute('data-turnstile-sitekey') || '').trim();
	var turnstileActive = siteKey !== '';
	if (turnstileActive) {
		var slot = document.getElementById('turnstile-slot');
		if (slot) {
			slot.innerHTML = '<div class="cf-turnstile" data-sitekey="' + siteKey + '" data-theme="dark"></div>';
			slot.style.margin = '1rem 0';
		}
		var ts = document.createElement('script');
		ts.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
		ts.async = true; ts.defer = true;
		document.head.appendChild(ts);
	}

	function setToastMessage(msg) {
		if (!toast || !msg) return;
		var box = toast.querySelector('.form-toast__box');
		if (box) box.textContent = msg;
	}

	function showToast(msg, opts) {
		if (!toast) return;
		opts = opts || {};
		setToastMessage(msg);
		toast.hidden = false;
		void toast.offsetWidth; // fuerza el reflow para animar la aparición
		toast.classList.add('is-visible');
		if (opts.stay) {
			// El usuario cierra el aviso; no se recarga la página.
			toast.style.pointerEvents = 'auto';
			toast.onclick = function () {
				toast.classList.remove('is-visible');
				setTimeout(function () { toast.hidden = true; }, 350);
			};
		} else {
			setTimeout(function () {
				window.location.href = window.location.pathname; // recarga al inicio
			}, 2500);
		}
	}

	function fieldValue(id) {
		var el = document.getElementById(id);
		return el ? (el.value || '') : '';
	}

	function openMailClient() {
		var body = 'Nombre: ' + fieldValue('name') + '\n'
			+ 'Email: ' + fieldValue('email') + '\n'
			+ 'Teléfono: ' + fieldValue('phone') + '\n'
			+ 'Servicio: ' + fieldValue('service') + '\n\n'
			+ fieldValue('message');
		window.location.href = 'mailto:' + DESTINATION_EMAIL
			+ '?subject=' + encodeURIComponent('Nuevo contacto - DATAR Consulting')
			+ '&body=' + encodeURIComponent(body);
	}

	function resetBtn(btn) {
		if (btn) { btn.disabled = false; btn.value = 'Enviar'; }
	}

	if (form && toast) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();

			if (form.checkValidity && !form.checkValidity()) {
				if (form.reportValidity) form.reportValidity();
				return;
			}

			var btn = form.querySelector('[type="submit"]');

			// Turnstile: exige el token antes de enviar.
			if (turnstileActive) {
				var tk = form.querySelector('[name="cf-turnstile-response"]');
				if (!tk || !tk.value) {
					showToast(TXT.verificacion, { stay: true });
					return;
				}
			}

			var endpoint = form.getAttribute('action') || 'send.php';
			if (btn) { btn.disabled = true; btn.value = 'Enviando…'; }

			fetch(endpoint, {
				method: 'POST',
				body: new FormData(form),
				headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
			}).then(function (res) {
				return res.text().then(function (txt) {
					var data;
					try { data = JSON.parse(txt); } catch (err) { data = { ok: res.ok }; }
					return { status: res.status, url: res.url, redirected: res.redirected, body: txt, data: data };
				});
			}).then(function (r) {
				if (r.redirected) {
					console.warn('send.php: la petición fue redirigida a', r.url,
						'— el POST pudo convertirse en GET. Revisa la URL canónica del sitio.');
				}
				if (r.data && r.data.ok) {
					showToast(TXT.exito);
				} else if (r.status === 429 || r.status === 403) {
					// Límite de envíos o verificación fallida: avisar y dejar reintentar.
					resetBtn(btn);
					if (window.turnstile && turnstileActive) window.turnstile.reset();
					showToast((r.data && r.data.message) || 'No se pudo enviar. Inténtalo más tarde.', { stay: true });
				} else {
					console.error('send.php no confirmó el envío:', r.status, r.body);
					openMailClient();
					showToast(TXT.exito);
				}
			}).catch(function (err) {
				console.error('Error al enviar el formulario:', err);
				openMailClient();
				showToast(TXT.exito);
			});
		});
	}
})();
