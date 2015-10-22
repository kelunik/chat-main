<?= $this->inline("header.php") ?>
<main id="react"><span class="loader"></span></main>

<script type="application/json" id="js-api-me"><?= json_encode($user) ?></script>
<script type="application/json" id="js-api-me-rooms"><?= json_encode($rooms) ?></script>
<script src="/bundle.js"></script>
<?= $this->inline("footer.php") ?>
