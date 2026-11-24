/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Lateral de Mi calendario: plegado, mini-calendario, listas de calendarios (visible, color, compartir,
 *              ajustes y borrado), selector de calendario del modal y eventos de solo lectura / «ocupado» / módulo.
 *              Usa los endpoints calendarios-* de CalendarioUsuario (Lib/CalendarioAjax) y window.AuralisCalendarPage.
 */

(function () {
    'use strict';

    const LS_PLEGADO = 'auralis-calendar-sidebar-plegado';
    // Secciones plegadas del lateral: { propios: true, filtros: false, … } (true = abierta).
    const LS_SECCIONES = 'auralis-calendar-sidebar-secciones';

    let cfg = {};
    let datos = {};
    let calendarios = [];
    let i18n = {};
    let paleta = [];
    let cola = Promise.resolve();

    const mini = { mes: null, seleccionada: null };
    const compartir = { idcalendario: 0, usuarios: [], roles: [], tsUsuario: null, tsRol: null };

    document.addEventListener('DOMContentLoaded', function () {
        const dataEl = document.getElementById('auralis-calendar-sidebar-data');
        if (!dataEl) {
            return;
        }
        try {
            cfg = JSON.parse(dataEl.textContent || '{}') || {};
        } catch (e) {
            console.error('[AuralisCalendarSidebar] JSON inválido', e);
            return;
        }
        datos = cfg.datos || {};
        calendarios = Array.isArray(datos.calendarios) ? datos.calendarios : [];
        i18n = cfg.i18n || {};
        paleta = Array.isArray(cfg.paleta) ? cfg.paleta : [];

        window.AuralisCalendarSidebar = {
            appendParams: appendParams,
            interceptarEvento: interceptarEvento,
            recargarLista: recargarLista
        };

        initPlegado();
        initPlegables();
        initFiltros();
        initMiniCal();
        pintarListas();
        initCrear();
        initModalCalendario();
        initCompartir();
        initEventos();
        initModalEvento();
    });

    // ── Almacenamiento local (siempre en try/catch: modo privado o sin permisos) ──

    function leerLocal(clave) {
        try {
            return window.localStorage.getItem(clave);
        } catch (e) {
            return null;
        }
    }

    function guardarLocal(clave, valor) {
        try {
            window.localStorage.setItem(clave, valor);
        } catch (e) {
            /* sin almacenamiento: el estado no se recuerda */
        }
    }

    // ── Peticiones (CSRF de un solo uso: POST en cola y newToken en cada respuesta) ──

    function token() {
        const input = document.querySelector('input[name="multireqtoken"]');
        return input ? input.value : '';
    }

    function actualizarToken(nuevo) {
        if (!nuevo) {
            return;
        }
        document.querySelectorAll('input[name="multireqtoken"]').forEach(function (input) {
            input.value = nuevo;
        });
    }

    function leerJson(res) {
        return res.json().then(function (data) {
            if (data && data.newToken) {
                actualizarToken(data.newToken);
            }
            return data || {};
        });
    }

    function urlAccion(accion) {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('action', accion);
        return url;
    }

    function get(accion, campos) {
        const url = urlAccion(accion);
        Object.keys(campos || {}).forEach(function (k) {
            url.searchParams.set(k, String(campos[k]));
        });
        return fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(leerJson);
    }

    function post(accion, campos) {
        const tarea = cola.then(function () {
            const body = new URLSearchParams();
            body.set('action', accion);
            Object.keys(campos || {}).forEach(function (k) {
                body.set(k, campos[k] === null || campos[k] === undefined ? '' : String(campos[k]));
            });
            body.set('multireqtoken', token());
            return fetch(urlAccion(accion).toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            }).then(leerJson);
        });
        cola = tarea.catch(function () {
            return null;
        });
        return tarea;
    }

    /** Response::json() del Core responde 200 siempre: el resultado va en success. */
    function exito(data, textoError) {
        if (data && data.success) {
            return true;
        }
        window.alert((data && data.message) || textoError || i18n.errorGuardar || 'Error');
        return false;
    }

    /** Color del icono sobre un punto de color libre (mismo criterio que las tarjetas de la rejilla). */
    function colorTexto(fondo) {
        return window.AuralisCalendarModal && typeof window.AuralisCalendarModal.colorTextoSobre === 'function'
            ? window.AuralisCalendarModal.colorTextoSobre(fondo)
            : '#ffffff';
    }

    function recargarEventos() {
        if (window.AuralisCalendarPage && typeof window.AuralisCalendarPage.reload === 'function') {
            window.AuralisCalendarPage.reload();
        }
    }

    // ── Calendarios elegidos → calendarios[] en calendar-events ──

    function appendParams(params) {
        if (!datos || !datos.ok) {
            return; // sin lista: el servidor usa la preferencia guardada
        }
        params.delete('calendarios[]');
        params.delete('calendarios');
        const visibles = calendarios.filter(function (c) {
            return c.visible;
        });
        if (!visibles.length) {
            params.set('calendarios', '');
            return;
        }
        visibles.forEach(function (c) {
            params.append('calendarios[]', c.clave);
        });
    }

    function buscar(idcalendario) {
        const id = parseInt(idcalendario, 10);
        return calendarios.find(function (c) {
            return parseInt(c.idcalendario, 10) === id;
        }) || null;
    }

    function buscarPorClave(clave) {
        return calendarios.find(function (c) {
            return c.clave === clave;
        }) || null;
    }

    function reemplazar(item) {
        if (!item || !item.idcalendario) {
            return;
        }
        const pos = calendarios.findIndex(function (c) {
            return parseInt(c.idcalendario, 10) === parseInt(item.idcalendario, 10);
        });
        if (pos >= 0) {
            calendarios[pos] = item;
        } else {
            calendarios.push(item);
        }
    }

    function recargarLista() {
        return get('calendarios-list').then(function (data) {
            if (!data || !data.ok) {
                return;
            }
            datos = data;
            calendarios = Array.isArray(data.calendarios) ? data.calendarios : [];
            pintarListas();
            pintarSelectorEvento();
        });
    }

    // ── Plegar el lateral (escritorio) ──

    function initPlegado() {
        const pagina = document.getElementById('auralisCalendarPage');
        const boton = document.getElementById('btnCalSidebarToggle');
        if (!pagina || !boton) {
            return;
        }
        const aplicar = function (plegado) {
            pagina.classList.toggle('auralis-calendar-lateral-plegado', plegado);
            boton.setAttribute('aria-expanded', plegado ? 'false' : 'true');
        };
        aplicar(leerLocal(LS_PLEGADO) === '1');
        boton.addEventListener('click', function () {
            const plegado = !pagina.classList.contains('auralis-calendar-lateral-plegado');
            aplicar(plegado);
            guardarLocal(LS_PLEGADO, plegado ? '1' : '0');
        });
    }

    function cerrarOffcanvas() {
        const lateral = document.getElementById('auralisCalendarSidebar');
        if (!lateral || !lateral.classList.contains('show') || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
            return;
        }
        const instancia = bootstrap.Offcanvas.getInstance(lateral);
        if (instancia) {
            instancia.hide();
        }
    }

    /**
     * Secciones plegables ([data-zcal-plegable]: mis calendarios, empresa, otros y filtros), como en Google
     * Calendar. Se abren según lo guardado o, si no hay nada, según su estado de serie (data-zcal-plegada="1").
     */
    function initPlegables() {
        let estado = {};
        try {
            estado = JSON.parse(leerLocal(LS_SECCIONES) || '{}') || {};
        } catch (e) {
            estado = {};
        }
        document.querySelectorAll('[data-zcal-plegable]').forEach(function (bloque) {
            const clave = bloque.getAttribute('data-zcal-plegable');
            const abierta = Object.prototype.hasOwnProperty.call(estado, clave)
                ? !!estado[clave]
                : bloque.getAttribute('data-zcal-plegada') !== '1';
            const boton = document.querySelector('[data-bs-target="#' + bloque.id + '"]');
            bloque.classList.toggle('show', abierta);
            if (boton) {
                boton.classList.toggle('collapsed', !abierta);
                boton.setAttribute('aria-expanded', abierta ? 'true' : 'false');
            }
            const guardar = function (valor) {
                estado[clave] = valor;
                guardarLocal(LS_SECCIONES, JSON.stringify(estado));
            };
            bloque.addEventListener('shown.bs.collapse', function (e) {
                if (e.target === bloque) {
                    guardar(true);
                }
            });
            bloque.addEventListener('hidden.bs.collapse', function (e) {
                if (e.target === bloque) {
                    guardar(false);
                }
            });
        });
    }

    function initFiltros() {
        const bloque = document.getElementById('auralisCalFiltros');
        if (!bloque) {
            return;
        }
        // Contador de filtros activos (visible aunque el bloque esté plegado) y «Quitar filtros».
        const contador = document.getElementById('auralisCalFiltrosContador');
        const quitar = document.getElementById('btnCalQuitarFiltros');
        const selects = function () {
            return Array.prototype.slice.call(bloque.querySelectorAll('select'));
        };
        const pintarContador = function () {
            const activos = selects().filter(function (sel) {
                return !!sel.value;
            }).length;
            if (contador) {
                const texto = (contador.getAttribute('data-texto') || '').replace('%n%', String(activos));
                contador.textContent = activos ? String(activos) : '';
                contador.title = texto;
                contador.setAttribute('aria-label', texto);
                contador.classList.toggle('d-none', activos === 0);
            }
            if (quitar) {
                quitar.classList.toggle('d-none', activos === 0);
            }
        };
        bloque.addEventListener('change', pintarContador);
        if (quitar) {
            quitar.addEventListener('click', function () {
                selects().forEach(function (sel) {
                    if (sel.tomselect) {
                        // Sin silenciar: un filtro de plugin (p. ej. vehículo de zTaller) puede guardar su valor
                        // fuera del select y necesita enterarse.
                        sel.tomselect.clear();
                    }
                    sel.value = '';
                });
                pintarContador();
                recargarEventos();
            });
        }
        pintarContador();

        // Móvil: el desplegable de Tom Select (anclado a body) se abre bajo el control; se centra antes de abrirlo.
        bloque.addEventListener('focusin', function (e) {
            const lateral = document.getElementById('auralisCalendarSidebar');
            const control = e.target.closest('.ts-wrapper, .form-select');
            if (control && lateral && lateral.classList.contains('show')) {
                control.scrollIntoView({ block: 'center' });
            }
        });
        initDesplegablesFijos(bloque, selects);
    }

    /**
     * Los Tom Select de los filtros (de Auralis o de un plugin) abren su desplegable dentro del lateral, que tiene
     * scroll propio y lo cortaba. Se pinta en posición fija bajo el control (o encima si abajo no cabe).
     * Se engancha al primer foco o clic: los plugins crean su Tom Select cuando quieren.
     */
    function initDesplegablesFijos(bloque, selects) {
        const abiertos = [];
        const colocar = function (ts) {
            const desplegable = ts.dropdown;
            const caja = ts.wrapper.getBoundingClientRect();
            desplegable.style.position = 'fixed';
            desplegable.style.left = caja.left + 'px';
            desplegable.style.width = caja.width + 'px';
            const alto = desplegable.offsetHeight;
            const libreAbajo = window.innerHeight - caja.bottom;
            const arriba = libreAbajo < alto + 8 && caja.top > libreAbajo;
            desplegable.style.top = (arriba ? Math.max(4, caja.top - alto - 8) : caja.bottom) + 'px';
        };
        const recolocar = function () {
            const lateral = document.getElementById('auralisCalendarSidebar');
            const visible = lateral ? lateral.getBoundingClientRect() : null;
            abiertos.slice().forEach(function (ts) {
                // Si el scroll del lateral deja el control fuera de la vista, el desplegable se cierra con él.
                const caja = ts.wrapper.getBoundingClientRect();
                if (visible && (caja.bottom < visible.top || caja.top > visible.bottom)) {
                    ts.close();
                    return;
                }
                colocar(ts);
            });
        };
        const anclar = function (ts) {
            if (!ts || ts.zcalDesplegableFijo) {
                return;
            }
            ts.zcalDesplegableFijo = true;
            ts.on('dropdown_open', function () {
                if (abiertos.indexOf(ts) === -1) {
                    abiertos.push(ts);
                }
                colocar(ts);
            });
            ts.on('dropdown_close', function () {
                const posicion = abiertos.indexOf(ts);
                if (posicion !== -1) {
                    abiertos.splice(posicion, 1);
                }
                ['position', 'left', 'width', 'top'].forEach(function (prop) {
                    ts.dropdown.style[prop] = '';
                });
            });
            // Al llegar los resultados cambia el alto (y puede tocar abrir hacia arriba).
            if (typeof window.ResizeObserver === 'function') {
                new ResizeObserver(function () {
                    if (ts.isOpen) {
                        colocar(ts);
                    }
                }).observe(ts.dropdown);
            }
            if (ts.isOpen) {
                abiertos.push(ts);
                colocar(ts);
            }
        };
        const engancharDesde = function (e) {
            selects().forEach(function (sel) {
                if (sel.tomselect && sel.tomselect.wrapper.contains(e.target)) {
                    anclar(sel.tomselect);
                }
            });
        };
        // En captura: llega antes que los manejadores de Tom Select, que abren el desplegable.
        bloque.addEventListener('focus', engancharDesde, true);
        bloque.addEventListener('mousedown', engancharDesde, true);
        window.addEventListener('scroll', recolocar, true);
        window.addEventListener('resize', recolocar);
    }

    // ── Mini-calendario mensual ──

    function idioma() {
        return document.documentElement.lang || 'es-ES';
    }

    function mismoDia(a, b) {
        return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function fechaIso(fecha) {
        return fecha.getFullYear() + '-' + String(fecha.getMonth() + 1).padStart(2, '0') + '-' + String(fecha.getDate()).padStart(2, '0');
    }

    function fechaPagina() {
        if (window.AuralisCalendarPage && typeof window.AuralisCalendarPage.getDate === 'function') {
            return window.AuralisCalendarPage.getDate();
        }
        return new Date();
    }

    function initMiniCal() {
        const rejilla = document.getElementById('auralisMiniCalRejilla');
        if (!rejilla) {
            return;
        }
        mini.seleccionada = fechaPagina();
        mini.mes = new Date(mini.seleccionada.getFullYear(), mini.seleccionada.getMonth(), 1);

        document.querySelectorAll('[data-minical-mover]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                const paso = parseInt(boton.getAttribute('data-minical-mover'), 10) || 0;
                mini.mes = new Date(mini.mes.getFullYear(), mini.mes.getMonth() + paso, 1);
                pintarMiniCal();
            });
        });

        rejilla.addEventListener('click', function (e) {
            const dia = e.target.closest('[data-minical-fecha]');
            if (!dia || !window.AuralisCalendarPage) {
                return;
            }
            const partes = dia.getAttribute('data-minical-fecha').split('-');
            const fecha = new Date(parseInt(partes[0], 10), parseInt(partes[1], 10) - 1, parseInt(partes[2], 10));
            mini.seleccionada = fecha;
            pintarMiniCal();
            window.AuralisCalendarPage.goToDate(fecha);
            cerrarOffcanvas();
        });

        document.addEventListener('auralis-calendar-loaded', function (e) {
            const fecha = e.detail && e.detail.fecha ? e.detail.fecha : fechaPagina();
            mini.seleccionada = fecha;
            if (fecha.getFullYear() !== mini.mes.getFullYear() || fecha.getMonth() !== mini.mes.getMonth()) {
                mini.mes = new Date(fecha.getFullYear(), fecha.getMonth(), 1);
            }
            pintarMiniCal();
        });

        pintarMiniCal();
    }

    function pintarMiniCal() {
        const rejilla = document.getElementById('auralisMiniCalRejilla');
        const titulo = document.getElementById('auralisMiniCalTitulo');
        if (!rejilla) {
            return;
        }
        if (titulo) {
            const texto = mini.mes.toLocaleDateString(idioma(), { month: 'long', year: 'numeric' });
            titulo.textContent = texto.charAt(0).toUpperCase() + texto.slice(1);
        }

        const hoy = new Date();
        const fragmento = document.createDocumentFragment();
        // Cabecera lunes → domingo (5 ene 2026 es lunes).
        for (let i = 0; i < 7; i++) {
            const cab = document.createElement('span');
            cab.className = 'auralis-minical-semana';
            cab.textContent = new Date(2026, 0, 5 + i).toLocaleDateString(idioma(), { weekday: 'narrow' });
            fragmento.appendChild(cab);
        }

        const desfase = (mini.mes.getDay() + 6) % 7;
        const inicio = new Date(mini.mes.getFullYear(), mini.mes.getMonth(), 1 - desfase);
        for (let i = 0; i < 42; i++) {
            const fecha = new Date(inicio.getFullYear(), inicio.getMonth(), inicio.getDate() + i);
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'auralis-minical-dia';
            if (fecha.getMonth() !== mini.mes.getMonth()) {
                boton.classList.add('es-otro-mes');
            }
            if (mismoDia(fecha, hoy)) {
                boton.classList.add('es-hoy');
                boton.setAttribute('aria-current', 'date');
            }
            if (mismoDia(fecha, mini.seleccionada)) {
                boton.classList.add('es-seleccionado');
            }
            boton.setAttribute('data-minical-fecha', fechaIso(fecha));
            boton.setAttribute('aria-label', fecha.toLocaleDateString(idioma(), { day: 'numeric', month: 'long', year: 'numeric' }));
            boton.textContent = String(fecha.getDate());
            fragmento.appendChild(boton);
        }
        rejilla.innerHTML = '';
        rejilla.appendChild(fragmento);
    }

    // ── Botón Crear ──

    function initCrear() {
        const boton = document.getElementById('btnCalSidebarCrear');
        if (!boton) {
            return;
        }
        boton.addEventListener('click', function () {
            if (!window.AuralisCalendarPage) {
                return;
            }
            const fecha = mini.seleccionada || fechaPagina();
            const ahora = new Date();
            const hora = mismoDia(fecha, ahora)
                ? String(Math.min(23, ahora.getHours() + 1)).padStart(2, '0') + ':00'
                : '09:00';
            cerrarOffcanvas();
            window.AuralisCalendarPage.openNew(fechaIso(fecha), hora);
        });
    }

    // ── Listas de calendarios ──

    function seccionDe(c) {
        if (c.tipo === 'personal') {
            return c.propio ? 'propios' : 'otros';
        }
        return 'empresa';
    }

    function pintarListas() {
        const grupos = { propios: [], empresa: [], otros: [] };
        calendarios.forEach(function (c) {
            grupos[seccionDe(c)].push(c);
        });
        Object.keys(grupos).forEach(function (clave) {
            const lista = document.querySelector('[data-zcal-lista="' + clave + '"]');
            const seccion = document.querySelector('[data-zcal-seccion="' + clave + '"]');
            if (!lista) {
                return;
            }
            lista.innerHTML = '';
            grupos[clave].forEach(function (c) {
                lista.appendChild(filaCalendario(c));
            });
            if (seccion && clave !== 'propios') {
                seccion.classList.toggle('d-none', grupos[clave].length === 0);
            }
            if (clave === 'propios' && grupos[clave].length === 0) {
                const vacio = document.createElement('li');
                vacio.className = 'auralis-cal-vacio';
                vacio.textContent = i18n.ninguno || '';
                lista.appendChild(vacio);
            }
        });
        pintarAyudaEmpresa();
    }

    /** Los comunes nacen desmarcados: si alguno de la empresa está sin marcar, se explica cómo ver sus eventos. */
    function pintarAyudaEmpresa() {
        const ayuda = document.querySelector('[data-zcal-ayuda="empresa"]');
        if (!ayuda) {
            return;
        }
        ayuda.classList.toggle('d-none', !calendarios.some(function (c) {
            return seccionDe(c) === 'empresa' && !c.visible;
        }));
    }

    function filaCalendario(c) {
        const li = document.createElement('li');
        li.className = 'auralis-cal-fila';
        li.setAttribute('data-idcalendario', String(c.idcalendario));
        li.style.setProperty('--zcal-color', c.color || '#4285f4');

        const etiqueta = document.createElement('label');
        etiqueta.className = 'auralis-cal-fila-etiqueta';
        etiqueta.title = c.nombre || '';

        const check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'form-check-input auralis-cal-check';
        check.checked = !!c.visible;
        check.setAttribute('aria-label', c.nombre || '');
        check.addEventListener('change', function () {
            cambiarVisible(c, check.checked);
        });

        const punto = document.createElement('span');
        punto.className = 'auralis-cal-punto';
        punto.setAttribute('aria-hidden', 'true');

        const nombre = document.createElement('span');
        nombre.className = 'auralis-cal-nombre';
        nombre.textContent = c.nombre || '';

        etiqueta.appendChild(check);
        etiqueta.appendChild(punto);
        etiqueta.appendChild(nombre);
        li.appendChild(etiqueta);

        if (c.tipo === 'modulo' && c.url) {
            const enlace = document.createElement('a');
            enlace.className = 'auralis-cal-fila-enlace';
            enlace.href = c.url;
            enlace.title = i18n.paginaModulo || '';
            enlace.setAttribute('aria-label', (i18n.paginaModulo || '') + ': ' + (c.nombre || ''));
            enlace.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i>';
            li.appendChild(enlace);
        }

        li.appendChild(menuCalendario(c));
        return li;
    }

    function itemMenu(texto, icono, alPulsar, clase) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'dropdown-item' + (clase ? ' ' + clase : '');
        boton.innerHTML = '<i class="' + icono + ' fa-fw me-2"></i>';
        boton.appendChild(document.createTextNode(texto));
        boton.addEventListener('click', alPulsar);
        return boton;
    }

    function enlaceMenu(texto, icono, href) {
        const enlace = document.createElement('a');
        enlace.className = 'dropdown-item';
        enlace.href = href;
        enlace.innerHTML = '<i class="' + icono + ' fa-fw me-2"></i>';
        enlace.appendChild(document.createTextNode(texto));
        return enlace;
    }

    function separador() {
        const hr = document.createElement('hr');
        hr.className = 'dropdown-divider';
        return hr;
    }

    function menuCalendario(c) {
        const caja = document.createElement('div');
        caja.className = 'dropdown auralis-cal-menu-caja';

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-link auralis-cal-menu-btn';
        boton.setAttribute('data-bs-toggle', 'dropdown');
        boton.setAttribute('aria-expanded', 'false');
        boton.setAttribute('aria-label', (i18n.opciones || '') + ': ' + (c.nombre || ''));
        boton.title = i18n.opciones || '';
        boton.innerHTML = '<i class="fa-solid fa-ellipsis-vertical"></i>';

        const menu = document.createElement('div');
        menu.className = 'dropdown-menu dropdown-menu-end auralis-cal-menu';

        menu.appendChild(itemMenu(i18n.soloEste || '', 'fa-solid fa-eye', function () {
            soloEste(c);
        }));
        menu.appendChild(separador());

        const cabColor = document.createElement('h6');
        cabColor.className = 'dropdown-header';
        cabColor.textContent = i18n.color || '';
        menu.appendChild(cabColor);
        menu.appendChild(paletaMenu(c));
        if (c.color && c.color_calendario && c.color.toLowerCase() !== c.color_calendario.toLowerCase()) {
            menu.appendChild(itemMenu(i18n.colorRestablecer || '', 'fa-solid fa-rotate-left', function () {
                cambiarColor(c, '');
            }));
        }

        if (c.tipo === 'personal' && c.propio && cfg.puedeEditar) {
            menu.appendChild(separador());
            menu.appendChild(itemMenu(i18n.ajustes || '', 'fa-solid fa-gear', function () {
                abrirModalCalendario(c);
            }));
            if (datos.permitir_compartir) {
                menu.appendChild(itemMenu(i18n.compartir || '', 'fa-solid fa-share-nodes', function () {
                    abrirCompartir(c);
                }));
            }
            if (!c.principal) {
                menu.appendChild(itemMenu(i18n.borrar || '', 'fa-solid fa-trash', function () {
                    borrarCalendario(c);
                }, 'text-danger'));
            }
        }

        if (c.tipo !== 'personal' && (c.puede_gestionar || (c.tipo === 'modulo' && c.url))) {
            menu.appendChild(separador());
            if (c.tipo === 'modulo' && c.url) {
                menu.appendChild(enlaceMenu(i18n.paginaModulo || '', 'fa-solid fa-arrow-up-right-from-square', c.url));
            }
            if (c.puede_gestionar) {
                menu.appendChild(enlaceMenu(i18n.gestionar || '', 'fa-solid fa-sliders',
                    (cfg.urlGestion || 'EditAuralisCalendario') + '?code=' + encodeURIComponent(String(c.idcalendario))));
            }
        }

        caja.appendChild(boton);
        caja.appendChild(menu);
        // Posición fija: el lateral tiene su propio scroll y recortaría un menú absoluto.
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            bootstrap.Dropdown.getOrCreateInstance(boton, {
                popperConfig: function (base) {
                    return Object.assign({}, base, { strategy: 'fixed' });
                }
            });
        }
        return caja;
    }

    function paletaMenu(c) {
        const fila = document.createElement('div');
        fila.className = 'auralis-cal-menu-paleta';
        const actual = (c.color || '').toLowerCase();
        const enPaleta = paleta.some(function (color) {
            return color.toLowerCase() === actual;
        });
        paleta.forEach(function (color) {
            const punto = document.createElement('button');
            punto.type = 'button';
            punto.className = 'z-color-dot' + ((c.color || '').toLowerCase() === color.toLowerCase() ? ' active' : '');
            punto.style.backgroundColor = color;
            punto.style.setProperty('--dot-color', color);
            punto.title = color;
            punto.setAttribute('aria-label', color);
            punto.addEventListener('click', function () {
                cambiarColor(c, color);
            });
            fila.appendChild(punto);
        });

        const libre = document.createElement('label');
        libre.className = 'z-color-dot z-color-dot-custom auralis-cal-color-libre';
        libre.title = i18n.colorLibre || '';
        libre.innerHTML = '<i class="fa-solid fa-palette"></i>';
        if (actual && !enPaleta) {
            libre.classList.add('active');
            libre.style.backgroundColor = c.color;
            libre.style.color = colorTexto(c.color);
            libre.style.setProperty('--dot-color', c.color);
        }
        const input = document.createElement('input');
        input.type = 'color';
        input.className = 'auralis-cal-color-input';
        input.value = c.color || '#4285f4';
        input.setAttribute('aria-label', i18n.colorLibre || '');
        input.addEventListener('change', function () {
            cambiarColor(c, input.value);
        });
        libre.appendChild(input);
        fila.appendChild(libre);
        return fila;
    }

    // ── Preferencias (visible / color) ──

    function guardarPreferencia(c, campos) {
        return post('calendario-preferencia', Object.assign({ idcalendario: c.idcalendario }, campos));
    }

    function cambiarVisible(c, visible) {
        c.visible = visible;
        pintarAyudaEmpresa();
        recargarEventos();
        guardarPreferencia(c, { visible: visible ? 1 : 0 }).then(function (data) {
            if (!exito(data)) {
                c.visible = !visible;
                pintarListas();
                recargarEventos();
            }
        }).catch(function (err) {
            console.error('[AuralisCalendarSidebar] preferencia', err);
        });
    }

    function soloEste(c) {
        const cambios = [];
        calendarios.forEach(function (otro) {
            const debe = parseInt(otro.idcalendario, 10) === parseInt(c.idcalendario, 10);
            if (!!otro.visible !== debe) {
                otro.visible = debe;
                cambios.push(otro);
            }
        });
        pintarListas();
        recargarEventos();
        cambios.forEach(function (otro) {
            guardarPreferencia(otro, { visible: otro.visible ? 1 : 0 }).then(function (data) {
                if (data && !data.success) {
                    console.warn('[AuralisCalendarSidebar] preferencia', data.message);
                }
            });
        });
    }

    function cambiarColor(c, color) {
        guardarPreferencia(c, { color: color }).then(function (data) {
            if (!exito(data)) {
                return;
            }
            reemplazar(data.calendario);
            pintarListas();
            pintarSelectorEvento();
            recargarEventos();
        }).catch(function (err) {
            console.error('[AuralisCalendarSidebar] color', err);
        });
    }

    // ── Crear / ajustes / borrar calendario personal ──

    function initModalCalendario() {
        const nuevo = document.getElementById('btnCalNuevoCalendario');
        if (nuevo) {
            nuevo.addEventListener('click', function () {
                cerrarOffcanvas();
                abrirModalCalendario(null);
            });
        }

        const contenedor = document.querySelector('[data-zcal-paleta="modal"]');
        if (contenedor) {
            contenedor.addEventListener('click', function (e) {
                const punto = e.target.closest('button.z-color-dot');
                if (punto) {
                    marcarColorModal(punto.getAttribute('data-color'));
                }
            });
            const libre = document.getElementById('zCalColorLibre');
            if (libre) {
                libre.addEventListener('input', function () {
                    marcarColorModal(libre.value);
                });
            }
        }

        const descripcion = document.getElementById('zCalDescripcion');
        if (descripcion) {
            descripcion.addEventListener('input', function () {
                descripcion.dataset.cambiada = '1';
            });
        }

        const form = document.getElementById('formAuralisCalendario');
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const id = document.getElementById('zCalId').value;
            const campos = {
                idcalendario: id,
                nombre: document.getElementById('zCalNombre').value.trim(),
                color: document.getElementById('zCalColor').value
            };
            // Al editar la descripción solo viaja si se ha tocado (la lista no la trae).
            if (!id || (descripcion && descripcion.dataset.cambiada === '1')) {
                campos.descripcion = descripcion ? descripcion.value : '';
            }
            const boton = document.getElementById('btnGuardarAuralisCalendario');
            if (boton) {
                boton.disabled = true;
            }
            post('calendario-guardar', campos).then(function (data) {
                if (!exito(data, i18n.errorGuardar)) {
                    return;
                }
                cerrarModal('auralisCalendarioModal');
                recargarLista().then(recargarEventos);
            }).catch(function (err) {
                console.error('[AuralisCalendarSidebar] guardar', err);
                window.alert(i18n.errorGuardar || 'Error');
            }).finally(function () {
                if (boton) {
                    boton.disabled = false;
                }
            });
        });
    }

    function marcarColorModal(color) {
        const input = document.getElementById('zCalColor');
        if (!input || !color) {
            return;
        }
        input.value = color;
        let enPaleta = false;
        document.querySelectorAll('[data-zcal-paleta="modal"] button.z-color-dot').forEach(function (punto) {
            const igual = (punto.getAttribute('data-color') || '').toLowerCase() === color.toLowerCase();
            punto.classList.toggle('active', igual);
            enPaleta = enPaleta || igual;
        });
        const libre = document.querySelector('[data-zcal-paleta="modal"] .auralis-cal-color-libre');
        if (libre) {
            libre.classList.toggle('active', !enPaleta);
            libre.style.setProperty('--dot-color', color);
            // Fuera de la paleta muestra el color elegido; dentro, vuelve al punto neutro del CSS.
            libre.style.backgroundColor = enPaleta ? '' : color;
            libre.style.color = enPaleta ? '' : colorTexto(color);
        }
        const picker = document.getElementById('zCalColorLibre');
        if (picker && picker.value.toLowerCase() !== color.toLowerCase()) {
            picker.value = color;
        }
    }

    function abrirModalCalendario(c) {
        const modal = document.getElementById('auralisCalendarioModal');
        if (!modal) {
            return;
        }
        const titulo = document.getElementById('auralisCalendarioModalLabel');
        if (titulo) {
            titulo.textContent = titulo.getAttribute(c ? 'data-titulo-editar' : 'data-titulo-nuevo') || '';
        }
        document.getElementById('zCalId').value = c ? String(c.idcalendario) : '';
        document.getElementById('zCalNombre').value = c ? (c.nombre || '') : '';
        const descripcion = document.getElementById('zCalDescripcion');
        if (descripcion) {
            descripcion.value = '';
            descripcion.dataset.cambiada = '0';
        }
        marcarColorModal(c ? (c.color_calendario || c.color || paleta[0]) : (paleta[0] || '#4285f4'));
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function cerrarModal(id) {
        const modal = document.getElementById(id);
        if (modal && bootstrap.Modal.getInstance(modal)) {
            bootstrap.Modal.getInstance(modal).hide();
        }
    }

    function borrarCalendario(c) {
        if (!window.confirm((c.nombre || '') + '\n\n' + (i18n.confirmarBorrado || ''))) {
            return;
        }
        post('calendario-borrar', { idcalendario: c.idcalendario, confirmar: 1 }).then(function (data) {
            if (!exito(data, i18n.errorBorrar)) {
                return;
            }
            recargarLista().then(recargarEventos);
        }).catch(function (err) {
            console.error('[AuralisCalendarSidebar] borrar', err);
            window.alert(i18n.errorBorrar || 'Error');
        });
    }

    // ── Compartir ──

    function initCompartir() {
        const form = document.getElementById('formAuralisCalendarioAcceso');
        if (!form) {
            return;
        }
        const usuario = document.getElementById('zCalCompUsuario');
        const rol = document.getElementById('zCalCompRol');

        if (typeof window.TomSelect === 'function') {
            const base = { maxItems: 1, allowEmptyOption: true, create: false, plugins: ['clear_button'] };
            compartir.tsUsuario = new window.TomSelect(usuario, Object.assign({}, base, {
                valueField: 'nick', labelField: 'etiqueta', searchField: ['nick', 'nombre'],
                onChange: function (valor) {
                    if (valor && compartir.tsRol) {
                        compartir.tsRol.clear(true);
                    }
                }
            }));
            compartir.tsRol = new window.TomSelect(rol, Object.assign({}, base, {
                valueField: 'codrole', labelField: 'etiqueta', searchField: ['codrole', 'descripcion'],
                onChange: function (valor) {
                    if (valor && compartir.tsUsuario) {
                        compartir.tsUsuario.clear(true);
                    }
                }
            }));
        } else {
            usuario.addEventListener('change', function () {
                if (usuario.value) {
                    rol.value = '';
                }
            });
            rol.addEventListener('change', function () {
                if (rol.value) {
                    usuario.value = '';
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            guardarAcceso({
                nick: valorSelect(compartir.tsUsuario, usuario),
                codrole: valorSelect(compartir.tsRol, rol),
                permiso: document.getElementById('zCalCompPermiso').value
            }, true);
        });

        document.getElementById('zCalCompLista').addEventListener('change', function (e) {
            const select = e.target.closest('select[data-acceso-permiso]');
            if (!select) {
                return;
            }
            const fila = select.closest('[data-acceso-id]');
            guardarAcceso({
                nick: fila.getAttribute('data-nick') || '',
                codrole: fila.getAttribute('data-codrole') || '',
                permiso: select.value
            }, false);
        });

        document.getElementById('zCalCompLista').addEventListener('click', function (e) {
            const boton = e.target.closest('[data-acceso-quitar]');
            if (!boton) {
                return;
            }
            const fila = boton.closest('[data-acceso-id]');
            post('calendario-acceso-borrar', { id: fila.getAttribute('data-acceso-id') }).then(function (data) {
                if (exito(data)) {
                    pintarAccesos(data.accesos || []);
                }
            });
        });
    }

    function valorSelect(ts, select) {
        const valor = ts ? ts.getValue() : select.value;
        return Array.isArray(valor) ? (valor[0] || '') : (valor || '');
    }

    function rellenarSelect(ts, select, opciones, campo) {
        if (ts) {
            ts.clear(true);
            ts.clearOptions();
            ts.addOptions(opciones);
            ts.refreshOptions(false);
            ts.close();
            return;
        }
        select.innerHTML = '<option value=""></option>';
        opciones.forEach(function (o) {
            const opt = document.createElement('option');
            opt.value = o[campo];
            opt.textContent = o.etiqueta;
            select.appendChild(opt);
        });
    }

    function abrirCompartir(c) {
        const modal = document.getElementById('auralisCalendarioCompartirModal');
        if (!modal) {
            return;
        }
        compartir.idcalendario = parseInt(c.idcalendario, 10);
        document.getElementById('zCalCompNombre').textContent = c.nombre || '';
        document.getElementById('zCalCompLista').innerHTML = '';
        get('calendario-accesos', { idcalendario: c.idcalendario }).then(function (data) {
            if (!data || !data.ok) {
                window.alert((data && data.message) || i18n.errorGuardar || 'Error');
                return;
            }
            compartir.usuarios = (data.usuarios || []).map(function (u) {
                return { nick: u.nick, nombre: u.nombre, etiqueta: u.nombre && u.nombre !== u.nick ? u.nombre + ' (' + u.nick + ')' : u.nick };
            });
            compartir.roles = (data.roles || []).map(function (r) {
                return { codrole: r.codrole, descripcion: r.descripcion, etiqueta: r.descripcion || r.codrole };
            });
            rellenarSelect(compartir.tsUsuario, document.getElementById('zCalCompUsuario'), compartir.usuarios, 'nick');
            rellenarSelect(compartir.tsRol, document.getElementById('zCalCompRol'), compartir.roles, 'codrole');
            pintarAccesos(data.accesos || []);
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }).catch(function (err) {
            console.error('[AuralisCalendarSidebar] accesos', err);
        });
    }

    function guardarAcceso(campos, limpiar) {
        post('calendario-acceso-guardar', Object.assign({ idcalendario: compartir.idcalendario }, campos)).then(function (data) {
            if (!exito(data)) {
                return;
            }
            pintarAccesos(data.accesos || []);
            if (limpiar) {
                if (compartir.tsUsuario) {
                    compartir.tsUsuario.clear(true);
                    compartir.tsRol.clear(true);
                } else {
                    document.getElementById('zCalCompUsuario').value = '';
                    document.getElementById('zCalCompRol').value = '';
                }
            }
        }).catch(function (err) {
            console.error('[AuralisCalendarSidebar] acceso', err);
        });
    }

    function nombreAcceso(a) {
        if (a.alcance === 'usuario') {
            const u = compartir.usuarios.find(function (x) {
                return x.nick === a.nick;
            });
            return { icono: 'fa-solid fa-user', texto: u ? u.etiqueta : a.nick };
        }
        if (a.alcance === 'rol') {
            const r = compartir.roles.find(function (x) {
                return x.codrole === a.codrole;
            });
            return { icono: 'fa-solid fa-users', texto: (i18n.grupo || '') + ': ' + (r ? r.etiqueta : a.codrole) };
        }
        return { icono: 'fa-solid fa-building', texto: i18n.todaEmpresa || '' };
    }

    function textoPermiso(p) {
        return p === 'ocupado' ? i18n.permisoOcupado : (p === 'editar' ? i18n.permisoEditar : i18n.permisoVer);
    }

    function pintarAccesos(accesos) {
        const lista = document.getElementById('zCalCompLista');
        lista.innerHTML = '';
        if (!accesos.length) {
            const vacio = document.createElement('li');
            vacio.className = 'list-group-item text-muted';
            vacio.textContent = i18n.sinAccesos || '';
            lista.appendChild(vacio);
            return;
        }
        const permisos = Array.isArray(datos.permisos) && datos.permisos.length ? datos.permisos : ['ocupado', 'ver', 'editar'];
        accesos.forEach(function (a) {
            const li = document.createElement('li');
            li.className = 'list-group-item auralis-cal-acceso';
            li.setAttribute('data-acceso-id', String(a.id));
            li.setAttribute('data-nick', a.nick || '');
            li.setAttribute('data-codrole', a.codrole || '');

            const destino = nombreAcceso(a);
            const nombre = document.createElement('span');
            nombre.className = 'auralis-cal-acceso-nombre';
            nombre.innerHTML = '<i class="' + destino.icono + ' fa-fw me-1 text-muted"></i>';
            nombre.appendChild(document.createTextNode(destino.texto));

            const select = document.createElement('select');
            select.className = 'form-select auralis-cal-acceso-permiso';
            select.setAttribute('data-acceso-permiso', '1');
            select.setAttribute('aria-label', textoPermiso(a.permiso) || '');
            permisos.forEach(function (p) {
                const opt = document.createElement('option');
                opt.value = p;
                opt.textContent = textoPermiso(p) || p;
                opt.selected = p === a.permiso;
                select.appendChild(opt);
            });

            const quitar = document.createElement('button');
            quitar.type = 'button';
            quitar.className = 'btn btn-outline-danger';
            quitar.setAttribute('data-acceso-quitar', '1');
            quitar.title = i18n.quitar || '';
            quitar.setAttribute('aria-label', (i18n.quitar || '') + ': ' + destino.texto);
            quitar.innerHTML = '<i class="fa-solid fa-xmark"></i>';

            li.appendChild(nombre);
            li.appendChild(select);
            li.appendChild(quitar);
            lista.appendChild(li);
        });
    }

    // ── Eventos de la rejilla: ocupado, módulo y solo lectura ──

    function leerTarjeta(el) {
        try {
            return JSON.parse(el.getAttribute('data-event-json') || '{}');
        } catch (e) {
            return null;
        }
    }

    /** Origen de la página (auralis-calendar-data): en una agenda de módulo sus propias citas no son «de módulo». */
    function origenPagina() {
        const el = document.getElementById('auralis-calendar-data');
        try {
            return (JSON.parse(el ? el.textContent : '{}') || {}).origenTipo || 'general';
        } catch (e) {
            return 'general';
        }
    }

    function esModulo(card) {
        return !!(card && card.origen_tipo && card.origen_tipo !== origenPagina());
    }

    /** Devuelve true si el evento no abre el modal (lo gestiona el lateral). */
    function interceptarEvento(card) {
        if (!card) {
            return false;
        }
        if (card.ocupado) {
            return true;
        }
        if (esModulo(card)) {
            let destino = card.url && card.url !== '#' ? card.url : '';
            if (!destino) {
                const cal = card.calendar_clave ? buscarPorClave(card.calendar_clave) : null;
                destino = cal && cal.url ? cal.url + (card.id ? '?idevent=' + encodeURIComponent(String(card.id)) : '') : '';
            }
            if (destino) {
                window.location.href = destino;
            }
            return true;
        }
        return false;
    }

    function initEventos() {
        document.addEventListener('auralis-calendar-loaded', function (e) {
            const container = e.detail && e.detail.container ? e.detail.container : document.querySelector('.z-calendar-container');
            if (!container) {
                return;
            }
            container.querySelectorAll('[data-event-json]').forEach(function (el) {
                const card = leerTarjeta(el);
                if (!card) {
                    return;
                }
                el.classList.toggle('z-evento-ocupado', !!card.ocupado);
                el.classList.toggle('z-evento-modulo', esModulo(card));
                if (card.can_edit === false) {
                    el.classList.add('z-evento-solo-lectura');
                    el.setAttribute('draggable', 'false');
                }
            });
        });

        // Sin permiso de edición no se arrastra (el servidor también lo rechaza).
        document.addEventListener('dragstart', function (e) {
            const el = e.target && e.target.closest ? e.target.closest('.z-timegrid-event') : null;
            if (!el) {
                return;
            }
            const card = leerTarjeta(el);
            if (card && card.can_edit === false) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    // ── Modal de evento: selector «Calendario» y solo lectura por evento ──

    function pintarSelectorEvento() {
        const select = document.getElementById('zEvIdcalendario');
        if (!select) {
            return;
        }
        const actual = select.value;
        const grupos = [
            ['propios', function (c) { return c.tipo === 'personal' && c.propio; }],
            ['empresa', function (c) { return c.tipo === 'comun'; }],
            ['otros', function (c) { return c.tipo === 'personal' && !c.propio; }]
        ];
        const titulos = {
            propios: document.querySelector('[data-zcal-seccion="propios"] .auralis-cal-seccion-titulo'),
            empresa: document.querySelector('[data-zcal-seccion="empresa"] .auralis-cal-seccion-titulo'),
            otros: document.querySelector('[data-zcal-seccion="otros"] .auralis-cal-seccion-titulo')
        };
        select.innerHTML = '';
        grupos.forEach(function (par) {
            const lista = calendarios.filter(function (c) {
                return c.permiso === 'editar' && c.tipo !== 'modulo' && par[1](c);
            });
            if (!lista.length) {
                return;
            }
            const grupo = document.createElement('optgroup');
            grupo.label = titulos[par[0]] ? titulos[par[0]].textContent.trim() : par[0];
            lista.forEach(function (c) {
                const opt = document.createElement('option');
                opt.value = String(c.idcalendario);
                opt.textContent = c.nombre || '';
                opt.setAttribute('data-color', c.color || '');
                grupo.appendChild(opt);
            });
            select.appendChild(grupo);
        });
        select.setAttribute('data-defecto', datos.defecto ? String(datos.defecto) : '');
        select.value = actual;
        if (select.value !== actual) {
            select.value = select.getAttribute('data-defecto') || '';
        }
        const modal = document.getElementById('auralisNewEventModal');
        ajustarCalendarioEvento(!!modal && (modal.hasAttribute('data-z-evento-solo-lectura') || modal.hasAttribute('data-z-readonly')));
    }

    function quitarOpcionTemporal(select) {
        select.querySelectorAll('option[data-temporal]').forEach(function (opt) {
            opt.remove();
        });
    }

    function colorDeSeleccion(select) {
        const opt = select.options[select.selectedIndex];
        if (opt && opt.getAttribute('data-color') && window.AuralisCalendarModal && window.AuralisCalendarModal.setColor) {
            window.AuralisCalendarModal.setColor(opt.getAttribute('data-color'));
        }
    }

    /**
     * «Calendario» del modal: selector solo si hay donde elegir (más de una opción contando el calendario actual
     * del evento aunque el usuario no pueda escribir en él); si no, o en solo lectura, el nombre fijo con su punto.
     * Oculto, el select va deshabilitado y no viaja en el POST: el servidor conserva el actual o pone el de por defecto.
     */
    function ajustarCalendarioEvento(soloLectura) {
        const select = document.getElementById('zEvIdcalendario');
        const bloque = document.getElementById('zEvCalendarioBloque');
        if (!select || !bloque) {
            return;
        }
        const selector = document.getElementById('zEvCalendarioSelector');
        const fijo = document.getElementById('zEvCalendarioFijo');
        const fijoNombre = document.getElementById('zEvCalendarioFijoNombre');

        const opt = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        const cal = opt && opt.value ? buscar(opt.value) : null;
        const nombre = cal ? (cal.nombre || '') : (opt && opt.value ? opt.textContent : '');
        const color = cal ? (cal.color || '') : (opt ? opt.getAttribute('data-color') || '' : '');
        const conSelector = !soloLectura && select.options.length > 1;

        if (selector) {
            selector.classList.toggle('d-none', !conSelector);
        }
        if (fijo) {
            fijo.classList.toggle('d-none', conSelector);
        }
        select.disabled = !conSelector;
        if (fijoNombre) {
            fijoNombre.textContent = nombre;
        }
        bloque.querySelectorAll('.auralis-ev-cal-punto').forEach(function (punto) {
            if (/^#[0-9a-f]{6}$/i.test(color)) {
                punto.style.setProperty('--zcal-color', color);
            } else {
                punto.style.removeProperty('--zcal-color');
            }
        });
        // Sin calendario conocido (evento antiguo o ajeno) y sin selector: no hay nada útil que enseñar.
        bloque.hidden = !conSelector && nombre === '';
    }

    function soloLecturaEvento(activo) {
        const modal = document.getElementById('auralisNewEventModal');
        if (!modal) {
            return;
        }
        const fieldset = modal.querySelector('.z-event-fieldset');
        if (activo) {
            modal.setAttribute('data-z-evento-solo-lectura', '1');
            if (fieldset) {
                fieldset.disabled = true;
            }
            return;
        }
        modal.removeAttribute('data-z-evento-solo-lectura');
        // Si la página entera es de solo lectura (roles del Core), el fieldset sigue bloqueado.
        if (fieldset && !modal.hasAttribute('data-z-readonly')) {
            fieldset.disabled = false;
        }
    }

    function initModalEvento() {
        const select = document.getElementById('zEvIdcalendario');
        const modal = document.getElementById('auralisNewEventModal');
        const paginaSoloLectura = !!modal && modal.hasAttribute('data-z-readonly');

        document.addEventListener('auralis-calendar-new-event', function () {
            soloLecturaEvento(false);
            if (!select) {
                return;
            }
            quitarOpcionTemporal(select);
            select.value = select.getAttribute('data-defecto') || '';
            if (!select.value && select.options.length) {
                select.selectedIndex = 0;
            }
            colorDeSeleccion(select);
            ajustarCalendarioEvento(paginaSoloLectura);
        });

        document.addEventListener('auralis-calendar-edit-event', function (e) {
            const card = e.detail && e.detail.card ? e.detail.card : null;
            const soloLectura = !!card && card.can_edit === false;
            soloLecturaEvento(soloLectura);
            if (!select || !card) {
                return;
            }
            quitarOpcionTemporal(select);
            const id = card.idcalendario ? String(card.idcalendario) : '';
            select.value = id;
            if (select.value !== id) {
                // Calendario sin permiso de edición (o evento antiguo): se muestra tal cual; si no se cambia,
                // el servidor lo conserva. Cuenta como opción: con él hay dónde elegir aunque solo se edite uno.
                const cal = id ? buscar(id) : null;
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = cal ? cal.nombre : '—';
                opt.setAttribute('data-color', cal ? (cal.color || '') : '');
                opt.setAttribute('data-temporal', '1');
                select.insertBefore(opt, select.firstChild);
                select.value = id;
            }
            ajustarCalendarioEvento(soloLectura || paginaSoloLectura);
        });

        // Guardar en un calendario desmarcado (p. ej. un común, que nace así) lo marca: si no, el evento
        // no se vería al recargar. Llega tras el guardado, con el token ya renovado y antes de la recarga.
        document.addEventListener('auralis-calendar-event-saved', function (e) {
            const id = e.detail && e.detail.idcalendario ? String(e.detail.idcalendario) : '';
            const c = id ? buscar(id) : null;
            if (!c || c.visible) {
                return;
            }
            c.visible = true;
            pintarListas();
            guardarPreferencia(c, { visible: 1 }).then(function (data) {
                if (data && !data.success) {
                    console.warn('[AuralisCalendarSidebar] preferencia', data.message);
                }
            }).catch(function (err) {
                console.error('[AuralisCalendarSidebar] preferencia', err);
            });
        });

        if (select) {
            select.addEventListener('change', function () {
                const idEvento = document.getElementById('zEvId');
                if (idEvento && !idEvento.value) {
                    colorDeSeleccion(select);
                }
                ajustarCalendarioEvento(false);
            });
        }
    }
})();
