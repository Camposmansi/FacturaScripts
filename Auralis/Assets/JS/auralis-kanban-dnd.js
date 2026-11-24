/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Adaptador de arrastre del tablero Kanban: único fichero que conoce SortableJS (window.Sortable).
 */
(function () {
    'use strict';

    const CONSULTA_MOVIL = '(max-width: 575.98px)';
    const NOMBRE_GRUPO = 'auralis-kanban';

    let estado = null;

    function soportado() {
        return typeof window.Sortable === 'function';
    }

    function sinAnimaciones() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function esTactil() {
        return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    }

    function listas() {
        return Array.from(estado.tablero.querySelectorAll(estado.op.selectorLista));
    }

    // Las pestañas móviles son hermanas del tablero: se buscan en su contenedor.
    function pestanas() {
        const raiz = estado.tablero.parentElement || estado.tablero;
        return Array.from(raiz.querySelectorAll(estado.op.selectorPestana));
    }

    function listaDeColumna(columnaId) {
        return listas().find(function (lista) {
            return lista.dataset.columna === String(columnaId);
        }) || null;
    }

    function limpiarMarcas() {
        const marcas = ['zk-destino-valido', 'zk-destino-no', 'zk-destino-sobre'];
        listas().concat(pestanas()).forEach(function (nodo) {
            marcas.forEach(function (clase) {
                nodo.classList.remove(clase);
            });
        });
    }

    function permiteSoltar(destino, origen) {
        return destino.dataset.permitirSoltar === '1' && destino.dataset.columna !== origen.dataset.columna;
    }

    function alLevantar(evt) {
        estado.tablero.classList.add('zk-arrastrando');
        const origen = evt.from;
        listas().concat(pestanas()).forEach(function (nodo) {
            if (nodo === origen || nodo.dataset.columna === origen.dataset.columna) {
                return;
            }
            nodo.classList.add(permiteSoltar(nodo, origen) ? 'zk-destino-valido' : 'zk-destino-no');
        });
        if (esTactil() && typeof navigator.vibrate === 'function') {
            navigator.vibrate(10);
        }
    }

    function alPasarPor(evt) {
        listas().concat(pestanas()).forEach(function (nodo) {
            nodo.classList.remove('zk-destino-sobre');
        });
        if (evt.to && evt.to !== evt.from) {
            evt.to.classList.add('zk-destino-sobre');
        }
    }

    function alTerminar() {
        estado.tablero.classList.remove('zk-arrastrando');
        limpiarMarcas();
    }

    function notificar(item, columnaId, posicion) {
        const id = item.dataset.id;
        try {
            Promise.resolve(estado.onMove(id, String(columnaId), posicion)).catch(function () { /* lo gestiona el tablero */ });
        } catch (error) {
            /* el tablero ya informa del fallo al usuario */
        }
    }

    // Soltado sobre una lista de columna (escritorio y tablet).
    function alSoltarEnLista(evt) {
        notificar(evt.item, evt.to.dataset.columna, evt.newIndex || 0);
    }

    // Soltado sobre una pestaña (móvil): la tarjeta no se queda en la pestaña, va al principio de la columna destino.
    function alSoltarEnPestana(evt) {
        const item = evt.item;
        const columnaId = evt.to.dataset.columna;
        const destino = listaDeColumna(columnaId);
        if (item.parentNode) {
            item.parentNode.removeChild(item);
        }
        if (destino) {
            destino.insertBefore(item, destino.firstChild);
        }
        notificar(item, columnaId, 0);
    }

    function opcionesBase() {
        return {
            sort: false,
            delay: 300,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            scroll: true,
            scrollSensitivity: 60,
            bubbleScroll: true,
            animation: sinAnimaciones() ? 0 : 150,
            ghostClass: 'zk-fantasma',
            chosenClass: 'zk-elegida',
            dragClass: 'zk-arrastrada',
            onStart: alLevantar,
            onMove: alPasarPor,
            onEnd: alTerminar
        };
    }

    function montar() {
        desmontarInstancias();
        const movil = estado.mq.matches;
        const base = opcionesBase();
        const selTarjeta = estado.op.selectorTarjeta;

        listas().forEach(function (lista) {
            const opciones = Object.assign({}, base, {
                group: {
                    name: NOMBRE_GRUPO,
                    pull: true,
                    // En móvil las listas no reciben: los destinos son las pestañas.
                    put: function (destino, origen) {
                        return !movil && permiteSoltar(destino.el, origen.el);
                    }
                },
                draggable: selTarjeta + ':not(.' + estado.op.claseBloqueada + ')',
                filter: '.' + estado.op.claseBloqueada + ', [data-kanban-menu]',
                preventOnFilter: false,
                emptyInsertThreshold: 24,
                onAdd: alSoltarEnLista
            });
            estado.instancias.push(window.Sortable.create(lista, opciones));
        });

        if (movil) {
            pestanas().forEach(function (pestana) {
                const opciones = Object.assign({}, base, {
                    group: {
                        name: NOMBRE_GRUPO,
                        pull: false,
                        put: function (destino, origen) {
                            return permiteSoltar(destino.el, origen.el);
                        }
                    },
                    draggable: '.zk-nunca-arrastrable',
                    emptyInsertThreshold: 48,
                    onAdd: alSoltarEnPestana
                });
                estado.instancias.push(window.Sortable.create(pestana, opciones));
            });
        }
    }

    function desmontarInstancias() {
        if (!estado) {
            return;
        }
        estado.instancias.forEach(function (instancia) {
            try {
                instancia.destroy();
            } catch (error) {
                /* instancia ya liberada */
            }
        });
        estado.instancias = [];
    }

    /**
     * Activa el arrastre sobre el tablero. Devuelve false si SortableJS no está disponible
     * (el tablero sigue funcionando con «Mover a…»).
     * @param {HTMLElement} boardEl contenedor con listas .zk-lista[data-columna][data-permitir-soltar]
     *   y, en móvil, pestañas .zk-pestana[data-columna][data-permitir-soltar]
     * @param {function(string, string, number): Promise} onMove (idTarjeta, idColumna, posicion)
     * @param {object} [opciones]
     */
    function init(boardEl, onMove, opciones) {
        destroy();
        if (!soportado() || !boardEl || typeof onMove !== 'function') {
            return false;
        }
        estado = {
            tablero: boardEl,
            onMove: onMove,
            op: Object.assign({
                selectorLista: '.zk-lista',
                selectorTarjeta: '.zk-tarjeta',
                selectorPestana: '.zk-pestana',
                claseBloqueada: 'zk-bloqueada'
            }, opciones || {}),
            instancias: [],
            mq: window.matchMedia(CONSULTA_MOVIL),
            alCambiarVista: null
        };
        estado.alCambiarVista = function () {
            montar();
        };
        if (typeof estado.mq.addEventListener === 'function') {
            estado.mq.addEventListener('change', estado.alCambiarVista);
        } else if (typeof estado.mq.addListener === 'function') {
            estado.mq.addListener(estado.alCambiarVista);
        }
        montar();
        return true;
    }

    function destroy() {
        if (!estado) {
            return;
        }
        desmontarInstancias();
        if (typeof estado.mq.removeEventListener === 'function') {
            estado.mq.removeEventListener('change', estado.alCambiarVista);
        } else if (typeof estado.mq.removeListener === 'function') {
            estado.mq.removeListener(estado.alCambiarVista);
        }
        estado.tablero.classList.remove('zk-arrastrando');
        limpiarMarcas();
        estado = null;
    }

    window.AuralisKanbanDnd = { init: init, destroy: destroy };
})();
