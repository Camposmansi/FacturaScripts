/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description «Nombres de PDF»: al pulsar una variable de la ayuda se inserta, como {variable}, en el último
 * campo de patrón (ventas o compras) que se haya enfocado, en la posición del cursor o sustituyendo la selección.
 */
(function () {
    'use strict';

    var CAMPOS = ['patron_ventas', 'patron_compras'];

    function iniciar() {
        var bloque = document.querySelector('[data-auralis-pdf-nombre-variables]');
        if (!bloque) {
            return;
        }
        var formulario = document.getElementById(bloque.getAttribute('data-form') || '');
        if (!formulario) {
            return;
        }

        var campos = {};
        CAMPOS.forEach(function (nombre) {
            var input = formulario.querySelector('input[name="' + nombre + '"]');
            if (input) {
                campos[nombre] = input;
            }
        });
        var nombres = Object.keys(campos);
        if (nombres.length === 0) {
            return;
        }

        // último campo enfocado y su selección (al pulsar el botón el campo pierde el foco)
        var actual = campos.patron_ventas ? 'patron_ventas' : nombres[0];
        var seleccion = {};
        nombres.forEach(function (nombre) {
            var input = campos[nombre];
            seleccion[nombre] = {inicio: input.value.length, fin: input.value.length};
            var recordar = function () {
                actual = nombre;
                seleccion[nombre] = {
                    inicio: input.selectionStart === null ? input.value.length : input.selectionStart,
                    fin: input.selectionEnd === null ? input.value.length : input.selectionEnd
                };
            };
            ['focus', 'click', 'keyup', 'select', 'input', 'blur'].forEach(function (evento) {
                input.addEventListener(evento, recordar);
            });
        });

        function insertar(variable) {
            var input = campos[actual];
            var texto = '{' + variable + '}';
            var rango = seleccion[actual];
            var valor = input.value;
            var inicio = Math.min(rango.inicio, valor.length);
            var fin = Math.min(Math.max(rango.fin, inicio), valor.length);
            var nuevo = valor.slice(0, inicio) + texto + valor.slice(fin);
            var maximo = parseInt(input.getAttribute('maxlength') || '0', 10);

            if (maximo > 0 && nuevo.length > maximo) {
                input.classList.add('is-invalid');
                input.setAttribute('title', bloque.getAttribute('data-aviso-largo') || '');
                window.setTimeout(function () {
                    input.classList.remove('is-invalid');
                    input.removeAttribute('title');
                }, 2500);
                input.focus();
                return;
            }

            input.value = nuevo;
            var cursor = inicio + texto.length;
            seleccion[actual] = {inicio: cursor, fin: cursor};
            input.focus();
            input.setSelectionRange(cursor, cursor);
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
        }

        bloque.querySelectorAll('[data-auralis-pdf-variable]').forEach(function (boton) {
            // evita que el campo pierda el foco y con él la posición del cursor
            boton.addEventListener('mousedown', function (evento) {
                evento.preventDefault();
            });
            boton.addEventListener('click', function () {
                insertar(boton.getAttribute('data-auralis-pdf-variable'));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
