/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Motor compartido del buscador Auralis: autocomplete de menú, expand-on-click (horizontal/móvil) y atajos Ctrl+K /
 */
(function () {
    "use strict";

    const i18n = window.auralisSearchI18n || {};
    const noResultsLabel = i18n.noResults || "No results";

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function sanitizeMenuUrl(url) {
        const u = String(url || "").trim();
        if (u === "" || u === "#") {
            return "#";
        }
        if (/^(javascript|data|vbscript):/i.test(u)) {
            return "#";
        }
        if (/["'<>\\]/.test(u)) {
            return "#";
        }
        return u;
    }

    function sanitizeIconClass(icon) {
        const parts = String(icon || "")
            .trim()
            .split(/\s+/)
            .filter((p) => /^[a-z0-9_-]+$/i.test(p));
        return parts.length ? parts.join(" ") : "fa-solid fa-file";
    }

    function isTypingTarget(el) {
        if (!el || el === document.body) {
            return false;
        }
        const tag = (el.tagName || "").toLowerCase();
        if (tag === "input" || tag === "textarea" || tag === "select") {
            return true;
        }
        if (el.isContentEditable) {
            return true;
        }
        return false;
    }

    function isVisible(el) {
        return !!(el && el.offsetParent !== null);
    }

    function setupSearchAutocomplete(inputEl, dropdownEl) {
        if (!inputEl || !dropdownEl) {
            return;
        }

        let selectedIndex = -1;

        function renderSearchResults(query) {
            const q = query.trim().toLowerCase();
            if (q.length === 0) {
                dropdownEl.classList.add("d-none");
                dropdownEl.innerHTML = "";
                selectedIndex = -1;
                return;
            }

            const pages = window.auralisMenuPages || [];
            const matches = pages
                .filter((p) => {
                    return (
                        (p.title && p.title.toLowerCase().includes(q)) ||
                        (p.menu && p.menu.toLowerCase().includes(q))
                    );
                })
                .slice(0, 8);

            if (matches.length === 0) {
                dropdownEl.innerHTML =
                    '<div class="px-3 py-2 text-muted small">' +
                    '<i class="fa-solid fa-circle-info me-1"></i>' +
                    escapeHtml(noResultsLabel) +
                    ' "<strong>' +
                    escapeHtml(query) +
                    '</strong>"' +
                    "</div>";
            } else {
                let html = "";
                matches.forEach((item, index) => {
                    const safeUrl = sanitizeMenuUrl(item.url);
                    const safeIcon = sanitizeIconClass(item.icon);
                    const menuBadge = item.menu
                        ? '<span class="auralis-search-item-badge">' +
                          escapeHtml(item.menu) +
                          "</span>"
                        : "";
                    html +=
                        '<a href="' +
                        escapeHtml(safeUrl) +
                        '" class="auralis-search-item' +
                        (index === selectedIndex ? " active" : "") +
                        '" data-index="' +
                        index +
                        '">' +
                        '<div class="auralis-search-item-left">' +
                        '<div class="auralis-search-item-icon"><i class="' +
                        safeIcon +
                        '"></i></div>' +
                        '<div class="auralis-search-item-title">' +
                        escapeHtml(item.title) +
                        "</div>" +
                        "</div>" +
                        menuBadge +
                        "</a>";
                });
                dropdownEl.innerHTML = html;
            }
            dropdownEl.classList.remove("d-none");
        }

        function updateActiveSearchItem(items) {
            items.forEach((item, idx) => {
                if (idx === selectedIndex) {
                    item.classList.add("active");
                    item.scrollIntoView({ block: "nearest" });
                } else {
                    item.classList.remove("active");
                }
            });
        }

        inputEl.addEventListener("input", function () {
            selectedIndex = -1;
            renderSearchResults(this.value);
        });

        inputEl.addEventListener("keydown", function (e) {
            const items = dropdownEl.querySelectorAll(".auralis-search-item");

            if (e.key === "Escape") {
                dropdownEl.classList.add("d-none");
                return;
            }

            if (items.length === 0) {
                return;
            }

            if (e.key === "ArrowDown") {
                e.preventDefault();
                selectedIndex = (selectedIndex + 1) % items.length;
                updateActiveSearchItem(items);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                selectedIndex = (selectedIndex - 1 + items.length) % items.length;
                updateActiveSearchItem(items);
            } else if (e.key === "Enter" && selectedIndex >= 0) {
                e.preventDefault();
                e.stopPropagation();
                const targetItem = items[selectedIndex];
                if (targetItem) {
                    const url = targetItem.getAttribute("href");
                    if (url) {
                        window.location.href = url;
                    }
                }
            }
        });

        function handleItemNavigation(e) {
            const itemEl = e.target.closest(".auralis-search-item");
            if (itemEl) {
                e.preventDefault();
                e.stopPropagation();
                const url = itemEl.getAttribute("href");
                if (url && url !== "#") {
                    window.location.href = url;
                }
            }
        }

        dropdownEl.addEventListener("mousedown", handleItemNavigation);
        dropdownEl.addEventListener("click", handleItemNavigation);

        const formEl = inputEl.closest("form");
        if (formEl) {
            formEl.addEventListener("submit", function (e) {
                const activeItem = dropdownEl.querySelector(".auralis-search-item.active");
                if (activeItem) {
                    e.preventDefault();
                    e.stopPropagation();
                    const url = activeItem.getAttribute("href");
                    if (url && url !== "#") {
                        window.location.href = url;
                    }
                    return;
                }
                if (!String(inputEl.value || "").trim()) {
                    e.preventDefault();
                }
            });
        }

        document.addEventListener("click", function (e) {
            if (!inputEl.contains(e.target) && !dropdownEl.contains(e.target)) {
                dropdownEl.classList.add("d-none");
            }
        });
    }

    function expandSearchRoot(root) {
        if (!root) {
            return;
        }
        root.classList.add("is-expanded");
        const input = root.querySelector(".auralis-search-input");
        if (input) {
            window.setTimeout(function () {
                input.focus();
                input.select();
            }, 50);
        }
    }

    function collapseSearchRoot(root) {
        if (!root) {
            return;
        }
        const input = root.querySelector(".auralis-search-input");
        const dropdown = root.querySelector(".auralis-search-results-dropdown");
        if (input && String(input.value || "").trim() !== "") {
            return;
        }
        root.classList.remove("is-expanded");
        if (dropdown) {
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
        }
    }

    function setupExpandableSearch(root) {
        if (!root || root.dataset.auralisSearchBound === "1") {
            return;
        }
        root.dataset.auralisSearchBound = "1";

        const trigger = root.querySelector(".auralis-search-expand-trigger");
        const input = root.querySelector(".auralis-search-input");
        const dropdown = root.querySelector(".auralis-search-results-dropdown");

        if (trigger) {
            trigger.addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                expandSearchRoot(root);
            });
        }

        function forceCollapseExpandable() {
            if (input) {
                input.value = "";
            }
            if (dropdown) {
                dropdown.classList.add("d-none");
                dropdown.innerHTML = "";
            }
            root.classList.remove("is-expanded");
        }

        if (input) {
            input.addEventListener("blur", function () {
                window.setTimeout(function () {
                    if (!root.classList.contains("is-expanded")) {
                        return;
                    }
                    if (root.contains(document.activeElement)) {
                        return;
                    }
                    if (dropdown && !dropdown.classList.contains("d-none") && dropdown.matches(":hover")) {
                        return;
                    }
                    forceCollapseExpandable();
                }, 180);
            });

            input.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    forceCollapseExpandable();
                    input.blur();
                }
            });
        }

        document.addEventListener("mousedown", function (e) {
            if (!root.classList.contains("is-expanded")) {
                return;
            }
            if (root.contains(e.target)) {
                return;
            }
            forceCollapseExpandable();
        });
    }

    function setupMobileSearchPanel() {
        const trigger = document.getElementById("menuIconSearchMobile");
        const panel = document.getElementById("auralisMobileSearchPanel");
        if (!trigger || !panel || panel.dataset.auralisSearchBound === "1") {
            return;
        }
        panel.dataset.auralisSearchBound = "1";

        const dialog = panel.querySelector(".auralis-mobile-search-dialog");
        const input = document.getElementById("auralisMobileSearchInput");
        const dropdown = document.getElementById("auralisMobileSearchResultsDropdown");
        const closeBtn = panel.querySelector(".auralis-mobile-search-close");

        function openPanel() {
            panel.classList.remove("d-none");
            panel.classList.add("is-open");
            trigger.classList.add("active");
            document.body.classList.add("auralis-search-modal-open");
            if (input) {
                window.setTimeout(function () {
                    input.focus();
                    input.select();
                }, 50);
            }
        }

        function closePanel() {
            panel.classList.add("d-none");
            panel.classList.remove("is-open");
            trigger.classList.remove("active");
            document.body.classList.remove("auralis-search-modal-open");
            if (dropdown) {
                dropdown.classList.add("d-none");
                dropdown.innerHTML = "";
            }
            if (input) {
                input.value = "";
            }
        }

        trigger.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (panel.classList.contains("is-open")) {
                closePanel();
            } else {
                openPanel();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener("click", function (e) {
                e.preventDefault();
                closePanel();
            });
        }

        // Clic en el fondo oscurecido (no en el diálogo)
        panel.addEventListener("mousedown", function (e) {
            if (dialog && dialog.contains(e.target)) {
                return;
            }
            closePanel();
        });

        if (input) {
            input.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    closePanel();
                }
            });
        }

        window.auralisOpenMobileSearch = openPanel;
        window.auralisCloseMobileSearch = closePanel;
    }

    function focusPreferredSearch() {
        const horizontalRoot = document.getElementById("auralisHorizontalSearchRoot");
        const horizontalInput = document.getElementById("auralisHorizontalSearchInput");
        const verticalInput = document.getElementById("auralisVerticalSearchInput");
        const sidebarInput = document.getElementById("auralisSidebarSearchInput");
        const mobileTrigger = document.getElementById("menuIconSearchMobile");
        const mobilePanel = document.getElementById("auralisMobileSearchPanel");

        if (horizontalRoot && isVisible(horizontalRoot) && horizontalInput) {
            expandSearchRoot(horizontalRoot);
            return true;
        }

        if (verticalInput && isVisible(verticalInput)) {
            verticalInput.focus();
            verticalInput.select();
            return true;
        }

        if (mobileTrigger && isVisible(mobileTrigger) && typeof window.auralisOpenMobileSearch === "function") {
            window.auralisOpenMobileSearch();
            return true;
        }

        if (sidebarInput) {
            if (typeof window.showAuralisSidebar === "function" && !isVisible(sidebarInput)) {
                window.showAuralisSidebar();
            }
            window.setTimeout(function () {
                const el = document.getElementById("auralisSidebarSearchInput");
                if (el) {
                    el.focus();
                    el.select();
                }
            }, 80);
            return true;
        }

        if (mobilePanel && typeof window.auralisOpenMobileSearch === "function") {
            window.auralisOpenMobileSearch();
            return true;
        }

        return false;
    }

    function patchMegaSearchClickableRows() {
        if (!window.jQuery) {
            return;
        }
        const origReload = window.reloadClickableRow;
        window.reloadClickableRow = function () {
            window.jQuery(".clickableRow").off("mousedown");
            if (typeof origReload === "function") {
                origReload();
            }
        };
    }

    function initAuralisSearch() {
        setupSearchAutocomplete(
            document.getElementById("auralisVerticalSearchInput"),
            document.getElementById("auralisSearchResultsDropdown")
        );
        setupSearchAutocomplete(
            document.getElementById("auralisSidebarSearchInput"),
            document.getElementById("auralisSidebarSearchResultsDropdown")
        );
        setupSearchAutocomplete(
            document.getElementById("auralisHorizontalSearchInput"),
            document.getElementById("auralisHorizontalSearchResultsDropdown")
        );
        setupSearchAutocomplete(
            document.getElementById("auralisMobileSearchInput"),
            document.getElementById("auralisMobileSearchResultsDropdown")
        );

        setupExpandableSearch(document.getElementById("auralisHorizontalSearchRoot"));
        setupMobileSearchPanel();
        patchMegaSearchClickableRows();
    }

    document.addEventListener("keydown", function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
            e.preventDefault();
            focusPreferredSearch();
            return;
        }

        if (e.key === "/" && !e.ctrlKey && !e.metaKey && !e.altKey) {
            if (isTypingTarget(e.target)) {
                return;
            }
            e.preventDefault();
            focusPreferredSearch();
        }
    });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAuralisSearch);
    } else {
        initAuralisSearch();
    }

    window.auralisSetupSearchAutocomplete = setupSearchAutocomplete;
    window.auralisFocusSearch = focusPreferredSearch;
})();
