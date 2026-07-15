<?php
require_once __DIR__ . '/../auth.php';
require_min_role('encadrant');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $activite = trim($_POST['activite'] ?? '');
    $dateDebut = $_POST['date_debut'] ?? '';
    $dateFin = $_POST['date_fin'] ?? '';
    $typeIds = $_POST['type_id'] ?? [];
    $materielIds = $_POST['materiel_id'] ?? [];
    $quantites = $_POST['quantite'] ?? [];

    // Construit la liste des lignes valides (au moins un type OU un materiel par ligne)
    $lignes = [];
    $nbLignes = max(count($typeIds), count($materielIds), count($quantites));
    for ($i = 0; $i < $nbLignes; $i++) {
        $t = isset($typeIds[$i]) && $typeIds[$i] !== '' ? (int)$typeIds[$i] : null;
        $m = isset($materielIds[$i]) && $materielIds[$i] !== '' ? (int)$materielIds[$i] : null;
        $q = max(1, (int)($quantites[$i] ?? 1));
        if ($t || $m) {
            $lignes[] = ['type_id' => $t, 'materiel_id' => $m, 'quantite' => $q];
        }
    }

    if (empty($lignes)) {
        $erreur = "Merci d'ajouter au moins un article (type ou article precis) a ta demande.";
    } elseif ($dateDebut === '' || $dateFin === '' || $dateFin < $dateDebut) {
        $erreur = "Merci de verifier les dates (la date de fin doit etre apres la date de debut).";
    } else {
        // Additionne les quantites demandees par article/type AVANT de verifier la disponibilite,
        // pour empecher de contourner la limite en repartissant une meme demande sur plusieurs lignes.
        $quantiteParMateriel = [];
        $quantiteParType = [];
        foreach ($lignes as $l) {
            if ($l['materiel_id']) {
                $quantiteParMateriel[$l['materiel_id']] = ($quantiteParMateriel[$l['materiel_id']] ?? 0) + $l['quantite'];
            } elseif ($l['type_id']) {
                $quantiteParType[$l['type_id']] = ($quantiteParType[$l['type_id']] ?? 0) + $l['quantite'];
            }
        }

        $erreursDispo = [];
        foreach ($quantiteParMateriel as $materielId => $quantiteDemandee) {
            $stmt = $pdo->prepare("SELECT marque, modele, quantite_disponible FROM materiel WHERE id = ?");
            $stmt->execute([$materielId]);
            $art = $stmt->fetch();
            if (!$art || (int)$art['quantite_disponible'] < $quantiteDemandee) {
                $dispo = $art ? (int)$art['quantite_disponible'] : 0;
                $nom = $art ? trim(($art['marque'] ?? '') . ' ' . ($art['modele'] ?? '')) : "article #{$materielId}";
                $erreursDispo[] = "Quantite insuffisante pour l'article {$nom} (disponible: {$dispo}, demande: {$quantiteDemandee}).";
            }
        }
        foreach ($quantiteParType as $typeId => $quantiteDemandee) {
            $stmt = $pdo->prepare("
                SELECT t.libelle, COALESCE(SUM(m.quantite_disponible), 0) AS total_dispo
                FROM types_epi t
                LEFT JOIN materiel m ON m.type_id = t.id AND m.statut = 'disponible'
                WHERE t.id = ?
                GROUP BY t.id, t.libelle
            ");
            $stmt->execute([$typeId]);
            $typ = $stmt->fetch();
            $totalDispo = $typ ? (int)$typ['total_dispo'] : 0;
            if ($totalDispo < $quantiteDemandee) {
                $nom = $typ['libelle'] ?? "type #{$typeId}";
                $erreursDispo[] = "Quantite insuffisante pour l'article {$nom} (disponible: {$totalDispo}, demande: {$quantiteDemandee}).";
            }
        }

        if (!empty($erreursDispo)) {
            $erreur = implode(' ', $erreursDispo);
        } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO demandes_emprunt (encadrant_id, activite, date_debut_souhaitee, date_fin_souhaitee, statut)
                VALUES (?, ?, ?, ?, 'en_attente')
            ");
            $stmt->execute([$user['id'], $activite ?: null, $dateDebut, $dateFin]);
            $demandeId = $pdo->lastInsertId();

            $stmtLigne = $pdo->prepare("
                INSERT INTO demandes_emprunt_lignes (demande_id, type_id, materiel_id, quantite)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($lignes as $l) {
                $stmtLigne->execute([$demandeId, $l['type_id'], $l['materiel_id'], $l['quantite']]);
            }

            $pdo->commit();
            $message = "Ta demande (" . count($lignes) . " article(s)) a bien ete envoyee. Un responsable materiel va la valider.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de l'enregistrement : " . $e->getMessage();
        }
        }
    }
}

