/**
 * Auralis - Menú Reordering Manager (FacturaScripts)
 * @author MansiHost.com
 * @description Gestor interactivo con Drag & Drop nativo para reordenación de 1º, 2º y 3º nivel de menú con colapso inteligente.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (window.AuralisMenuOrderInitialized) {
        return;
    }
    window.AuralisMenuOrderInitialized = true;

    const data = window.AuralisMenuData || {};
    const tree = data.tree || {};
    const savedOrders = data.savedOrders || [];
    let ordenActivo = !!data.ordenActivo;
    let requestInFlight = false;

    const switchActive = document.getElementById('ordenMenuActivoSwitch');
    const switchAgrupar = document.getElementById('agruparAdminGeneralSwitch');
    const switchAgruparInformes = document.getElementById('agruparInformesMenuSwitch');
    const topbarSwitches = {
        topbar_show_search: document.getElementById('topbarShowSearchSwitch'),
        topbar_show_dashboard: document.getElementById('topbarShowDashboardSwitch'),
        topbar_show_calendar: document.getElementById('topbarShowCalendarSwitch'),
        topbar_show_notifications: document.getElementById('topbarShowNotificationsSwitch'),
        topbar_show_help: document.getElementById('topbarShowHelpSwitch'),
        topbar_show_user: document.getElementById('topbarShowUserSwitch')
    };
    const panel = document.getElementById('ordenMenuPanel');
    const colLevel1 = document.getElementById('colLevel1');
    const colLevel2 = document.getElementById('colLevel2');
    const colLevel3 = document.getElementById('colLevel3');
    const listLevel1 = document.getElementById('listLevel1');
    const listLevel2 = document.getElementById('listLevel2');
    const listLevel3 = document.getElementById('listLevel3');
    const countLevel1 = document.getElementById('countLevel1');
    const countLevel2 = document.getElementById('countLevel2');
    const countLevel3 = document.getElementById('countLevel3');
    const selectedMenuTitle = document.getElementById('selectedMenuTitle');
    const selectedSubmenuTitle = document.getElementById('selectedSubmenuTitle');
    const noMenuSelectedAlert = document.getElementById('noMenuSelectedAlert');
    const btnCollapseL1 = document.getElementById('btnCollapseL1');
    const btnExpandL1 = document.getElementById('btnExpandL1');
    const btnCloseL2 = document.getElementById('btnCloseL2');
    const btnCloseL3 = document.getElementById('btnCloseL3');
    const btnBackToL1 = document.getElementById('btnBackToL1');
    const btnBackToL2 = document.getElementById('btnBackToL2');
    const btnSave = document.getElementById('btnSaveMenu');
    const btnReset = document.getElementById('btnResetMenu');

    if (!listLevel1) return;

    // Indexar posiciones guardadas
    const savedL1 = {};
    const savedL2 = {}; // { parentName: { itemName: pos } }
    const savedL3 = {}; // { compositeKey/parentName: { pageName: pos } }

    savedOrders.forEach(item => {
        const nivel = parseInt(item.nivel, 10);
        const pos = parseInt(item.posicion, 10);
        if (nivel === 1) {
            savedL1[item.nombre] = pos;
        } else if (nivel === 2 && item.padre) {
            if (!savedL2[item.padre]) savedL2[item.padre] = {};
            savedL2[item.padre][item.nombre] = pos;
        } else if (nivel === 3 && item.padre) {
            if (!savedL3[item.padre]) savedL3[item.padre] = {};
            savedL3[item.padre][item.nombre] = pos;
        }
    });

    // Estructura interna de datos reordenables
    let itemsL1 = [];
    let itemsL2 = {}; // { parentKey: [ { nombre, title, icon, padre, posicion, isNew, hasChildren, childCount, compositeKey } ] }
    let itemsL3 = {}; // { compositeKey: [ { nombre, title, icon, padre, posicion, isNew } ] }
    let selectedParentKey = null;
    let selectedParentTitle = '';
    let selectedSubmenuKey = null;
    let isL1Collapsed = false;

    // Inicializar estructuras desde tree
    Object.keys(tree).forEach(key => {
        const item = tree[key];
        const isNewL1 = savedL1[key] === undefined;
        itemsL1.push({
            nombre: key,
            title: item.title || key,
            icon: item.icon || 'fa-solid fa-folder',
            posicion: savedL1[key] !== undefined ? savedL1[key] : 9999,
            isNew: isNewL1
        });

        // Submenús (Nivel 2) y Páginas (Nivel 3)
        itemsL2[key] = [];
        if (item.menu && typeof item.menu === 'object') {
            const parentSaved = savedL2[key] || {};
            Object.keys(item.menu).forEach(subKey => {
                const subItem = item.menu[subKey];
                const isNewL2 = parentSaved[subKey] === undefined;
                const compositeKey = key + '/' + subKey;

                // Nivel 3 (Páginas hijas)
                itemsL3[compositeKey] = [];
                let hasChildren = false;
                let childCount = 0;

                if (subItem.menu && typeof subItem.menu === 'object' && Object.keys(subItem.menu).length > 0) {
                    hasChildren = true;
                    const parentSavedL3 = savedL3[compositeKey] || savedL3[subKey] || {};
                    Object.keys(subItem.menu).forEach(pageKey => {
                        const pageItem = subItem.menu[pageKey];
                        const isNewL3 = parentSavedL3[pageKey] === undefined;
                        itemsL3[compositeKey].push({
                            nombre: pageKey,
                            title: pageItem.title || pageKey,
                            icon: pageItem.icon || 'fa-solid fa-file',
                            padre: compositeKey,
                            posicion: parentSavedL3[pageKey] !== undefined ? parentSavedL3[pageKey] : 9999,
                            isNew: isNewL3
                        });
                    });

                    childCount = itemsL3[compositeKey].length;

                    // Ordenar Nivel 3 inicial
                    itemsL3[compositeKey].sort((a, b) => {
                        if (a.posicion === b.posicion) {
                            return a.title.localeCompare(b.title);
                        }
                        return a.posicion - b.posicion;
                    });
                }

                itemsL2[key].push({
                    nombre: subKey,
                    title: subItem.title || subKey,
                    icon: hasChildren ? (subItem.icon || 'fa-solid fa-folder') : (subItem.icon || 'fa-solid fa-file'),
                    padre: key,
                    posicion: parentSaved[subKey] !== undefined ? parentSaved[subKey] : 9999,
                    isNew: isNewL2,
                    hasChildren: hasChildren,
                    childCount: childCount,
                    compositeKey: compositeKey
                });
            });

            // Ordenar Nivel 2 inicial
            itemsL2[key].sort((a, b) => {
                if (a.posicion === b.posicion) {
                    return a.title.localeCompare(b.title);
                }
                return a.posicion - b.posicion;
            });
        }
    });

    // Ordenar Nivel 1 inicial
    itemsL1.sort((a, b) => {
        if (a.posicion === b.posicion) {
            return a.title.localeCompare(b.title);
        }
        return a.posicion - b.posicion;
    });

    // Control de Switch Activo
    if (switchActive) {
        switchActive.addEventListener('change', function () {
            ordenActivo = this.checked;
            if (ordenActivo) {
                panel.classList.remove('opacity-50', 'pointer-events-none');
            } else {
                panel.classList.add('opacity-50', 'pointer-events-none');
            }
        });
    }

    if (switchAgrupar) {
        switchAgrupar.addEventListener('change', function () {
            const i18n = window.AURALIS_I18N || {};
            const hint = i18n.agruparAdminGeneralSaveHint
                || 'Pulsa «Guardar y Actualizar Menú» para aplicar la agrupación en la barra. En menú horizontal, General se abre hacia la derecha.';
            if (typeof window.setToast === 'function') {
                window.setToast(hint, 'info', '', 5000);
            }
        });
    }

    if (switchAgruparInformes) {
        switchAgruparInformes.addEventListener('change', function () {
            const i18n = window.AURALIS_I18N || {};
            const hint = i18n.agruparInformesMenuSaveHint
                || 'Pulsa «Guardar y Actualizar Menú» para aplicar la carpeta Informes en la barra.';
            if (typeof window.setToast === 'function') {
                window.setToast(hint, 'info', '', 5000);
            }
        });
    }

    // Estado y sincronización de vistas responsive (Mobile drill-down vs Desktop 3-column)
    function isMobileView() {
        return window.innerWidth < 768;
    }

    function syncMobileView() {
        if (!isMobileView()) {
            // Modo Escritorio / Tablet: restaurar visualización de columnas
            colLevel1.classList.remove('mobile-hidden');
            colLevel2.classList.remove('mobile-hidden');
            if (btnBackToL1) btnBackToL1.classList.add('d-none');
            if (btnBackToL2) btnBackToL2.classList.add('d-none');
            if (selectedSubmenuKey) {
                colLevel3.classList.remove('d-none', 'mobile-hidden');
            } else {
                colLevel3.classList.add('d-none');
            }
            return;
        }

        // Modo Móvil (< 768px): Vistas apiladas Drill-down
        if (selectedSubmenuKey) {
            // Nivel 3 activo: ocultar N1 y N2, mostrar solo N3 con botón atrás
            colLevel1.classList.add('mobile-hidden');
            colLevel2.classList.add('mobile-hidden');
            colLevel3.classList.remove('d-none', 'mobile-hidden');
            if (btnBackToL2) btnBackToL2.classList.remove('d-none');
            if (btnBackToL1) btnBackToL1.classList.add('d-none');
        } else if (selectedParentKey) {
            // Nivel 2 activo: ocultar N1 y N3, mostrar solo N2 con botón atrás
            colLevel1.classList.add('mobile-hidden');
            colLevel2.classList.remove('mobile-hidden', 'd-none');
            colLevel3.classList.add('d-none', 'mobile-hidden');
            if (btnBackToL1) btnBackToL1.classList.remove('d-none');
            if (btnBackToL2) btnBackToL2.classList.add('d-none');
        } else {
            // Inicio: mostrar solo Nivel 1 a pantalla completa
            colLevel1.classList.remove('mobile-hidden', 'is-collapsed');
            colLevel2.classList.add('mobile-hidden');
            colLevel3.classList.add('d-none', 'mobile-hidden');
            if (btnBackToL1) btnBackToL1.classList.add('d-none');
            if (btnBackToL2) btnBackToL2.classList.add('d-none');
        }
    }

    window.addEventListener('resize', syncMobileView);

    // Toggle Colapso Nivel 1
    function setCollapseL1(collapsed) {
        if (isMobileView()) {
            return;
        }
        isL1Collapsed = !!collapsed;
        if (isL1Collapsed) {
            colLevel1.classList.add('is-collapsed');
        } else {
            colLevel1.classList.remove('is-collapsed');
        }
        renderLevel1();
    }

    if (btnCollapseL1) {
        btnCollapseL1.addEventListener('click', function (e) {
            e.stopPropagation();
            setCollapseL1(true);
        });
    }

    if (btnExpandL1) {
        btnExpandL1.addEventListener('click', function (e) {
            e.stopPropagation();
            setCollapseL1(false);
        });
    }

    if (btnCloseL2) {
        btnCloseL2.addEventListener('click', function (e) {
            e.stopPropagation();
            hideLevel2();
            renderLevel1();
        });
    }

    if (btnCloseL3) {
        btnCloseL3.addEventListener('click', function (e) {
            e.stopPropagation();
            hideLevel3();
        });
    }

    if (btnBackToL1) {
        btnBackToL1.addEventListener('click', function (e) {
            e.stopPropagation();
            hideLevel2();
            renderLevel1();
        });
    }

    if (btnBackToL2) {
        btnBackToL2.addEventListener('click', function (e) {
            e.stopPropagation();
            hideLevel3();
            if (selectedParentKey) {
                renderLevel2(selectedParentKey, selectedParentTitle);
            }
        });
    }

    // Mostrar / Ocultar Nivel 3
    function showLevel3(parentKey, subKey, subTitle) {
        if (!colLevel3) return;
        selectedSubmenuKey = subKey;

        if (selectedSubmenuTitle) {
            selectedSubmenuTitle.textContent = subTitle;
        }

        colLevel3.classList.remove('d-none');
        if (noSubmenuSelectedAlert) {
            noSubmenuSelectedAlert.classList.add('d-none');
        }
        if (listLevel3) {
            listLevel3.classList.remove('d-none');
        }

        // Colapsar Nivel 1 en escritorio para dar máxima amplitud a N2 y N3
        setCollapseL1(true);

        renderLevel3(parentKey, subKey, subTitle);
        syncMobileView();
    }

    function hideLevel3() {
        selectedSubmenuKey = null;

        if (colLevel3) {
            colLevel3.classList.add('d-none');
        }
        if (listLevel3) {
            listLevel3.classList.add('d-none');
            listLevel3.innerHTML = '';
        }
        if (noSubmenuSelectedAlert) {
            noSubmenuSelectedAlert.classList.remove('d-none');
        }
        if (selectedSubmenuTitle) {
            const i18n = window.AURALIS_I18N || {};
            selectedSubmenuTitle.textContent = i18n.seleccionaUnSubmenu || 'Selecciona un submenú';
        }

        // Restaurar expansión de Nivel 1 en escritorio
        setCollapseL1(false);

        // Re-renderizar Nivel 2 para deseleccionar el elemento activo
        if (selectedParentKey) {
            renderLevel2(selectedParentKey, selectedParentTitle);
        }
        syncMobileView();
    }

    function hideLevel2() {
        selectedParentKey = null;
        selectedParentTitle = '';

        const i18n = window.AURALIS_I18N || {};
        if (selectedMenuTitle) {
            selectedMenuTitle.textContent = i18n.seleccionaUnMenu || 'Selecciona un menú';
        }
        if (noMenuSelectedAlert) {
            noMenuSelectedAlert.classList.remove('d-none');
        }
        if (listLevel2) {
            listLevel2.classList.add('d-none');
            listLevel2.innerHTML = '';
        }
        if (countLevel2) {
            countLevel2.textContent = '0';
        }
        if (btnCloseL2) {
            btnCloseL2.classList.add('d-none');
        }

        hideLevel3();
        setCollapseL1(false);
        syncMobileView();
    }

    // Renderizar Nivel 1
    function renderLevel1() {
        listLevel1.innerHTML = '';
        countLevel1.textContent = itemsL1.length;

        itemsL1.forEach((item, index) => {
            const li = document.createElement('li');
            const isSelected = selectedParentKey === item.nombre;
            li.dataset.index = index;
            li.dataset.key = item.nombre;
            li.setAttribute('title', (index + 1) + '. ' + item.title);

            if (isL1Collapsed && !isMobileView()) {
                li.className = 'list-group-item d-flex align-items-center justify-content-center sortable-item p-2 mb-1 border rounded text-center' + (isSelected ? ' selected' : '');
                li.setAttribute('draggable', 'false');
                li.innerHTML = `
                    <i class="${item.icon} item-icon fs-5 ${isSelected ? 'text-success' : 'text-primary'}"></i>
                `;
            } else {
                li.className = 'list-group-item d-flex align-items-center justify-content-between sortable-item p-2 mb-1 border rounded' + (isSelected ? ' selected' : '');
                li.setAttribute('draggable', 'true');
                li.innerHTML = `
                    <div class="d-flex align-items-center me-2 overflow-hidden item-title-col flex-grow-1">
                        <i class="fa-solid fa-grip-vertical drag-handle me-2"></i>
                        <i class="${item.icon} item-icon me-2 ${isSelected ? 'text-success' : 'text-primary'}"></i>
                        <span class="item-title fw-bold text-truncate me-2">${escapeHtml(item.title)}</span>
                        ${item.isNew ? '<span class="badge bg-success ms-1">Nuevo</span>' : ''}
                    </div>
                    <div class="d-flex align-items-center item-chevron-col flex-shrink-0">
                        <span class="badge ${isSelected ? 'bg-success text-white' : 'bg-light text-dark border'} me-2">${index + 1}</span>
                        <i class="fa-solid fa-chevron-right ${isSelected ? 'text-success' : 'text-muted'} small"></i>
                    </div>
                `;
            }

            // Click para seleccionar (o deseleccionar si ya está activo)
            li.addEventListener('click', (e) => {
                if (e.target.closest('.drag-handle')) return;

                if (selectedParentKey === item.nombre) {
                    hideLevel2();
                    renderLevel1();
                    return;
                }

                selectedParentKey = item.nombre;
                selectedParentTitle = item.title;

                hideLevel3();
                renderLevel1();
                renderLevel2(item.nombre, item.title);
                syncMobileView();
            });

            listLevel1.appendChild(li);
        });

        if (!isL1Collapsed || isMobileView()) {
            setupDragAndDrop(listLevel1, itemsL1, renderLevel1);
        }
    }

    // Renderizar Nivel 2
    function renderLevel2(parentKey, parentTitle) {
        selectedParentKey = parentKey;
        selectedParentTitle = parentTitle;

        selectedMenuTitle.textContent = parentTitle;
        noMenuSelectedAlert.classList.add('d-none');
        listLevel2.classList.remove('d-none');
        listLevel2.innerHTML = '';
        if (btnCloseL2) {
            btnCloseL2.classList.remove('d-none');
        }

        const subItems = itemsL2[parentKey] || [];
        countLevel2.textContent = subItems.length;

        if (subItems.length === 0) {
            listLevel2.innerHTML = '<li class="list-group-item text-center text-muted py-4">Este menú no tiene sub-elementos.</li>';
            hideLevel3();
            return;
        }

        const i18n = window.AURALIS_I18N || {};

        subItems.forEach((subItem, index) => {
            const li = document.createElement('li');
            const isSubSelected = selectedSubmenuKey === subItem.nombre;
            li.className = 'list-group-item d-flex align-items-center justify-content-between sortable-item p-2 mb-1 border rounded' + (isSubSelected ? ' selected-sub' : '');
            li.setAttribute('draggable', 'true');
            li.dataset.index = index;
            li.dataset.key = subItem.nombre;

            const countText = subItem.childCount === 1
                ? (i18n.paginaCount || 'página')
                : (i18n.paginasCount || 'páginas');

            if (subItem.hasChildren) {
                li.innerHTML = `
                    <div class="d-flex align-items-center me-2 overflow-hidden flex-grow-1">
                        <i class="fa-solid fa-grip-vertical drag-handle me-2"></i>
                        <i class="fa-solid fa-folder-open item-icon me-2 ${isSubSelected ? 'text-success' : 'text-primary'}"></i>
                        <span class="item-title text-truncate me-2 ${isSubSelected ? 'fw-bold' : 'fw-semibold'}">${escapeHtml(subItem.title)}</span>
                        ${subItem.isNew ? '<span class="badge bg-success ms-1">Nuevo</span>' : ''}
                    </div>
                    <div class="d-flex align-items-center gap-1 flex-shrink-0">
                        <span class="badge bg-light text-muted border" style="font-size:0.75rem;">${subItem.childCount} ${countText}</span>
                        <span class="badge ${isSubSelected ? 'bg-success text-white' : 'bg-light text-dark border'}">${index + 1}</span>
                        <i class="fa-solid fa-chevron-right ${isSubSelected ? 'text-success' : 'text-primary'} ms-1 small"></i>
                    </div>
                `;
            } else {
                li.innerHTML = `
                    <div class="d-flex align-items-center me-2 overflow-hidden flex-grow-1">
                        <i class="fa-solid fa-grip-vertical drag-handle me-2"></i>
                        <i class="${subItem.icon} item-icon me-2 ${isSubSelected ? 'text-success' : 'text-secondary'}"></i>
                        <span class="item-title text-truncate me-2">${escapeHtml(subItem.title)}</span>
                        ${subItem.isNew ? '<span class="badge bg-success ms-1">Nuevo</span>' : ''}
                    </div>
                    <div class="d-flex align-items-center flex-shrink-0">
                        <span class="badge ${isSubSelected ? 'bg-success text-white' : 'bg-light text-dark border'}">${index + 1}</span>
                    </div>
                `;
            }

            // Click para seleccionar (o deseleccionar si ya está activo)
            li.addEventListener('click', (e) => {
                if (e.target.closest('.drag-handle')) return;

                if (subItem.hasChildren) {
                    if (selectedSubmenuKey === subItem.nombre) {
                        hideLevel3();
                        renderLevel2(parentKey, parentTitle);
                        return;
                    }
                    showLevel3(parentKey, subItem.nombre, subItem.title);
                    renderLevel2(parentKey, parentTitle);
                } else {
                    hideLevel3();
                    renderLevel2(parentKey, parentTitle);
                }
                syncMobileView();
            });

            listLevel2.appendChild(li);
        });

        setupDragAndDrop(listLevel2, itemsL2[parentKey], () => renderLevel2(parentKey, parentTitle));
    }

    // Renderizar Nivel 3 (Páginas hijas de un submenú)
    function renderLevel3(parentKey, subKey, subTitle) {
        if (!colLevel3) return;
        selectedSubmenuTitle.textContent = subTitle;
        noSubmenuSelectedAlert.classList.add('d-none');
        listLevel3.classList.remove('d-none');
        listLevel3.innerHTML = '';

        const compositeKey = parentKey + '/' + subKey;
        const pageItems = itemsL3[compositeKey] || [];
        countLevel3.textContent = pageItems.length;

        if (pageItems.length === 0) {
            const i18n = window.AURALIS_I18N || {};
            listLevel3.innerHTML = `<li class="list-group-item text-center text-muted py-4">${i18n.esteSubmenuNoTienePaginas || 'Este elemento no contiene páginas de tercer nivel.'}</li>`;
            return;
        }

        pageItems.forEach((page, index) => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex align-items-center justify-content-between sortable-item p-2 mb-1 border rounded';
            li.setAttribute('draggable', 'true');
            li.dataset.index = index;
            li.dataset.key = page.nombre;

            li.innerHTML = `
                <div class="d-flex align-items-center me-2 overflow-hidden flex-grow-1">
                    <i class="fa-solid fa-grip-vertical drag-handle me-2"></i>
                    <i class="${page.icon} item-icon text-primary me-2"></i>
                    <span class="item-title text-truncate me-2">${escapeHtml(page.title)}</span>
                    ${page.isNew ? '<span class="badge bg-success ms-1">Nuevo</span>' : ''}
                </div>
                <div class="d-flex align-items-center flex-shrink-0">
                    <span class="badge bg-light text-dark border">${index + 1}</span>
                </div>
            `;

            listLevel3.appendChild(li);
        });

        setupDragAndDrop(listLevel3, itemsL3[compositeKey], () => renderLevel3(parentKey, subKey, subTitle));
    }

    // Helper Drag and Drop nativo con reordenación dinámica visual en tiempo real
    function setupDragAndDrop(container, dataArray, callback) {
        let draggedItem = null;

        const items = container.querySelectorAll('.sortable-item');
        items.forEach(item => {
            item.addEventListener('dragstart', function (e) {
                draggedItem = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragend', function () {
                this.classList.remove('dragging');

                // Recalcular posiciones según el nuevo orden físico en el DOM tras arrastrar
                const currentElements = Array.from(container.querySelectorAll('.sortable-item'));
                const reordered = [];
                currentElements.forEach((el, idx) => {
                    const key = el.dataset.key;
                    const found = dataArray.find(it => it.nombre === key);
                    if (found) {
                        found.posicion = idx + 1;
                        reordered.push(found);
                    }
                });

                dataArray.length = 0;
                dataArray.push(...reordered);

                callback();
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (!draggedItem || draggedItem === this) return;

                const bounding = this.getBoundingClientRect();
                const offset = e.clientY - bounding.top - (bounding.height / 2);

                if (offset < 0) {
                    container.insertBefore(draggedItem, this);
                } else {
                    container.insertBefore(draggedItem, this.nextSibling);
                }
            });
        });
    }

    // Escape HTML básico
    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function restoreSaveButton() {
        if (!btnSave) return;
        btnSave.disabled = false;
        btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar y Actualizar Menú';
    }

    function restoreResetButton() {
        if (!btnReset) return;
        const i18n = window.AURALIS_I18N || {};
        btnReset.disabled = false;
        btnReset.innerHTML = '<i class="fa-solid fa-arrow-rotate-left me-1"></i>' + (i18n.menuOrderResetLabel || 'Restablecer por defecto');
    }

    // Botón Guardar
    if (btnSave) {
        btnSave.addEventListener('click', function () {
            if (requestInFlight) {
                return;
            }
            requestInFlight = true;
            btnSave.disabled = true;
            btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Guardando...';

            // Construir payload JSON de posiciones
            const payload = [];

            // Nivel 1
            itemsL1.forEach((item, index) => {
                payload.push({
                    nivel: 1,
                    nombre: item.nombre,
                    padre: null,
                    posicion: index + 1
                });
            });

            // Nivel 2
            Object.keys(itemsL2).forEach(parentKey => {
                const subItems = itemsL2[parentKey] || [];
                subItems.forEach((subItem, index) => {
                    payload.push({
                        nivel: 2,
                        nombre: subItem.nombre,
                        padre: parentKey,
                        posicion: index + 1
                    });
                });
            });

            // Nivel 3
            Object.keys(itemsL3).forEach(compositeKey => {
                const pageItems = itemsL3[compositeKey] || [];
                pageItems.forEach((pageItem, index) => {
                    payload.push({
                        nivel: 3,
                        nombre: pageItem.nombre,
                        padre: compositeKey,
                        posicion: index + 1
                    });
                });
            });

            const formData = new FormData();
            formData.append('action', 'save_orden_menu');
            formData.append('multireqtoken', data.formToken || '');
            formData.append('orden_menu_activo', switchActive ? (switchActive.checked ? '1' : '0') : '0');
            formData.append('agrupar_admin_general', switchAgrupar ? (switchAgrupar.checked ? '1' : '0') : '0');
            formData.append('agrupar_informes_menu', switchAgruparInformes ? (switchAgruparInformes.checked ? '1' : '0') : '1');
            Object.keys(topbarSwitches).forEach(key => {
                const toggle = topbarSwitches[key];
                formData.append(key, toggle && toggle.checked ? '1' : '0');
            });
            formData.append('menu_positions_json', JSON.stringify(payload));

            fetch(data.urlSave, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async function (res) {
                const text = await res.text();
                let resData;
                try {
                    resData = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error('Respuesta no JSON (HTTP ' + res.status + ')');
                }
                return resData;
            })
            .then(resData => {
                if (resData && resData.newToken) {
                    data.formToken = resData.newToken;
                }
                if (resData && resData.status === 'ok') {
                    window.location.reload();
                    return;
                }
                alert((resData && resData.message) || 'Error al guardar el orden del menú.');
                requestInFlight = false;
                restoreSaveButton();
            })
            .catch(err => {
                console.error(err);
                requestInFlight = false;
                restoreSaveButton();
                alert('No se pudo guardar el orden del menú. Revisa la consola o vuelve a intentarlo.');
            });
        });
    }

    // Botón Restablecer por Defecto
    if (btnReset) {
        btnReset.addEventListener('click', function () {
            if (requestInFlight) {
                return;
            }
            const i18n = window.AURALIS_I18N || {};
            if (!confirm(i18n.menuOrderResetConfirm || '¿Estás seguro de que deseas restablecer la ordenación del menú al orden alfabético por defecto de FacturaScripts?')) {
                return;
            }

            requestInFlight = true;
            btnReset.disabled = true;
            btnReset.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>' + (i18n.menuOrderResetting || 'Restableciendo...');

            const formData = new FormData();
            formData.append('action', 'reset_orden_menu');
            formData.append('multireqtoken', data.formToken || '');

            fetch(data.urlSave, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async function (res) {
                const text = await res.text();
                let resData;
                try {
                    resData = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error('Respuesta no JSON (HTTP ' + res.status + ')');
                }
                return resData;
            })
            .then(resData => {
                if (resData && resData.newToken) {
                    data.formToken = resData.newToken;
                }
                if (resData && resData.status === 'ok') {
                    window.location.reload();
                    return;
                }
                alert((resData && resData.message) || 'Error al restablecer el orden del menú.');
                requestInFlight = false;
                restoreResetButton();
            })
            .catch(err => {
                console.error(err);
                requestInFlight = false;
                restoreResetButton();
                alert('No se pudo restablecer el orden del menú. Revisa la consola o vuelve a intentarlo.');
            });
        });
    }

    // Inicializar renderizado de Nivel 1 y sincronizar vista responsive
    renderLevel1();
    syncMobileView();
});
