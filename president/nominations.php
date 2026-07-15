<?php
require_once __DIR__ . '/../auth.php';
require_min_role('president');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

// L'administrateur peut nommer un president. Le president ne peut nommer que responsable_materiel ou encadrant.
$rolesNommables = has_role('administrateur')
    ? ['president', 'responsable_materiel', 'encadrant']
    : ['responsable_materiel', 'encadrant'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $utilisateurId = (int)$_POST['utilisateur_id'];
    $roleAttribue = $_POST['role_attribue'];

    if (!in_array($roleAttribue, $rolesNommables, true)) {
        $erreur = "Tu n'as pas le droit d'attribuer ce role.";
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE utilisateurs SET role = ? WHERE id = ?")->execute([$roleAttribue, $utilisateurId]);

            $pdo->prepare("
                INSERT INTO nominations (utilisateur_id, role_attribue, nomme_par, actif)
                VALUES (?, ?, ?, 1)
            ")->execute([$utilisateurId, $roleAttribue, $user['id']]);

            $pdo->commit();
            $message = "Nomination enregistree.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}

$utilisateurs = $pdo->query("SELECT id, prenom, nom, email, role FROM utilisateurs WHERE actif = 1 ORDER BY prenom, nom")->fetchAll();

$historique = $pdo->query("
    SELECT n.*, u.prenom AS u_prenom, u.nom AS u_nom, p.prenom AS p_prenom, p.nom AS p_nom
    FROM nominations n
    JOIN utilisateurs u ON u.id = n.utilisateur_id
    JOIN utilisateurs p ON p.id = n.nomme_par
    ORDER BY n.date_nomination DESC LIMIT 30
")->fetchAll();

$titre_page = 'Nominations';
include __DIR__ . '/../includes/header.php';
?>

<h1>Nommer un responsable</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-group">
      <label for="utilisateur_id">Membre</label>
      <select name="utilisateur_id" id="utilisateur_id" required>
        <option value="">-- Choisir --</option>
        <?php foreach ($utilisateurs as $u): ?>
          <option value="<?= $u['id'] ?>">
            <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom'] . ' (' . $u['email'] . ') - actuellement ' . $u['role']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="role_attribue">Nouveau role</label>
      <select name="role_attribue" id="role_attribue" required>
        <?php foreach ($rolesNommables as $r): ?>
          <option value="<?= $r ?>"><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn">Nommer</button>
  </form>
</div>

<div class="card">
  <h2>Historique des nominations</h2>
  <table>
    <thead><tr><th>Date</th><th>Membre</th><th>Nouveau role</th><th>Nomme par</th></tr></thead>
    <tbody>
      <?php foreach ($historique as $h): ?>
      <tr>
        <td><?= htmlspecialchars(substr($h['date_nomination'], 0, 16)) ?></td>
        <td><?= htmlspecialchars($h['u_prenom'] . ' ' . $h['u_nom']) ?></td>
        <td><?= htmlspecialchars($h['role_attribue']) ?></td>
        <td><?= htmlspecialchars($h['p_prenom'] . ' ' . $h['p_nom']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($historique)): ?><tr><td colspan="4">Aucune nomination enregistree.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
