<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $mdp   = $_POST['mot_de_passe'] ?? '';

    if ($email === '' || $mdp === '') {
        $erreur = 'Merci de renseigner email et mot de passe.';
    } elseif (login($email, $mdp)) {
        header('Location: /index.php');
        exit;
    } else {
        $erreur = 'Identifiants incorrects ou compte desactive.';
    }
}

$titre_page = 'Connexion';
include __DIR__ . '/includes/header.php';
?>

<div class="login-box">
  <div class="card">
    <h1>Connexion</h1>

    <?php if (isset($_GET['expire'])): ?>
      <div class="alert warn">Ta session a expire, merci de te reconnecter.</div>
    <?php endif; ?>

    <?php if ($erreur): ?>
      <div class="alert err"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
      </div>
      <div class="form-group">
        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" required>
      </div>
      <button type="submit" class="btn">Se connecter</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
