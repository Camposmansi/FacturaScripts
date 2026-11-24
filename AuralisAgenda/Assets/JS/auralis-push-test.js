/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Botón «Enviar push de prueba» de Config agenda (POST CSRF a AuralisAgendaNotificationsAjax, resultado en línea).
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-auralis-push-test]');
    if (!box || box.getAttribute('data-bound') === '1') {
        return;
    }
    box.setAttribute('data-bound', '1');

    var button = box.querySelector('[data-auralis-push-test-btn]');
    var result = box.querySelector('[data-auralis-push-test-result]');
    if (!button || !result) {
        return;
    }

    // Subir el bloque al final del grupo push (si no existe, se queda en el pie de la vista).
    var group = document.getElementById('grupo-calendario-push-pwa');
    var wrapper = box.parentNode;
    var target = group ? group.firstElementChild : null;
    if (target && wrapper) {
        target.appendChild(wrapper);
    }

    // CSRF de un solo uso: se lee del form y se renueva con el newToken de cada respuesta.
    function token() {
        var input = document.querySelector('input[name="multireqtoken"]');
        return input ? input.value : '';
    }

    function updateToken(value) {
        if (!value) {
            return;
        }
        document.querySelectorAll('input[name="multireqtoken"]').forEach(function (input) {
            input.value = value;
        });
    }

    function show(kind, text) {
        result.className = 'alert mt-3 mb-0 alert-' + kind;
        result.textContent = text;
    }

    button.addEventListener('click', function () {
        var body = new URLSearchParams();
        body.set('action', 'push-test');
        body.set('multireqtoken', token());

        button.disabled = true;
        result.className = 'alert mt-3 mb-0 d-none';

        fetch(box.getAttribute('data-url') || 'AuralisAgendaNotificationsAjax', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            data = data || {};
            updateToken(data.newToken);
            var kind = data.ok ? 'success' : (data.destinos === 0 ? 'info' : 'warning');
            show(kind, data.message || box.getAttribute('data-error') || '');
        }).catch(function () {
            show('danger', box.getAttribute('data-error') || '');
        }).then(function () {
            button.disabled = false;
        });
    });
})();
