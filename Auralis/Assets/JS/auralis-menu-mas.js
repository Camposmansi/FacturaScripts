/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Menú «Más» de la barra horizontal: pasa a un desplegable los menús de primer nivel que no caben, para que la barra no provoque scroll horizontal
 */
(function () {
    'use strict';

    const barra = document.querySelector('nav.auralis-topbar-horizontal');
    const menus = barra ? barra.querySelector('[data-auralis-menu-mas]') : null;
    const mas = document.getElementById('auralisMenuMas');
    if (!menus || !mas) {
        return;
    }

    const zona = menus.closest('.navbar-collapse');
    const iconos = zona ? zona.querySelector(':scope > .navbar-nav.ms-auto') : null;
    const enlaceMas = mas.querySelector(':scope > .nav-link');
    const listaMas = mas.querySelector(':scope > .dropdown-menu');
    if (!zona || !enlaceMas || !listaMas) {
        return;
    }

    let abierto = false;
    let recalcularAlCerrar = false;

    // Solo se mueven los menús de primer nivel que pinta el Core (Macro/Menu.html.twig).
    // Cualquier otro <li> de la lista (p. ej. añadido por otro plugin) se queda siempre a la vista.
    function esMenuDelCore(li) {
        return li !== mas
            && li.matches('li.fs-menu-item.nav-item.dropdown')
            && li.querySelector(':scope > a.nav-link.dropdown-toggle') !== null
            && li.querySelector(':scope > ul.dropdown-menu') !== null;
    }

    function cerrarDesplegable(enlace) {
        if (!window.bootstrap || !window.bootstrap.Dropdown) {
            return;
        }
        const instancia = window.bootstrap.Dropdown.getInstance(enlace);
        if (instancia) {
            instancia.hide();
        }
    }

    function tituloDe(enlace) {
        const completo = enlace.querySelector('.d-md-inline-block');
        return (completo ? completo.textContent : enlace.textContent).trim();
    }

    function quitarAbiertos(raiz) {
        raiz.querySelectorAll('.auralis-menu-mas-abierto').forEach(function (el) {
            el.classList.remove('auralis-menu-mas-abierto');
        });
    }

    // Lleva la lista de páginas del menú a «Más». El <li> original se queda en su sitio, oculto y con su id.
    function mover(li) {
        const enlace = li.querySelector(':scope > a.nav-link');
        const lista = li.querySelector(':scope > ul.dropdown-menu');
        cerrarDesplegable(enlace);
        lista.classList.remove('show');

        const item = document.createElement('li');
        item.className = 'fs-menu-item dropdown-submenu auralis-menu-mas-item';
        const enlaceItem = document.createElement('a');
        enlaceItem.className = 'dropdown-item' + (enlace.classList.contains('active') ? ' active' : '');
        enlaceItem.href = '#';
        enlaceItem.setAttribute('role', 'button');
        enlaceItem.setAttribute('aria-haspopup', 'true');
        const icono = document.createElement('i');
        icono.className = 'fa-solid fa-folder-open fa-fw me-1';
        icono.setAttribute('aria-hidden', 'true');
        enlaceItem.append(icono, ' ' + tituloDe(enlace));
        item.append(enlaceItem, lista);
        item.auralisOrigen = li;

        // Se mueve desde el final: se antepone para conservar el orden del menú
        listaMas.prepend(item);
        li.classList.add('auralis-menu-mas-oculto');
    }

    function restaurar() {
        listaMas.querySelectorAll(':scope > .auralis-menu-mas-item').forEach(function (item) {
            const lista = item.querySelector(':scope > ul.dropdown-menu');
            if (item.auralisOrigen && lista) {
                lista.classList.remove('show');
                quitarAbiertos(lista);
                item.auralisOrigen.append(lista);
                item.auralisOrigen.classList.remove('auralis-menu-mas-oculto');
            }
            item.remove();
        });
        mas.classList.add('d-none');
        enlaceMas.classList.remove('active');
    }

    function anchoMenus() {
        let total = 0;
        for (const li of menus.children) {
            const estilo = window.getComputedStyle(li);
            total += li.getBoundingClientRect().width + parseFloat(estilo.marginLeft) + parseFloat(estilo.marginRight);
        }
        return total;
    }

    function recalcular() {
        if (abierto) {
            recalcularAlCerrar = true;
            return;
        }

        restaurar();
        if (zona.clientWidth === 0) {
            // Barra oculta: por debajo del breakpoint móvil se usa la cabecera móvil
            return;
        }

        // Los iconos (MenuIcon* y los que añadan otros plugins por JS) tienen prioridad: no se encogen nunca
        const disponible = zona.clientWidth - (iconos ? iconos.getBoundingClientRect().width : 0) + 0.5;
        if (anchoMenus() <= disponible) {
            return;
        }

        mas.classList.remove('d-none');
        const candidatos = Array.prototype.filter.call(menus.children, esMenuDelCore);
        while (candidatos.length > 0 && anchoMenus() > disponible) {
            mover(candidatos.pop());
        }

        if (listaMas.children.length === 0) {
            mas.classList.add('d-none');
            return;
        }
        enlaceMas.classList.toggle('active', listaMas.querySelector(':scope > .auralis-menu-mas-item > .dropdown-item.active') !== null);
    }

    mas.addEventListener('shown.bs.dropdown', function () {
        abierto = true;
    });
    mas.addEventListener('hidden.bs.dropdown', function () {
        abierto = false;
        quitarAbiertos(listaMas);
        if (recalcularAlCerrar) {
            recalcularAlCerrar = false;
            recalcular();
        }
    });

    // Toque o clic en un submenú dentro de «Más»: lo abre sin navegar a «#» (en táctil no hay hover)
    listaMas.addEventListener('click', function (evento) {
        const enlace = evento.target.closest('.dropdown-submenu > a.dropdown-item');
        if (!enlace || !listaMas.contains(enlace)) {
            return;
        }
        evento.preventDefault();
        const item = enlace.parentElement;
        const abrir = !item.classList.contains('auralis-menu-mas-abierto');
        Array.prototype.forEach.call(item.parentElement.children, function (hermano) {
            hermano.classList.remove('auralis-menu-mas-abierto');
        });
        item.classList.toggle('auralis-menu-mas-abierto', abrir);
    });

    // Sin este script la barra se queda como siempre (sin recortes): el CSS de «Más» solo aplica con esta clase
    barra.classList.add('auralis-menu-mas-activo');
    recalcular();

    // Recalcular si cambia el ancho de la ventana o el de la zona de iconos (un plugin añade un icono,
    // Font Awesome cambia <i> por <svg>, se expande el buscador…). Solo cuenta el ancho, así nuestros
    // propios cambios no vuelven a disparar el cálculo.
    if (window.ResizeObserver) {
        const anchos = new WeakMap();
        const observador = new ResizeObserver(function (entradas) {
            let cambia = false;
            entradas.forEach(function (entrada) {
                const ancho = Math.round(entrada.contentRect.width);
                if (anchos.get(entrada.target) !== ancho) {
                    anchos.set(entrada.target, ancho);
                    cambia = true;
                }
            });
            if (cambia) {
                recalcular();
            }
        });
        observador.observe(barra);
        if (iconos) {
            observador.observe(iconos);
        }
    } else {
        window.addEventListener('resize', recalcular);
    }

    // Menús de primer nivel añadidos después de pintar la página
    if (window.MutationObserver) {
        new MutationObserver(recalcular).observe(menus, {childList: true});
    }

    // Los anchos cambian al terminar de cargar la tipografía configurada
    if (document.fonts) {
        document.fonts.ready.then(recalcular);
        document.fonts.addEventListener('loadingdone', recalcular);
    }
    window.addEventListener('load', recalcular);
})();
