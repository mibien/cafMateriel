<?php
require_once __DIR__ . '/../auth.php';
require_min_role('responsable_materiel');

$pdo = db();
$user = current_user();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $demandeId = (int)$_POST['demande_id'];
    $decision = $_POST['decision']; // 'approuvee' ou 'refusee'

    if (in_array($decision, ['approuvee', 'refusee'], true)) {
        $stmt = $pdo->prepare("UPDATE demandes_emprunt SET statut = ?, traitee_par = ?, date_traitement = NOW() WHERE id = ? AND statut = 'en_attente'");
        $stmt->execute([$decision, $user['id'], $demandeId]);
        $message = $decision === 'approuvee'
            ? "Demande approuvee. Va dans 'Enregistrer un emprunt' pour la convertir en emprunt reel."
            : "Demande refusee.";
    }
}

$demandes = $pdo->query("
    SELECT d.*, u.prenom, u.nom, u.email
    FROM demandes_emprunt d
    JOIN utilisateurs u ON u.id = d.encadrant_id
    WHERE d.statut = 'en_attente'
    ORDER BY d.date_creation ASC
")->fetchAll();

$lignesParDemande = [];
if (!empty($demandes)) {
    $ids = array_column($demandes, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT dl.*, t.libelle AS type_libelle, m.marque, m.modele, m.numero_serie
        FROM demandes_emprunt_lignes dl
        LEFT JOIN types_epi t ON t.id = dl.type_id
        LEFT JOIN materiel m ON m.id = dl.materiel_id
        WHERE dl.demande_id IN ($in)
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $l) {
        $lignesParDemande[$l['demande_id']][] = $l;
    }
}

$titre_page = 'Demandes en attente';
include __DIR__ . '/../includes/header.php';
?>

<h1>Demandes d'emprunt en attente</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="card">
  <?php foreach ($demandes as $d): ?>
    <div style="border:1px solid var(--bordure); border-radius:8px; padding:1rem; margin-bottom:1rem;">
      <p>
        <strong><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></strong>
        (<?= htmlspecialchars($d['email']) ?>)
        - <?= htmlspecialchars($d['activite'] ?? 'Activite non precisee') ?>
        - du <?= htmlspecialchars($d['date_debut_souhaitee']) ?> au <?= htmlspecialchars($d['date_fin_souhaitee']) ?>
      </p>
      <ul>
        <?php foreach ($lignesParDemande[$d['id']] ?? [] as $l): ?>
          <li>
            <?= (int)$l['quantite'] ?> x
            <?= htmlspecialchars($l['type_libelle'] ?? ($l['marque'] . ' ' . $l['modele'] . ($l['numero_serie'] ? ' (' . $l['numero_serie'] . ')' : ''))) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <div style="display:flex; gap:0.5rem;">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="demande_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="decision" value="approuvee">
          <button type="submit" class="btn vert">Approuver</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="demande_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="decision" value="refusee">
          <button type="submit" class="btn rouge">Refuser</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($demandes)): ?><p>Aucune demande en attente.</p><?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
