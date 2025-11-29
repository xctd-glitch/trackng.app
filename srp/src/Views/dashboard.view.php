<?php
$pageTitle = 'SRP Traffic Control';
require __DIR__ . '/components/header.php';
?>
<div x-data="dash" x-cloak>
    <?php require __DIR__ . '/components/dashboard-header.php'; ?>

<!-- Toast & Confirm Modal -->
<?php require __DIR__ . '/components/toast.php'; ?>

<main class="flex-1 w-full">
    <?php require __DIR__ . '/components/dashboard-content.php'; ?>
</main>
</div>

<!-- Load external JavaScript file -->
<script src="/assets/js/dashboard.js" nonce="<?= htmlspecialchars($cspNonce ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); ?>"></script>

<?php require __DIR__ . '/components/footer.php'; ?>
