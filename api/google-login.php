<?php

declare(strict_types=1);

// Sans .htaccess : ce fichier physique sert à la fois au départ OAuth
// et au callback Google. Si Google renvoie state + code/error, on traite
// le callback ; sinon on démarre la connexion.
if (isset($_GET['state']) && (isset($_GET['code']) || isset($_GET['error']))) {
    define('BW_GOOGLE_AUTH_ACTION', 'callback');
} else {
    define('BW_GOOGLE_AUTH_ACTION', 'start');
}

require __DIR__ . '/google-auth.php';
