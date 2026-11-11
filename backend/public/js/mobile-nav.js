(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {


        const hamburger = document.getElementById('hamburgerBtn');
        const drawer    = document.getElementById('mobileNavDrawer');
        const overlay   = document.getElementById('mobileNavOverlay');
        const closeBtn  = document.getElementById('mobileNavClose');
        const logoutBtn = document.getElementById('mobileLogoutBtn');


        if (!hamburger) { console.error('[MobileNav] hamburgerBtn nije pronađen!'); return; }
        if (!drawer)    { console.error('[MobileNav] mobileNavDrawer nije pronađen!'); return; }
        if (!overlay)   { console.error('[MobileNav] mobileNavOverlay nije pronađen!'); return; }

        // Klik na hamburger otvara ili zatvara meni
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            drawer.classList.contains('open') ? closeMenu() : openMenu();
        });

        // Klik na pozadinu zatvara meni
        overlay.addEventListener('click', closeMenu);

        if (closeBtn) closeBtn.addEventListener('click', closeMenu);

        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                closeMenu();
                if (window.potvrdiOdjavu) {
                    window.potvrdiOdjavu();
                } else if (confirm('Da li ste sigurni da želite da se odjavite?')) {
                    const form = document.getElementById('logoutForm');
                    if (form) form.submit();
                }
            });
        }

        drawer.querySelectorAll('.mobile-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                setTimeout(closeMenu, 150);
            });
        });

        // Pritisak na taster Escape zatvara meni
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });

    });

    function openMenu() {
        const drawer    = document.getElementById('mobileNavDrawer');
        const overlay   = document.getElementById('mobileNavOverlay');
        const hamburger = document.getElementById('hamburgerBtn');

        overlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
        overlay.offsetHeight;
        overlay.classList.add('visible');
        drawer.classList.add('open');
        if (hamburger) hamburger.classList.add('open');
    }

    function closeMenu() {
        const drawer    = document.getElementById('mobileNavDrawer');
        const overlay   = document.getElementById('mobileNavOverlay');
        const hamburger = document.getElementById('hamburgerBtn');

        overlay.classList.remove('visible');
        drawer.classList.remove('open');
        if (hamburger) hamburger.classList.remove('open');
        document.body.style.overflow = '';

        setTimeout(function () {
            if (!overlay.classList.contains('visible')) {
                overlay.style.display = 'none';
            }
        }, 350);
    }

})();
