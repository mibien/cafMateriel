<?php
require_once __DIR__ . '/../auth.php';
require_min_role('encadrant');

$pdo = db();
$user = current_user();

$stmt = $pdo->prepare("
    SELECT * FROM demandes_emprunt WHERE encadrant_id = ? ORDER BY date_creation DESC
");
$stmt->execute([$user['id']]);
$demandes = $stmt->fetchAll();

$lignesParDemande = [];
if (!empty($demandes)) {
    $ids = array_column($demandes, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT dl.*, t.libelle AS type_libelle, m.marque, m.modele
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

$stmt = $pdo->prepare("
    SELECT e.*, r.prenom AS resp_prenom, r.nom AS resp_nom
    FROM emprunts e
    JOIN utilisateurs r ON r.id = e.responsable_id
    WHERE e.encadrant_id = ?
    ORDER BY e.date_sortie DESC
");
$stmt->execute([$user['id']]);
$emprunts = $stmt->fetchAll();

$titre_page = 'Mes emprunts';
include __DIR__ . '/../includes/header.php';
?>

<h1>Mon historique</h1>

<div class="card">
  <h2>Mes demandes d'emprunt</h2>
  <?php foreach ($demandes as $d): ?>
    <div style="border:1px solid var(--bordure); border-radius:8px; padding:0.8rem 1rem; margin-bottom:0.8rem;">
      <p style="margin:0 0 0.4rem 0;">
        <?= htmlspecialchars(substr($d['date_creation'], 0, 16)) ?>
        - <?= htmlspecialchars($d['activite'] ?? 'Activite non precisee') ?>
        - du <?= htmlspecialchars($d['date_debut_souhaitee']) ?> au <?= htmlspecialchars($d['date_fin_souhaitee']) ?>
        - statut : <strong><?= htmlspecialchars($d['statut']) ?></strong>
      </p>
      <ul style="margin:0.3rem 0;">
        <?php foreach ($lignesParDemande[$d['id']] ?? [] as $l): ?>
          <li><?= (int)$l['quantite'] ?> x <?= htmlspecialchars($l['type_libelle'] ?? (($l['marque'] ?? '') . ' ' . ($l['modele'] ?? ''))) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
  <?php if (empty($demandes)): ?><p>Aucune demande pour le moment.</p><?php endif; ?>
</div>

<div class="card">
  <h2>Mes emprunts (historique des emprunts)</h2>
  <table>
    <thead><tr><th>Emprunte le</th><th>Retour prevu</th><th>Retour effectif</th><th>Enregistre par</th><th>Statut</th></tr></thead>
    <tbody>
      <?php foreach ($emprunts as $e): ?>
      <tr>
        <td><?= htmlspecialchars(substr($e['date_sortie'], 0, 16)) ?></td>
        <td><?= htmlspecialchars($e['date_retour_prevue'] ?? '-') ?></td>
        <td><?= htmlspecialchars($e['date_retour_effective'] ? substr($e['date_retour_effective'], 0, 16) : '-') ?></td>
        <td><?= htmlspecialchars($e['resp_prenom'] . ' ' . $e['resp_nom']) ?></td>
        <td><?= htmlspecialchars($e['statut']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($emprunts)): ?><tr><td colspan="5">Aucun emprunt enregistre pour le moment.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
