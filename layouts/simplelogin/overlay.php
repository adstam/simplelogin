<?php
defined('_JEXEC') or die;

extract($displayData);
?>

<div class="simplelogin-overlay">
    <div class="simplelogin-card">
        <?php if ($statusMessage): ?>
            <div class="alert alert-<?php echo $statusType; ?>">
                <?php echo $statusMessage; ?>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <form method="post">
                <input type="text" name="username" placeholder="Gebruikersnaam" required>
                <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
                <button type="submit">Verstuur link</button>
            </form>
        <?php endif; ?>

        <?php if ($redirectUrl): ?>
            <script>
                setTimeout(() => window.location.href = "<?php echo $redirectUrl; ?>", 2000);
            </script>
        <?php endif; ?>
    </div>
</div>