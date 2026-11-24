/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Filas dinámicas del widget zLineasProducto. Por defecto botón
 *              «Añadir línea»; con data-zlp-autoadd="1" clona al elegir producto.
 *              data-zlp-max-lineas limita las filas (bloqueadas incluidas; 0 = sin límite).
 *              data-zlp-autocomplete-filter viaja como POST `filtro` si no está vacío.
 *              Las filas nuevas salen del molde <template class="zlp-molde">.
 *              Cada fila lleva clave[] y su detalle (atributo detail) viaja como detalle[<clave>][campo]:
 *              al clonar el molde se cambia el marcador __ZLP__ por una clave única (name, id, for,
 *              list, data-* y aria-*). La papelera de la última fila editable la vacía (detalle incluido)
 *              y le da una clave nueva. Eventos para el consumidor: zlp:fila-nueva y zlp:fila-vaciada.
 */

(function ($) {
    'use strict';

    if (typeof $ === 'undefined') {
        return;
    }

    let activeProduct = null;
    let enter = false;
    let contadorFilas = 0;
    const MARCADOR = '__ZLP__';
    const ATRIBUTOS_CLAVE = /^(name|id|for|list|data-.+|aria-.+)$/;

    /** Clave única de fila nueva («n» + sello de tiempo + contador; la fila vacía inicial es n0). */
    function nuevaClave() {
        contadorFilas += 1;
        return 'n' + Date.now().toString(36) + contadorFilas;
    }

    /** Cambia el marcador del molde por la clave en la fila y en todo lo que cuelga de ella. */
    function reemplazarMarcador($fila, clave) {
        const nodos = $fila.get().concat($fila.find('*').get());
        nodos.forEach(function (nodo) {
            Array.prototype.slice.call(nodo.attributes || []).forEach(function (attr) {
                if (ATRIBUTOS_CLAVE.test(attr.name) && attr.value.indexOf(MARCADOR) !== -1) {
                    nodo.setAttribute(attr.name, attr.value.split(MARCADOR).join(clave));
                }
            });
        });
        $fila.find('input.zlp-clave').val(clave);
    }

    /** Da otra clave a una fila ya pintada: clave[] y el prefijo detalle[<clave>] de sus campos. */
    function cambiarClave($fila, clave) {
        const $clave = $fila.find('input.zlp-clave');
        const anterior = String($clave.val() || '');
        $clave.val(clave);
        if (anterior === '') {
            return;
        }

        const prefijo = 'detalle[' + anterior + ']';
        $fila.find('[name]').each(function () {
            const nombre = this.getAttribute('name') || '';
            if (nombre.indexOf(prefijo) === 0) {
                this.setAttribute('name', 'detalle[' + clave + ']' + nombre.substring(prefijo.length));
            }
        });
    }

    function moldeDe($el) {
        const molde = embedDe($el).find('template.zlp-molde').get(0);
        return molde && molde.content ? molde.content : null;
    }

    /**
     * Vacía los campos del detalle: texto, número, fecha y hora a '', casillas desmarcadas y
     * select a su opción de valor '' si la tiene (así la fila queda vacía y el servidor la borra);
     * si no, a la opción por defecto del mismo campo en el molde o a la primera. Los hidden no se tocan.
     */
    function vaciarDetalle($fila) {
        const molde = moldeDe($fila);
        $fila.find('.zlp-detalle').find('input, select, textarea').each(function () {
            const tipo = String(this.type || '').toLowerCase();
            if (this.tagName === 'SELECT') {
                let indice = Array.prototype.findIndex.call(this.options, function (opcion) {
                    return opcion.value === '';
                });
                const nombre = this.getAttribute('name') || '';
                const resto = nombre.indexOf('detalle[') === 0 ? nombre.substring(nombre.indexOf(']') + 1) : '';
                const gemelo = indice < 0 && molde && resto !== ''
                    ? molde.querySelector('select[name="detalle[' + MARCADOR + ']' + resto.replace(/"/g, '\\"') + '"]')
                    : null;
                indice = indice < 0 ? 0 : indice;
                if (gemelo) {
                    const porDefecto = Array.prototype.findIndex.call(gemelo.options, function (opcion) {
                        return opcion.defaultSelected;
                    });
                    indice = porDefecto >= 0 ? porDefecto : 0;
                }
                this.selectedIndex = this.options.length > indice ? indice : 0;
                $(this).trigger('change');
                return;
            }
            if (tipo === 'checkbox' || tipo === 'radio') {
                this.checked = false;
                return;
            }
            if (['hidden', 'button', 'submit', 'reset', 'file', 'image'].indexOf(tipo) !== -1) {
                return;
            }
            this.value = '';
        });
    }

    function filaActiva($el) {
        const id = $el.attr('data-product') || $el.closest('.zlp-fila').attr('id');
        return id ? $('#' + id) : $();
    }

    function listaDeFila($fila) {
        return $fila.closest('.zlp-list');
    }

    function embedDe($el) {
        return $el.closest('[data-zlp-lineas]');
    }

    function autoaddActivo($lista) {
        const $embed = embedDe($lista);
        return $embed.length && $embed.attr('data-zlp-autoadd') === '1';
    }

    /** Cantidad de una fila nueva o limpiada: 1, o vacía con emptyquantity. */
    function cantidadNueva($el) {
        return embedDe($el).attr('data-zlp-cantidad-vacia') === '1' ? '' : 1;
    }

    function maxLineas($lista) {
        const n = parseInt(embedDe($lista).attr('data-zlp-max-lineas') || '0', 10);
        return isNaN(n) || n < 0 ? 0 : n;
    }

    function limiteAlcanzado($lista) {
        const max = maxLineas($lista);
        return max > 0 && $lista.find('.zlp-fila').length >= max;
    }

    function actualizarBotonAnadir($lista) {
        embedDe($lista).find('.zlp-add-bar').toggleClass('d-none', limiteAlcanzado($lista));
    }

    function actualizarCabecera($lista) {
        const $embed = embedDe($lista);
        if (!$embed.length) {
            return;
        }

        let n = 0;
        $lista.find('.zlp-fila').each(function () {
            const ref = String($(this).find('input[name="referencia[]"]').val() || '').trim();
            if (ref !== '') {
                n += 1;
            }
        });

        const plantilla = $embed.attr('data-hint-n') || '%n%';
        const vacio = $embed.attr('data-hint-vacio') || '';
        $embed.find('.zlp-toggle-hint').text(n > 0 ? plantilla.replace('%n%', String(n)) : vacio);

        const $badge = $embed.find('.zlp-badge');
        $badge.text(String(n));
        $badge.toggleClass('d-none', n === 0);
        actualizarBotonAnadir($lista);
    }

    function codclienteActual($fila) {
        const $embed = embedDe($fila);
        if ($embed.length && $embed.attr('data-zlp-codcliente')) {
            return $embed.attr('data-zlp-codcliente');
        }
        return window.zlpCodcliente || '';
    }

    function autocompleteAction($fila) {
        const $embed = embedDe($fila);
        if ($embed.length && $embed.attr('data-zlp-autocomplete-action')) {
            return $embed.attr('data-zlp-autocomplete-action');
        }
        return 'autocomplete-producto-lineas';
    }

    function autocompleteFiltro($fila) {
        const $embed = embedDe($fila);
        return $embed.length ? String($embed.attr('data-zlp-autocomplete-filter') || '') : '';
    }

    function limpiarProducto($fila) {
        $fila.find('.zlp-search-ref').removeClass('d-none');
        $fila.find('.zlp-clear-ref').addClass('d-none');
        $fila.find('input[name="referencia[]"]').val('');
        $fila.find('input.zlp-search-product').val('').focus();
        $fila.find('textarea[name="descripcion[]"]').val('');
        $fila.find('input[name="cantidad[]"]').val(cantidadNueva($fila));
        $fila.find('input[name="precio[]"]').val(0);
        $fila.find('input[name="facturable[]"]').val('1');
        $fila.find('.zlp-facturable-check').prop('checked', true);
    }

    function filaDesdeMolde($lista) {
        const molde = embedDe($lista).find('template.zlp-molde').get(0);
        if (!molde || !molde.content) {
            return $();
        }
        const fila = molde.content.querySelector('.zlp-fila');
        return fila ? $(fila.cloneNode(true)) : $();
    }

    function clonarFilaVacia($lista) {
        if (limiteAlcanzado($lista)) {
            actualizarBotonAnadir($lista);
            return $();
        }

        // Molde primero (permite añadir aunque todas las filas visibles estén bloqueadas).
        const clave = nuevaClave();
        let $clone = filaDesdeMolde($lista);
        if ($clone.length) {
            reemplazarMarcador($clone, clave);
        } else {
            $clone = $lista.find('.zlp-fila:not(.zlp-fila-bloqueada):last').clone();
            if ($clone.length) {
                cambiarClave($clone, clave);
                vaciarDetalle($clone);
            }
        }
        if (!$clone.length) {
            return $();
        }

        const newId = ($lista.attr('id') || 'zlp-list') + '-fila-' + Date.now() + '-' + contadorFilas;
        $clone.attr('id', newId);
        $clone.attr('data-product-id', newId);
        $clone.find('input[name="idlinea[]"]').val('');
        $clone.find('[data-product]').attr('data-product', newId);
        $clone.find('.zlp-facturable-check').attr('id', newId + '-facturable');
        // solo la etiqueta del interruptor: las del detalle ya apuntan a sus propios ids
        $clone.find('.zlp-fila-acciones label[for]').attr('for', newId + '-facturable');
        $lista.append($clone);
        const $nueva = $('#' + newId);
        limpiarProducto($nueva);
        bindAutocomplete($lista);
        actualizarCabecera($lista);
        $nueva.trigger('zlp:fila-nueva', [clave]);
        return $nueva;
    }

    function rellenarProducto(item) {
        const key = item && typeof item === 'object' ? item.key : item;
        if (key === null || key === undefined) {
            window.alert(window.zlpProductoNotFound || 'Producto no encontrado');
            return;
        }

        if (!activeProduct || !activeProduct.length) {
            return;
        }

        const desc = item && typeof item === 'object' ? (item.descripcion || item.value || '') : '';
        const precio = item && typeof item === 'object' && item.precio !== undefined ? item.precio : 0;

        activeProduct.find('input[name="referencia[]"]').val(key);
        activeProduct.find('input.zlp-search-product').val(key);
        activeProduct.find('textarea[name="descripcion[]"]').val(desc);
        activeProduct.find('input[name="precio[]"]').val(precio);
        activeProduct.find('.zlp-search-ref').addClass('d-none');
        activeProduct.find('.zlp-clear-ref').removeClass('d-none');

        const $lista = listaDeFila(activeProduct);
        if (!$lista.length) {
            return;
        }

        if (autoaddActivo($lista) && !limiteAlcanzado($lista)) {
            activeProduct = clonarFilaVacia($lista);
        } else {
            actualizarCabecera($lista);
        }
    }

    function bindAutocomplete($scope) {
        const $root = $scope && $scope.length ? $scope : $(document);
        $root.find('.zlp-search-product').autocomplete({
            autoFocus: true,
            source: function (request, response) {
                activeProduct = filaActiva($(this.element[0]));
                const datos = {
                    action: autocompleteAction(activeProduct),
                    query: request,
                    codcliente: codclienteActual(activeProduct)
                };
                const filtro = autocompleteFiltro(activeProduct);
                if (filtro !== '') {
                    datos.filtro = filtro;
                }
                $.ajax({
                    method: 'POST',
                    url: window.zlpAutocompleteUrl || window.location.href,
                    data: datos,
                    dataType: 'json',
                    success: function (results) {
                        const values = [];
                        (results || []).forEach(function (element) {
                            if (element.key === null || element.key === element.value) {
                                values.push(element);
                            } else {
                                values.push({
                                    key: element.key,
                                    value: element.key + ' | ' + element.value,
                                    precio: element.precio,
                                    descripcion: element.descripcion || element.value
                                });
                            }
                        });
                        response(values);

                        if (enter && values.length > 0 && values[0].key) {
                            rellenarProducto(values[0]);
                        }
                        enter = false;
                    },
                    error: function (msg) {
                        window.alert(msg.status + ' ' + msg.responseText);
                    }
                });
            },
            select: function (event, ui) {
                if (ui.item && ui.item.key !== null) {
                    rellenarProducto(ui.item);
                }
            }
        });
    }

    $(function () {
        if (!$('.zlp-list').length) {
            return;
        }

        $('.zlp-list').each(function () {
            const $form = $(this).closest('form');
            if ($form.data('zlp-keypress')) {
                return;
            }
            $form.data('zlp-keypress', true);
            $form.on('keypress', function (event) {
                const keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    if ($(event.target).hasClass('zlp-search-product')) {
                        enter = true;
                    }
                    event.preventDefault();
                    return false;
                }
            });
        });

        $(document).on('change', '.zlp-list .zlp-facturable-check', function () {
            $(this).closest('.zlp-fila').find('input[name="facturable[]"]').val(this.checked ? '1' : '0');
        });

        $(document).on('click', '.zlp-list .zlp-clear-ref', function () {
            activeProduct = filaActiva($(this));
            limpiarProducto(activeProduct);
            actualizarCabecera(listaDeFila(activeProduct));
        });

        $(document).on('click', '.zlp-list .zlp-delete-fila', function () {
            activeProduct = filaActiva($(this));
            const $lista = listaDeFila(activeProduct);
            if ($lista.find('.zlp-fila:not(.zlp-fila-bloqueada)').length === 1) {
                // Última fila editable: se vacía (y cambia de clave) para que el POST la envíe vacía
                // y el servidor borre la línea; su detalle ya no se confunde con el de la borrada.
                limpiarProducto(activeProduct);
                vaciarDetalle(activeProduct);
                const clave = nuevaClave();
                cambiarClave(activeProduct, clave);
                actualizarCabecera($lista);
                activeProduct.trigger('zlp:fila-vaciada', [clave]);
                return false;
            }
            activeProduct.remove();
            actualizarCabecera($lista);
        });

        $(document).on('click', '.zlp-add-line', function () {
            const $embed = $(this).closest('[data-zlp-lineas]');
            const $lista = $embed.find('.zlp-list').first();
            if (!$lista.length) {
                return;
            }
            activeProduct = clonarFilaVacia($lista);
            if (activeProduct.length) {
                activeProduct.find('input.zlp-search-product').focus();
            }
        });

        $('.zlp-list').each(function () {
            actualizarBotonAnadir($(this));
        });

        bindAutocomplete($(document));
    });
})(window.jQuery);
