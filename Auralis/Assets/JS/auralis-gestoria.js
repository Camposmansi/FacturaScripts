/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Pantalla «Documentos para la gestoría»: atajos de periodo, autocompletar, comprobar, descargar ZIP y enviar.
 */
(function () {
    'use strict';

    // El bloque javascripts de MenuTemplate se pinta en <head>: hay que esperar al DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    function iniciar() {
        var form = document.getElementById('auralisGestoriaForm');
        if (!form) {
            return;
        }

        var mensaje = document.getElementById('auralisGestoriaMensaje');
        var resultado = document.getElementById('auralisGestoriaResultado');
        var url = form.getAttribute('data-url') || window.location.pathname;
        var ocupado = false;

        function tokenInput() {
            return form.querySelector('input[name="multireqtoken"]');
        }

        function actualizarToken(nuevo) {
            var input = tokenInput();
            if (nuevo && input) {
                input.value = nuevo;
            }
        }

        function mostrarMensaje(texto, tipo) {
            mensaje.textContent = '';
            if (!texto) {
                return;
            }
            var alerta = document.createElement('div');
            alerta.className = 'alert alert-' + (tipo || 'info');
            alerta.setAttribute('role', 'alert');
            alerta.textContent = texto;
            mensaje.appendChild(alerta);
        }

        // Atajos de periodo
        form.querySelectorAll('.auralis-gestoria-atajo').forEach(function (boton) {
            boton.addEventListener('click', function () {
                form.querySelector('[name="desde"]').value = boton.getAttribute('data-desde');
                form.querySelector('[name="hasta"]').value = boton.getAttribute('data-hasta');
                form.querySelectorAll('.auralis-gestoria-atajo').forEach(function (otro) {
                    otro.classList.toggle('active', otro === boton);
                });
            });
        });
        ['desde', 'hasta'].forEach(function (nombre) {
            form.querySelector('[name="' + nombre + '"]').addEventListener('change', function () {
                form.querySelectorAll('.auralis-gestoria-atajo').forEach(function (otro) {
                    otro.classList.remove('active');
                });
            });
        });

        // Casillas de ámbito: activan o desactivan su bloque
        function aplicarAmbito(casilla) {
            var cuerpo = form.querySelector('[data-ambito-cuerpo="' + casilla.getAttribute('data-ambito') + '"]');
            if (!cuerpo) {
                return;
            }
            cuerpo.classList.toggle('auralis-gestoria-inactivo', !casilla.checked);
            cuerpo.querySelectorAll('input, select').forEach(function (control) {
                if (control.type === 'hidden') {
                    return;
                }
                if (!casilla.checked) {
                    control.dataset.zgPrevioDisabled = control.disabled ? '1' : '0';
                    control.disabled = true;
                } else if (control.dataset.zgPrevioDisabled !== undefined) {
                    control.disabled = control.dataset.zgPrevioDisabled === '1';
                }
            });
        }
        form.querySelectorAll('.auralis-gestoria-ambito').forEach(function (casilla) {
            casilla.addEventListener('change', function () {
                aplicarAmbito(casilla);
            });
        });

        // Autocompletar de cliente / proveedor
        form.querySelectorAll('.auralis-gestoria-buscador').forEach(function (input) {
            var destino = document.getElementById(input.getAttribute('data-destino'));
            var lista = input.parentElement.querySelector('.auralis-gestoria-sugerencias');
            var temporizador = null;
            var peticion = 0;

            function cerrar() {
                lista.classList.add('d-none');
                lista.textContent = '';
            }

            function pintar(resultados) {
                lista.textContent = '';
                if (!resultados.length) {
                    var vacio = document.createElement('div');
                    vacio.className = 'list-group-item text-body-secondary';
                    vacio.textContent = form.getAttribute('data-msg-sin-resultados');
                    lista.appendChild(vacio);
                }
                resultados.forEach(function (item) {
                    var opcion = document.createElement('button');
                    opcion.type = 'button';
                    opcion.className = 'list-group-item list-group-item-action';
                    opcion.setAttribute('role', 'option');
                    opcion.textContent = item.nombre + ' (' + item.codigo + ')';
                    opcion.addEventListener('click', function () {
                        destino.value = item.codigo;
                        input.value = item.nombre + ' (' + item.codigo + ')';
                        cerrar();
                    });
                    lista.appendChild(opcion);
                });
                lista.classList.remove('d-none');
            }

            input.addEventListener('input', function () {
                destino.value = '';
                clearTimeout(temporizador);
                var texto = input.value.trim();
                if (texto === '') {
                    cerrar();
                    return;
                }
                temporizador = setTimeout(function () {
                    var actual = ++peticion;
                    var token = tokenInput() ? tokenInput().value : '';
                    var params = new URLSearchParams({
                        action: 'gestoria-buscar',
                        tipo: input.getAttribute('data-tipo'),
                        term: texto,
                        multireqtoken: token
                    });
                    fetch(url + '?' + params.toString(), {credentials: 'same-origin'})
                        .then(function (r) {
                            return r.json();
                        })
                        .then(function (data) {
                            if (actual === peticion && data && data.ok) {
                                pintar(data.resultados || []);
                            }
                        })
                        .catch(function () {
                            cerrar();
                        });
                }, 250);
            });

            input.addEventListener('keydown', function (evento) {
                if (evento.key === 'Escape') {
                    cerrar();
                }
            });
            document.addEventListener('click', function (evento) {
                if (!input.parentElement.contains(evento.target)) {
                    cerrar();
                }
            });
        });

        // Comprobación previa
        function pintarComprobacion(data) {
            var cuerpo = resultado.querySelector('[data-gestoria-resumen]');
            var avisos = resultado.querySelector('[data-gestoria-avisos]');
            var bloqueSinAdjunto = resultado.querySelector('[data-gestoria-sin-adjunto-bloque]');
            var listaSinAdjunto = resultado.querySelector('[data-gestoria-sin-adjunto]');
            cuerpo.textContent = '';
            avisos.textContent = '';
            listaSinAdjunto.textContent = '';

            (data.resumen || []).forEach(function (linea) {
                var fila = document.createElement('tr');
                [linea.etiqueta, String(linea.documentos), linea.total].forEach(function (valor, indice) {
                    var celda = document.createElement('td');
                    if (indice > 0) {
                        celda.className = 'text-end';
                    }
                    celda.textContent = valor;
                    fila.appendChild(celda);
                });
                cuerpo.appendChild(fila);
            });

            var filaTotal = document.createElement('tr');
            filaTotal.className = 'fw-semibold';
            var celdaEtiqueta = document.createElement('td');
            celdaEtiqueta.textContent = form.getAttribute('data-msg-total');
            var celdaTotal = document.createElement('td');
            celdaTotal.className = 'text-end';
            celdaTotal.textContent = String(data.total_documentos || 0);
            filaTotal.appendChild(celdaEtiqueta);
            filaTotal.appendChild(celdaTotal);
            filaTotal.appendChild(document.createElement('td'));
            cuerpo.appendChild(filaTotal);

            function aviso(texto, tipo) {
                var div = document.createElement('div');
                div.className = 'alert alert-' + tipo;
                div.textContent = texto;
                avisos.appendChild(div);
            }

            if (data.tiempo_estimado && data.total_documentos > 0) {
                aviso(data.tiempo_estimado, 'info');
            }
            if (data.excede) {
                aviso(form.getAttribute('data-msg-excede').replace('%maximo%', String(data.maximo)), 'warning');
            }
            if (data.denegados && data.denegados.length) {
                aviso(form.getAttribute('data-msg-denegados') + ' ' + data.denegados.join(', '), 'secondary');
            }

            var sinAdjunto = data.sin_adjunto || [];
            bloqueSinAdjunto.classList.toggle('d-none', sinAdjunto.length === 0);
            sinAdjunto.forEach(function (item) {
                var enlace = document.createElement('a');
                enlace.className = 'list-group-item list-group-item-action d-flex justify-content-between gap-2';
                enlace.href = item.url;
                enlace.target = '_blank';
                enlace.rel = 'noopener';
                var texto = document.createElement('span');
                texto.textContent = item.codigo + ' · ' + item.nombre;
                var fecha = document.createElement('span');
                fecha.className = 'text-body-secondary';
                fecha.textContent = item.fecha;
                enlace.appendChild(texto);
                enlace.appendChild(fecha);
                listaSinAdjunto.appendChild(enlace);
            });
            if (sinAdjunto.length === 0 && data.modo_compras && data.modo_compras !== 'pdf') {
                aviso(form.getAttribute('data-msg-todo-adjunto'), 'success');
            }

            resultado.classList.remove('d-none');
        }

        function descargarBlob(blob, nombre) {
            var enlace = document.createElement('a');
            var objeto = URL.createObjectURL(blob);
            enlace.href = objeto;
            enlace.download = nombre;
            document.body.appendChild(enlace);
            enlace.click();
            setTimeout(function () {
                URL.revokeObjectURL(objeto);
                enlace.remove();
            }, 1000);
        }

        function nombreDescarga(cabecera) {
            var coincide = /filename="?([^";]+)"?/i.exec(cabecera || '');
            return coincide ? coincide[1] : 'documentos-gestoria.zip';
        }

        function ejecutar(accion, boton) {
            if (ocupado) {
                return;
            }
            ocupado = true;
            form.querySelector('input[name="action"]').value = accion;
            var datos = new FormData(form);
            var iconoOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>');
            mostrarMensaje('', '');

            fetch(url, {method: 'POST', body: datos, credentials: 'same-origin'})
                .then(function (respuesta) {
                    var tipo = respuesta.headers.get('Content-Type') || '';
                    if (tipo.indexOf('application/json') !== -1) {
                        return respuesta.json().then(function (data) {
                            actualizarToken(data.newToken);
                            if (!data.ok) {
                                mostrarMensaje(data.message || form.getAttribute('data-msg-error'), 'danger');
                                return;
                            }
                            if (accion === 'gestoria-comprobar') {
                                pintarComprobacion(data);
                            } else {
                                mostrarMensaje(data.message, 'success');
                            }
                        });
                    }

                    actualizarToken(respuesta.headers.get('X-Auralis-Token'));
                    if (!respuesta.ok) {
                        mostrarMensaje(form.getAttribute('data-msg-error'), 'danger');
                        return;
                    }
                    var nombre = nombreDescarga(respuesta.headers.get('Content-Disposition'));
                    return respuesta.blob().then(function (blob) {
                        descargarBlob(blob, nombre);
                        mostrarMensaje(form.getAttribute('data-msg-descarga'), 'success');
                    });
                })
                .catch(function () {
                    mostrarMensaje(form.getAttribute('data-msg-error'), 'danger');
                })
                .finally(function () {
                    ocupado = false;
                    boton.disabled = false;
                    boton.innerHTML = iconoOriginal;
                });
        }

        form.querySelectorAll('[data-gestoria-accion]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                if (!form.reportValidity()) {
                    return;
                }
                ejecutar(boton.getAttribute('data-gestoria-accion'), boton);
            });
        });

        form.addEventListener('submit', function (evento) {
            evento.preventDefault();
        });
    }
})();
