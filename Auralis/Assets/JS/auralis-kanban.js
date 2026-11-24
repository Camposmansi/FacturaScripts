/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Tablero Kanban reutilizable: render de columnas y tarjetas, paginación, orden elegible («Ordenar por»), movimiento optimista con confirmación y deshacer, menú «Mover a…» y teclado accesible.
 */
(function () {
    'use strict';

    const CLAVE_PISTA = 'auralis-kanban-pista-vista';
    const CONSULTA_MOVIL = '(max-width: 575.98px)';
    const TAMANO_PAGINA = 25;
    const MS_DESHACER = 6000;

    // ---------------------------------------------------------------- utilidades

    function crear(etiqueta, clase, texto) {
        const nodo = document.createElement(etiqueta);
        if (clase) {
            nodo.className = clase;
        }
        if (texto !== undefined && texto !== null) {
            nodo.textContent = String(texto);
        }
        return nodo;
    }

    function icono(clase) {
        const i = crear('i', clase);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function leerJson(texto, porDefecto) {
        try {
            const valor = JSON.parse(texto);
            return valor === null || typeof valor !== 'object' ? porDefecto : valor;
        } catch (error) {
            return porDefecto;
        }
    }

    // Solo rutas del propio sitio: rechaza javascript:, data: y absolutas a otro origen.
    function urlSegura(url) {
        if (typeof url !== 'string' || url.trim() === '') {
            return null;
        }
        try {
            const destino = new URL(url.trim(), window.location.href);
            if ((destino.protocol !== 'http:' && destino.protocol !== 'https:') || destino.origin !== window.location.origin) {
                return null;
            }
            return destino.href;
        } catch (error) {
            return null;
        }
    }

    // Etiquetas a la vista en cada tarjeta; las demás quedan en el tooltip de «+N».
    const MAX_ETIQUETAS_VISIBLES = 2;

    // Colores semánticos de Bootstrap que usa el Core en los estados (EditEstadoDocumento).
    const COLORES_BOOTSTRAP = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

    // Solo colores CSS simples (hex, rgb/hsl) o nombres de Bootstrap; cualquier otra cosa se descarta.
    function colorSeguro(color) {
        if (typeof color !== 'string') {
            return '';
        }
        const valor = color.trim();
        if (COLORES_BOOTSTRAP.indexOf(valor.toLowerCase()) !== -1) {
            return 'var(--bs-' + valor.toLowerCase() + ')';
        }
        if (/^#[0-9a-f]{3,8}$/i.test(valor) || /^(rgb|hsl)a?\([0-9.,%\s\/-]+\)$/i.test(valor)) {
            return valor;
        }
        return '';
    }

    // Color por defecto de la columna según su tipo cuando el estado no trae color propio (nunca gris).
    const COLOR_POR_TIPO = {
        abierto: 'var(--bs-blue)',
        pedido: 'var(--bs-teal)',
        rechazado: 'var(--bs-danger)',
        caducado: 'var(--bs-warning)',
        facturar: 'var(--bs-success)',
        otro: 'var(--v-primary, #0d6efd)'
    };

    function colorColumna(col) {
        return colorSeguro(col.color) || COLOR_POR_TIPO[col.tipo] || COLOR_POR_TIPO.otro;
    }

    // Solo la primera letra en mayúscula; el resto del nombre queda intacto.
    function capitalizar(texto) {
        return String(texto).replace(/^\p{L}/u, function (letra) { return letra.toLocaleUpperCase(); });
    }

    // Columna con todos sus documentos bloqueados: el candado va una vez en la cabecera.
    function columnaBloqueada(col) {
        return col.lockedCount > 0 && col.lockedCount === col.count;
    }

    function esMovil() {
        return !!(window.matchMedia && window.matchMedia(CONSULTA_MOVIL).matches);
    }

    function sinAnimaciones() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    // Desempate del servidor en todos los criterios: id desc.
    function compararId(a, b) {
        const ia = Number(a.id);
        const ib = Number(b.id);
        if (!isNaN(ia) && !isNaN(ib)) {
            return ib - ia;
        }
        return String(b.id).localeCompare(String(a.id));
    }

    // Valor de un criterio en la tarjeta (sortValues): número, fecha ISO en texto o null.
    function valorOrden(tarjeta, clave) {
        const valores = tarjeta.d && tarjeta.d.sortValues && typeof tarjeta.d.sortValues === 'object' ? tarjeta.d.sortValues : null;
        const valor = valores ? valores[clave] : null;
        return valor === undefined || valor === '' ? null : valor;
    }

    // Comparación ascendente de dos valores no nulos: números como números; fechas ISO y texto, como texto.
    function compararValores(va, vb) {
        if (typeof va === 'number' && typeof vb === 'number') {
            return va < vb ? -1 : (va > vb ? 1 : 0);
        }
        const sa = String(va);
        const sb = String(vb);
        return sa < sb ? -1 : (sa > sb ? 1 : 0);
    }

    // Mismo orden que el servidor. Sin criterio (tablero sin selector): fecha desc, id desc.
    // Con criterio: su valor en la dirección pedida, los que no tienen valor al final en las dos
    // direcciones y desempate por id desc.
    function comparar(a, b, orden) {
        if (!orden) {
            const fa = String(a.d.fecha || a.d.date || '');
            const fb = String(b.d.fecha || b.d.date || '');
            if (fa !== fb) {
                return fa < fb ? 1 : -1;
            }
            return compararId(a, b);
        }
        const va = valorOrden(a, orden.clave);
        const vb = valorOrden(b, orden.clave);
        if (va === null || vb === null) {
            if (va !== null || vb !== null) {
                return va === null ? 1 : -1;
            }
            return compararId(a, b);
        }
        const resultado = compararValores(va, vb);
        if (resultado !== 0) {
            return orden.dir === 'asc' ? resultado : -resultado;
        }
        return compararId(a, b);
    }

    // ---------------------------------------------------------------- toasts y modal (globales a la página)

    let contenedorToasts = null;
    let modalConfirmar = null;

    function obtenerContenedorToasts() {
        if (!contenedorToasts) {
            contenedorToasts = crear('div', 'toast-container position-fixed bottom-0 end-0 p-3 auralis-kanban-toasts');
            document.body.appendChild(contenedorToasts);
        }
        // En móvil, por encima de la barra de navegación inferior (su alto es 0 si no se muestra).
        const barraInferior = document.querySelector('.mobile-bottom-nav');
        contenedorToasts.style.marginBottom = (barraInferior ? barraInferior.offsetHeight : 0) + 'px';
        return contenedorToasts;
    }

    function mostrarToast(opciones) {
        const toast = crear('div', 'toast align-items-center' + (opciones.error ? ' text-bg-danger border-0' : ''));
        toast.setAttribute('role', opciones.error ? 'alert' : 'status');
        toast.setAttribute('aria-live', opciones.error ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        const fila = crear('div', 'd-flex align-items-center');
        const cuerpo = crear('div', 'toast-body flex-grow-1', opciones.mensaje);
        // enlace interno opcional (p. ej. «Abrir ficha»); urlSegura descarta todo lo que no sea del mismo origen
        const enlaceSeguro = opciones.url ? urlSegura(opciones.url) : null;
        if (enlaceSeguro) {
            cuerpo.appendChild(document.createTextNode(' '));
            const enlace = crear('a', 'alert-link text-white fw-semibold', opciones.urlTexto || '');
            enlace.href = enlaceSeguro;
            cuerpo.appendChild(enlace);
        }
        fila.appendChild(cuerpo);
        let cerrar = null;
        if (typeof opciones.accion === 'function') {
            const boton = crear('button', 'btn btn-link', opciones.accionTexto);
            boton.type = 'button';
            boton.addEventListener('click', function () {
                cerrar();
                opciones.accion();
            });
            fila.appendChild(boton);
        }
        const x = crear('button', 'btn-close me-2' + (opciones.error ? ' btn-close-white' : ''));
        x.type = 'button';
        x.setAttribute('aria-label', opciones.cerrarTexto || 'x');
        fila.appendChild(x);
        toast.appendChild(fila);
        obtenerContenedorToasts().appendChild(toast);

        const retardo = opciones.duracion || (opciones.error ? 8000 : 4000);
        if (window.bootstrap && window.bootstrap.Toast) {
            const instancia = window.bootstrap.Toast.getOrCreateInstance(toast, { delay: retardo, autohide: true });
            cerrar = function () { instancia.hide(); };
            x.addEventListener('click', cerrar);
            toast.addEventListener('hidden.bs.toast', function () {
                instancia.dispose();
                toast.remove();
            });
            instancia.show();
        } else {
            cerrar = function () { toast.remove(); };
            x.addEventListener('click', cerrar);
            toast.classList.add('show');
            window.setTimeout(cerrar, retardo);
        }
    }

    function confirmarModal(textos, resumen) {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return Promise.resolve(window.confirm(resumen));
        }
        if (!modalConfirmar) {
            const modal = crear('div', 'modal fade auralis-kanban-modal');
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('aria-labelledby', 'auralis-kanban-modal-titulo');
            const dialogo = crear('div', 'modal-dialog modal-dialog-centered');
            const contenido = crear('div', 'modal-content');
            const cabecera = crear('div', 'modal-header');
            const titulo = crear('h2', 'modal-title fs-5');
            titulo.id = 'auralis-kanban-modal-titulo';
            const x = crear('button', 'btn-close');
            x.type = 'button';
            x.setAttribute('data-bs-dismiss', 'modal');
            cabecera.appendChild(titulo);
            cabecera.appendChild(x);
            const cuerpo = crear('div', 'modal-body');
            const parrafo = crear('p', 'mb-0');
            cuerpo.appendChild(parrafo);
            const pie = crear('div', 'modal-footer');
            const cancelar = crear('button', 'btn btn-secondary');
            cancelar.type = 'button';
            cancelar.setAttribute('data-bs-dismiss', 'modal');
            const aceptar = crear('button', 'btn btn-primary');
            aceptar.type = 'button';
            pie.appendChild(cancelar);
            pie.appendChild(aceptar);
            contenido.appendChild(cabecera);
            contenido.appendChild(cuerpo);
            contenido.appendChild(pie);
            dialogo.appendChild(contenido);
            modal.appendChild(dialogo);
            document.body.appendChild(modal);
            modalConfirmar = {
                el: modal, titulo: titulo, parrafo: parrafo, cancelar: cancelar, aceptar: aceptar, x: x,
                instancia: window.bootstrap.Modal.getOrCreateInstance(modal),
                resolver: null, confirmado: false
            };
            aceptar.addEventListener('click', function () {
                modalConfirmar.confirmado = true;
                modalConfirmar.instancia.hide();
            });
            modal.addEventListener('hidden.bs.modal', function () {
                const resolver = modalConfirmar.resolver;
                modalConfirmar.resolver = null;
                if (resolver) {
                    resolver(modalConfirmar.confirmado);
                }
            });
        }
        const m = modalConfirmar;
        m.titulo.textContent = textos.titulo;
        m.parrafo.textContent = resumen;
        m.cancelar.textContent = textos.cancelar;
        m.aceptar.textContent = textos.confirmar;
        m.x.setAttribute('aria-label', textos.cancelar);
        m.confirmado = false;
        return new Promise(function (resolver) {
            m.resolver = resolver;
            m.instancia.show();
        });
    }

    // ---------------------------------------------------------------- tablero

    function crearTablero(raiz) {
        const ctx = {
            raiz: raiz,
            cont: raiz.querySelector('.auralis-kanban-root') || raiz,
            live: raiz.querySelector('[data-kanban-live]'),
            url: raiz.dataset.url || 'KanbanAuralis',
            board: raiz.dataset.board || '',
            filtros: leerJson(raiz.dataset.filters || '{}', {}),
            puedeMover: raiz.dataset.canMove === '1',
            i18n: {},
            columnas: [],
            cola: Promise.resolve(),
            columnaMovil: null,
            elevada: null,
            tablero: null,
            ultimoArrastre: 0,
            detalle: raiz.dataset.detail === 'modal' ? 'modal' : 'page',
            modalDetalle: null,
            detalleActual: null,
            // criterio de orden activo {clave, dir}; null = orden fijo (tablero sin selector)
            orden: null,
            ordenUi: null
        };
        if (Array.isArray(ctx.filtros)) {
            ctx.filtros = {};
        }
        const nodoI18n = document.getElementById('auralis-kanban-i18n');
        if (nodoI18n) {
            ctx.i18n = leerJson(nodoI18n.textContent || '{}', {});
        }

        function t(clave, variables) {
            let texto = ctx.i18n['auralis-kanban-' + clave];
            if (typeof texto !== 'string') {
                texto = '';
            }
            if (variables) {
                Object.keys(variables).forEach(function (nombre) {
                    texto = texto.split('%' + nombre + '%').join(String(variables[nombre]));
                });
            }
            return texto;
        }

        function anunciar(mensaje) {
            if (ctx.live) {
                ctx.live.textContent = mensaje;
            }
        }

        // --- red

        function token() {
            const input = ctx.raiz.querySelector('input[name="multireqtoken"]');
            return input ? input.value : '';
        }

        function guardarToken(nuevo) {
            if (!nuevo) {
                return;
            }
            document.querySelectorAll('input[name="multireqtoken"]').forEach(function (input) {
                input.value = nuevo;
            });
        }

        async function peticion(parametros) {
            const datos = new FormData();
            datos.append('action', parametros.action);
            datos.append('board', ctx.board);
            datos.append('multireqtoken', token());
            Object.keys(ctx.filtros).forEach(function (nombre) {
                const valor = ctx.filtros[nombre];
                if (valor !== null && valor !== undefined && valor !== '') {
                    datos.append(nombre, String(valor));
                }
            });
            // criterio de orden (el servidor lo vuelve a validar contra su lista cerrada)
            if (ctx.orden) {
                datos.set('orden', ctx.orden.clave);
                datos.set('dir', ctx.orden.dir);
            }
            // Campos de un formulario (detalle): van agrupados en campos[...] para no chocar
            // con los filtros ni con los parámetros propios de la carcasa.
            if (parametros.formulario instanceof FormData) {
                parametros.formulario.forEach(function (valor, nombre) {
                    datos.append('campos[' + nombre + ']', valor);
                });
            }
            Object.keys(parametros).forEach(function (nombre) {
                if (nombre !== 'action' && nombre !== 'formulario') {
                    datos.append(nombre, String(parametros[nombre]));
                }
            });
            const respuesta = await fetch(ctx.url, {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            let json = null;
            try {
                json = await respuesta.json();
            } catch (error) {
                json = null;
            }
            if (json && json.newToken) {
                guardarToken(json.newToken);
            }
            if (!json || typeof json !== 'object') {
                const fallo = new Error('respuesta no válida');
                fallo.http = respuesta.status;
                throw fallo;
            }
            // estado HTTP de la respuesta (el JSON trae su propio «status» ok/error)
            json._http = respuesta.status;
            return json;
        }

        // 403 = el proveedor no ofrece la interfaz de detalle (o el token caducó).
        function esProhibido(origen) {
            return !!origen && (origen._http === 403 || origen.http === 403);
        }

        // Las peticiones se serializan para que cada una use el token renovado por la anterior.
        function enviar(parametros) {
            const tarea = ctx.cola.then(function () { return peticion(parametros); });
            ctx.cola = tarea.catch(function () { /* se gestiona en quien llama */ });
            return tarea;
        }

        // --- estado

        function buscarTarjeta(id) {
            for (let c = 0; c < ctx.columnas.length; c++) {
                const encontrada = ctx.columnas[c].cards.find(function (tarjeta) {
                    return tarjeta.id === String(id);
                });
                if (encontrada) {
                    return encontrada;
                }
            }
            return null;
        }

        function columnaPorId(id) {
            return ctx.columnas.find(function (col) { return col.id === String(id); }) || null;
        }

        function envolverTarjeta(datos, col) {
            return { id: String(datos.id), d: datos, el: null, col: col, srv: true, pendiente: false };
        }

        function offsetServidor(col) {
            return col.cards.filter(function (tarjeta) { return tarjeta.srv; }).length;
        }

        function indiceOrden(col, tarjeta) {
            let i = 0;
            while (i < col.cards.length && (col.cards[i] === tarjeta || comparar(col.cards[i], tarjeta, ctx.orden) <= 0)) {
                i++;
            }
            return i;
        }

        function visibles() {
            return ctx.columnas.filter(function (col) { return !col.oculta; });
        }

        // --- cabecera y contadores

        function actualizarCabecera(col) {
            if (!col.refs) {
                return;
            }
            col.oculta = !col.active && col.count === 0;
            col.refs.sec.hidden = col.oculta;
            col.refs.tab.hidden = col.oculta;
            col.refs.contador.textContent = String(col.count);
            // singular con una sola tarjeta («1 tarjeta», no «1 tarjetas»)
            const una = col.count === 1;
            col.refs.suma.textContent = col.totalLabel
                ? t(una ? 'importe-en-una' : 'importe-en', { importe: col.totalLabel, num: col.count })
                : t(una ? 'una-tarjeta' : 'tarjetas', { num: col.count });
            col.refs.sec.setAttribute('aria-label', t(una ? 'columna-region-una' : 'columna-region', { columna: col.title, num: col.count }));
            col.refs.tabContador.textContent = String(col.count);
            col.refs.lista.classList.toggle('zk-lista-vacia', col.cards.length === 0);
            col.refs.mas.hidden = !col.hasMore;

            // candado único en la cabecera si todos los documentos de la columna están bloqueados
            const bloqueada = columnaBloqueada(col);
            col.refs.sec.classList.toggle('zk-columna-bloqueada', bloqueada);
            col.refs.bloqueo.hidden = !bloqueada;
            // la línea de etiquetas solo existe si hay alguna visible (sin hueco vacío bajo el nombre)
            col.refs.etiquetas.hidden = !bloqueada && !col.generatesDoc;
            // candado de la pestaña móvil: mismo motivo que las etiquetas, con el texto solo para lectores
            const motivos = [];
            if (bloqueada) {
                motivos.push(t('columna-bloqueada'));
            }
            if (col.generatesDoc) {
                motivos.push(t('genera-desde-ficha-corto'));
            }
            col.refs.tabBloqueo.hidden = motivos.length === 0;
            col.refs.tabBloqueo.title = motivos.join(' · ');
            col.refs.tabBloqueo.lastChild.textContent = motivos.join(', ');

            // columna vacía: franja plegada (solo escritorio y tablet, lo decide el CSS); con tarjetas queda abierta
            if (col.count > 0) {
                col.abierta = true;
            }
            // plegada: vacía (por defecto) o plegada a mano por el usuario (se recuerda por tablero)
            const plegada = col.plegadaManual || (col.count === 0 && !col.abierta);
            col.refs.sec.classList.toggle('zk-columna-plegada', plegada);
            col.refs.plegadoBtn.setAttribute('aria-expanded', plegada ? 'false' : 'true');
            col.refs.plegadoContador.textContent = String(col.count);
            col.refs.plegadoBloqueo.hidden = !bloqueada;
            col.refs.plegarBtn.hidden = plegada;
        }

        function abrirColumna(col, enfocar) {
            if (!col.refs || (col.abierta && !col.plegadaManual)) {
                return;
            }
            col.abierta = true;
            if (col.plegadaManual) {
                col.plegadaManual = false;
                guardarPlegadas();
            }
            actualizarCabecera(col);
            if (enfocar) {
                col.refs.nombre.focus();
            }
        }

        function plegarColumna(col) {
            if (!col.refs) {
                return;
            }
            col.abierta = false;
            // las vacías se pliegan solas; las que tienen tarjetas se recuerdan plegadas
            col.plegadaManual = col.count > 0;
            guardarPlegadas();
            actualizarCabecera(col);
            col.refs.plegadoBtn.focus();
        }

        // Columnas plegadas a mano, por tablero, en el navegador del usuario (localStorage con try/catch).
        function clavePlegadas() {
            return 'auralis-kanban-plegadas-' + ctx.board;
        }

        function plegadasGuardadas() {
            try {
                const lista = JSON.parse(window.localStorage.getItem(clavePlegadas()) || '[]');
                return Array.isArray(lista) ? lista.map(String) : [];
            } catch (error) {
                return [];
            }
        }

        function guardarPlegadas() {
            const ids = ctx.columnas.filter(function (c) { return c.plegadaManual; }).map(function (c) { return c.id; });
            try {
                window.localStorage.setItem(clavePlegadas(), JSON.stringify(ids));
            } catch (error) {
                // sin almacenamiento: el plegado dura hasta recargar
            }
        }

        // Al arrastrar una tarjeta sobre una franja plegada se abre, si la columna admite soltar.
        function alArrastrarSobre(col) {
            if (ctx.puedeMover && col.allowDrop && ctx.tablero && ctx.tablero.classList.contains('zk-arrastrando')) {
                abrirColumna(col, false);
            }
        }

        function aplicarConteos(conteos) {
            if (!conteos || typeof conteos !== 'object') {
                return;
            }
            ctx.columnas.forEach(function (col) {
                const dato = conteos[col.id];
                if (dato) {
                    col.count = Number(dato.count) || 0;
                    col.total = Number(dato.total) || 0;
                    col.totalLabel = typeof dato.totalLabel === 'string' ? dato.totalLabel : '';
                    if (dato.lockedCount !== undefined) {
                        col.lockedCount = Number(dato.lockedCount) || 0;
                    }
                }
                actualizarCabecera(col);
            });
        }

        function instantanea() {
            return ctx.columnas.map(function (col) {
                return { col: col, count: col.count, total: col.total, totalLabel: col.totalLabel, lockedCount: col.lockedCount };
            });
        }

        function restaurar(copia) {
            copia.forEach(function (fila) {
                fila.col.lockedCount = fila.lockedCount;
                fila.col.count = fila.count;
                fila.col.total = fila.total;
                fila.col.totalLabel = fila.totalLabel;
                actualizarCabecera(fila.col);
            });
        }

        // --- tabindex itinerante

        function refrescarTabindex(col) {
            if (!col.cards.length) {
                return;
            }
            const actual = col.cards.find(function (tarjeta) { return tarjeta.id === col.focoId; }) || col.cards[0];
            col.cards.forEach(function (tarjeta) {
                if (tarjeta.el) {
                    tarjeta.el.tabIndex = tarjeta === actual ? 0 : -1;
                }
            });
        }

        // --- colocación por orden (DOM + estado)

        function colocar(tarjeta, col) {
            const origen = tarjeta.col;
            origen.cards = origen.cards.filter(function (otra) { return otra !== tarjeta; });
            if (origen !== col) {
                tarjeta.srv = false;
            }
            tarjeta.col = col;
            const indice = indiceOrden(col, tarjeta);
            col.cards.splice(indice, 0, tarjeta);
            const siguiente = col.cards[indice + 1];
            col.refs.lista.insertBefore(tarjeta.el, siguiente && siguiente.el ? siguiente.el : null);
            refrescarTabindex(col);
            if (origen !== col) {
                refrescarTabindex(origen);
                actualizarCabecera(origen);
            }
            actualizarCabecera(col);
        }

        function marcarPendiente(tarjeta, activo) {
            tarjeta.pendiente = activo;
            tarjeta.el.classList.toggle('zk-pendiente', activo);
            tarjeta.el.setAttribute('aria-busy', activo ? 'true' : 'false');
            const hueco = tarjeta.el.querySelector('.zk-spinner');
            if (hueco) {
                hueco.hidden = !activo;
            }
        }

        // --- movimiento

        async function mover(id, destinoId, opciones) {
            opciones = opciones || {};
            const tarjeta = buscarTarjeta(id);
            const destino = columnaPorId(destinoId);
            if (!tarjeta || !destino || tarjeta.pendiente) {
                if (tarjeta) {
                    colocar(tarjeta, tarjeta.col);
                }
                return;
            }
            const origen = tarjeta.col;
            if (origen === destino) {
                colocar(tarjeta, origen);
                return;
            }
            if (!ctx.puedeMover || tarjeta.d.locked || !destino.allowDrop) {
                colocar(tarjeta, origen);
                return;
            }

            const copia = instantanea();
            const tuvoFoco = document.activeElement === tarjeta.el;
            origen.count = Math.max(0, origen.count - 1);
            destino.count += 1;
            colocar(tarjeta, destino);
            marcarPendiente(tarjeta, true);
            if (tuvoFoco) {
                tarjeta.el.focus();
            }

            function revertir() {
                marcarPendiente(tarjeta, false);
                colocar(tarjeta, origen);
                restaurar(copia);
                if (tuvoFoco) {
                    tarjeta.el.focus();
                }
            }

            let respuesta;
            try {
                respuesta = await enviar({ action: 'kanban-move', id: tarjeta.id, to: destino.id, position: 0, confirm: 0 });
                if (respuesta.status === 'needsConfirm') {
                    const aceptado = await confirmarModal({
                        titulo: t('confirmar-titulo'), confirmar: t('confirmar'), cancelar: t('cancelar')
                    }, respuesta.summary || '');
                    if (!aceptado) {
                        revertir();
                        return;
                    }
                    respuesta = await enviar({ action: 'kanban-move', id: tarjeta.id, to: destino.id, position: 0, confirm: 1 });
                }
            } catch (error) {
                revertir();
                mostrarToast({ mensaje: t('error-red'), error: true, cerrarTexto: t('cancelar') });
                anunciar(t('error-red'));
                return;
            }

            if (respuesta.status !== 'ok') {
                revertir();
                const mensaje = respuesta.message || t('error-red');
                mostrarToast({
                    mensaje: mensaje, error: true, cerrarTexto: t('cancelar'),
                    url: respuesta.url, urlTexto: t('abrir-ficha')
                });
                anunciar(mensaje);
                return;
            }

            marcarPendiente(tarjeta, false);
            if (respuesta.card && typeof respuesta.card === 'object') {
                tarjeta.d = respuesta.card;
                const nuevo = crearTarjetaEl(tarjeta);
                const viejo = tarjeta.el;
                viejo.replaceWith(nuevo);
                tarjeta.el = nuevo;
            }
            colocar(tarjeta, destino);
            aplicarConteos(respuesta.counts);
            if (tuvoFoco) {
                tarjeta.el.focus();
            }

            const mensaje = respuesta.message || t('movido', { estado: destino.title });
            anunciar(mensaje);
            // sin «Deshacer» si el destino pide confirmación (cierre de factura) o el origen ya no admite tarjetas
            if (!destino.confirm && !opciones.sinDeshacer && origen.allowDrop) {
                mostrarToast({
                    mensaje: mensaje,
                    duracion: MS_DESHACER,
                    accionTexto: t('deshacer'),
                    cerrarTexto: t('cancelar'),
                    accion: function () { mover(tarjeta.id, origen.id, { sinDeshacer: true }); }
                });
            } else {
                mostrarToast({ mensaje: mensaje, cerrarTexto: t('cancelar') });
            }
        }

        // --- construcción de tarjetas

        function reconstruirMenu(tarjeta, lista) {
            lista.textContent = '';
            const cabecera = crear('li');
            cabecera.appendChild(crear('h6', 'dropdown-header', t('mover-a')));
            lista.appendChild(cabecera);
            ctx.columnas.forEach(function (col) {
                if (col === tarjeta.col || !col.allowDrop || col.oculta) {
                    return;
                }
                const item = crear('li');
                const boton = crear('button', 'dropdown-item', col.title);
                boton.type = 'button';
                boton.addEventListener('click', function () {
                    mover(tarjeta.id, col.id);
                });
                item.appendChild(boton);
                lista.appendChild(item);
            });
        }

        function crearMenu(tarjeta) {
            const caja = crear('div', 'dropdown zk-menu');
            caja.setAttribute('data-kanban-menu', '');
            const boton = crear('button', 'btn btn-link zk-menu-btn');
            boton.type = 'button';
            boton.setAttribute('data-bs-toggle', 'dropdown');
            boton.setAttribute('aria-expanded', 'false');
            boton.setAttribute('aria-label', t('mas-acciones'));
            boton.appendChild(icono('fa-solid fa-ellipsis'));
            const lista = crear('ul', 'dropdown-menu dropdown-menu-end');
            if (tarjeta.d.locked) {
                boton.disabled = true;
                boton.setAttribute('aria-disabled', 'true');
            } else {
                boton.addEventListener('click', function () {
                    reconstruirMenu(tarjeta, lista);
                });
            }
            caja.appendChild(boton);
            caja.appendChild(lista);
            // Bootstrap busca el menú en el padre del botón: el Dropdown se crea con los dos ya en la caja
            if (false === boton.disabled && window.bootstrap && window.bootstrap.Dropdown) {
                window.bootstrap.Dropdown.getOrCreateInstance(boton, {
                    popperConfig: function (porDefecto) {
                        return Object.assign({}, porDefecto, { strategy: 'fixed' });
                    }
                });
            }
            return caja;
        }

        function crearTarjetaEl(tarjeta) {
            const d = tarjeta.d;
            const art = crear('article', 'zk-tarjeta');
            art.setAttribute('role', 'listitem');
            art.dataset.id = tarjeta.id;
            art.tabIndex = -1;
            art.setAttribute('aria-busy', 'false');
            if (d.locked) {
                art.classList.add('zk-bloqueada');
            }

            // Fila superior: código (hueco 1), bloqueo, spinner y menú.
            const superior = crear('div', 'zk-fila-codigo');
            if (d.code) {
                const enlace = urlSegura(d.url);
                let codigo;
                if (enlace) {
                    codigo = crear('a', 'zk-codigo', d.code);
                    codigo.href = enlace;
                    codigo.draggable = false;
                    codigo.tabIndex = -1;
                    codigo.setAttribute('aria-label', t('abrir-ficha') + ' ' + d.code);
                } else {
                    codigo = crear('span', 'zk-codigo', d.code);
                }
                superior.appendChild(codigo);
            }
            if (d.locked) {
                const bloqueo = crear('span', 'zk-bloqueo');
                bloqueo.appendChild(icono('fa-solid fa-lock'));
                bloqueo.appendChild(crear('span', '', t('bloqueado-corto')));
                superior.appendChild(bloqueo);
            }
            const spinner = crear('span', 'spinner-border zk-spinner');
            spinner.setAttribute('role', 'status');
            spinner.hidden = true;
            spinner.appendChild(crear('span', 'visually-hidden', t('cargando')));
            superior.appendChild(spinner);
            if (ctx.puedeMover) {
                superior.appendChild(crear('span', 'zk-espacio'));
                superior.appendChild(crearMenu(tarjeta));
            }
            if (superior.childNodes.length) {
                art.appendChild(superior);
            }

            if (d.title) {
                art.appendChild(crear('div', 'zk-titulo', d.title));
            }
            if (d.subtitle) {
                art.appendChild(crear('div', 'zk-subtitulo', d.subtitle));
            }
            if (d.keyValue) {
                art.appendChild(crear('div', 'zk-clave', d.keyValue));
            }

            // Pie: fecha (vencida con icono y texto), responsable y etiquetas.
            const pie = crear('div', 'zk-pie');
            if (d.date || d.dateLabel) {
                const etiquetaFecha = d.dateLabel || d.date;
                const abierta = !!tarjeta.col && tarjeta.col.tipo === 'abierto';
                if (d.overdue) {
                    const vencida = crear('span', 'zk-fecha zk-vencida');
                    vencida.appendChild(icono('fa-solid fa-triangle-exclamation'));
                    vencida.appendChild(crear('span', '', t('vencida') + ' ' + etiquetaFecha));
                    pie.appendChild(vencida);
                } else if (abierta && d.dueSoon) {
                    // ámbar con icono y texto (nunca solo color)
                    const pronto = crear('span', 'zk-fecha zk-vence-pronto');
                    pronto.title = t('vence-pronto');
                    pronto.appendChild(icono('fa-solid fa-hourglass-half'));
                    pronto.appendChild(crear('span', '', t('validez') + ' ' + etiquetaFecha));
                    pronto.appendChild(crear('span', 'visually-hidden', ' (' + t('vence-pronto') + ')'));
                    pie.appendChild(pronto);
                } else if (abierta) {
                    pie.appendChild(crear('span', 'zk-fecha', t('validez') + ' ' + etiquetaFecha));
                } else {
                    pie.appendChild(crear('span', 'zk-fecha', etiquetaFecha));
                }
            }
            if (Array.isArray(d.tags)) {
                // como mucho 2 a la vista; si hay más, «+N» con la lista completa en el tooltip
                const etiquetas = d.tags.filter(function (etiqueta) {
                    return !!etiqueta && typeof etiqueta.label === 'string' && etiqueta.label !== '';
                });
                etiquetas.slice(0, MAX_ETIQUETAS_VISIBLES).forEach(function (etiqueta) {
                    const caja = crear('span', 'zk-etiqueta');
                    if (typeof etiqueta.title === 'string' && etiqueta.title !== '') {
                        caja.title = etiqueta.title;
                    }
                    const punto = crear('span', 'zk-punto-etiqueta');
                    const color = colorSeguro(etiqueta.color);
                    if (color) {
                        punto.style.backgroundColor = color;
                    }
                    caja.appendChild(punto);
                    caja.appendChild(crear('span', '', etiqueta.label));
                    pie.appendChild(caja);
                });
                if (etiquetas.length > MAX_ETIQUETAS_VISIBLES) {
                    const lista = etiquetas.map(function (etiqueta) {
                        return etiqueta.label;
                    }).join(' · ');
                    const mas = crear('span', 'zk-etiqueta zk-etiqueta-mas', '+' + (etiquetas.length - MAX_ETIQUETAS_VISIBLES));
                    mas.title = lista;
                    mas.setAttribute('aria-label', lista);
                    pie.appendChild(mas);
                }
            }
            // dato de un criterio de orden de otro plugin (texto plano), solo mientras se ordena por él
            const datoOrden = ctx.orden && d.sortLabels && typeof d.sortLabels === 'object' ? d.sortLabels[ctx.orden.clave] : null;
            if (typeof datoOrden === 'string' && datoOrden !== '') {
                pie.appendChild(crear('span', 'zk-dato-orden', datoOrden));
            }
            if (d.owner || d.ownerInitials) {
                const avatar = crear('span', 'zk-avatar', d.ownerInitials || '');
                if (d.owner) {
                    avatar.title = d.owner;
                    avatar.setAttribute('role', 'img');
                    avatar.setAttribute('aria-label', d.owner);
                }
                pie.appendChild(avatar);
            }
            if (pie.childNodes.length) {
                art.appendChild(pie);
            }
            return art;
        }

        // --- construcción de columnas

        function crearColumnaEl(col, indice) {
            const sec = crear('section', 'zk-columna');
            sec.setAttribute('role', 'region');
            sec.dataset.columna = col.id;

            const cabecera = crear('header', 'zk-cabecera');
            const fila = crear('div', 'zk-cabecera-fila');
            // color del estado en una variable por columna: lo usan el punto y el borde superior
            const color = colorColumna(col);
            sec.style.setProperty('--zk-color-estado', color);
            const nombreVisible = capitalizar(col.title);
            const punto = crear('span', 'zk-punto');
            fila.appendChild(punto);
            const nombre = crear('h2', 'zk-nombre', nombreVisible);
            nombre.title = nombreVisible;
            nombre.tabIndex = -1;
            fila.appendChild(nombre);
            const contador = crear('span', 'zk-contador', col.count);
            fila.appendChild(contador);
            // Etiquetas de bloqueo en su propia línea bajo la fila principal, para que no le quiten ancho al nombre.
            // El texto siempre está en el DOM (nombre accesible); el CSS lo reduce a solo candado en columnas estrechas.
            const etiquetas = crear('div', 'zk-etiquetas');
            const bloqueo = crear('span', 'zk-bloqueo zk-bloqueo-columna');
            bloqueo.title = t('columna-bloqueada');
            bloqueo.appendChild(icono('fa-solid fa-lock'));
            bloqueo.appendChild(crear('span', 'zk-bloqueo-texto', t('columna-bloqueada')));
            bloqueo.hidden = true;
            etiquetas.appendChild(bloqueo);
            // columna que generaría otro documento: no admite soltar tarjetas, se hace desde la ficha
            if (col.generatesDoc) {
                const genera = crear('span', 'zk-bloqueo zk-bloqueo-columna zk-genera-ficha');
                genera.title = t('genera-desde-ficha');
                genera.appendChild(icono('fa-solid fa-lock'));
                genera.appendChild(crear('span', 'zk-bloqueo-texto', t('genera-desde-ficha-corto')));
                etiquetas.appendChild(genera);
                nombre.title = nombreVisible + ' · ' + t('genera-desde-ficha');
            }
            // «Plegar»: en cualquier columna abierta (el CSS lo oculta en móvil, donde no hay franjas)
            const plegarBtn = crear('button', 'btn btn-link zk-plegar-btn');
            plegarBtn.type = 'button';
            plegarBtn.hidden = true;
            plegarBtn.setAttribute('aria-label', t('plegar-columna', { columna: nombreVisible }));
            plegarBtn.title = t('plegar-columna', { columna: nombreVisible });
            plegarBtn.appendChild(icono('fa-solid fa-angles-left'));
            plegarBtn.addEventListener('click', function () {
                plegarColumna(col);
            });
            fila.appendChild(plegarBtn);
            cabecera.appendChild(fila);
            etiquetas.hidden = !col.generatesDoc;
            cabecera.appendChild(etiquetas);
            const suma = crear('div', 'zk-suma');
            cabecera.appendChild(suma);
            sec.appendChild(cabecera);

            const lista = crear('div', 'zk-lista');
            lista.setAttribute('role', 'list');
            lista.dataset.columna = col.id;
            lista.dataset.permitirSoltar = ctx.puedeMover && col.allowDrop ? '1' : '0';
            lista.setAttribute('data-vacio', t('arrastra-aqui'));
            sec.appendChild(lista);

            const mas = crear('button', 'btn btn-outline-secondary zk-mas', t('cargar-mas'));
            mas.type = 'button';
            mas.addEventListener('click', function () {
                verMas(col);
            });
            sec.appendChild(mas);

            // Franja de la columna vacía plegada (el CSS solo la muestra en escritorio y tablet).
            const plegadoBtn = crear('button', 'zk-plegada-btn');
            plegadoBtn.type = 'button';
            plegadoBtn.setAttribute('aria-expanded', 'false');
            plegadoBtn.setAttribute('aria-label', t('mostrar-columna', { columna: nombreVisible }));
            plegadoBtn.title = nombreVisible;
            plegadoBtn.appendChild(crear('span', 'zk-punto'));
            plegadoBtn.appendChild(crear('span', 'zk-plegada-nombre', nombreVisible));
            const plegadoContador = crear('span', 'zk-plegada-contador', col.count);
            plegadoBtn.appendChild(plegadoContador);
            // candado en la franja si toda la columna está bloqueada
            const plegadoBloqueo = crear('span', 'zk-plegada-bloqueo');
            plegadoBloqueo.appendChild(icono('fa-solid fa-lock'));
            plegadoBloqueo.appendChild(crear('span', 'visually-hidden', t('columna-bloqueada')));
            plegadoBloqueo.title = t('columna-bloqueada');
            plegadoBloqueo.hidden = true;
            plegadoBtn.appendChild(plegadoBloqueo);
            plegadoBtn.addEventListener('click', function () {
                abrirColumna(col, true);
            });
            sec.insertBefore(plegadoBtn, cabecera);
            sec.addEventListener('dragenter', function () { alArrastrarSobre(col); });
            sec.addEventListener('pointerenter', function () { alArrastrarSobre(col); });

            // Pestaña móvil (también destino de arrastre).
            const tab = crear('div', 'zk-pestana');
            tab.setAttribute('role', 'tab');
            tab.dataset.columna = col.id;
            tab.dataset.permitirSoltar = ctx.puedeMover && col.allowDrop ? '1' : '0';
            if (col.generatesDoc) {
                tab.title = t('genera-desde-ficha');
            }
            tab.style.setProperty('--zk-color-estado', color);
            const tabPunto = crear('span', 'zk-punto');
            tab.appendChild(tabPunto);
            tab.appendChild(crear('span', 'zk-pestana-nombre', nombreVisible));
            // En móvil las etiquetas de la columna no ocupan línea: el candado va dentro de la pestaña (lo muestra actualizarCabecera)
            const tabBloqueo = crear('span', 'zk-pestana-bloqueo');
            tabBloqueo.appendChild(icono('fa-solid fa-lock'));
            tabBloqueo.appendChild(crear('span', 'visually-hidden'));
            tabBloqueo.hidden = true;
            tab.appendChild(tabBloqueo);
            const tabContador = crear('span', 'zk-pestana-contador', col.count);
            tab.appendChild(tabContador);
            tab.addEventListener('click', function () {
                activarColumnaMovil(col.id, true);
            });
            tab.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    activarColumnaMovil(col.id, true);
                }
            });

            col.refs = {
                sec: sec, lista: lista, contador: contador, suma: suma, mas: mas, tab: tab, tabContador: tabContador,
                nombre: nombre, bloqueo: bloqueo, plegadoBtn: plegadoBtn, plegadoContador: plegadoContador,
                plegarBtn: plegarBtn, plegadoBloqueo: plegadoBloqueo, etiquetas: etiquetas, tabBloqueo: tabBloqueo
            };
            col.cards.forEach(function (tarjeta) {
                tarjeta.el = crearTarjetaEl(tarjeta);
                lista.appendChild(tarjeta.el);
            });
            refrescarTabindex(col);
            actualizarCabecera(col);
            return sec;
        }

        // --- carga y estados

        function limpiar() {
            if (window.AuralisKanbanDnd) {
                window.AuralisKanbanDnd.destroy();
            }
            ctx.cont.textContent = '';
            ctx.tablero = null;
        }

        function mostrarSqueleto() {
            limpiar();
            ctx.cont.setAttribute('aria-busy', 'true');
            const tablero = crear('div', 'zk-tablero zk-squeleto');
            for (let c = 0; c < 3; c++) {
                const col = crear('div', 'zk-columna');
                col.appendChild(crear('div', 'zk-sq-cabecera'));
                for (let i = 0; i < 3; i++) {
                    col.appendChild(crear('div', 'zk-sq-tarjeta'));
                }
                tablero.appendChild(col);
            }
            tablero.appendChild(crear('span', 'visually-hidden', t('cargando')));
            ctx.cont.appendChild(tablero);
        }

        function mostrarError(mensaje) {
            limpiar();
            ctx.cont.setAttribute('aria-busy', 'false');
            const alerta = crear('div', 'alert alert-danger d-flex align-items-center flex-wrap gap-2');
            alerta.setAttribute('role', 'alert');
            alerta.appendChild(icono('fa-solid fa-triangle-exclamation'));
            alerta.appendChild(crear('span', 'flex-grow-1', mensaje || t('error-carga')));
            const reintentar = crear('button', 'btn btn-outline-danger', t('reintentar'));
            reintentar.type = 'button';
            reintentar.addEventListener('click', cargar);
            alerta.appendChild(reintentar);
            ctx.cont.appendChild(alerta);
        }

        function hayFiltros() {
            return Object.keys(ctx.filtros).some(function (nombre) {
                const valor = ctx.filtros[nombre];
                return valor !== null && valor !== undefined && valor !== '';
            });
        }

        function mostrarVacio() {
            limpiar();
            ctx.cont.setAttribute('aria-busy', 'false');
            const conFiltros = hayFiltros();
            const caja = crear('div', 'zk-estado');
            caja.appendChild(icono(conFiltros ? 'fa-solid fa-filter' : 'fa-solid fa-table-columns'));
            caja.appendChild(crear('p', 'zk-estado-texto', conFiltros ? t('sin-resultados') : t('vacio')));
            if (conFiltros) {
                const limpiarFiltros = crear('button', 'btn btn-outline-secondary', t('limpiar-filtros'));
                limpiarFiltros.type = 'button';
                limpiarFiltros.addEventListener('click', function () {
                    // se conservan solo el tablero y el modo de detalle de la URL actual
                    const actuales = new URLSearchParams(window.location.search);
                    const conservar = new URLSearchParams();
                    conservar.set('board', actuales.get('board') || ctx.board);
                    const detalle = actuales.get('detalle');
                    if (detalle) {
                        conservar.set('detalle', detalle);
                    }
                    window.location.href = window.location.pathname + '?' + conservar.toString();
                });
                caja.appendChild(limpiarFiltros);
            }
            ctx.cont.appendChild(caja);
        }

        function pintar() {
            limpiar();
            ctx.cont.setAttribute('aria-busy', 'false');
            const total = ctx.columnas.reduce(function (suma, col) { return suma + col.count; }, 0);
            if (!ctx.columnas.length || total === 0) {
                mostrarVacio();
                return;
            }
            const pestanas = crear('div', 'zk-pestanas');
            pestanas.setAttribute('role', 'tablist');
            pestanas.setAttribute('aria-label', t('tablero'));
            const tablero = crear('div', 'zk-tablero');
            ctx.columnas.forEach(function (col, indice) {
                tablero.appendChild(crearColumnaEl(col, indice));
                pestanas.appendChild(col.refs.tab);
            });
            ctx.cont.appendChild(pestanas);
            ctx.cont.appendChild(tablero);
            ctx.tablero = tablero;

            const primera = visibles()[0];
            if (!ctx.columnaMovil || !columnaPorId(ctx.columnaMovil) || columnaPorId(ctx.columnaMovil).oculta) {
                ctx.columnaMovil = primera ? primera.id : null;
            }
            activarColumnaMovil(ctx.columnaMovil, false);
            activarSwipe(tablero);

            if (ctx.puedeMover && window.AuralisKanbanDnd) {
                window.AuralisKanbanDnd.init(tablero, function (id, columnaId) {
                    return new Promise(function (resolver) {
                        window.setTimeout(function () {
                            ctx.ultimoArrastre = Date.now();
                            resolver(mover(id, columnaId));
                        }, 0);
                    });
                });
            }
            mostrarPista();
        }

        async function cargar() {
            mostrarSqueleto();
            let datos;
            try {
                datos = await enviar({ action: 'kanban-cards', column: '', offset: 0, limit: TAMANO_PAGINA });
            } catch (error) {
                mostrarError(t('error-carga'));
                return;
            }
            if (datos.status !== 'ok' || !Array.isArray(datos.columns)) {
                mostrarError(datos.message || t('error-carga'));
                return;
            }
            if (datos.board && typeof datos.board === 'object' && datos.board.canMove === false) {
                ctx.puedeMover = false;
            }
            ctx.columnas = datos.columns
                .filter(function (c) { return c.active !== false || Number(c.count) > 0; })
                .map(function (c) {
                    const col = {
                        id: String(c.id), title: String(c.title || ''), color: c.color || '',
                        confirm: !!c.confirm, generatesDoc: !!c.generatesDoc,
                        // una columna que genera documento nunca es destino (drop, «Mover a…» ni teclado)
                        allowDrop: c.allowDrop !== false && !c.generatesDoc, active: c.active !== false,
                        count: Number(c.count) || 0, total: Number(c.total) || 0,
                        totalLabel: typeof c.totalLabel === 'string' ? c.totalLabel : '',
                        tipo: typeof c.tipo === 'string' && c.tipo !== '' ? c.tipo : 'otro',
                        lockedCount: Number(c.lockedCount) || 0, abierta: false,
                        plegadaManual: plegadasGuardadas().indexOf(String(c.id)) !== -1,
                        cards: [], hasMore: !!c.hasMore, focoId: null, refs: null, oculta: false
                    };
                    col.cards = (Array.isArray(c.cards) ? c.cards : []).map(function (d) {
                        return envolverTarjeta(d, col);
                    });
                    return col;
                });
            pintar();
        }

        async function verMas(col) {
            const boton = col.refs.mas;
            boton.disabled = true;
            try {
                const datos = await enviar({ action: 'kanban-cards', column: col.id, offset: offsetServidor(col), limit: TAMANO_PAGINA });
                if (datos.status !== 'ok') {
                    throw new Error(datos.message || 'error');
                }
                (Array.isArray(datos.cards) ? datos.cards : []).forEach(function (d) {
                    if (buscarTarjeta(d.id)) {
                        return;
                    }
                    const tarjeta = envolverTarjeta(d, col);
                    tarjeta.el = crearTarjetaEl(tarjeta);
                    col.cards.push(tarjeta);
                    col.refs.lista.appendChild(tarjeta.el);
                });
                col.hasMore = !!datos.hasMore;
                refrescarTabindex(col);
                actualizarCabecera(col);
            } catch (error) {
                mostrarToast({ mensaje: error && error.message && error.message !== 'error' && error.message !== 'respuesta no válida' ? error.message : t('error-red'), error: true, cerrarTexto: t('cancelar') });
            } finally {
                boton.disabled = false;
            }
        }

        // --- móvil: pestañas, swipe y pista

        function activarColumnaMovil(id, enfocar) {
            ctx.columnaMovil = id;
            ctx.columnas.forEach(function (col) {
                if (!col.refs) {
                    return;
                }
                const activa = col.id === String(id);
                col.refs.sec.classList.toggle('zk-columna-activa', activa);
                col.refs.tab.classList.toggle('zk-pestana-activa', activa);
                col.refs.tab.setAttribute('aria-selected', activa ? 'true' : 'false');
                col.refs.tab.tabIndex = activa ? 0 : -1;
                if (activa && enfocar && typeof col.refs.tab.scrollIntoView === 'function') {
                    col.refs.tab.scrollIntoView({ block: 'nearest', inline: 'center', behavior: sinAnimaciones() ? 'auto' : 'smooth' });
                }
            });
        }

        function activarSwipe(tablero) {
            let inicio = null;
            tablero.addEventListener('touchstart', function (evento) {
                if (!esMovil() || evento.touches.length !== 1 || tablero.classList.contains('zk-arrastrando')) {
                    inicio = null;
                    return;
                }
                inicio = { x: evento.touches[0].clientX, y: evento.touches[0].clientY };
            }, { passive: true });
            tablero.addEventListener('touchend', function (evento) {
                if (!inicio || !esMovil() || tablero.classList.contains('zk-arrastrando') || Date.now() - ctx.ultimoArrastre < 500) {
                    inicio = null;
                    return;
                }
                const toque = evento.changedTouches[0];
                const dx = toque.clientX - inicio.x;
                const dy = toque.clientY - inicio.y;
                inicio = null;
                if (Math.abs(dx) < 60 || Math.abs(dy) > 40) {
                    return;
                }
                const lista = visibles();
                const posicion = lista.findIndex(function (col) { return col.id === ctx.columnaMovil; });
                const siguiente = lista[posicion + (dx < 0 ? 1 : -1)];
                if (siguiente) {
                    activarColumnaMovil(siguiente.id, true);
                }
            }, { passive: true });
        }

        function mostrarPista() {
            if (!ctx.puedeMover || !esMovil()) {
                return;
            }
            try {
                if (window.localStorage.getItem(CLAVE_PISTA)) {
                    return;
                }
                window.localStorage.setItem(CLAVE_PISTA, '1');
            } catch (error) {
                /* almacenamiento no disponible: se muestra igualmente */
            }
            mostrarToast({ mensaje: t('pista-movil'), duracion: 6000, cerrarTexto: t('cancelar') });
        }

        // --- repintado de una tarjeta ya existente (guardado del detalle o cambio externo)

        function aplicarTarjeta(card) {
            if (!card || typeof card !== 'object' || card.id === undefined || card.id === null) {
                return null;
            }
            const tarjeta = buscarTarjeta(card.id);
            if (!tarjeta) {
                return null;
            }
            // Una elevación por teclado en curso se cancela (anunciándolo): el contenido cambia bajo la tarjeta.
            if (ctx.elevada && ctx.elevada.tarjeta === tarjeta) {
                soltarElevada(true);
            }
            const tuvoFoco = !!tarjeta.el && document.activeElement === tarjeta.el;
            tarjeta.d = card;
            const tieneColumna = card.column !== undefined && card.column !== null;
            const destino = tieneColumna ? columnaPorId(card.column) : null;

            // La columna nueva no está pintada (inactiva y vacía, o desconocida): la tarjeta sale del tablero.
            if (tieneColumna && (!destino || !destino.refs)) {
                const origen = tarjeta.col;
                const posicion = origen.cards.indexOf(tarjeta);
                origen.cards = origen.cards.filter(function (otra) { return otra !== tarjeta; });
                if (tarjeta.el) {
                    tarjeta.el.remove();
                }
                tarjeta.el = null;
                origen.count = Math.max(0, origen.count - 1);
                refrescarTabindex(origen);
                actualizarCabecera(origen);
                if (tuvoFoco) {
                    enfocarTarjeta(origen.cards[posicion] || origen.cards[posicion - 1] || null);
                }
                return tarjeta;
            }

            const nuevo = crearTarjetaEl(tarjeta);
            tarjeta.el.replaceWith(nuevo);
            tarjeta.el = nuevo;
            if (tarjeta.pendiente) {
                marcarPendiente(tarjeta, true);
            }
            // colocar() reubica con el criterio de orden activo, también dentro de la misma columna
            colocar(tarjeta, destino || tarjeta.col);
            if (tuvoFoco) {
                tarjeta.el.focus();
            }
            return tarjeta;
        }

        async function refrescarTarjeta(id) {
            try {
                const respuesta = await enviar({ action: 'kanban-card', id: String(id) });
                if (respuesta.status !== 'ok') {
                    return false;
                }
                const tarjeta = aplicarTarjeta(respuesta.card);
                aplicarConteos(respuesta.counts);
                return !!tarjeta;
            } catch (error) {
                return false;
            }
        }

        // --- detalle en modal

        // El html llega de nuestro propio servidor (Twig con autoescape, sesión autenticada). La primera
        // barrera es el autoescape de Twig; esto es defensa en profundidad con lista permitida:
        // el fragmento se inserta inerte, sin elementos activos, manejadores ni URL ajenas al sitio.
        const ETIQUETAS_PROHIBIDAS = [
            'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta',
            'link', 'style', 'template', 'noscript', 'portal'
        ];
        // Elementos SVG que animan atributos o incrustan HTML/otros recursos (en minúsculas para comparar).
        const ETIQUETAS_SVG_PROHIBIDAS = ['animate', 'set', 'animatemotion', 'animatetransform', 'foreignobject', 'use'];
        const ATRIBUTOS_URL = [
            'href', 'src', 'action', 'data', 'poster', 'background', 'xlink:href', 'to', 'from',
            'values', 'ping', 'cite', 'longdesc', 'srcset'
        ];

        function urlPermitida(nombre, valor) {
            if (nombre === 'srcset') {
                // cada candidato es «url [descriptor]», separados por comas
                return valor.split(',').every(function (candidato) {
                    const url = candidato.trim().split(/\s+/)[0];
                    return url !== '' && urlSegura(url) !== null;
                });
            }
            // enlaces de correo y teléfono (comunes en una ficha de tarea o contacto), solo en href
            if (nombre === 'href' && /^(mailto|tel):[^\s<>"'\\]+$/i.test(String(valor).trim())) {
                return true;
            }
            return urlSegura(valor) !== null;
        }

        function sanearFragmento(fragmento) {
            const nodos = Array.prototype.slice.call(fragmento.querySelectorAll('*'));
            nodos.forEach(function (nodo) {
                const etiqueta = String(nodo.localName || '').toLowerCase();
                const esSvg = nodo.namespaceURI === 'http://www.w3.org/2000/svg';
                if (ETIQUETAS_PROHIBIDAS.indexOf(etiqueta) !== -1 || (esSvg && ETIQUETAS_SVG_PROHIBIDAS.indexOf(etiqueta) !== -1)) {
                    nodo.remove();
                    return;
                }
                if (etiqueta === 'form') {
                    // el envío lo hace siempre la carcasa por AJAX
                    nodo.removeAttribute('action');
                    nodo.removeAttribute('method');
                    nodo.removeAttribute('target');
                }
                Array.prototype.slice.call(nodo.attributes).forEach(function (atributo) {
                    const nombre = atributo.name.toLowerCase();
                    const valor = String(atributo.value);
                    let quitar = false;
                    if (nombre.indexOf('on') === 0 || nombre === 'srcdoc' || nombre === 'formaction') {
                        quitar = true;
                    } else if (nombre === 'style') {
                        const compacto = valor.replace(/[\s\u0000-\u001f]+/g, '').toLowerCase();
                        quitar = compacto.indexOf('url(') !== -1 || compacto.indexOf('expression(') !== -1 || compacto.indexOf('\\') !== -1;
                    } else if (ATRIBUTOS_URL.indexOf(nombre) !== -1) {
                        quitar = !urlPermitida(nombre, valor);
                    }
                    if (quitar) {
                        nodo.removeAttribute(atributo.name);
                    }
                });
            });
        }

        function insertarHtml(destino, html) {
            const plantilla = document.createElement('template');
            plantilla.innerHTML = html;
            sanearFragmento(plantilla.content);
            destino.textContent = '';
            destino.appendChild(plantilla.content.cloneNode(true));
        }

        function crearModalDetalle() {
            const modal = crear('div', 'modal fade auralis-kanban-modal auralis-kanban-modal-detalle');
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('aria-labelledby', 'auralis-kanban-detalle-titulo');
            const dialogo = crear('div', 'modal-dialog modal-dialog-scrollable modal-lg modal-fullscreen-sm-down');
            const contenido = crear('div', 'modal-content');

            const cabecera = crear('div', 'modal-header');
            const bloqueTitulo = crear('div', 'zk-detalle-titulos');
            const titulo = crear('h2', 'modal-title fs-5', t('detalle-titulo'));
            titulo.id = 'auralis-kanban-detalle-titulo';
            const subtitulo = crear('small', 'zk-detalle-subtitulo d-block');
            subtitulo.hidden = true;
            bloqueTitulo.appendChild(titulo);
            bloqueTitulo.appendChild(subtitulo);
            const enlace = crear('a', 'btn btn-outline-secondary text-nowrap', t('abrir-ficha-completa'));
            enlace.hidden = true;
            const x = crear('button', 'btn-close');
            x.type = 'button';
            x.setAttribute('data-bs-dismiss', 'modal');
            x.setAttribute('aria-label', t('cerrar'));
            cabecera.appendChild(bloqueTitulo);
            cabecera.appendChild(enlace);
            cabecera.appendChild(x);

            const cuerpo = crear('div', 'modal-body');
            const alertas = crear('div', 'zk-detalle-alertas');
            const zona = crear('div', 'zk-detalle-contenido');
            cuerpo.appendChild(alertas);
            cuerpo.appendChild(zona);

            const pie = crear('div', 'modal-footer');
            const cerrar = crear('button', 'btn btn-secondary', t('cerrar'));
            cerrar.type = 'button';
            cerrar.setAttribute('data-bs-dismiss', 'modal');
            const guardar = crear('button', 'btn btn-primary', t('guardar'));
            guardar.type = 'button';
            guardar.hidden = true;
            pie.appendChild(cerrar);
            pie.appendChild(guardar);

            contenido.appendChild(cabecera);
            contenido.appendChild(cuerpo);
            contenido.appendChild(pie);
            dialogo.appendChild(contenido);
            modal.appendChild(dialogo);
            document.body.appendChild(modal);

            const m = {
                el: modal, dialogo: dialogo, titulo: titulo, subtitulo: subtitulo, enlace: enlace,
                cuerpo: cuerpo, alertas: alertas, zona: zona, guardar: guardar, x: x, cerrar: cerrar,
                instancia: window.bootstrap.Modal.getOrCreateInstance(modal)
            };
            guardar.addEventListener('click', guardarDetalle);
            // Mientras se guarda no se puede cerrar (ni con Esc ni pulsando fuera): así el resultado siempre llega a la vista.
            modal.addEventListener('hide.bs.modal', function (evento) {
                if (ctx.detalleActual && ctx.detalleActual.guardando) {
                    evento.preventDefault();
                }
            });
            zona.addEventListener('submit', function (evento) {
                if (evento.target.closest && evento.target.closest('form[data-kanban-detalle-form]')) {
                    evento.preventDefault();
                    guardarDetalle();
                }
            });
            modal.addEventListener('hidden.bs.modal', function () {
                const actual = ctx.detalleActual;
                ctx.detalleActual = null;
                // primero se avisa (el consumidor aún puede leer su contenido para limpiar) y luego se vacía
                if (actual && actual.abierto) {
                    document.dispatchEvent(new CustomEvent('auralis-kanban:detalle-cerrado', {
                        detail: { board: ctx.board, id: actual.id }
                    }));
                }
                m.zona.textContent = '';
                m.alertas.textContent = '';
                if (!actual) {
                    return;
                }
                // foco de vuelta a la tarjeta de origen (su elemento pudo reemplazarse al repintar)
                const tarjeta = buscarTarjeta(actual.id);
                if (tarjeta && tarjeta.el && tarjeta.el.isConnected) {
                    if (esMovil() && tarjeta.col.refs && !tarjeta.col.refs.sec.classList.contains('zk-columna-activa')) {
                        activarColumnaMovil(tarjeta.col.id, false);
                    }
                    enfocarTarjeta(tarjeta);
                }
            });
            return m;
        }

        function mostrarAlertaDetalle(m, mensaje) {
            m.alertas.textContent = '';
            const alerta = crear('div', 'alert alert-danger d-flex align-items-start gap-2');
            alerta.setAttribute('role', 'alert');
            alerta.appendChild(icono('fa-solid fa-triangle-exclamation'));
            alerta.appendChild(crear('span', 'flex-grow-1', mensaje));
            m.alertas.appendChild(alerta);
        }

        function pintarCargandoDetalle(m) {
            m.zona.textContent = '';
            const caja = crear('div', 'd-flex align-items-center justify-content-center gap-2 py-5');
            caja.setAttribute('role', 'status');
            const spinner = crear('span', 'spinner-border');
            spinner.setAttribute('aria-hidden', 'true');
            caja.appendChild(spinner);
            caja.appendChild(crear('span', '', t('cargando')));
            m.zona.appendChild(caja);
        }

        function limpiarErroresDetalle(m) {
            m.alertas.textContent = '';
            m.zona.querySelectorAll('.is-invalid').forEach(function (control) {
                control.classList.remove('is-invalid');
                control.removeAttribute('aria-invalid');
            });
            m.zona.querySelectorAll('[data-error-for]').forEach(function (aviso) {
                aviso.textContent = '';
                aviso.classList.remove('d-block');
            });
        }

        // Búsqueda por comparación (sin construir selectores con datos del servidor).
        function porAtributo(raiz, atributo, valor) {
            return Array.prototype.filter.call(raiz.querySelectorAll('[' + atributo + ']'), function (nodo) {
                return nodo.getAttribute(atributo) === valor;
            });
        }

        function marcarErroresDetalle(m, errores) {
            let primero = null;
            Object.keys(errores).forEach(function (nombre) {
                porAtributo(m.zona, 'name', nombre).forEach(function (control) {
                    control.classList.add('is-invalid');
                    control.setAttribute('aria-invalid', 'true');
                    if (!primero) {
                        primero = control;
                    }
                });
                porAtributo(m.zona, 'data-error-for', nombre).forEach(function (aviso) {
                    aviso.textContent = String(errores[nombre]);
                    aviso.classList.add('d-block');
                });
            });
            if (primero && typeof primero.focus === 'function') {
                primero.focus();
            }
        }

        // Bloquea o libera los botones de cierre (mientras se guarda).
        function bloquearCierre(m, bloqueado) {
            m.x.disabled = bloqueado;
            m.cerrar.disabled = bloqueado;
        }

        // El proveedor no ofrece el detalle: se cierra el modal y se abre la ficha de la tarjeta.
        // Devuelve false si no hay ficha a la que ir (el modal se queda con el aviso).
        function irAFichaDesdeDetalle(m, id) {
            const tarjeta = buscarTarjeta(id);
            if (!tarjeta || !urlSegura(tarjeta.d.url)) {
                return false;
            }
            m.instancia.hide();
            abrirFicha(tarjeta);
            return true;
        }

        async function abrirDetalle(id) {
            id = String(id);
            const respaldo = buscarTarjeta(id);
            // Detalle modal opcional: si el tablero no lo pide (o no hay Bootstrap), se abre la ficha.
            if (ctx.detalle !== 'modal' || !window.bootstrap || !window.bootstrap.Modal) {
                if (respaldo) {
                    abrirFicha(respaldo);
                }
                return;
            }
            if (ctx.detalleActual) {
                return;
            }
            if (!ctx.modalDetalle) {
                ctx.modalDetalle = crearModalDetalle();
            }
            const m = ctx.modalDetalle;
            const sesion = { id: id, editable: false, guardando: false, abierto: false };
            ctx.detalleActual = sesion;
            bloquearCierre(m, false);

            m.titulo.textContent = t('detalle-titulo');
            m.subtitulo.textContent = '';
            m.subtitulo.hidden = true;
            m.enlace.hidden = true;
            m.enlace.removeAttribute('href');
            m.guardar.hidden = true;
            m.guardar.disabled = false;
            m.guardar.removeAttribute('aria-busy');
            m.alertas.textContent = '';
            m.dialogo.classList.remove('modal-xl');
            m.dialogo.classList.add('modal-lg');
            pintarCargandoDetalle(m);
            m.instancia.show();

            let respuesta;
            try {
                respuesta = await enviar({ action: 'kanban-detail', id: id });
            } catch (error) {
                if (ctx.detalleActual === sesion) {
                    if (esProhibido(error) && irAFichaDesdeDetalle(m, id)) {
                        return;
                    }
                    m.zona.textContent = '';
                    mostrarAlertaDetalle(m, t('detalle-error'));
                }
                return;
            }
            if (ctx.detalleActual !== sesion) {
                return;
            }
            if (esProhibido(respuesta) && irAFichaDesdeDetalle(m, id)) {
                return;
            }
            if (respuesta.status !== 'ok' || typeof respuesta.html !== 'string') {
                m.zona.textContent = '';
                mostrarAlertaDetalle(m, respuesta.message || t('detalle-error'));
                return;
            }

            m.titulo.textContent = respuesta.titulo ? String(respuesta.titulo) : t('detalle-titulo');
            if (respuesta.subtitulo) {
                m.subtitulo.textContent = String(respuesta.subtitulo);
                m.subtitulo.hidden = false;
            }
            const ficha = urlSegura(respuesta.url);
            if (ficha) {
                m.enlace.href = ficha;
                m.enlace.hidden = false;
            }
            m.dialogo.classList.remove('modal-lg', 'modal-xl');
            m.dialogo.classList.add(respuesta.tamano === 'xl' ? 'modal-xl' : 'modal-lg');
            sesion.editable = !!respuesta.editable && ctx.puedeMover;
            m.guardar.hidden = !sesion.editable;
            insertarHtml(m.zona, respuesta.html);
            sesion.abierto = true;
            // «cuerpo» es el contenedor del contenido nuevo de esta apertura (no todo el modal-body)
            document.dispatchEvent(new CustomEvent('auralis-kanban:detalle-abierto', {
                detail: { board: ctx.board, id: id, cuerpo: m.zona }
            }));
        }

        // Controles del formulario cuyo valor difiere del inicial (defaultValue, defaultChecked, defaultSelected).
        function recogerCambios(form) {
            const datos = new FormData();
            let cantidad = 0;
            Array.prototype.forEach.call(form.elements, function (control) {
                const nombre = control.name;
                if (!nombre || control.disabled) {
                    return;
                }
                const etiqueta = control.localName;
                if (etiqueta === 'textarea') {
                    if (control.value !== control.defaultValue) {
                        datos.append(nombre, control.value);
                        cantidad++;
                    }
                } else if (etiqueta === 'select') {
                    const opciones = Array.prototype.slice.call(control.options);
                    let cambiado;
                    if (control.multiple) {
                        cambiado = opciones.some(function (opcion) { return opcion.selected !== opcion.defaultSelected; });
                    } else {
                        // sin ninguna opción marcada de origen, el navegador selecciona la primera
                        let inicial = opciones.findIndex(function (opcion) { return opcion.defaultSelected; });
                        if (inicial === -1) {
                            inicial = 0;
                        }
                        cambiado = control.selectedIndex !== inicial;
                    }
                    if (cambiado) {
                        opciones.forEach(function (opcion) {
                            if (opcion.selected) {
                                datos.append(nombre, opcion.value);
                            }
                        });
                        cantidad++;
                    }
                } else if (etiqueta === 'input') {
                    const tipo = String(control.type).toLowerCase();
                    if (tipo === 'checkbox') {
                        if (control.checked !== control.defaultChecked) {
                            datos.append(nombre, control.checked ? '1' : '0');
                            cantidad++;
                        }
                    } else if (tipo === 'radio') {
                        if (control.checked && !control.defaultChecked) {
                            datos.append(nombre, control.value);
                            cantidad++;
                        }
                    } else if (tipo !== 'file' && tipo !== 'submit' && tipo !== 'button' && tipo !== 'reset' && tipo !== 'image') {
                        if (control.value !== control.defaultValue) {
                            datos.append(nombre, control.value);
                            cantidad++;
                        }
                    }
                }
            });
            return { datos: datos, cantidad: cantidad };
        }

        async function guardarDetalle() {
            const sesion = ctx.detalleActual;
            const m = ctx.modalDetalle;
            if (!sesion || !m || sesion.guardando || !sesion.editable) {
                return;
            }
            const form = m.zona.querySelector('form[data-kanban-detalle-form]');
            if (!form) {
                return;
            }
            limpiarErroresDetalle(m);

            // Solo viajan los campos cuyo valor difiere del inicial.
            const cambios = recogerCambios(form);
            const textoSinCambios = t('sin-cambios');
            if (cambios.cantidad === 0 && textoSinCambios) {
                anunciar(textoSinCambios);
                mostrarToast({ mensaje: textoSinCambios, cerrarTexto: t('cerrar') });
                m.instancia.hide();
                return;
            }
            // (sin la clave i18n se pregunta al servidor, que responde ok con su propio mensaje)

            sesion.guardando = true;
            m.guardar.disabled = true;
            m.guardar.setAttribute('aria-busy', 'true');
            bloquearCierre(m, true);

            function terminar() {
                sesion.guardando = false;
                m.guardar.disabled = false;
                m.guardar.removeAttribute('aria-busy');
                bloquearCierre(m, false);
            }

            let respuesta;
            try {
                respuesta = await enviar({ action: 'kanban-detail-save', id: sesion.id, formulario: cambios.datos });
            } catch (error) {
                terminar();
                if (ctx.detalleActual === sesion) {
                    mostrarAlertaDetalle(m, t('error-red'));
                } else {
                    mostrarToast({ mensaje: t('error-red'), error: true, cerrarTexto: t('cerrar') });
                }
                return;
            }
            terminar();

            if (respuesta.status !== 'ok') {
                if (ctx.detalleActual !== sesion) {
                    // el modal ya no está: el error no puede perderse
                    mostrarToast({ mensaje: respuesta.message || t('error-red'), error: true, cerrarTexto: t('cerrar') });
                    return;
                }
                mostrarAlertaDetalle(m, respuesta.message || t('error-red'));
                if (respuesta.errores && typeof respuesta.errores === 'object' && !Array.isArray(respuesta.errores)) {
                    marcarErroresDetalle(m, respuesta.errores);
                }
                return;
            }

            const tarjeta = aplicarTarjeta(respuesta.card);
            aplicarConteos(respuesta.counts);
            const mensaje = respuesta.message || t('guardado');
            anunciar(mensaje);
            mostrarToast({ mensaje: mensaje, cerrarTexto: t('cerrar') });
            document.dispatchEvent(new CustomEvent('auralis-kanban:detalle-guardado', {
                detail: { board: ctx.board, id: sesion.id, card: respuesta.card || (tarjeta ? tarjeta.d : null) }
            }));
            if (ctx.detalleActual === sesion) {
                m.instancia.hide();
            }
        }

        document.addEventListener('auralis-kanban:tarjeta-actualizada', function (evento) {
            const detalle = evento && evento.detail ? evento.detail : null;
            if (!detalle || detalle.id === undefined || detalle.id === null) {
                return;
            }
            if (detalle.board && String(detalle.board) !== ctx.board) {
                return;
            }
            refrescarTarjeta(detalle.id);
        });

        // --- clic y teclado

        function abrirFicha(tarjeta) {
            const destino = urlSegura(tarjeta.d.url);
            if (destino) {
                window.location.href = destino;
            }
        }

        // Según data-detail: modal (si Bootstrap está disponible) o navegar a la ficha.
        function abrirTarjeta(tarjeta) {
            if (ctx.detalle === 'modal' && window.bootstrap && window.bootstrap.Modal) {
                abrirDetalle(tarjeta.id);
            } else {
                abrirFicha(tarjeta);
            }
        }

        function tarjetaDeElemento(nodo) {
            const el = nodo && nodo.closest ? nodo.closest('.zk-tarjeta') : null;
            return el ? buscarTarjeta(el.dataset.id) : null;
        }

        function alHacerClic(evento) {
            const tarjeta = tarjetaDeElemento(evento.target);
            if (!tarjeta || evento.target.closest('[data-kanban-menu]')) {
                return;
            }
            if (Date.now() - ctx.ultimoArrastre < 400) {
                return;
            }
            const enlace = evento.target.closest('a');
            if (enlace || evento.target.closest('button')) {
                // En modo modal, el clic simple en el código abre el detalle; con modificadores navega (nueva pestaña, etc.).
                const simple = evento.button === 0 && !evento.ctrlKey && !evento.metaKey && !evento.shiftKey && !evento.altKey;
                if (ctx.detalle === 'modal' && enlace && enlace.classList.contains('zk-codigo') && simple) {
                    evento.preventDefault();
                    abrirTarjeta(tarjeta);
                }
                return;
            }
            abrirTarjeta(tarjeta);
        }

        function enfocarTarjeta(tarjeta) {
            if (!tarjeta || !tarjeta.el) {
                return;
            }
            tarjeta.col.focoId = tarjeta.id;
            refrescarTabindex(tarjeta.col);
            tarjeta.el.focus();
        }

        function columnaVecina(col, paso, soloConTarjetas, soloDestinos) {
            const lista = visibles();
            let i = lista.indexOf(col) + paso;
            while (i >= 0 && i < lista.length) {
                const candidata = lista[i];
                // al recorrer tarjetas se salta una columna plegada a mano (sus tarjetas no se ven; en móvil no hay franjas)
                const plegada = soloConTarjetas && candidata.plegadaManual && !esMovil();
                if ((!soloConTarjetas || candidata.cards.length) && (!soloDestinos || candidata.allowDrop) && !plegada) {
                    return candidata;
                }
                i += paso;
            }
            return null;
        }

        function marcarDestinoTeclado(col) {
            // un destino vacío y plegado se abre para ver dónde caerá la tarjeta
            if (col && col.allowDrop) {
                abrirColumna(col, false);
            }
            ctx.columnas.forEach(function (otra) {
                if (otra.refs) {
                    otra.refs.sec.classList.toggle('zk-destino-sobre', !!col && !!ctx.elevada && otra === col && col !== ctx.elevada.tarjeta.col);
                }
            });
        }

        function anunciarPosicion(tarjeta, col) {
            const mismo = tarjeta.col === col;
            const posicion = mismo ? col.cards.indexOf(tarjeta) + 1 : indiceOrden(col, tarjeta) + 1;
            const total = col.cards.length + (mismo ? 0 : 1);
            anunciar(t('anuncio-posicion', {
                codigo: tarjeta.d.code || tarjeta.d.title || tarjeta.id, columna: col.title, pos: posicion, total: total
            }));
        }

        function soltarElevada(cancelar) {
            const e = ctx.elevada;
            if (!e) {
                return;
            }
            ctx.elevada = null;
            e.tarjeta.el.classList.remove('zk-levantada');
            marcarDestinoTeclado(null);
            ctx.columnas.forEach(function (col) {
                if (col.refs) {
                    col.refs.sec.classList.remove('zk-destino-sobre');
                }
            });
            if (cancelar) {
                anunciar(t('anuncio-cancelado'));
                e.tarjeta.el.focus();
                return;
            }
            if (e.destino !== e.tarjeta.col) {
                mover(e.tarjeta.id, e.destino.id);
            } else {
                e.tarjeta.el.focus();
            }
        }

        function alTeclear(evento) {
            const tarjeta = tarjetaDeElemento(evento.target);
            if (!tarjeta || evento.target !== tarjeta.el) {
                return;
            }
            const col = tarjeta.col;
            const tecla = evento.key;

            if (ctx.elevada) {
                if (tecla === 'Escape') {
                    evento.preventDefault();
                    soltarElevada(true);
                } else if (tecla === ' ' || tecla === 'Enter') {
                    evento.preventDefault();
                    soltarElevada(false);
                } else if (tecla === 'ArrowLeft' || tecla === 'ArrowRight') {
                    evento.preventDefault();
                    const vecina = columnaVecina(ctx.elevada.destino, tecla === 'ArrowRight' ? 1 : -1, false, true);
                    if (vecina) {
                        ctx.elevada.destino = vecina;
                        marcarDestinoTeclado(vecina);
                        if (esMovil()) {
                            activarColumnaMovil(vecina.id, true);
                        }
                        anunciarPosicion(tarjeta, vecina);
                    }
                } else if (tecla === 'ArrowUp' || tecla === 'ArrowDown') {
                    evento.preventDefault();
                }
                return;
            }

            if (tecla === 'ArrowDown' || tecla === 'ArrowUp') {
                evento.preventDefault();
                const indice = col.cards.indexOf(tarjeta) + (tecla === 'ArrowDown' ? 1 : -1);
                if (col.cards[indice]) {
                    enfocarTarjeta(col.cards[indice]);
                    anunciarPosicion(col.cards[indice], col);
                }
            } else if (tecla === 'ArrowRight' || tecla === 'ArrowLeft') {
                evento.preventDefault();
                const vecina = columnaVecina(col, tecla === 'ArrowRight' ? 1 : -1, true, false);
                if (vecina) {
                    const indice = Math.min(col.cards.indexOf(tarjeta), vecina.cards.length - 1);
                    if (esMovil()) {
                        activarColumnaMovil(vecina.id, true);
                    }
                    enfocarTarjeta(vecina.cards[indice]);
                    anunciarPosicion(vecina.cards[indice], vecina);
                }
            } else if (tecla === 'Enter') {
                evento.preventDefault();
                abrirTarjeta(tarjeta);
            } else if (tecla === ' ') {
                evento.preventDefault();
                if (!ctx.puedeMover || tarjeta.d.locked || tarjeta.pendiente) {
                    return;
                }
                ctx.elevada = { tarjeta: tarjeta, destino: col };
                tarjeta.el.classList.add('zk-levantada');
                anunciar(t('anuncio-levantada', { codigo: tarjeta.d.code || tarjeta.d.title || tarjeta.id }));
            }
        }

        function alEnfocar(evento) {
            const tarjeta = tarjetaDeElemento(evento.target);
            if (tarjeta && evento.target === tarjeta.el) {
                tarjeta.col.focoId = tarjeta.id;
                refrescarTabindex(tarjeta.col);
            }
        }

        function alSalirFoco(evento) {
            if (ctx.elevada && evento.target === ctx.elevada.tarjeta.el && !ctx.raiz.contains(evento.relatedTarget)) {
                soltarElevada(true);
            }
        }

        // --- orden: «Ordenar por» e invertir (el control lo pinta _kanban_orden.html.twig fuera de la raíz)

        const DIRECCIONES = ['asc', 'desc'];

        function opcionOrden(ui, clave) {
            return Array.prototype.find.call(ui.select.options, function (opcion) { return opcion.value === clave; }) || null;
        }

        function dirDeOpcion(opcion) {
            return opcion && DIRECCIONES.indexOf(opcion.dataset.dir) !== -1 ? opcion.dataset.dir : 'desc';
        }

        // Se recuerda por tablero y usuario en este navegador (localStorage, siempre con try/catch).
        function claveOrdenGuardado(ui) {
            return 'auralis-kanban-orden-' + ctx.board + (ui.usuario ? '-' + ui.usuario : '');
        }

        function ordenGuardado(ui) {
            try {
                const guardado = JSON.parse(window.localStorage.getItem(claveOrdenGuardado(ui)) || 'null');
                const opcion = guardado && typeof guardado.clave === 'string' ? opcionOrden(ui, guardado.clave) : null;
                if (opcion) {
                    return { clave: opcion.value, dir: DIRECCIONES.indexOf(guardado.dir) !== -1 ? guardado.dir : dirDeOpcion(opcion) };
                }
            } catch (error) {
                // sin almacenamiento: vale el de la URL o el de por defecto
            }
            return null;
        }

        function guardarOrden(ui) {
            try {
                window.localStorage.setItem(claveOrdenGuardado(ui), JSON.stringify(ctx.orden));
            } catch (error) {
                // sin almacenamiento: el criterio dura hasta recargar
            }
        }

        function pintarOrden(ui) {
            ui.select.value = ctx.orden.clave;
            if (ui.campoDir) {
                ui.campoDir.value = ctx.orden.dir;
            }
            const texto = t('orden-invertir', { sentido: t('orden-' + ctx.orden.dir) });
            ui.boton.setAttribute('aria-label', texto);
            ui.boton.title = texto;
            const marca = ui.boton.querySelector('i');
            if (marca) {
                marca.className = 'fa-solid fa-fw ' + (ctx.orden.dir === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short');
            }
        }

        // El criterio viaja también en la URL (compartir el enlace o recargar dan el mismo orden).
        function ordenEnUrl() {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('orden', ctx.orden.clave);
                url.searchParams.set('dir', ctx.orden.dir);
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (error) {
                // sin History API: el criterio sigue en el formulario de filtros y en el navegador
            }
        }

        async function cambiarOrden(ui, clave, dir) {
            if (ctx.elevada) {
                soltarElevada(true);
            }
            ctx.orden = { clave: clave, dir: dir };
            pintarOrden(ui);
            guardarOrden(ui);
            ordenEnUrl();
            // primera página de todas las columnas con el nuevo orden (la paginación vuelve a empezar)
            await cargar();
            const opcion = opcionOrden(ui, clave);
            anunciar(t('orden-anuncio', { criterio: opcion ? opcion.textContent.trim() : clave, sentido: t('orden-' + dir) }));
        }

        function iniciarOrden() {
            let raizOrden = null;
            document.querySelectorAll('[data-kanban-orden]').forEach(function (el) {
                if (!raizOrden && el.dataset.board === ctx.board) {
                    raizOrden = el;
                }
            });
            if (!raizOrden) {
                return;
            }
            const ui = {
                select: raizOrden.querySelector('[data-kanban-orden-select]'),
                boton: raizOrden.querySelector('[data-kanban-orden-dir]'),
                campoDir: raizOrden.querySelector('[data-kanban-orden-dir-campo]'),
                usuario: raizOrden.dataset.usuario || ''
            };
            if (!ui.select || !ui.boton || ui.select.options.length < 2) {
                return;
            }

            // URL (ya validada por el servidor) > recordado en el navegador > por defecto
            let opcion = opcionOrden(ui, raizOrden.dataset.orden || '') || ui.select.options[0];
            let dir = DIRECCIONES.indexOf(raizOrden.dataset.dir) !== -1 ? raizOrden.dataset.dir : dirDeOpcion(opcion);
            let elegido = { clave: opcion.value, dir: dir };
            if (raizOrden.dataset.ordenUrl !== '1') {
                elegido = ordenGuardado(ui) || elegido;
            }
            ctx.orden = elegido;
            ctx.ordenUi = ui;
            pintarOrden(ui);

            ui.select.addEventListener('change', function () {
                opcion = opcionOrden(ui, ui.select.value);
                if (opcion) {
                    // cada criterio empieza en su dirección por defecto
                    cambiarOrden(ui, opcion.value, dirDeOpcion(opcion));
                }
            });
            ui.boton.addEventListener('click', function () {
                cambiarOrden(ui, ctx.orden.clave, ctx.orden.dir === 'asc' ? 'desc' : 'asc');
            });
        }

        iniciarOrden();

        ctx.raiz.addEventListener('click', alHacerClic);
        ctx.raiz.addEventListener('keydown', alTeclear);
        ctx.raiz.addEventListener('focusin', alEnfocar);
        ctx.raiz.addEventListener('focusout', alSalirFoco);

        return { cargar: cargar, refrescarTarjeta: refrescarTarjeta, abrirDetalle: abrirDetalle };
    }

    function init(raiz) {
        if (!raiz || raiz.__auralisKanban) {
            return raiz ? raiz.__auralisKanban : null;
        }
        const tablero = crearTablero(raiz);
        raiz.__auralisKanban = tablero;
        tablero.cargar();
        return tablero;
    }

    // Tablero de la página (o el de la raíz indicada), ya inicializado.
    function tableroDe(raiz) {
        const el = raiz || document.getElementById('auralis-kanban');
        return el && el.__auralisKanban ? el.__auralisKanban : null;
    }

    function refrescarTarjeta(id, raiz) {
        const tablero = tableroDe(raiz);
        return tablero ? tablero.refrescarTarjeta(id) : Promise.resolve(false);
    }

    function abrirDetalle(id, raiz) {
        const tablero = tableroDe(raiz);
        return tablero ? tablero.abrirDetalle(id) : Promise.resolve();
    }

    window.AuralisKanban = { init: init, refrescarTarjeta: refrescarTarjeta, abrirDetalle: abrirDetalle };

    document.addEventListener('DOMContentLoaded', function () {
        const raiz = document.getElementById('auralis-kanban');
        if (raiz) {
            init(raiz);
        }
    });
})();