$types = $pdo->query("SELECT id, libelle FROM types_epi WHERE actif = 1 ORDER BY libelle")->fetchAll();
$materielDispo = $pdo->query("
    SELECT m.id, m.marque, m.modele, m.numero_serie, t.libelle AS type_libelle
    FROM materiel m JOIN types_epi t ON t.id = m.type_id
    WHERE m.statut = 'disponible' AND m.quantite_disponible > 0
    ORDER BY t.libelle, m.marque
")->fetchAll();

$titre_page = 'Demander un emprunt';
include __DIR__ . '/../includes/header.php';
?>

<h1>Demander un emprunt de materiel</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err" id="alerte-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <form method="post" id="form-demande">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-group">
      <label for="activite">Activite / sortie concernee</label>
      <input type="text" name="activite" id="activite" placeholder="Ex: Initiation escalade - falaise de ...">
    </div>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
      <div class="form-group" style="flex:1; min-width:200px;">
        <label for="date_debut">Date de debut souhaitee (debut emprunt)</label>
        <input type="date" name="date_debut" id="date_debut" required>
      </div>
      <div class="form-group" style="flex:1; min-width:200px;">
        <label for="date_fin">Date de fin souhaitee (retour prevu)</label>
        <input type="date" name="date_fin" id="date_fin" required>
      </div>
    </div>

    <h2>Materiel demande</h2>
    <div id="lignes-materiel">
      <div class="ligne-materiel" style="display:flex; gap:0.6rem; margin-bottom:0.6rem; align-items:center;">
        <select name="type_id[]" style="flex:2;">
          <option value="">-- Type de materiel --</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="materiel_id[]" style="flex:2;">
          <option value="">-- OU un article precis --</option>
          <?php foreach ($materielDispo as $m): ?>
            <option value="<?= $m['id'] ?>">
              <?= htmlspecialchars($m['type_libelle'] . ' - ' . ($m['marque'] ?? '') . ' ' . ($m['modele'] ?? '') . ($m['numero_serie'] ? ' (' . $m['numero_serie'] . ')' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="quantite[]" min="1" value="1" style="flex:1;">
        <button type="button" class="btn secondaire" onclick="supprimerLigne(this)">Supprimer</button>
      </div>
    </div>
    <button type="button" class="btn secondaire" onclick="ajouterLigne()">+ Ajouter un article</button>

    <div style="margin-top:1.5rem;">
      <button type="submit" class="btn">Envoyer la demande</button>
    </div>
  </form>
</div>

<script>
function ajouterLigne() {
  const conteneur = document.getElementById('lignes-materiel');
  const premiere = conteneur.querySelector('.ligne-materiel');
  const clone = premiere.cloneNode(true);
  clone.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
  clone.querySelector('input').value = 1;
  conteneur.appendChild(clone);
  masquerErreur();
}
function supprimerLigne(bouton) {
  const conteneur = document.getElementById('lignes-materiel');
  if (conteneur.querySelectorAll('.ligne-materiel').length > 1) {
    bouton.closest('.ligne-materiel').remove();
  } else {
    bouton.closest('.ligne-materiel').querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    bouton.closest('.ligne-materiel').querySelector('input').value = 1;
  }
  masquerErreur();
}
function masquerErreur() {
  const alerte = document.getElementById('alerte-erreur');
  if (alerte) { alerte.style.display = 'none'; }
}
// Masque aussi le message des qu'on modifie n'importe quel champ du formulaire
document.getElementById('form-demande').addEventListener('input', masquerErreur);
document.getElementById('form-demande').addEventListener('change', masquerErreur);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
