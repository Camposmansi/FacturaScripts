/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Vista previa de la pestaña «PDF»: lee los campos del formulario (por su name) y pinta la maqueta al instante.
 * Replica las reglas de PdfEstiloAuralis (contraste del texto de cabecera, filas alternas, color de línea, papel y tamaño).
 */
(function () {
    'use strict';

    var TAMANO_CORE = 9;
    var TAMANO_MIN = 7;
    var TAMANO_MAX = 12;
    var OCULTAR = ['tipo_documento', 'logo', 'forma_pago', 'iban', 'recibos', 'envio', 'referencia', 'descuentos', 'numeracion'];

    // clave guardada => familia CSS (las incrustadas salen de auralis-fonts.css; las estándar, del sistema)
    var FAMILIAS = {
        inter: "'Inter'",
        lato: "'Lato'",
        merriweather: "'Merriweather'",
        montserrat: "'Montserrat'",
        nunito: "'Nunito'",
        open_sans: "'Open Sans'",
        outfit: "'Outfit'",
        plus_jakarta_sans: "'Plus Jakarta Sans'",
        poppins: "'Poppins'",
        roboto: "'Roboto'"
    };
    var FAMILIAS_SISTEMA = {
        'Helvetica': "'Helvetica', Arial, 'Liberation Sans', sans-serif",
        'Times-Roman': "'Times New Roman', Times, 'Liberation Serif', serif",
        'Courier': "'Courier New', Courier, 'Liberation Mono', monospace"
    };
    var PAPELES = {
        a4: {w: 595, h: 842},
        letter: {w: 612, h: 792}
    };

    /** Igual que PdfEstiloAuralis::normalizarHex(): '#rrggbb' en minúsculas o null. */
    function normalizarHex(texto) {
        var limpio = String(texto || '').trim().toLowerCase();
        if (limpio.charAt(0) === '#') {
            limpio = limpio.slice(1);
        }
        if (/^[0-9a-f]{3}$/.test(limpio)) {
            limpio = limpio.charAt(0) + limpio.charAt(0) + limpio.charAt(1) + limpio.charAt(1) + limpio.charAt(2) + limpio.charAt(2);
        }
        return /^[0-9a-f]{6}$/.test(limpio) ? '#' + limpio : null;
    }

    /** Igual que PdfEstiloAuralis::hexARgb(): canales 0..1 redondeados a 4 decimales. */
    function hexARgb(texto, porDefecto) {
        var hex = normalizarHex(texto);
        if (hex === null) {
            return porDefecto;
        }
        return [1, 3, 5].map(function (i) {
            return Math.round(parseInt(hex.substr(i, 2), 16) / 255 * 10000) / 10000;
        });
    }

    /** Igual que PdfEstiloAuralis::colorTextoSobreFondo(): true si el texto debe ser blanco. */
    function textoBlancoSobre(fondo) {
        var c = fondo.map(function (v) {
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        var luminancia = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        return luminancia < 0.4;
    }

    /** Igual que PdfEstiloAuralis::mezclarConBlanco(). */
    function mezclarConBlanco(rgb, proporcion) {
        var p = Math.max(0, Math.min(1, proporcion));
        return rgb.map(function (v) {
            return Math.round((v * (1 - p) + p) * 10000) / 10000;
        });
    }

    function css(rgb) {
        return 'rgb(' + rgb.map(function (v) {
            return Math.round(v * 255);
        }).join(',') + ')';
    }

    function iniciar() {
        var bloque = document.querySelector('[data-zpdf-preview]');
        if (!bloque) {
            return;
        }
        var hoja = bloque.querySelector('[data-zpdf-hoja]');
        var formulario = document.getElementById(bloque.getAttribute('data-form') || '');
        if (!formulario) {
            return;
        }

        colocarAlLado(bloque);

        // Con PlantillasPDF activo no hay maqueta, solo el aviso.
        if (!hoja) {
            return;
        }

        var acentoDefecto = hexARgb(bloque.getAttribute('data-acento-defecto'), hexARgb('#1f4e79', [0, 0, 0]));
        var fuenteTema = bloque.getAttribute('data-fuente-tema') || 'Helvetica';
        var avisoInactivo = bloque.querySelector('[data-zpdf-inactivo]');
        var notaEstandar = bloque.querySelector('[data-zpdf-nota-estandar]');
        var anterior = '';

        function campo(nombre) {
            return formulario.querySelector('[name="' + nombre + '"]');
        }

        function texto(nombre) {
            var el = campo(nombre);
            return el ? String(el.value || '') : '';
        }

        function marcado(nombre) {
            var el = campo(nombre);
            return !!(el && el.checked);
        }

        function leer() {
            var estado = {
                activo: marcado('activo'),
                acento: texto('color_acento'),
                colorTexto: texto('color_texto'),
                cabecera: texto('color_cabecera'),
                fuente: texto('fuente'),
                tamano: texto('tamano_base'),
                papel: texto('papel'),
                alternas: marcado('filas_alternas'),
                cabeceraPosicion: texto('cabecera_posicion'),
                logoTamano: texto('logo_tamano'),
                empresaNombreTamano: texto('empresa_nombre_tamano'),
                empresaDatosTamano: texto('empresa_datos_tamano'),
                cabeceraEspaciado: texto('cabecera_espaciado'),
                totalesPosicion: texto('totales_posicion'),
                mostrarContactoEmpresa: marcado('mostrar_contacto_empresa'),
                ocultar: {}
            };
            OCULTAR.forEach(function (clave) {
                estado.ocultar[clave] = marcado('ocultar_' + clave);
            });
            return estado;
        }

        function familia(estado) {
            var clave = estado.fuente === 'tema' || estado.fuente === '' ? fuenteTema : estado.fuente;
            if (FAMILIAS[clave]) {
                return {css: FAMILIAS[clave] + ', ' + FAMILIAS_SISTEMA.Helvetica, sistema: false};
            }
            if (FAMILIAS_SISTEMA[clave]) {
                return {css: FAMILIAS_SISTEMA[clave], sistema: true};
            }
            return {css: FAMILIAS_SISTEMA.Helvetica, sistema: true};
        }

        function actualizar() {
            var estado = leer();
            var firma = JSON.stringify(estado);
            if (firma === anterior) {
                return;
            }
            anterior = firma;

            var acento = hexARgb(estado.acento, acentoDefecto);
            var textoColor = hexARgb(estado.colorTexto, [0, 0, 0]);
            var cabecera = hexARgb(estado.cabecera, [0.95, 0.95, 0.95]);
            var cabeceraBlanca = textoBlancoSobre(cabecera);
            // Cabecera clara: se usa tal cual en las filas alternas; oscura: se aclara al 85 % (como AuralisCezpdf).
            var alterna = cabeceraBlanca ? mezclarConBlanco(cabecera, 0.85) : cabecera;
            var linea = mezclarConBlanco(acento, 0.5);

            var tamano = parseFloat(estado.tamano);
            tamano = isFinite(tamano) ? Math.max(TAMANO_MIN, Math.min(TAMANO_MAX, Math.round(tamano))) : TAMANO_CORE;
            var papel = PAPELES[estado.papel] || PAPELES.a4;
            var fuente = familia(estado);

            var estilo = hoja.style;
            estilo.setProperty('--zpdf-acento', css(acento));
            estilo.setProperty('--zpdf-texto', css(textoColor));
            estilo.setProperty('--zpdf-cabecera', css(cabecera));
            estilo.setProperty('--zpdf-cabecera-texto', cabeceraBlanca ? '#fff' : '#000');
            estilo.setProperty('--zpdf-alterna', css(alterna));
            estilo.setProperty('--zpdf-linea', css(linea));
            estilo.setProperty('--zpdf-base', String(tamano));
            estilo.setProperty('--zpdf-w', String(papel.w));
            estilo.setProperty('--zpdf-h', String(papel.h));
            estilo.setProperty('--zpdf-fuente', fuente.css);
            estilo.setProperty('--zpdf-logo-alto', String(limitarNumero(estado.logoTamano, 20, 90, 50)));
            estilo.setProperty('--zpdf-empresa-nombre', String(limitarNumero(estado.empresaNombreTamano, 8, 18, 11)));
            estilo.setProperty('--zpdf-empresa-datos', String(limitarNumero(estado.empresaDatosTamano, 6, 12, 8)));
            estilo.setProperty('--zpdf-cabecera-espaciado', String(limitarNumero(estado.cabeceraEspaciado, 0, 60, 8)));
            hoja.classList.toggle('zpdf-alternas', estado.alternas);

            var bloqueFinal = bloque.querySelector('[data-zpdf-bloque-final]');
            if (bloqueFinal) {
                bloqueFinal.classList.toggle('zpdf-bloque-final-al-pie', estado.totalesPosicion === 'pie_pagina');
            }

            var empresa = bloque.querySelector('[data-zpdf-empresa]');
            if (empresa) {
                empresa.classList.toggle('zpdf-empresa-derecha', estado.cabeceraPosicion === 'derecha');
            }
            var contactoEmpresa = bloque.querySelector('[data-zpdf-empresa-contacto]');
            if (contactoEmpresa) {
                contactoEmpresa.hidden = !estado.mostrarContactoEmpresa;
            }

            bloque.querySelectorAll('[data-zpdf-oculto-si]').forEach(function (el) {
                el.hidden = !!estado.ocultar[el.getAttribute('data-zpdf-oculto-si')];
            });
            bloque.querySelectorAll('[data-zpdf-visible-si]').forEach(function (el) {
                el.hidden = !estado.ocultar[el.getAttribute('data-zpdf-visible-si')];
            });

            if (avisoInactivo) {
                avisoInactivo.hidden = estado.activo;
            }
            if (notaEstandar) {
                notaEstandar.hidden = !fuente.sistema;
            }
        }

        ['input', 'change', 'keyup'].forEach(function (evento) {
            formulario.addEventListener(evento, actualizar);
        });

        // El selector de color (jscolor) cambia el valor sin avisar siempre: se comprueba al mover o soltar el puntero.
        var pendiente = false;
        function alMoverPuntero() {
            if (pendiente) {
                return;
            }
            pendiente = true;
            window.requestAnimationFrame(function () {
                pendiente = false;
                actualizar();
            });
        }
        document.addEventListener('pointermove', alMoverPuntero);
        document.addEventListener('pointerup', alMoverPuntero);

        actualizar();
    }

    function limitarNumero(valor, minimo, maximo, porDefecto) {
        var numero = parseFloat(valor);
        if (!isFinite(numero)) {
            return porDefecto;
        }
        return Math.max(minimo, Math.min(maximo, Math.round(numero)));
    }

    /**
     * En pantallas anchas la vista previa va a la derecha del formulario: se marcan el panel de la pestaña
     * y el bloque del pie, y el resto lo hace el CSS (sin mover nada del DOM).
     */
    function colocarAlLado(bloque) {
        var panel = bloque.closest('.tab-pane');
        if (!panel) {
            return;
        }
        var hijo = bloque;
        while (hijo.parentElement && hijo.parentElement !== panel) {
            hijo = hijo.parentElement;
        }
        if (hijo.parentElement !== panel) {
            return;
        }
        panel.classList.add('zpdf-layout');
        hijo.classList.add('zpdf-lado');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
