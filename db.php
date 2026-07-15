<?php
/**
 * db.php - connexion PDO partagee (singleton simple)
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // En prod, ne jamais afficher le message brut a l'utilisateur final
            error_log('Erreur connexion BDD : ' . $e->getMessage());
            die('Erreur de connexion a la base de donnees. Contactez l\'administrateur.');
        }
    }
    return $pdo;
}
