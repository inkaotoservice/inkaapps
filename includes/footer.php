    </main><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<!-- Lucide Icons Init -->
<script>
    lucide.createIcons();

    function openSidebar() {
        document.getElementById('sidebar').classList.remove('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.remove('hidden');
    }
    function closeSidebar() {
        document.getElementById('sidebar').classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.add('hidden');
    }
</script>
<?php if (isset($extra_js)) echo $extra_js; ?>
</body>
</html>
