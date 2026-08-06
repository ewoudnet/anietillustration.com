(function () {
    'use strict';

    function resetMenu(menu) {
        menu.classList.remove('open');
        menu.style.position = '';
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
    }

    function closeAllMenus(except) {
        document.querySelectorAll('.actions-menu.open').forEach(function (menu) {
            if (menu !== except) {
                resetMenu(menu);
            }
        });
    }

    function positionMenu(trigger, menu) {
        var rect = trigger.getBoundingClientRect();

        // position:fixed t.o.v. het scherm (niet absolute t.o.v. .actions-dropdown),
        // zodat een scrollbare voorouder (.table-wrapper) het menu niet kan clippen.
        menu.style.position = 'fixed';
        menu.style.left = 'auto';
        menu.style.top = (rect.bottom + 4) + 'px';

        var rightEdge = window.innerWidth - rect.right;
        menu.style.right = Math.max(8, rightEdge) + 'px';
    }

    function repositionOpenMenus() {
        document.querySelectorAll('.actions-menu.open').forEach(function (menu) {
            var trigger = menu.parentElement.querySelector('.actions-trigger');
            if (trigger) {
                positionMenu(trigger, menu);
            }
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.actions-trigger');

        if (!trigger) {
            closeAllMenus(null);
            return;
        }

        var menu = trigger.parentElement.querySelector('.actions-menu');
        if (!menu) {
            return;
        }

        var wasOpen = menu.classList.contains('open');
        closeAllMenus(menu);

        if (wasOpen) {
            resetMenu(menu);
        } else {
            positionMenu(trigger, menu);
            menu.classList.add('open');
        }

        e.stopPropagation();
    });

    window.addEventListener('scroll', repositionOpenMenus, true);
    window.addEventListener('resize', repositionOpenMenus);
})();
