/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Sincroniza aria-expanded del banner de avisos Auralis con Bootstrap Collapse.
 */
(function () {
    'use strict';

    function bindAvisosCollapse(root) {
        if (!root || !window.bootstrap) {
            return;
        }

        root.querySelectorAll('.auralis-avisos-banner [data-bs-toggle="collapse"]').forEach(function (btn) {
            var targetSelector = btn.getAttribute('data-bs-target');
            if (!targetSelector) {
                return;
            }

            var target = root.querySelector(targetSelector) || document.querySelector(targetSelector);
            if (!target) {
                return;
            }

            target.addEventListener('shown.bs.collapse', function () {
                btn.setAttribute('aria-expanded', 'true');
            });
            target.addEventListener('hidden.bs.collapse', function () {
                btn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    function init() {
        document.querySelectorAll('.auralis-avisos-wrap').forEach(bindAvisosCollapse);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Tras recargas AJAX de cabecera de documento
    document.addEventListener('fsDocRecalculated', init);
    document.addEventListener('ajaxComplete', init);
})();
