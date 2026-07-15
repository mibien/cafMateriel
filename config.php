<?php
/**
 * config.php
 * Renseigne ici tes identifiants OVH (visibles dans ton manager phpMyAdmin / hebergement).
 * Ce fichier ne doit JAMAIS etre commite dans un depot public.
 */

// --- A ADAPTER avec tes infos OVH ---
define('DB_HOST', 'hote.mysql.db'); // ton hote MySQL OVH
define('DB_NAME', 'hote');           // nom de la base (souvent identique a l'hote)
define('DB_USER', 'ton_identifiant_ovh');
define('DB_PASS', 'ton_mot_de_passe_ovh');
define('DB_CHARSET', 'utf8mb4');

// Duree de session (en secondes) avant deconnexion automatique
define('SESSION_LIFETIME', 3600 * 4); // 4h

// Fuseau horaire
date_default_timezone_set('Europe/Paris');
