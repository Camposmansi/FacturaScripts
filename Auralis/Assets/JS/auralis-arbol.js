/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Visor de árbol AuralisArbol en SVG puro y sin librerías: colocación por huecos, zoom y pellizco con Pointer Events, exportar SVG e imprimir.
 */

(function () {
    'use strict';

    // El partial puede incluirse varias veces: la segunda carga solo monta los contenedores nuevos.
    if (window.AuralisArbol) {
        window.AuralisArbol.montar();
        return;
    }

    const NS = 'http://www.w3.org/2000/svg';
    const ZOOM_MIN = 0.1;
    const ZOOM_MAX = 3;
    const ARRASTRE_MIN = 4;
    // Medidas para una fuente de 14px; se escalan con --auralis-font-size-base.
    const BASE = {ancho: 232, alto: 92, sepX: 20, sepY: 56, margen: 24};
    const medidor = document.createElement('canvas').getContext('2d');

    function svgEl(tag, attrs, padre) {
        const n = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach((k) => {
            if (attrs[k] !== null && attrs[k] !== undefined) {
                n.setAttribute(k, attrs[k]);
            }
        });
        if (padre) {
            padre.appendChild(n);
        }
        return n;
    }

    function svgTexto(attrs, texto, padre) {
        const n = svgEl('text', attrs, padre);
        n.textContent = texto;
        return n;
    }

    function htmlEl(tag, clase, texto, padre) {
        const n = document.createElement(tag);
        if (clase) {
            n.className = clase;
        }
        if (texto) {
            n.textContent = texto;
        }
        if (padre) {
            padre.appendChild(n);
        }
        return n;
    }

    function limitar(v, min, max) {
        return Math.min(max, Math.max(min, v));
    }

    function numero(v) {
        try {
            return new Intl.NumberFormat(document.documentElement.lang || undefined, {maximumFractionDigits: 4}).format(v);
        } catch (e) {
            return String(v);
        }
    }

    function escaparHtml(t) {
        return String(t).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;'}[c]));
    }

    class Visor {
        constructor(contenedor, opciones) {
            this.c = contenedor;
            let cfg = {};
            const json = contenedor.querySelector('script.auralis-arbol-config');
            if (json) {
                try {
                    cfg = JSON.parse(json.textContent || '{}');
                } catch (e) {
                    cfg = {};
                }
            }
            this.cfg = Object.assign({url: 'ArbolAuralis', textos: {}}, cfg, opciones || {});
            this.t = this.cfg.textos || {};
            this.estado = {
                proveedor: String(this.cfg.proveedor || ''),
                id: String(this.cfg.id || ''),
                modo: this.cfg.modo === 'implosion' ? 'implosion' : 'explosion',
                profundidad: parseInt(this.cfg.profundidad, 10) || 0
            };
            this.vista = {x: 0, y: 0, k: 1};
            this.plegados = new Set();
            this.punteros = new Map();
            this.nodos = new Map();
            this.raiz = null;
            this.seleccionado = null;
            this.pendienteAjuste = false;

            this.lienzo = contenedor.querySelector('.auralis-arbol-lienzo') || htmlEl('div', 'auralis-arbol-lienzo', '', contenedor);
            this.svg = svgEl('svg', {xmlns: NS, role: 'img', 'aria-label': this.cfg.titulo || ''}, this.lienzo);
            this.gVista = svgEl('g', {class: 'za-vista'}, this.svg);
            this.gContenido = svgEl('g', {class: 'za-contenido'}, this.gVista);
            this.gAristas = svgEl('g', {class: 'za-aristas'}, this.gContenido);
            this.gNodos = svgEl('g', {class: 'za-nodos'}, this.gContenido);
            this.estadoEl = contenedor.querySelector('.auralis-arbol-estado') || htmlEl('div', 'auralis-arbol-estado', '', contenedor);
            this.avisoEl = contenedor.querySelector('.auralis-arbol-aviso');
            this.detalleEl = contenedor.querySelector('.auralis-arbol-detalle');

            this.enlazarBarra();
            this.enlazarPunteros();
            if (window.ResizeObserver) {
                new ResizeObserver(() => {
                    if (this.pendienteAjuste && this.svg.clientWidth > 0) {
                        this.ajustar();
                    }
                }).observe(this.lienzo);
            }
            contenedor.__auralisArbol = this;
            this.cargar();
        }

        // ---------- Datos ----------

        cargar() {
            if (!this.estado.proveedor || !this.estado.id) {
                this.mostrarEstado(this.t.vacio || '');
                return;
            }
            if (this.peticion) {
                this.peticion.abort();
            }
            this.peticion = window.AbortController ? new AbortController() : null;
            this.mostrarEstado(this.t.cargando || '…');
            this.mostrarAviso('');
            this.actualizarBarra();

            const p = new URLSearchParams({
                proveedor: this.estado.proveedor,
                id: this.estado.id,
                modo: this.estado.modo,
                profundidad: String(this.estado.profundidad)
            });
            const url = this.cfg.url + (this.cfg.url.indexOf('?') >= 0 ? '&' : '?') + p.toString();
            fetch(url, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
                signal: this.peticion ? this.peticion.signal : undefined
            })
                .then((r) => r.json().catch(() => ({ok: false})))
                .then((datos) => {
                    if (!datos || !datos.ok) {
                        this.construir([]);
                        this.mostrarEstado((datos && datos.error) || this.t.error || '');
                        return;
                    }
                    this.construir(datos.nodos || []);
                    this.dibujar();
                    this.ajustar();
                    this.mostrarEstado(this.raiz ? '' : (this.t.vacio || ''));
                    if (datos.truncado) {
                        this.mostrarAviso(String(this.t.truncado || '').replace('%max%', datos.maxNodos));
                    }
                })
                .catch((e) => {
                    if (e && e.name === 'AbortError') {
                        return;
                    }
                    this.construir([]);
                    this.mostrarEstado(this.t.error || '');
                });
        }

        construir(lista) {
            this.nodos.clear();
            this.raiz = null;
            this.seleccionado = null;
            this.cerrarDetalle();
            lista.forEach((n) => {
                if (n && n.id !== undefined && !this.nodos.has(String(n.id))) {
                    this.nodos.set(String(n.id), Object.assign({}, n, {id: String(n.id), hijos: []}));
                }
            });
            this.nodos.forEach((n) => {
                const padre = n.padre === null || n.padre === undefined ? null : this.nodos.get(String(n.padre));
                if (padre) {
                    padre.hijos.push(n);
                } else if (!this.raiz && (n.padre === null || n.padre === undefined)) {
                    this.raiz = n;
                }
            });
            if (!this.raiz) {
                this.gAristas.textContent = '';
                this.gNodos.textContent = '';
            }
        }

        // ---------- Colocación y dibujo ----------

        medidas() {
            const fs = parseFloat(getComputedStyle(this.c).fontSize) || 14;
            const s = limitar(fs / 14, 0.8, 1.6);
            const m = {s: s};
            Object.keys(BASE).forEach((k) => {
                m[k] = Math.round(BASE[k] * s);
            });
            return m;
        }

        visibles(n) {
            return this.plegados.has(n.id) ? [] : n.hijos;
        }

        // Cada hoja ocupa el siguiente hueco; el padre se centra sobre su primer y último hijo.
        colocar(m) {
            let hueco = 0;
            const paso = (n, nivel) => {
                n.y = nivel * (m.alto + m.sepY);
                const hijos = this.visibles(n);
                if (!hijos.length) {
                    n.x = hueco * (m.ancho + m.sepX);
                    hueco++;
                    return;
                }
                hijos.forEach((h) => paso(h, nivel + 1));
                n.x = (hijos[0].x + hijos[hijos.length - 1].x) / 2;
            };
            paso(this.raiz, 0);
        }

        fuentes() {
            const f = {};
            ['za-etiqueta', 'za-subtitulo', 'za-badge-texto'].forEach((clase) => {
                const t = svgTexto({class: clase}, 'x', this.gNodos);
                const cs = getComputedStyle(t);
                f[clase] = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
                t.remove();
            });
            return f;
        }

        // Recorte medido con canvas (sin forzar layout del SVG por cada nodo).
        recortar(texto, max, fuente) {
            texto = String(texto || '');
            medidor.font = fuente;
            if (medidor.measureText(texto).width <= max) {
                return texto;
            }
            let lo = 0;
            let hi = texto.length;
            while (lo < hi) {
                const mid = Math.ceil((lo + hi) / 2);
                if (medidor.measureText(texto.slice(0, mid) + '…').width <= max) {
                    lo = mid;
                } else {
                    hi = mid - 1;
                }
            }
            return texto.slice(0, lo).trimEnd() + '…';
        }

        dibujar() {
            this.gAristas.textContent = '';
            this.gNodos.textContent = '';
            if (!this.raiz) {
                return;
            }
            const m = this.medidas();
            const f = this.fuentes();
            this.m = m;
            this.colocar(m);

            const pila = [this.raiz];
            while (pila.length) {
                const n = pila.pop();
                this.visibles(n).forEach((h) => {
                    const x1 = n.x + m.ancho / 2;
                    const y1 = n.y + m.alto;
                    const ym = y1 + m.sepY / 2;
                    svgEl('path', {
                        class: 'za-arista',
                        d: 'M' + x1 + ' ' + y1 + 'V' + ym + 'H' + (h.x + m.ancho / 2) + 'V' + h.y
                    }, this.gAristas);
                    pila.push(h);
                });
                this.tarjeta(n, m, f);
            }
        }

        tarjeta(n, m, f) {
            const s = m.s;
            const conAlerta = n.alerta !== null && n.alerta !== undefined;
            const g = svgEl('g', {
                class: 'za-nodo' + (conAlerta ? ' za-con-alerta' : '') + (this.seleccionado === n.id ? ' za-seleccionado' : ''),
                transform: 'translate(' + n.x + ',' + n.y + ')',
                tabindex: 0,
                role: 'button',
                'data-id': n.id,
                'aria-label': [n.etiqueta, n.subtitulo].filter(Boolean).join(' · ')
            }, this.gNodos);
            svgEl('title', {}, g).textContent = [n.etiqueta, n.subtitulo, conAlerta ? n.alerta : ''].filter(Boolean).join('\n');
            svgEl('rect', {class: 'za-tarjeta', width: m.ancho, height: m.alto, rx: Math.round(8 * s)}, g);
            const tipo = (n.badges && n.badges[0] && n.badges[0].tipo) || 'primary';
            svgEl('rect', {class: 'za-franja za-' + tipo, x: 0, y: 0, width: Math.round(4 * s), height: m.alto, rx: Math.round(2 * s)}, g);

            const izq = Math.round(14 * s);
            let anchoEtiqueta = m.ancho - izq * 2;
            if (n.cantidad !== null && n.cantidad !== undefined) {
                const cant = '× ' + numero(n.cantidad);
                medidor.font = f['za-etiqueta'];
                anchoEtiqueta -= medidor.measureText(cant).width + 8 * s;
                svgTexto({class: 'za-cantidad', x: m.ancho - Math.round(10 * s), y: Math.round(22 * s), 'text-anchor': 'end'}, cant, g);
            }
            svgTexto({class: 'za-etiqueta', x: izq, y: Math.round(22 * s)}, this.recortar(n.etiqueta, anchoEtiqueta, f['za-etiqueta']), g);
            if (n.subtitulo) {
                svgTexto({class: 'za-subtitulo', x: izq, y: Math.round(41 * s)}, this.recortar(n.subtitulo, m.ancho - izq * 2, f['za-subtitulo']), g);
            }
            const valores = Object.keys(n.valores || {}).slice(0, 2).map((k) => k + ': ' + n.valores[k]).join(' · ');
            if (valores) {
                svgTexto({class: 'za-valores', x: izq, y: Math.round(59 * s)}, this.recortar(valores, m.ancho - izq * 2, f['za-subtitulo']), g);
            }

            let bx = izq;
            const by = Math.round(68 * s);
            const bh = Math.round(16 * s);
            medidor.font = f['za-badge-texto'];
            (n.badges || []).forEach((b) => {
                if (!b.texto) {
                    return;
                }
                const ancho = Math.ceil(medidor.measureText(b.texto).width + 12 * s);
                if (bx + ancho > m.ancho - izq) {
                    return;
                }
                const gb = svgEl('g', {class: 'za-badge za-' + (b.tipo || 'secondary')}, g);
                svgEl('rect', {class: 'za-badge-fondo', x: bx, y: by, width: ancho, height: bh, rx: Math.round(bh / 2)}, gb);
                svgTexto({class: 'za-badge-texto', x: bx + ancho / 2, y: by + bh / 2, 'text-anchor': 'middle', 'dominant-baseline': 'central'}, b.texto, gb);
                bx += ancho + Math.round(4 * s);
            });

            const r = Math.round(9 * s);
            if (conAlerta) {
                const ga = svgEl('g', {class: 'za-alerta-marca', transform: 'translate(' + (m.ancho - r / 2) + ',' + (r / 2) + ')'}, g);
                svgEl('circle', {r: r}, ga);
                svgTexto({'text-anchor': 'middle', 'dominant-baseline': 'central'}, '!', ga);
            }
            if (n.hijos.length || n.tieneHijos) {
                const plegado = this.plegados.has(n.id);
                const sinCargar = !n.hijos.length;
                const gp = svgEl('g', {
                    class: 'za-control ' + (sinCargar ? 'za-mas' : 'za-plegar'),
                    transform: 'translate(' + (m.ancho / 2) + ',' + m.alto + ')'
                }, g);
                svgEl('title', {}, gp).textContent = sinCargar ? this.t.masNiveles : (plegado ? this.t.expandir : this.t.contraer);
                svgEl('circle', {r: r}, gp);
                svgTexto({'text-anchor': 'middle', 'dominant-baseline': 'central'}, sinCargar ? '…' : (plegado ? '+' : '−'), gp);
            }
        }

        // ---------- Vista: zoom, desplazamiento y ajuste ----------

        aplicarVista() {
            const v = this.vista;
            this.gVista.setAttribute('transform', 'translate(' + v.x + ',' + v.y + ') scale(' + v.k + ')');
        }

        zoomEn(factor, cx, cy) {
            const v = this.vista;
            const k = limitar(v.k * factor, ZOOM_MIN, ZOOM_MAX);
            if (cx === undefined) {
                cx = this.svg.clientWidth / 2;
                cy = this.svg.clientHeight / 2;
            }
            v.x = cx - (cx - v.x) * (k / v.k);
            v.y = cy - (cy - v.y) * (k / v.k);
            v.k = k;
            this.aplicarVista();
        }

        caja() {
            let bb = null;
            try {
                bb = this.gContenido.getBBox();
            } catch (e) {
                bb = null;
            }
            // Contenedor oculto (pestaña inactiva): getBBox devuelve 0, se calcula con la colocación.
            if ((!bb || !bb.width) && this.raiz && this.m) {
                let x0 = Infinity;
                let y0 = Infinity;
                let x1 = -Infinity;
                let y1 = -Infinity;
                const pila = [this.raiz];
                while (pila.length) {
                    const n = pila.pop();
                    x0 = Math.min(x0, n.x);
                    y0 = Math.min(y0, n.y);
                    x1 = Math.max(x1, n.x + this.m.ancho);
                    y1 = Math.max(y1, n.y + this.m.alto);
                    this.visibles(n).forEach((h) => pila.push(h));
                }
                bb = {x: x0, y: y0, width: x1 - x0, height: y1 - y0};
            }
            return bb && bb.width ? bb : null;
        }

        ajustar() {
            const bb = this.caja();
            const cw = this.svg.clientWidth;
            const ch = this.svg.clientHeight;
            if (!bb || !cw || !ch) {
                this.pendienteAjuste = !!this.raiz;
                return;
            }
            this.pendienteAjuste = false;
            const margen = this.m ? this.m.margen : BASE.margen;
            // Hueco arriba para la barra flotante.
            const barra = this.c.querySelector('.auralis-arbol-barra');
            const arriba = barra ? barra.offsetHeight + margen : margen;
            const k = limitar(Math.min((cw - margen * 2) / bb.width, (ch - arriba - margen) / bb.height, 1.25), ZOOM_MIN, ZOOM_MAX);
            this.vista = {
                k: k,
                x: (cw - bb.width * k) / 2 - bb.x * k,
                y: arriba + Math.max(0, (ch - arriba - margen - bb.height * k) / 2) - bb.y * k
            };
            this.aplicarVista();
        }

        alternar(id) {
            const n = this.nodos.get(id);
            if (!n || !n.hijos.length) {
                return;
            }
            const antes = {x: n.x, y: n.y};
            if (this.plegados.has(id)) {
                this.plegados.delete(id);
            } else {
                this.plegados.add(id);
            }
            this.dibujar();
            // El nodo pulsado se queda quieto en pantalla.
            this.vista.x += (antes.x - n.x) * this.vista.k;
            this.vista.y += (antes.y - n.y) * this.vista.k;
            this.aplicarVista();
        }

        // ---------- Interacción ----------

        puntoLocal(e) {
            const r = this.svg.getBoundingClientRect();
            return {x: e.clientX - r.left, y: e.clientY - r.top};
        }

        enlazarPunteros() {
            const svg = this.svg;
            svg.addEventListener('pointerdown', (e) => {
                if (e.pointerType === 'mouse' && e.button !== 0) {
                    return;
                }
                try {
                    svg.setPointerCapture(e.pointerId);
                } catch (err) {
                    // Sin captura el arrastre sigue funcionando dentro del lienzo.
                }
                this.punteros.set(e.pointerId, this.puntoLocal(e));
                if (this.punteros.size === 1) {
                    // Con captura, el click llega al SVG: el objetivo se fija aquí.
                    this.toque = {objetivo: e.target, p: this.puntoLocal(e), movido: false};
                    this.arrastre = {p: this.puntoLocal(e), x: this.vista.x, y: this.vista.y};
                } else {
                    this.toque = null;
                    this.iniciarPellizco();
                }
            });
            svg.addEventListener('pointermove', (e) => {
                if (!this.punteros.has(e.pointerId)) {
                    return;
                }
                const p = this.puntoLocal(e);
                this.punteros.set(e.pointerId, p);
                if (this.punteros.size >= 2 && this.pellizco) {
                    this.moverPellizco();
                    return;
                }
                const a = this.arrastre;
                if (!a) {
                    return;
                }
                const dx = p.x - a.p.x;
                const dy = p.y - a.p.y;
                if (this.toque && !this.toque.movido && Math.hypot(dx, dy) < ARRASTRE_MIN) {
                    return;
                }
                if (this.toque) {
                    this.toque.movido = true;
                }
                svg.classList.add('za-arrastrando');
                this.vista.x = a.x + dx;
                this.vista.y = a.y + dy;
                this.aplicarVista();
            });
            const fin = (e) => {
                if (!this.punteros.has(e.pointerId)) {
                    return;
                }
                this.punteros.delete(e.pointerId);
                if (this.punteros.size < 2) {
                    this.pellizco = null;
                }
                if (this.punteros.size === 1) {
                    // Queda un dedo: el arrastre sigue desde él sin saltos.
                    const p = this.punteros.values().next().value;
                    this.arrastre = {p: p, x: this.vista.x, y: this.vista.y};
                    return;
                }
                svg.classList.remove('za-arrastrando');
                this.arrastre = null;
                if (e.type === 'pointerup' && this.toque && !this.toque.movido) {
                    this.pulsar(this.toque.objetivo);
                }
                this.toque = null;
            };
            svg.addEventListener('pointerup', fin);
            svg.addEventListener('pointercancel', fin);
            svg.addEventListener('wheel', (e) => {
                // Incrustado en una página, la rueda sola desplaza la página (no atrapa el scroll).
                // El pellizco del trackpad llega como rueda con ctrlKey; en pantalla completa, rueda directa.
                if (!e.ctrlKey && !e.metaKey && document.fullscreenElement !== this.c) {
                    this.mostrarPista();
                    return;
                }
                e.preventDefault();
                const p = this.puntoLocal(e);
                const paso = e.deltaMode === 1 ? 0.05 : 0.002;
                this.zoomEn(Math.exp(-e.deltaY * paso), p.x, p.y);
            }, {passive: false});
            svg.addEventListener('keydown', (e) => {
                const nodo = e.target.closest ? e.target.closest('.za-nodo') : null;
                if (!nodo) {
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.seleccionar(nodo.getAttribute('data-id'));
                } else if (e.key === ' ') {
                    e.preventDefault();
                    this.alternar(nodo.getAttribute('data-id'));
                    const otra = this.gNodos.querySelector('[data-id="' + CSS.escape(nodo.getAttribute('data-id')) + '"]');
                    if (otra) {
                        otra.focus();
                    }
                }
            });
        }

        iniciarPellizco() {
            const [a, b] = Array.from(this.punteros.values());
            const centro = {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2};
            this.pellizco = {
                d: Math.hypot(a.x - b.x, a.y - b.y) || 1,
                k: this.vista.k,
                // Punto del contenido bajo el centro del pellizco.
                cx: (centro.x - this.vista.x) / this.vista.k,
                cy: (centro.y - this.vista.y) / this.vista.k
            };
        }

        moverPellizco() {
            const [a, b] = Array.from(this.punteros.values());
            const pz = this.pellizco;
            const k = limitar(pz.k * (Math.hypot(a.x - b.x, a.y - b.y) / pz.d), ZOOM_MIN, ZOOM_MAX);
            this.vista = {
                k: k,
                x: (a.x + b.x) / 2 - pz.cx * k,
                y: (a.y + b.y) / 2 - pz.cy * k
            };
            this.aplicarVista();
        }

        pulsar(objetivo) {
            const el = objetivo && objetivo.closest ? objetivo : null;
            const nodo = el ? el.closest('.za-nodo') : null;
            if (!nodo) {
                this.cerrarDetalle();
                return;
            }
            const id = nodo.getAttribute('data-id');
            if (el.closest('.za-plegar')) {
                this.alternar(id);
            } else if (el.closest('.za-mas')) {
                this.recargar({profundidad: 0});
            } else {
                this.seleccionar(id);
            }
        }

        enlazarBarra() {
            const barra = this.c.querySelector('.auralis-arbol-barra');
            if (!barra) {
                return;
            }
            const pantalla = barra.querySelector('[data-za-accion="pantalla"]');
            if (pantalla && !this.c.requestFullscreen) {
                pantalla.hidden = true;
            }
            barra.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-za-accion]');
                if (!btn) {
                    return;
                }
                const valor = btn.getAttribute('data-za-valor');
                switch (btn.getAttribute('data-za-accion')) {
                    case 'acercar':
                        this.zoomEn(1.25);
                        break;
                    case 'alejar':
                        this.zoomEn(0.8);
                        break;
                    case 'ajustar':
                        this.ajustar();
                        break;
                    case 'modo':
                        if (valor !== this.estado.modo) {
                            this.recargar({modo: valor});
                        }
                        break;
                    case 'profundidad':
                        if (parseInt(valor, 10) !== this.estado.profundidad) {
                            this.recargar({profundidad: parseInt(valor, 10) || 0});
                        }
                        break;
                    case 'svg':
                        this.exportarSvg();
                        break;
                    case 'pdf':
                        this.imprimir();
                        break;
                    case 'pantalla':
                        this.pantallaCompleta();
                        break;
                }
            });
            this.c.addEventListener('fullscreenchange', () => {
                if (pantalla) {
                    const i = pantalla.querySelector('i');
                    if (i) {
                        i.className = document.fullscreenElement === this.c ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
                    }
                }
                window.requestAnimationFrame(() => this.ajustar());
            });
        }

        actualizarBarra() {
            this.c.querySelectorAll('[data-za-accion="modo"], [data-za-accion="profundidad"]').forEach((btn) => {
                const accion = btn.getAttribute('data-za-accion');
                const valor = btn.getAttribute('data-za-valor');
                const activo = accion === 'modo' ? valor === this.estado.modo : parseInt(valor, 10) === this.estado.profundidad;
                btn.classList.toggle('active', activo);
                btn.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
        }

        pantallaCompleta() {
            if (document.fullscreenElement === this.c) {
                document.exitFullscreen();
            } else if (this.c.requestFullscreen) {
                this.c.requestFullscreen().catch(() => null);
            }
        }

        mostrarEstado(texto) {
            this.estadoEl.textContent = texto || '';
            this.estadoEl.hidden = !texto;
        }

        /** Aviso breve «Ctrl + rueda para hacer zoom», dentro del contenedor. */
        mostrarPista() {
            if (!this.t.pistaZoom) {
                return;
            }
            if (!this.pistaEl) {
                this.pistaEl = htmlEl('div', 'auralis-arbol-pista', this.t.pistaZoom, this.c);
                this.pistaEl.setAttribute('aria-hidden', 'true');
            }
            this.pistaEl.classList.add('za-visible');
            clearTimeout(this.pistaTimer);
            this.pistaTimer = setTimeout(() => this.pistaEl.classList.remove('za-visible'), 1200);
        }

        mostrarAviso(texto) {
            if (!this.avisoEl) {
                return;
            }
            this.avisoEl.textContent = texto || '';
            this.avisoEl.hidden = !texto;
        }

        // ---------- Panel de detalle (HTML dentro del contenedor) ----------

        seleccionar(id) {
            const n = this.nodos.get(id);
            if (!n) {
                return;
            }
            this.seleccionado = id;
            this.gNodos.querySelectorAll('.za-seleccionado').forEach((g) => g.classList.remove('za-seleccionado'));
            const g = this.gNodos.querySelector('[data-id="' + CSS.escape(id) + '"]');
            if (g) {
                g.classList.add('za-seleccionado');
            }
            const d = this.detalleEl;
            if (!d) {
                return;
            }
            d.textContent = '';
            const cab = htmlEl('div', 'auralis-arbol-detalle-cabecera', '', d);
            htmlEl('div', 'auralis-arbol-detalle-titulo', n.etiqueta || n.id, cab);
            const cerrar = htmlEl('button', 'btn-close', '', cab);
            cerrar.type = 'button';
            cerrar.setAttribute('aria-label', this.t.cerrar || '');
            cerrar.addEventListener('click', () => this.cerrarDetalle());
            if (n.subtitulo) {
                htmlEl('div', 'auralis-arbol-detalle-subtitulo', n.subtitulo, d);
            }
            if (n.alerta !== null && n.alerta !== undefined) {
                const al = htmlEl('div', 'auralis-arbol-detalle-alerta', '', d);
                htmlEl('i', 'fa-solid fa-triangle-exclamation', '', al).setAttribute('aria-hidden', 'true');
                htmlEl('span', '', ' ' + (n.alerta || this.t.alerta || ''), al);
            }
            const filas = [];
            if (n.cantidad !== null && n.cantidad !== undefined) {
                filas.push([this.t.cantidad || '', numero(n.cantidad)]);
            }
            Object.keys(n.valores || {}).forEach((k) => filas.push([k, n.valores[k]]));
            if (filas.length) {
                const dl = htmlEl('dl', 'auralis-arbol-detalle-valores', '', d);
                filas.forEach((f) => {
                    htmlEl('dt', '', String(f[0]), dl);
                    htmlEl('dd', '', String(f[1]), dl);
                });
            }
            if (n.badges && n.badges.length) {
                const bs = htmlEl('div', 'auralis-arbol-detalle-badges', '', d);
                n.badges.forEach((b) => {
                    const span = htmlEl('span', 'badge text-bg-' + (b.tipo || 'secondary'), '', bs);
                    if (b.icono) {
                        htmlEl('i', 'fa-solid ' + b.icono, '', span).setAttribute('aria-hidden', 'true');
                    }
                    if (b.texto) {
                        span.appendChild(document.createTextNode((b.icono ? ' ' : '') + b.texto));
                    }
                });
            }
            if (n.url) {
                const a = htmlEl('a', 'btn btn-primary', this.t.abrir || '', d);
                a.href = n.url;
            }
            d.hidden = false;
        }

        cerrarDetalle() {
            if (this.detalleEl) {
                this.detalleEl.hidden = true;
                this.detalleEl.textContent = '';
            }
            this.seleccionado = null;
            this.gNodos.querySelectorAll('.za-seleccionado').forEach((g) => g.classList.remove('za-seleccionado'));
        }

        // ---------- API pública ----------

        recargar(cambios) {
            Object.assign(this.estado, cambios || {});
            this.estado.profundidad = parseInt(this.estado.profundidad, 10) || 0;
            this.plegados.clear();
            this.cargar();
        }

        // SVG autónomo: estilos calculados incrustados (selector padre > hijo, porque el color del
        // badge depende del grupo) y sin controles ni selección.
        serializar() {
            const bb = this.caja();
            if (!bb) {
                return '';
            }
            this.cerrarDetalle();
            const props = ['fill', 'fill-opacity', 'stroke', 'stroke-width', 'font-family', 'font-size', 'font-weight'];
            const selector = (e) => {
                const cls = (e.getAttribute('class') || '').trim();
                return cls ? '.' + cls.split(/\s+/).join('.') : e.tagName.toLowerCase();
            };
            const reglas = {};
            this.gContenido.querySelectorAll('*').forEach((e) => {
                if (e.tagName.toLowerCase() === 'title' || e.closest('.za-control')) {
                    return;
                }
                const clave = selector(e.parentNode) + '>' + selector(e);
                if (reglas[clave] === undefined) {
                    const cs = getComputedStyle(e);
                    reglas[clave] = props.map((p) => p + ':' + cs.getPropertyValue(p)).join(';');
                }
            });
            const css = Object.keys(reglas).map((c) => c + '{' + reglas[c] + '}').join('\n');
            const textos = getComputedStyle(this.c);
            const copia = this.gContenido.cloneNode(true);
            copia.querySelectorAll('.za-control').forEach((e) => e.remove());
            copia.querySelectorAll('[tabindex]').forEach((e) => {
                e.removeAttribute('tabindex');
                e.removeAttribute('role');
            });

            const margen = this.m ? this.m.margen : BASE.margen;
            const w = Math.ceil(bb.width + margen * 2);
            const h = Math.ceil(bb.height + margen * 2);
            const salida = svgEl('svg', {
                xmlns: NS,
                width: w,
                height: h,
                viewBox: (bb.x - margen) + ' ' + (bb.y - margen) + ' ' + w + ' ' + h,
                'font-family': textos.fontFamily,
                'font-size': textos.fontSize
            });
            svgEl('style', {}, salida).textContent = css;
            svgEl('rect', {x: bb.x - margen, y: bb.y - margen, width: w, height: h, fill: textos.backgroundColor || '#fff'}, salida);
            salida.appendChild(copia);
            this.ultimaCaja = {w: w, h: h};
            return new XMLSerializer().serializeToString(salida);
        }

        nombreArchivo() {
            const base = String(this.cfg.titulo || this.estado.id || 'arbol').replace(/[^\w.-]+/g, '_').slice(0, 80);
            return (base || 'arbol') + '-' + this.estado.modo;
        }

        exportarSvg() {
            const svg = this.serializar();
            if (!svg) {
                return;
            }
            const url = URL.createObjectURL(new Blob(['<?xml version="1.0" encoding="UTF-8"?>\n' + svg], {type: 'image/svg+xml;charset=utf-8'}));
            // El enlace vive un instante dentro del contenedor, nunca en body.
            const a = htmlEl('a', '', '', this.c);
            a.href = url;
            a.download = this.nombreArchivo() + '.svg';
            a.hidden = true;
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        }

        imprimir() {
            const svg = this.serializar();
            if (!svg) {
                return;
            }
            const w = window.open('', '_blank');
            if (!w) {
                this.mostrarAviso(this.t.ventanaBloqueada || '');
                return;
            }
            const horizontal = this.ultimaCaja.w >= this.ultimaCaja.h;
            w.document.open();
            w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + escaparHtml(this.nombreArchivo())
                + '</title><style>@page{size:' + (horizontal ? 'landscape' : 'portrait') + ';margin:10mm}'
                + 'html,body{margin:0;background:#fff}svg{display:block;width:100%;height:auto;max-height:100vh}</style></head><body>'
                + svg + '</body></html>');
            w.document.close();
            w.focus();
            w.onafterprint = () => w.close();
            setTimeout(() => w.print(), 300);
        }
    }

    function resolver(el) {
        if (typeof el === 'string') {
            el = document.querySelector(el);
        }
        if (el && el.__auralisArbol) {
            return el.__auralisArbol;
        }
        return el instanceof Visor ? el : null;
    }

    window.AuralisArbol = {
        // Sin argumento monta todos los [data-auralis-arbol] pendientes (p. ej. tras inyectar HTML por AJAX).
        montar: function (el, opciones) {
            if (el === undefined || el === null) {
                document.querySelectorAll('[data-auralis-arbol]').forEach((c) => {
                    if (!c.__auralisArbol) {
                        new Visor(c);
                    }
                });
                return null;
            }
            if (typeof el === 'string') {
                el = document.querySelector(el);
            }
            if (!el) {
                return null;
            }
            return el.__auralisArbol || new Visor(el, opciones);
        },
        recargar: function (el, cambios) {
            const v = resolver(el);
            if (v) {
                v.recargar(cambios);
            }
        },
        exportarSvg: function (el) {
            const v = resolver(el);
            if (v) {
                v.exportarSvg();
            }
        },
        imprimir: function (el) {
            const v = resolver(el);
            if (v) {
                v.imprimir();
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.AuralisArbol.montar());
    } else {
        window.AuralisArbol.montar();
    }
})();
