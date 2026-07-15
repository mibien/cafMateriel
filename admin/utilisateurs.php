<?php
require_once __DIR__ . '/../auth.php';
require_min_role('administrateur');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'creer_compte') {
        $email = trim($_POST['email'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $motDePasse = $_POST['mot_de_passe'] ?? '';
        $role = $_POST['role'] ?? 'encadrant';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Email invalide.";
        } elseif (strlen($motDePasse) < 8) {
            $erreur = "Le mot de passe doit faire au moins 8 caracteres.";
        } elseif (!in_array($role, ['administrateur','president','responsable_materiel','encadrant'], true)) {
            $erreur = "Role invalide.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (email, mot_de_passe_hash, nom, prenom, role, actif, cree_par)
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$email, password_hash($motDePasse, PASSWORD_DEFAULT), $nom, $prenom, $role, $user['id']]);
                $message = "Compte cree pour $prenom $nom.";
            } catch (PDOException $e) {
                $erreur = str_contains($e->getMessage(), 'Duplicate') ? "Cet email existe deja." : "Erreur lors de la creation.";
            }
        }
    }

    if ($action === 'basculer_actif') {
        $id = (int)$_POST['utilisateur_id'];
        if ($id !== $user['id']) { // on ne se desactive pas soi-meme par erreur
            $pdo->prepare("UPDATE utilisateurs SET actif = NOT actif WHERE id = ?")->execute([$id]);
            $message = "Statut du compte mis a jour.";
        } else {
            $erreur = "Tu ne peux pas desactiver ton propre compte.";
        }
    }
}

$utilisateurs = $pdo->query("SELECT id, email, nom, prenom, role, actif, date_creation FROM utilisateurs ORDER BY date_creation DESC")->fetchAll();

$titre_page = 'Comptes utilisateurs';
include __DIR__ . '/../includes/header.php';
?>

<h1>Gestion des comptes utilisateurs</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <h2>Creer un compte</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="creer_compte">

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:160px;">
        <label for="prenom">Prenom</label>
        <input type="text" name="prenom" id="prenom" required>
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label for="nom">Nom</label>
        <input type="text" name="nom" id="nom" required>
      </div>
    </div>

    <div class="form-group">
      <label for="email">Email</label>
      <input type="email" name="email" id="email" required>
    </div>

    <div class="form-group">
      <label for="mot_de_passe">Mot de passe provisoire (8 caracteres min)</label>
      <input type="text" name="mot_de_passe" id="mot_de_passe" required minlength="8">
    </div>

    <div class="form-group">
      <label for="role">Role initial</label>
      <select name="role" id="role">
        <option value="encadrant">Encadrant</option>
        <option value="responsable_materiel">Responsable materiel</option>
        <option value="president">President</option>
        <option value="administrateur">Administrateur</option>
      </select>
    </div>

    <button type="submit" class="btn vert">Creer le compte</button>
  </form>
</div>

<div class="card">
  <h2>Tous les comptes</h2>
  <table>
    <thead><tr><th>Nom</th><th>Email</th><th>Role</th><th>Actif</th><th>Cree le</th><th>Action</th></tr></thead>
    <tbody>
      <?php foreach ($utilisateurs as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['role']) ?></td>
        <td><?= $u['actif'] ? 'Oui' : 'Non' ?></td>
        <td><?= htmlspecialchars(substr($u['date_creation'], 0, 10)) ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="basculer_actif">
            <input type="hidden" name="utilisateur_id" value="<?= $u['id'] ?>">
            <button type="submit" class="btn <?= $u['actif'] ? 'rouge' : 'vert' ?>">
              <?= $u['actif'] ? 'Desactiver' : 'Reactiver' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
