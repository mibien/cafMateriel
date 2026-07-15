<?php
require_once __DIR__ . '/../auth.php';
require_login();

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /materiel/liste.php');
    exit;
}

// Sauvegarde des modifications (responsable materiel et au-dessus uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_min_role('responsable_materiel');
    csrf_check();

    $marque = trim($_POST['marque'] ?? '') ?: null;
    $modele = trim($_POST['modele'] ?? '') ?: null;
    $numSerie = trim($_POST['numero_serie'] ?? '') ?: null;
    $anneeFab = $_POST['annee_fabrication'] !== '' ? (int)$_POST['annee_fabrication'] : null;
    $dateAchat = $_POST['date_achat'] ?: null;
    $datePremiereUtil = $_POST['date_premiere_utilisation'] ?: null;
    $longueur = $_POST['longueur_cm'] !== '' ? (float)str_replace(',', '.', $_POST['longueur_cm']) : null;
    $diametre = $_POST['diametre_mm'] !== '' ? (float)str_replace(',', '.', $_POST['diametre_mm']) : null;
    $dateRebut = $_POST['date_mise_au_rebut'] ?: null;
    $dateDerniereRevision = $_POST['date_derniere_revision'] ?: null;
    $dateProchaineRevision = $_POST['date_prochaine_revision'] ?: null;
    $sac = trim($_POST['sac_rangement'] ?? '') ?: null;
    $quantiteStock = max(0, (int)($_POST['quantite_stock'] ?? 1));
    $quantiteDisponible = max(0, min($quantiteStock, (int)($_POST['quantite_disponible'] ?? 1)));
    $statut = $_POST['statut'] ?? 'disponible';
    $commentaire = trim($_POST['commentaire'] ?? '') ?: null;

    $stmt = $pdo->prepare("
        UPDATE materiel SET
            marque = ?, modele = ?, numero_serie = ?, annee_fabrication = ?, date_achat = ?,
            date_premiere_utilisation = ?, longueur_cm = ?, diametre_mm = ?, date_mise_au_rebut = ?,
            date_derniere_revision = ?, date_prochaine_revision = ?, sac_rangement = ?,
            quantite_stock = ?, quantite_disponible = ?, statut = ?, commentaire = ?
        WHERE id = ?
    ");
    // date_maj est mise a jour automatiquement par la base (ON UPDATE CURRENT_TIMESTAMP)
    $stmt->execute([
        $marque, $modele, $numSerie, $anneeFab, $dateAchat, $datePremiereUtil, $longueur, $diametre,
        $dateRebut, $dateDerniereRevision, $dateProchaineRevision, $sac,
        $quantiteStock, $quantiteDisponible, $statut, $commentaire, $id
    ]);
    $message = "Fiche mise a jour.";
}

