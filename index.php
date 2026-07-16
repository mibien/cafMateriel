<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$pdo = db();

// Quelques indicateurs, calcules selon le role
$stats = [];

$stats['materiel_total'] = $pdo->query("SELECT COALESCE(SUM(quantite_stock),0) FROM materiel")->fetchColumn();
$stats['materiel_dispo'] = $pdo->query("SELECT COALESCE(SUM(quantite_disponible),0) FROM materiel WHERE statut='disponible'")->fetchColumn();

if (has_min_role('responsable_materiel')) {
    $stats['demandes_attente'] = $pdo->query("SELECT COUNT(*) FROM demandes_emprunt WHERE statut='en_attente'")->fetchColumn();
    $stats['emprunts_en_cours'] = $pdo->query("SELECT COUNT(*) FROM emprunts WHERE statut IN ('en_cours','en_retard')")->fetchColumn();
    $stats['revisions_proches'] = $pdo->query("
        SELECT COUNT(*) FROM materiel
        WHERE date_prochaine_revision IS NOT NULL
        AND date_prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ")->fetchColumn();
}

// Vérification des DVA avec piles mises (pour l'alerte saisonnière)
$mois_actuel = (int)date('n');
$dva_piles_mises_count = 0;
if ($mois_actuel >= 5) { // À partir de mai (5)
    $dva_piles_mises_count = $pdo->query("
        SELECT COUNT(*) FROM materiel m
        JOIN types_epi t ON m.type_id = t.id
        WHERE t.code = 'DVA' AND m.piles = 'mises'
    ")->fetchColumn();
}

if (has_role('encadrant') && !has_min_role('responsable_materiel')) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM demandes_emprunt WHERE encadrant_id = ? AND statut = 'en_attente'");
    $stmt->execute([$user['id']]);
    $stats['mes_demandes_attente'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprunts WHERE encadrant_id = ? AND statut IN ('en_cours','en_retard')");
    $stmt->execute([$user['id']]);
    $stats['mes_emprunts_en_cours'] = $stmt->fetchColumn();
}

$titre_page = 'Tableau de bord';
include __DIR__ . '/includes/header.php';
?>

<h1>Bonjour <?= htmlspecialchars($user['prenom']) ?> 
dc4b</h1>

<div class="card">
  <h2>Inventaire</h2>
  <p><?= (int)$stats['materiel_dispo'] ?> articles disponibles sur <?= (int)$stats['materiel_total'] ?> au total.</p>
  <a class="btn secondaire" href="/materiel/liste.php">Voir l'inventaire complet</a>
</div>

<?php if (has_min_role('responsable_materiel')): ?>
<div class="card">
  <h2>A traiter</h2>
  <p>
    <strong><?= (int)$stats['demandes_attente'] ?></strong> demande(s) d'emprunt en attente de validation.
    <?php if ($stats['demandes_attente'] > 0): ?>
      <a class="btn" href="/responsable/demandes.php">Traiter les demandes</a>
    <?php endif; ?>
  </p>
  <p><?= (int)$stats['emprunts_en_cours'] ?> emprunt(s) actuellement en cours (materiel sorti, non rendu).</p>
  <?php if ($stats['revisions_proches'] > 0): ?>
    <p class="alert warn"><?= (int)$stats['revisions_proches'] ?> article(s) arrivent a echeance de revision dans les 60 jours.</p>
  <?php endif; ?>
  <?php if ($mois_actuel >= 5 && $dva_piles_mises_count > 0): ?>
    <p class="alert warn">
      
dc4a <strong><?= (int)$dva_piles_mises_count ?></strong> DVA ont encore les piles mises. 
      Pensez a les retirer pour la saison estivale !
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (has_role('encadrant') && !has_min_role('responsable_materiel')): ?>
<div class="card">
  <h2>Mes emprunts</h2>
  <p><?= (int)$stats['mes_emprunts_en_cours'] ?> emprunt(s) en cours, <?= (int)$stats['mes_demandes_attente'] ?> demande(s) en attente de validation.</p>
  <a class="btn" href="/encadrant/demande_form.php">Faire une nouvelle demande</a>
  <a class="btn secondaire" href="/encadrant/mes_emprunts.php">Voir mon historique</a>
</div>
<?php endif; ?>

<?php if (has_min_role('president')): ?>
<div class="card">
  <h2>Gouvernance du club</h2>
  <p>En tant que <?= has_role('administrateur') ? 'administrateur' : 'president' ?>, tu peux nommer les responsables materiel<?= has_role('administrateur') ? ' (et le president)' : '' ?>.</p>
  <a class="btn secondaire" href="/president/nominations.php">Gerer les nominations</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
