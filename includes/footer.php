    </section>
    <footer class="app-footer">PrintBoss v<?= APP_VERSION ?> &middot; ICT312 Advanced Web Information Systems &middot; <a href="<?= BASE_PATH ?>/privacy.php">Privacy</a></footer>
  </main>
</div>
<script src="<?= BASE_PATH ?>/assets/js/app.js"></script>
<?php if (!empty($pageScripts)): foreach ((array)$pageScripts as $s): ?>
<script src="<?= BASE_PATH ?>/assets/js/<?= e($s) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
