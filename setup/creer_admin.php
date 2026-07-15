<?php
/**
 * setup/creer_admin.php
 *
 * A UTILISER UNE SEULE FOIS pour creer ton compte administrateur (developpeur),
 * puis A SUPPRIMER du serveur (ou au moins renommer/proteger) car il ne demande
 * aucune authentification.
 */
require_once __DIR__ . '/../db.php';

$message = null;
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $motDePasse = $_POST['mot_de_passe'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Email invalide.";
    } elseif (strlen($motDePasse) < 8) {
        $erreur = "Le mot de passe doit faire au moins 8 caracteres.";
    } else {
        try {
            $stmt = db()->prepare("
                INSERT INTO utilisateurs (email, mot_de_passe_hash, nom, prenom, role, actif)
                VALUES (?, ?, ?, ?, 'administrateur', 1)
            ");
            $stmt->execute([$email, password_hash($motDePasse, PASSWORD_DEFAULT), $nom, $prenom]);
            $message = "Compte administrateur cree avec succes. SUPPRIME MAINTENANT ce fichier (setup/creer_admin.php) du serveur.";
        } catch (PDOException $e) {
            $erreur = str_contains($e->getMessage(), 'Duplicate') ? "Un compte avec cet email existe deja." : "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Creation compte administrateur</title></head>
<body style="font-family:sans-serif; max-width:420px; margin:3rem auto;">
<h1>Creer le compte administrateur</h1>
<p style="color:#b03a2e;"><strong>A supprimer du serveur juste apres usage.</strong></p>

<?php if ($message): ?><p style="color:green;"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($erreur): ?><p style="color:red;"><?= htmlspecialchars($erreur) ?></p><?php endif; ?>

<?php if (!$message): ?>
<form method="post">
  <p><label>Prenom<br><input type="text" name="prenom" required style="width:100%;"></label></p>
  <p><label>Nom<br><input type="text" name="nom" required style="width:100%;"></label></p>
  <p><label>Email<br><input type="email" name="email" required style="width:100%;"></label></p>
  <p><label>Mot de passe (8 caracteres min)<br><input type="password" name="mot_de_passe" required minlength="8" style="width:100%;"></label></p>
  <button type="submit">Creer le compte</button>
</form>
<?php endif; ?>
</body>
</html>
