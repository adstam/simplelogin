<?php
// redirect.php

// Zorg ervoor dat deze file alleen wordt aangeroepen vanuit de browser
defined('_JEXEC') or die;

// Verkrijg de token uit de GET-parameter
$token = isset($_GET['token']) ? $_GET['token'] : '';

// Controleer of de token geldig is
if (!empty($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    // Redirect naar de Joomla-plugin
    header("Location: /index.php?simplelogin=1&token=" . urlencode($token));
    exit();
} else {
    // Toon een foutmelding voor een ongeldige link
    echo "Ongeldige link.";
}
?>
