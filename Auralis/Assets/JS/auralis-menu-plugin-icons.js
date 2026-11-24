/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Clona iconos de hooks MenuIcon* del menú desktop al slot móvil sin duplicar modales ni scripts
 */
(function () {
    'use strict';

    function nodesBetween(startId, endId) {
        const start = document.getElementById(startId);
        const end = document.getElementById(endId);
        if (!start || !end || start.parentNode !== end.parentNode) {
            return [];
        }
        const nodes = [];
        let node = start.nextSibling;
        while (node && node !== end) {
            nodes.push(node);
            node = node.nextSibling;
        }
        return nodes;
    }

    function stripCloneIds(root) {
        if (root.nodeType !== 1) {
            return;
        }
        if (root.id) {
            root.setAttribute('data-auralis-clone-of', root.id);
            root.removeAttribute('id');
        }
        root.querySelectorAll('[id]').forEach(function (el) {
            el.setAttribute('data-auralis-clone-of', el.id);
            el.removeAttribute('id');
        });
        root.querySelectorAll('script, style, link, .modal').forEach(function (el) {
            el.remove();
        });
    }

    function cloneForMobile(node) {
        if (node.nodeType !== 1) {
            return null;
        }
        if (node.matches('script, style, link, .modal, [hidden]')) {
            return null;
        }
        if (node.id && node.id.indexOf('auralis-mic-') === 0) {
            return null;
        }
        const clone = node.cloneNode(true);
        stripCloneIds(clone);
        return clone;
    }

    function moveModalsToBody(nodes) {
        nodes.forEach(function (node) {
            if (node.nodeType !== 1) {
                return;
            }
            if (node.classList && node.classList.contains('modal')) {
                document.body.appendChild(node);
                return;
            }
            if (node.querySelectorAll) {
                node.querySelectorAll('.modal').forEach(function (modal) {
                    document.body.appendChild(modal);
                });
            }
        });
    }

    function polishMobileClone(clone, slotId) {
        if (slotId.indexOf('mobile') === -1 || clone.nodeType !== 1) {
            return;
        }
        if (clone.matches('li.nav-item')) {
            clone.querySelectorAll(':scope > .nav-link, :scope > a').forEach(function (link) {
                link.classList.add('px-1', 'py-0');
                link.querySelectorAll('i').forEach(function (icon) {
                    icon.classList.add('fa-lg');
                });
            });
        }
    }

    function fillSlot(slotId, nodes) {
        const slot = document.getElementById(slotId);
        if (!slot || !slot.parentNode) {
            return;
        }
        const parent = slot.parentNode;
        nodes.forEach(function (node) {
            const clone = cloneForMobile(node);
            if (clone) {
                polishMobileClone(clone, slotId);
                parent.insertBefore(clone, slot);
            }
        });
    }

    function syncCloneVisualState() {
        document.querySelectorAll('[data-auralis-clone-of]').forEach(function (clone) {
            const srcId = clone.getAttribute('data-auralis-clone-of');
            const src = document.getElementById(srcId);
            if (!src) {
                return;
            }
            const pairs = [
                ['.fa-circle', '.fa-circle'],
                ['.fa-bell', '.fa-bell'],
                ['.badge', '.badge'],
                ['.unread', '.unread']
            ];
            pairs.forEach(function (pair) {
                const srcEl = src.matches(pair[0]) ? src : src.querySelector(pair[0]);
                const cloneEl = clone.matches(pair[1]) ? clone : clone.querySelector(pair[1]);
                if (srcEl && cloneEl) {
                    cloneEl.className = srcEl.className;
                    if (srcEl.textContent !== undefined && cloneEl.childElementCount === 0) {
                        cloneEl.textContent = srcEl.textContent;
                    }
                }
            });
            if (src.classList && clone.classList && src.tagName === clone.tagName) {
                ['d-none', 'active', 'show', 'text-danger'].forEach(function (cls) {
                    clone.classList.toggle(cls, src.classList.contains(cls));
                });
            }
        });
    }

    function init() {
        const beforeNodes = nodesBetween('auralis-mic-before-start', 'auralis-mic-before-end');
        const afterNodes = nodesBetween('auralis-mic-after-start', 'auralis-mic-after-end');
        const userNodes = nodesBetween('auralis-mic-user-start', 'auralis-mic-user-end');

        moveModalsToBody(beforeNodes.concat(afterNodes));

        fillSlot('auralis-mobile-mic-before-slot', beforeNodes);
        fillSlot('auralis-mobile-mic-after-slot', afterNodes);
        fillSlot('auralis-mobile-mic-user-slot', userNodes);

        syncCloneVisualState();
        setInterval(syncCloneVisualState, 1500);

        if (typeof MutationObserver !== 'undefined') {
            const targets = [];
            ['notificationsIcon', 'notificationsModal'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    targets.push(el);
                }
            });
            if (targets.length) {
                const obs = new MutationObserver(syncCloneVisualState);
                targets.forEach(function (el) {
                    obs.observe(el, { attributes: true, childList: true, subtree: true, characterData: true });
                });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
