<?php
require_once __DIR__ . '/../auth.php';
require_min_role('responsable_materiel');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_materiel') {
    csrf_check();
    $typeId = (int)$_POST['type_id'];
    $marque = trim($_POST['marque'] ?? '') ?: null;
    $modele = trim($_POST['modele'] ?? '') ?: null;
    $numSerie = trim($_POST['numero_serie'] ?? '') ?: null;
    $anneeFab = $_POST['annee_fabrication'] !== '' ? (int)$_POST['annee_fabrication'] : null;
    $dateAchat = $_POST['date_achat'] ?: null;
    $datePremiereUtil = $_POST['date_premiere_utilisation'] ?: null;
    $longueur = $_POST['longueur_cm'] !== '' ? (float)str_replace(',', '.', $_POST['longueur_cm']) : null;
    $diametre = $_POST['diametre_mm'] !== '' ? (float)str_replace(',', '.', $_POST['diametre_mm']) : null;
    $sac = trim($_POST['sac_rangement'] ?? '') ?: null;
    $quantite = max(1, (int)($_POST['quantite_stock'] ?? 1));
    $commentaire = trim($_POST['commentaire'] ?? '') ?: null;

    if (!$typeId) {
        $erreur = "Merci de choisir un type d'EPI.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO materiel
                (type_id, marque, modele, numero_serie, annee_fabrication, date_achat, date_premiere_utilisation,
                 longueur_cm, diametre_mm, sac_rangement, quantite_stock, quantite_disponible, statut, commentaire, cree_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'disponible', ?, ?)
        ");
        $stmt->execute([
            $typeId, $marque, $modele, $numSerie, $anneeFab, $dateAchat, $datePremiereUtil,
            $longueur, $diametre, $sac, $quantite, $quantite, $commentaire, $user['id']
        ]);
        $message = "Nouveau materiel enregistre.";
    }
}

// Passage rapide en revision / hors service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changer_statut') {
    csrf_check();
    $materielId = (int)$_POST['materiel_id'];
    $nouveauStatut = $_POST['nouveau_statut'];
    if (in_array($nouveauStatut, ['disponible','en_revision','hors_service','perdu'], true)) {
        $stmt = $pdo->prepare("UPDATE materiel SET statut = ? WHERE id = ?");
        $stmt->execute([$nouveauStatut, $materielId]);
        $message = "Statut mis a jour.";
    }
}

$types = $pdo->query("SELECT id, libelle FROM types_epi WHERE actif = 1 ORDER BY libelle")->fetchAll();
$materiel = $pdo->query("
    SELECT m.*, t.libelle AS type_libelle
    FROM materiel m JOIN types_epi t ON t.id = m.type_id
    ORDER BY m.date_creation DESC LIMIT 100
")->fetchAll();

$titre_page = 'Gerer le materiel';
include __DIR__ . '/../includes/header.php';
?>

<h1>Gerer le materiel</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <h2>Enregistrer un nouvel article</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="ajouter_materiel">

    <div class="form-group">
      <label for="type_id">Type d'EPI *</label>
      <select name="type_id" id="type_id" required>
        <option value="">-- Choisir --</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:180px;">
        <label for="marque">Marque</label>
        <input type="text" name="marque" id="marque">
      </div>
      <div class="form-group" style="flex:1; min-width:180px;">
        <label for="modele">Modele</label>
        <input type="text" name="modele" id="modele">
      </div>
      <div class="form-group" style="flex:1; min-width:180px;">
        <label for="numero_serie">N° de serie</label>
        <input type="text" name="numero_serie" id="numero_serie">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:140px;">
        <label for="annee_fabrication">Annee de fabrication</label>
        <input type="number" name="annee_fabrication" id="annee_fabrication" min="1990" max="2100">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label for="date_achat">Date d'achat</label>
        <input type="date" name="date_achat" id="date_achat">
      </div>
      <div class="form-group" style="flex:1; min-width:160px;">
        <label for="date_premiere_utilisation">1ere utilisation</label>
        <input type="date" name="date_premiere_utilisation" id="date_premiere_utilisation">
      </div>
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:140px;">
        <label for="longueur_cm">Longueur (cm) - cordes/sangles</label>
        <input type="text" name="longueur_cm" id="longueur_cm">
      </div>
      <div class="form-group" style="flex:1; min-width:140px;">
        <label for="diametre_mm">Diametre (mm) - cordes</label>
        <input type="text" name="diametre_mm" id="diametre_mm">
      </div>
      <div class="form-group" style="flex:1; min-width:140px;">
        <label for="sac_rangement">Sac de rangement</label>
        <input type="text" name="sac_rangement" id="sac_rangement">
      </div>
      <div class="form-group" style="flex:1; min-width:140px;">
        <label for="quantite_stock">Quantite (si materiel en lot)</label>
        <input type="number" name="quantite_stock" id="quantite_stock" value="1" min="1">
      </div>
    </div>

    <div class="form-group">
      <label for="commentaire">Commentaire</label>
      <textarea name="commentaire" id="commentaire" rows="2"></textarea>
    </div>

    <button type="submit" class="btn vert">Enregistrer le materiel</button>
  </form>
</div>

<div class="card">
  <h2>Derniers articles enregistres</h2>
  <table>
    <thead><tr><th>Type</th><th>Marque / Modele</th><th>N° serie</th><th>Statut</th><th>Changer statut</th></tr></thead>
    <tbody>
      <?php foreach ($materiel as $m): ?>
      <tr>
        <td><?= htmlspecialchars($m['type_libelle']) ?></td>
        <td><?= htmlspecialchars(trim(($m['marque'] ?? '') . ' ' . ($m['modele'] ?? ''))) ?: '-' ?></td>
        <td><?= htmlspecialchars($m['numero_serie'] ?? '-') ?></td>
        <td><span class="statut-<?= htmlspecialchars($m['statut']) ?>"><?= htmlspecialchars($m['statut']) ?></span></td>
        <td>
          <form method="post" style="display:flex; gap:0.4rem;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="changer_statut">
            <input type="hidden" name="materiel_id" value="<?= $m['id'] ?>">
            <select name="nouveau_statut">
              <?php foreach (['disponible','en_revision','hors_service','perdu'] as $s): ?>
                <option value="<?= $s ?>" <?= $m['statut'] === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn secondaire">OK</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
