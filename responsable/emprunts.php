<?php
require_once __DIR__ . '/../auth.php';
require_min_role('responsable_materiel');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

// ---------- Traitement du formulaire (creation de l'emprunt) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $encadrantId = (int)$_POST['encadrant_id'];
    $activite = trim($_POST['activite'] ?? '') ?: null;
    $dateRetourPrevue = $_POST['date_retour_prevue'] ?: null;
    $demandeId = $_POST['demande_id'] !== '' ? (int)$_POST['demande_id'] : null;
    $materielIds = $_POST['materiel_id'] ?? [];
    $quantites = $_POST['quantite'] ?? [];

    if (!$encadrantId || empty($materielIds)) {
        $erreur = "Merci de choisir un encadrant et au moins un article.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO emprunts (demande_id, encadrant_id, responsable_id, activite, date_sortie, date_retour_prevue, statut)
                VALUES (?, ?, ?, ?, NOW(), ?, 'en_cours')
            ");
            $stmt->execute([$demandeId, $encadrantId, $user['id'], $activite, $dateRetourPrevue]);
            $empruntId = $pdo->lastInsertId();

            foreach ($materielIds as $i => $materielId) {
                $materielId = (int)$materielId;
                $qte = max(1, (int)($quantites[$i] ?? 1));
                if (!$materielId) continue;

                $stmt = $pdo->prepare("SELECT quantite_disponible FROM materiel WHERE id = ? FOR UPDATE");
                $stmt->execute([$materielId]);
                $dispo = (int)$stmt->fetchColumn();

                if ($dispo < $qte) {
                    throw new Exception("Quantite insuffisante pour l'article #$materielId (disponible: $dispo, demande: $qte).");
                }

                $pdo->prepare("INSERT INTO emprunts_lignes (emprunt_id, materiel_id, quantite) VALUES (?, ?, ?)")
                    ->execute([$empruntId, $materielId, $qte]);

                $nouvelleQte = $dispo - $qte;
                $nouveauStatut = $nouvelleQte === 0 ? 'emprunte' : 'disponible';
                $pdo->prepare("UPDATE materiel SET quantite_disponible = ?, statut = ? WHERE id = ?")
                    ->execute([$nouvelleQte, $nouveauStatut, $materielId]);
            }

            // Si l'emprunt provient d'une demande, on la marque traitee (approuvee) si ce n'est pas deja fait
            if ($demandeId) {
                $pdo->prepare("
                    UPDATE demandes_emprunt
                    SET statut = 'approuvee', traitee_par = COALESCE(traitee_par, ?), date_traitement = COALESCE(date_traitement, NOW())
                    WHERE id = ?
                ")->execute([$user['id'], $demandeId]);
            }

            $pdo->commit();
            $message = "Emprunt enregistre avec succes.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}

// ---------- Demandes en attente ou approuvees, pas encore transformees en emprunt ----------
$demandes = $pdo->query("
    SELECT d.*, u.prenom, u.nom, u.email
    FROM demandes_emprunt d
    JOIN utilisateurs u ON u.id = d.encadrant_id
    WHERE d.statut IN ('en_attente', 'approuvee')
      AND NOT EXISTS (SELECT 1 FROM emprunts e WHERE e.demande_id = d.id)
    ORDER BY d.date_creation ASC
")->fetchAll();

$lignesParDemande = [];
if (!empty($demandes)) {
    $ids = array_column($demandes, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT dl.*, t.code AS type_code, t.libelle AS type_libelle, m.marque, m.modele, m.numero_serie
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

// ---------- Demande selectionnee a convertir en emprunt (prefill) ----------
$demandeSelectionnee = null;
$lignesPrefill = [];
$demandeIdGet = (int)($_GET['demande_id'] ?? 0);
if ($demandeIdGet) {
    foreach ($demandes as $d) {
        if ((int)$d['id'] === $demandeIdGet) { $demandeSelectionnee = $d; break; }
    }
    $lignesPrefill = $lignesParDemande[$demandeIdGet] ?? [];
}

$encadrants = $pdo->query("SELECT id, prenom, nom, email FROM utilisateurs WHERE actif = 1 ORDER BY prenom, nom")->fetchAll();

$materielDispo = $pdo->query("
    SELECT m.id, m.marque, m.modele, m.numero_serie, m.quantite_disponible, m.type_id, t.libelle AS type_libelle
    FROM materiel m JOIN types_epi t ON t.id = m.type_id
    WHERE m.quantite_disponible > 0 AND m.statut = 'disponible'
    ORDER BY t.libelle, m.marque
")->fetchAll();

$titre_page = 'Enregistrer un emprunt';
include __DIR__ . '/../includes/header.php';
?>

<h1>Enregistrer un emprunt de materiel</h1>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err" id="alerte-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="card">
  <h2>Demandes en attente ou approuvees (a convertir en emprunt)</h2>
  <?php if (empty($demandes)): ?>
    <p>Aucune demande a traiter pour le moment.</p>
  <?php endif; ?>
  <?php foreach ($demandes as $d): ?>
    <div style="border:1px solid var(--bordure); border-radius:8px; padding:0.8rem 1rem; margin-bottom:0.8rem; <?= $demandeSelectionnee && $demandeSelectionnee['id'] === $d['id'] ? 'background:#eef5f8;' : '' ?>">
      <p style="margin:0 0 0.4rem 0;">
        <strong><?= htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></strong>
        - <?= htmlspecialchars($d['activite'] ?? 'Activite non precisee') ?>
        - du <?= htmlspecialchars($d['date_debut_souhaitee']) ?> au <?= htmlspecialchars($d['date_fin_souhaitee']) ?>
        - statut : <?= htmlspecialchars($d['statut']) ?>
      </p>
      <ul style="margin:0.3rem 0;">
        <?php foreach ($lignesParDemande[$d['id']] ?? [] as $l): ?>
          <li>
            <?= (int)$l['quantite'] ?> x
            <?= htmlspecialchars($l['type_libelle'] ?? ($l['marque'] . ' ' . $l['modele'] . ($l['numero_serie'] ? ' (' . $l['numero_serie'] . ')' : ''))) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="btn secondaire" href="/responsable/emprunts.php?demande_id=<?= $d['id'] ?>">Convertir cette demande en emprunt</a>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2><?= $demandeSelectionnee ? 'Emprunt pour la demande selectionnee' : 'Emprunt manuel (sans demande prealable)' ?></h2>
  <form method="post" id="form-emprunt">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="demande_id" value="<?= $demandeSelectionnee ? (int)$demandeSelectionnee['id'] : '' ?>">

    <div class="form-group">
      <label for="encadrant_id">Emprunteur (encadrant) *</label>
      <select name="encadrant_id" id="encadrant_id" required>
        <option value="">-- Choisir --</option>
        <?php foreach ($encadrants as $e): ?>
          <option value="<?= $e['id'] ?>" <?= $demandeSelectionnee && (int)$demandeSelectionnee['encadrant_id'] === (int)$e['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['prenom'] . ' ' . $e['nom'] . ' (' . $e['email'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="activite">Activite / sortie concernee</label>
      <input type="text" name="activite" id="activite" value="<?= htmlspecialchars($demandeSelectionnee['activite'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label for="date_retour_prevue">Date de retour prevue</label>
      <input type="date" name="date_retour_prevue" id="date_retour_prevue" value="<?= htmlspecialchars($demandeSelectionnee['date_fin_souhaitee'] ?? '') ?>">
    </div>

    <h2>Materiel a sortir</h2>
    <div id="lignes-materiel">
      <?php if (!empty($lignesPrefill)): ?>
        <?php foreach ($lignesPrefill as $l): ?>
          <div class="ligne-materiel" style="display:flex; gap:0.6rem; margin-bottom:0.6rem;">
            <select name="materiel_id[]" style="flex:3;">
              <option value="">-- Choisir un article --</option>
              <?php foreach ($materielDispo as $m): ?>
                <?php
                  // preselectionne un article precis si la demande en visait un,
                  // sinon preselectionne le premier article disponible du meme type
                  $preselectionne = $l['materiel_id']
                      ? ((int)$m['id'] === (int)$l['materiel_id'])
                      : ((int)$m['type_id'] === (int)$l['type_id']);
                ?>
                <option value="<?= $m['id'] ?>" <?= $preselectionne ? 'selected' : '' ?>>
                  <?= htmlspecialchars($m['type_libelle'] . ' - ' . ($m['marque'] ?? '') . ' ' . ($m['modele'] ?? '') . ($m['numero_serie'] ? ' (' . $m['numero_serie'] . ')' : '') . ' [dispo: ' . $m['quantite_disponible'] . ']') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="quantite[]" min="1" value="<?= (int)$l['quantite'] ?>" style="flex:1;">
            <button type="button" class="btn secondaire" onclick="supprimerLigne(this)">Supprimer</button>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="ligne-materiel" style="display:flex; gap:0.6rem; margin-bottom:0.6rem;">
          <select name="materiel_id[]" style="flex:3;">
            <option value="">-- Choisir un article --</option>
            <?php foreach ($materielDispo as $m): ?>
              <option value="<?= $m['id'] ?>">
                <?= htmlspecialchars($m['type_libelle'] . ' - ' . ($m['marque'] ?? '') . ' ' . ($m['modele'] ?? '') . ($m['numero_serie'] ? ' (' . $m['numero_serie'] . ')' : '') . ' [dispo: ' . $m['quantite_disponible'] . ']') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="quantite[]" min="1" value="1" style="flex:1;">
          <button type="button" class="btn secondaire" onclick="supprimerLigne(this)">Supprimer</button>
        </div>
      <?php endif; ?>
    </div>
    <button type="button" class="btn secondaire" onclick="ajouterLigne()">+ Ajouter un article</button>

    <div style="margin-top:1.5rem;">
      <button type="submit" class="btn vert">Enregistrer l'emprunt</button>
    </div>
  </form>
</div>

<script>
function ajouterLigne() {
  const conteneur = document.getElementById('lignes-materiel');
  const premiere = conteneur.querySelector('.ligne-materiel');
  const clone = premiere.cloneNode(true);
  clone.querySelector('select').selectedIndex = 0;
  clone.querySelector('input').value = 1;
  conteneur.appendChild(clone);
  masquerErreur();
}
function supprimerLigne(bouton) {
  const conteneur = document.getElementById('lignes-materiel');
  if (conteneur.querySelectorAll('.ligne-materiel').length > 1) {
    bouton.closest('.ligne-materiel').remove();
  } else {
    bouton.closest('.ligne-materiel').querySelector('select').selectedIndex = 0;
    bouton.closest('.ligne-materiel').querySelector('input').value = 1;
  }
  masquerErreur();
}
function masquerErreur() {
  const alerte = document.getElementById('alerte-erreur');
  if (alerte) { alerte.style.display = 'none'; }
}
document.getElementById('form-emprunt').addEventListener('input', masquerErreur);
document.getElementById('form-emprunt').addEventListener('change', masquerErreur);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
