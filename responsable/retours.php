<?php
require_once __DIR__ . '/../auth.php';
require_min_role('responsable_materiel');

$pdo = db();
$user = current_user();
$message = null;
$erreur = null;

// Un responsable peut valider le retour d'un emprunt enregistre par un AUTRE responsable :
// aucune restriction ici sur qui a cree l'emprunt (emprunts.responsable_id), volontairement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ligneId = (int)$_POST['ligne_id'];
    $etat = $_POST['etat_retour'];
    $commentaireLigne = trim($_POST['commentaire_ligne'] ?? '') ?: null;

    if (!in_array($etat, ['bon','a_verifier','endommage','perdu'], true)) {
        $erreur = "Etat de retour invalide.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM emprunts_lignes WHERE id = ?");
            $stmt->execute([$ligneId]);
            $ligne = $stmt->fetch();
            if (!$ligne) throw new Exception("Ligne d'emprunt introuvable.");

            $pdo->prepare("UPDATE emprunts_lignes SET etat_retour = ?, commentaire_ligne = ? WHERE id = ?")
                ->execute([$etat, $commentaireLigne, $ligneId]);

            $stmt = $pdo->prepare("SELECT quantite_disponible, quantite_stock FROM materiel WHERE id = ? FOR UPDATE");
            $stmt->execute([$ligne['materiel_id']]);
            $m = $stmt->fetch();

            if ($etat === 'perdu') {
                $pdo->prepare("UPDATE materiel SET statut = 'perdu' WHERE id = ?")->execute([$ligne['materiel_id']]);
            } elseif ($etat === 'endommage') {
                $pdo->prepare("UPDATE materiel SET statut = 'en_revision' WHERE id = ?")->execute([$ligne['materiel_id']]);
            } else {
                $nouvelleQte = min($m['quantite_stock'], $m['quantite_disponible'] + $ligne['quantite']);
                $pdo->prepare("UPDATE materiel SET quantite_disponible = ?, statut = 'disponible' WHERE id = ?")
                    ->execute([$nouvelleQte, $ligne['materiel_id']]);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(etat_retour IS NOT NULL) AS traitees FROM emprunts_lignes WHERE emprunt_id = ?");
            $stmt->execute([$ligne['emprunt_id']]);
            $compte = $stmt->fetch();
            if ((int)$compte['total'] === (int)$compte['traitees']) {
                $pdo->prepare("UPDATE emprunts SET statut = 'retourne', date_retour_effective = NOW(), responsable_retour_id = ? WHERE id = ?")
                    ->execute([$user['id'], $ligne['emprunt_id']]);
            }

            $pdo->commit();

            // Redirige (Post-Redirect-Get) en gardant l'emprunt traite deroule, pour eviter
            // qu'un re-envoi de formulaire ne re-enregistre le meme retour et que la liste se re-enroule.
            header('Location: /responsable/retours.php?open=' . (int)$ligne['emprunt_id'] . '&msg=ok#emprunt-' . (int)$ligne['emprunt_id']);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'ok') {
    $message = "Retour enregistre.";
}
$empruntOuvert = (int)($_GET['open'] ?? 0);

// Recupere tous les emprunts en cours (avec au moins une ligne non retournee), groupes
$emprunts = $pdo->query("
    SELECT e.id AS emprunt_id, e.date_sortie, e.activite, u.prenom, u.nom, r.prenom AS resp_prenom, r.nom AS resp_nom
    FROM emprunts e
    JOIN utilisateurs u ON u.id = e.encadrant_id
    JOIN utilisateurs r ON r.id = e.responsable_id
    WHERE e.statut IN ('en_cours','en_retard')
    ORDER BY e.date_sortie ASC
")->fetchAll();

$lignesParEmprunt = [];
if (!empty($emprunts)) {
    $ids = array_column($emprunts, 'emprunt_id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT el.*, mt.libelle AS type_libelle, m.marque, m.modele, m.numero_serie
        FROM emprunts_lignes el
        JOIN materiel m ON m.id = el.materiel_id
        JOIN types_epi mt ON mt.id = m.type_id
        WHERE el.emprunt_id IN ($in)
        ORDER BY el.id
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $l) {
        $lignesParEmprunt[$l['emprunt_id']][] = $l;
    }
}

$titre_page = 'Enregistrer un retour';
include __DIR__ . '/../includes/header.php';
?>

<h1>Enregistrer un retour de materiel</h1>
<p style="color:var(--gris); font-size:0.9rem;">
  Tu peux valider le retour d'un emprunt meme s'il a ete enregistre par un autre responsable materiel.
</p>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<?php foreach ($emprunts as $e): ?>
  <?php
    $lignesRestantes = array_filter($lignesParEmprunt[$e['emprunt_id']] ?? [], fn($l) => $l['etat_retour'] === null);
    if (empty($lignesRestantes)) continue; // tout est deja rendu pour cet emprunt
    $titre = htmlspecialchars($e['prenom'] . ' ' . $e['nom']) . ' - '
           . htmlspecialchars($e['activite'] ?? 'Activite non precisee') . ' - '
           . htmlspecialchars(substr($e['date_sortie'], 0, 16));
  ?>
  <details class="card" id="emprunt-<?= (int)$e['emprunt_id'] ?>" <?= $empruntOuvert === (int)$e['emprunt_id'] ? 'open' : '' ?> style="cursor:pointer;">
    <summary style="cursor:pointer; font-weight:600; font-size:1.05rem; color:var(--bleu-fonce);">
      <?= $titre ?> (<?= count($lignesRestantes) ?> article(s) a rendre - emprunt enregistre par <?= htmlspecialchars($e['resp_prenom'] . ' ' . $e['resp_nom']) ?>)
    </summary>
    <table style="margin-top:1rem;">
      <thead><tr><th>Materiel</th><th>Qte</th><th>Traitement du retour</th></tr></thead>
      <tbody>
        <?php foreach ($lignesRestantes as $l): ?>
        <tr>
          <td><?= htmlspecialchars($l['type_libelle'] . ' - ' . ($l['marque'] ?? '') . ' ' . ($l['modele'] ?? '') . ($l['numero_serie'] ? ' (' . $l['numero_serie'] . ')' : '')) ?></td>
          <td><?= (int)$l['quantite'] ?></td>
          <td>
            <form method="post" style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="ligne_id" value="<?= $l['id'] ?>">
              <select name="etat_retour">
                <option value="bon">Bon etat</option>
                <option value="a_verifier">A verifier</option>
                <option value="endommage">Endommage</option>
                <option value="perdu">Perdu</option>
              </select>
              <input type="text" name="commentaire_ligne" placeholder="Commentaire sur cet article (facultatif)" style="width:220px;">
              <button type="submit" class="btn">Valider retour</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
<?php endforeach; ?>

<?php if (empty($emprunts)): ?>
  <div class="card">Aucun emprunt en cours a retourner.</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
