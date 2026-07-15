<?php
/**
 * auth.php - gestion de session, authentification, controle des roles
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(SESSION_LIFETIME);
    session_start();
}

// Hierarchie des roles : plus l'indice est eleve, plus les droits sont larges.
// administrateur = developpeur, peut nommer le president lui-meme.
const ROLE_HIERARCHIE = [
    'encadrant'            => 1,
    'responsable_materiel' => 2,
    'president'            => 3,
    'administrateur'       => 4,
];

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function has_role(string $role): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === $role;
}

/** Vrai si l'utilisateur a AU MOINS le niveau de droits du role demande */
function has_min_role(string $role): bool
{
    $user = current_user();
    if ($user === null) return false;
    $niveauRequis = ROLE_HIERARCHIE[$role] ?? 999;
    $niveauUser   = ROLE_HIERARCHIE[$user['role']] ?? 0;
    return $niveauUser >= $niveauRequis;
}

/** Bloque l'acces si non connecte */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
    // Regenere le timestamp d'activite (expiration glissante)
    if (isset($_SESSION['derniere_activite']) && (time() - $_SESSION['derniere_activite']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header('Location: /login.php?expire=1');
        exit;
    }
    $_SESSION['derniere_activite'] = time();
}

/** Bloque l'acces si l'utilisateur n'a pas au moins ce niveau de role */
function require_min_role(string $role): void
{
    require_login();
    if (!has_min_role($role)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:2rem;">Acces refuse : cette page necessite le role "' . htmlspecialchars($role) . '" ou superieur.</p><p><a href="/index.php">Retour a l\'accueil</a></p>');
    }
}

/** Bloque l'acces si l'utilisateur n'a pas EXACTEMENT ce role (rarement utile, prefer require_min_role) */
function require_exact_role(string $role): void
{
    require_login();
    if (!has_role($role)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:2rem;">Acces reserve au role "' . htmlspecialchars($role) . '".</p>');
    }
}

function login(string $email, string $motDePasse): bool
{
    $stmt = db()->prepare('SELECT id, email, mot_de_passe_hash, nom, prenom, role, actif FROM utilisateurs WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !$user['actif'] || !password_verify($motDePasse, $user['mot_de_passe_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    unset($user['mot_de_passe_hash']);
    $_SESSION['user'] = $user;
    $_SESSION['derniere_activite'] = time();
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_unset();
    session_destroy();
}

/** Genere/verifie un jeton CSRF simple pour les formulaires */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        die('Jeton de securite invalide, merci de recharger la page et reessayer.');
    }
}