$stmt = $pdo->prepare("
    SELECT m.*, t.libelle AS type_libelle
    FROM materiel m JOIN types_epi t ON t.id = m.type_id
    WHERE m.id = ?
");
$stmt->execute([$id]);
$m = $stmt->fetch();

if (!$m) {
    $titre_page = 'Article introuvable';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert err">Cet article n\'existe pas ou a ete supprime.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$types = $pdo->query("SELECT id, libelle FROM types_epi WHERE actif = 1 ORDER BY libelle")->fetchAll();

// Historique des emprunts de cet article, avec commentaires rattaches
$stmt = $pdo->prepare("
    SELECT el.quantite, el.etat_retour, el.commentaire_ligne,
           e.date_sortie, e.date_retour_effective, e.activite, e.statut AS statut_emprunt,
           u.prenom, u.nom
    FROM emprunts_lignes el
    JOIN emprunts e ON e.id = el.emprunt_id
    JOIN utilisateurs u ON u.id = e.encadrant_id
    WHERE el.materiel_id = ?
    ORDER BY e.date_sortie DESC
");
$stmt->execute([$id]);
$historique = $stmt->fetchAll();

$titre_page = 'Fiche materiel';
include __DIR__ . '/../includes/header.php';
?>

<p><a href="/materiel/liste.php">← Retour a l'inventaire</a></p>
<h1><?= htmlspecialchars($m['type_libelle']) ?> - <?= htmlspecialchars(trim(($m['marque'] ?? '') . ' ' . ($m['modele'] ?? '')) ?: 'Article #' . $m['id']) ?></h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <h2>Informations</h2>
  <p style="color:var(--gris); font-size:0.85rem;">
    Derniere modification : <?= htmlspecialchars(substr($m['date_maj'], 0, 16)) ?>
    &nbsp;|&nbsp; Cree le : <?= htmlspecialchars(substr($m['date_creation'], 0, 16)) ?>
    &nbsp;|&nbsp; <?= count($historique) ?> emprunt(s) enregistre(s)
  </p>

  <?php if (has_min_role('responsable_materiel')): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:180px;">
        <label>Marque</label>
        <input type="text" name="marque" value="<?= htmlspecialchars($m['marque'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:180px;">
        <label>Modele</label>
        <input type="text" name="modele" value="<?= htmlspecialchars($m['modele'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:180px;">
        <label>N° de serie</label>
        <input type="text" name="numero_serie" value="<?= htmlspecialchars($m['numero_serie'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:140px;">
        <label>Annee de fabrication</label>
        <input type="number" name="annee_fabrication" value="<?= htmlspecialchars($m['annee_fabrication'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Date d'achat</label>
        <input type="date" name="date_achat" value="<?= htmlspecialchars($m['date_achat'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>1ere utilisation</label>
        <input type="date" name="date_premiere_utilisation" value="<?= htmlspecialchars($m['date_premiere_utilisation'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:140px;">
        <label>Longueur (cm)</label>
        <input type="text" name="longueur_cm" value="<?= htmlspecialchars($m['longueur_cm'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:140px;">
        <label>Diametre (mm)</label>
        <input type="text" name="diametre_mm" value="<?= htmlspecialchars($m['diametre_mm'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Sac de rangement</label>
        <input type="text" name="sac_rangement" value="<?= htmlspecialchars($m['sac_rangement'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Date de mise au rebut</label>
        <input type="date" name="date_mise_au_rebut" value="<?= htmlspecialchars($m['date_mise_au_rebut'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Derniere revision</label>
        <input type="date" name="date_derniere_revision" value="<?= htmlspecialchars($m['date_derniere_revision'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Prochaine revision</label>
        <input type="date" name="date_prochaine_revision" value="<?= htmlspecialchars($m['date_prochaine_revision'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:140px;">
        <label>Quantite totale</label>
        <input type="number" name="quantite_stock" min="0" value="<?= (int)$m['quantite_stock'] ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:140px;">
        <label>Quantite disponible</label>
        <input type="number" name="quantite_disponible" min="0" value="<?= (int)$m['quantite_disponible'] ?>">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label>Statut</label>
        <select name="statut">
          <?php foreach (['disponible','emprunte','en_revision','hors_service','perdu'] as $s): ?>
            <option value="<?= $s ?>" <?= $m['statut'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Commentaire</label>
      <textarea name="commentaire" rows="2"><?= htmlspecialchars($m['commentaire'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn vert">Enregistrer les modifications</button>
  </form>
  <?php else: ?>
    <table>
      <tr><th>Marque</th><td><?= htmlspecialchars($m['marque'] ?? '-') ?></td></tr>
      <tr><th>Modele</th><td><?= htmlspecialchars($m['modele'] ?? '-') ?></td></tr>
      <tr><th>N° serie</th><td><?= htmlspecialchars($m['numero_serie'] ?? '-') ?></td></tr>
      <tr><th>Annee fabrication</th><td><?= htmlspecialchars($m['annee_fabrication'] ?? '-') ?></td></tr>
      <tr><th>Date achat</th><td><?= htmlspecialchars($m['date_achat'] ?? '-') ?></td></tr>
      <tr><th>1ere utilisation</th><td><?= htmlspecialchars($m['date_premiere_utilisation'] ?? '-') ?></td></tr>
      <tr><th>Longueur (cm)</th><td><?= htmlspecialchars($m['longueur_cm'] ?? '-') ?></td></tr>
      <tr><th>Diametre (mm)</th><td><?= htmlspecialchars($m['diametre_mm'] ?? '-') ?></td></tr>
      <tr><th>Sac de rangement</th><td><?= htmlspecialchars($m['sac_rangement'] ?? '-') ?></td></tr>
      <tr><th>Prochaine revision</th><td><?= htmlspecialchars($m['date_prochaine_revision'] ?? '-') ?></td></tr>
      <tr><th>Quantite dispo / totale</th><td><?= (int)$m['quantite_disponible'] ?> / <?= (int)$m['quantite_stock'] ?></td></tr>
      <tr><th>Statut</th><td><span class="statut-<?= htmlspecialchars($m['statut']) ?>"><?= htmlspecialchars($m['statut']) ?></span></td></tr>
      <tr><th>Commentaire</th><td><?= htmlspecialchars($m['commentaire'] ?? '-') ?></td></tr>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Historique des emprunts</h2>
  <table>
    <thead><tr><th>Emprunte le</th><th>Emprunteur</th><th>Activite</th><th>Qte</th><th>Etat au retour</th><th>Commentaire</th></tr></thead>
    <tbody>
      <?php foreach ($historique as $h): ?>
      <tr>
        <td><?= htmlspecialchars(substr($h['date_sortie'], 0, 16)) ?></td>
        <td><?= htmlspecialchars($h['prenom'] . ' ' . $h['nom']) ?></td>
        <td><?= htmlspecialchars($h['activite'] ?? '-') ?></td>
        <td><?= (int)$h['quantite'] ?></td>
        <td><?= htmlspecialchars($h['etat_retour'] ?? 'en cours') ?></td>
        <td><?= htmlspecialchars($h['commentaire_ligne'] ?? '-') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($historique)): ?><tr><td colspan="6">Aucun emprunt enregistre pour cet article.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
