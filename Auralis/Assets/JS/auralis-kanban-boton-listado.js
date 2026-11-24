/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Lleva el botón «Tablero» de los listados de documentos al final del grupo de la derecha
 *              del ListView del Core: [Filtros][Orden][Colores][Tablero], como hermano posterior del
 *              botón de colores. Si no encuentra ese grupo, no hace nada y el botón se queda en la
 *              fila de acciones.
 */
(function () {
    'use strict';

    /** Prefijo del id que pone KanbanBotonListado::PREFIJO_ID. */
    var PREFIJO = 'auralis-kanban-boton-';

    function grupoDerecho(boton) {
        // ListView.html.twig: <div class="row"> con la columna de botones de la izquierda
        // (col-md-auto, donde el Core pinta las acciones), el buscador y la columna de la derecha.
        var izquierda = boton.closest('.col-md-auto');
        var fila = izquierda ? izquierda.parentElement : null;
        if (!fila || !fila.classList.contains('row')) {
            return null;
        }

        for (var i = 0; i < fila.children.length; i++) {
            var columna = fila.children[i];
            if (columna !== izquierda && columna.classList.contains('col-sm-auto') && columna.classList.contains('text-end')) {
                return columna;
            }
        }

        return null;
    }

    function mover(boton) {
        if (boton.getAttribute('data-zk-movido') === '1') {
            return;
        }

        var destino = grupoDerecho(boton);
        if (!destino) {
            return;
        }

        // el mismo nodo (no se duplica y conserva href, title e icono); el margen lo da el espacio, como en el Core
        boton.classList.remove('me-1');
        if (!boton.getAttribute('aria-label') && boton.getAttribute('title')) {
            boton.setAttribute('aria-label', boton.getAttribute('title'));
        }
        // al final de la columna: después del botón de colores (hermano suyo, nunca dentro de su menú)
        destino.appendChild(document.createTextNode(' '));
        destino.appendChild(boton);
        boton.setAttribute('data-zk-movido', '1');
    }

    function iniciar() {
        var botones = document.querySelectorAll('a[id^="' + PREFIJO + '"]');
        for (var i = 0; i < botones.length; i++) {
            mover(botones[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
