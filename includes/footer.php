<?php
// ============================================================
// Layout - Footer Component
// ============================================================
?>
    </main>
    <footer class="bg-white border-top py-3 px-4 text-center text-muted small">
        <div class="d-flex justify-content-between align-items-center">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
            <span>Version 1.0.0 (Production Ready)</span>
        </div>
    </footer>
</div><!-- /.app-main -->
</div><!-- /.app-wrapper -->

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Mobile Sidebar & Live Clock Script -->
<script>
    function toggleMobileSidebar() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            const isOpen = sidebar.classList.contains('show');
            if (isOpen) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                document.body.classList.remove('overflow-hidden');
            } else {
                sidebar.classList.add('show');
                overlay.classList.add('show');
                document.body.classList.add('overflow-hidden');
            }
        }
    }

    // Auto-close mobile sidebar when clicking a link inside it
    document.querySelectorAll('#appSidebar .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) {
                toggleMobileSidebar();
            }
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('appSidebar');
            if (sidebar && sidebar.classList.contains('show')) {
                toggleMobileSidebar();
            }
        }
    });

    setInterval(function() {
        const now = new Date();
        const clockEl = document.getElementById('systemClock');
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString();
        }
    }, 1000);
</script>
</body>
</html>
