/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Barra de filtros del tablero Kanban: aplica al cambiar (GET, filtros en la URL) y buscador remoto con Tom Select.
 */

(function () {
    'use strict';

    /** Envía el formulario sin los campos vacíos, para que la URL solo lleve los filtros activos. */
    function aplicar(form) {
        form.querySelectorAll('input[name], select[name]').forEach(function (campo) {
            if (campo.value === '') {
                campo.disabled = true;
            }
        });
        form.submit();
    }

    /** Buscador remoto (solo lectura) para los controles «buscar»; sin Tom Select queda el select nativo. */
    function iniciarBuscador(form, select) {
        if (typeof window.TomSelect === 'undefined') {
            return;
        }

        const filtro = select.getAttribute('data-kanban-buscar') || '';
        const base = form.getAttribute('data-buscar-url') || '';
        const board = form.getAttribute('data-board') || '';

        // eslint-disable-next-line no-new
        new window.TomSelect(select, {
            valueField: 'value',
            labelField: 'text',
            searchField: ['text', 'value'],
            maxItems: 1,
            create: false,
            preload: 'focus',
            loadThrottle: 250,
            allowEmptyOption: true,
            placeholder: select.getAttribute('placeholder') || '',
            load: function (texto, callback) {
                const parametros = new URLSearchParams({ action: 'kanban-filter-search', board: board, filtro: filtro, q: texto || '' });
                fetch(base + '?' + parametros.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (respuesta) { return respuesta.ok ? respuesta.json() : { opciones: [] }; })
                    .then(function (datos) { callback(Array.isArray(datos.opciones) ? datos.opciones : []); })
                    .catch(function () { callback(); });
            },
            onChange: function () {
                aplicar(form);
            }
        });
    }

    function iniciar(form) {
        // el selector «Ordenar por» no recarga la página: lo atiende auralis-kanban.js (solo viaja al aplicar filtros)
        form.querySelectorAll('select:not([data-kanban-buscar]):not([data-kanban-orden-select]), input[type="date"]').forEach(function (campo) {
            campo.addEventListener('change', function () {
                aplicar(form);
            });
        });

        form.querySelectorAll('select[data-kanban-buscar]').forEach(function (select) {
            iniciarBuscador(form, select);
        });

        form.addEventListener('submit', function (evento) {
            evento.preventDefault();
            aplicar(form);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-kanban-filtros]').forEach(iniciar);
    });

    // al volver con «atrás» (caché del navegador) los campos vacíos seguirían deshabilitados
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-kanban-filtros] [disabled]').forEach(function (campo) {
            campo.disabled = false;
        });
    });
})();
