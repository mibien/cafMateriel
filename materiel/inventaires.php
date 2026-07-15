<?php
require_once __DIR__ . '/../auth.php';
require_login();

$pdo = db();
$user = current_user();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_min_role('responsable_materiel');
    csrf_check();
    $date = $_POST['date_inventaire'] ?? '';
    $commentaire = trim($_POST['commentaire'] ?? '') ?: null;
    if ($date !== '') {
        $stmt = $pdo->prepare("INSERT INTO inventaires (date_inventaire, effectue_par, commentaire) VALUES (?, ?, ?)");
        $stmt->execute([$date, $user['id'], $commentaire]);
        $message = "Inventaire enregistre.";
    }
}

$inventaires = $pdo->query("
    SELECT i.*, u.prenom, u.nom
    FROM inventaires i
    LEFT JOIN utilisateurs u ON u.id = i.effectue_par
    ORDER BY i.date_inventaire DESC
")->fetchAll();

$titre_page = 'Historique des inventaires';
include __DIR__ . '/../includes/header.php';
?>

<p><a href="/materiel/liste.php">← Retour a l'inventaire</a></p>
<h1>Historique des inventaires</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<?php if (has_min_role('responsable_materiel')): ?>
<div class="card">
  <h2>Enregistrer un nouvel inventaire</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label for="date_inventaire">Date de l'inventaire</label>
      <input type="date" name="date_inventaire" id="date_inventaire" required>
    </div>
    <div class="form-group">
      <label for="commentaire">Commentaire</label>
      <input type="text" name="commentaire" id="commentaire" placeholder="Ex: inventaire annuel, controle apres saison hiver...">
    </div>
    <button type="submit" class="btn vert">Enregistrer</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <table>
    <thead><tr><th>Date</th><th>Effectue par</th><th>Commentaire</th></tr></thead>
    <tbody>
      <?php foreach ($inventaires as $i): ?>
      <tr>
        <td><?= htmlspecialchars(date('d/m/Y', strtotime($i['date_inventaire']))) ?></td>
        <td><?= htmlspecialchars($i['prenom'] ? $i['prenom'] . ' ' . $i['nom'] : '-') ?></td>
        <td><?= htmlspecialchars($i['commentaire'] ?? '-') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($inventaires)): ?><tr><td colspan="3">Aucun inventaire enregistre.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
