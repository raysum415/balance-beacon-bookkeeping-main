document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var sidebar = document.getElementById('balance-app-sidebar');
    var toggle = document.getElementById('balance-app-menu-toggle');
    var backdrop = document.getElementById('balance-app-sidebar-backdrop');
    function close() { if (sidebar) sidebar.classList.add('-translate-x-full'); if (backdrop) backdrop.classList.add('hidden'); }
    function open() { if (sidebar) sidebar.classList.remove('-translate-x-full'); if (backdrop) backdrop.classList.remove('hidden'); }
    if (toggle) toggle.addEventListener('click', open);
    if (backdrop) backdrop.addEventListener('click', close);
    var closeButton = document.getElementById('balance-app-sidebar-close');
    if (closeButton) closeButton.addEventListener('click', close);
    document.querySelectorAll('#balance-app-sidebar a').forEach(function (link) { link.addEventListener('click', close); });
    window.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
});
