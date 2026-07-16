<?php
require_once __DIR__ . '/../auth.php';
require_login();

$pdo = db();

$typeFiltre = $_GET['type'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$recherche = trim($_GET['q'] ?? '');

function construireSql(array &$params, string $typeFiltre, string $statutFiltre, string $recherche): string
{
    $sql = "SELECT m.*, t.libelle AS type_libelle, t.code AS type_code
            FROM materiel m
            JOIN types_epi t ON t.id = m.type_id
            WHERE 1=1";
    if ($typeFiltre !== '') {
        $sql .= " AND t.code = ?";
        $params[] = $typeFiltre;
    }
    if ($statutFiltre === 'emprunte') {
        // Un lot partiellement emprunte (ex: 1 degaine sur 28) garde le statut 'disponible'
        // tant qu'il en reste au moins une : on filtre donc sur les quantites, pas sur le statut brut.
        $sql .= " AND m.quantite_disponible < m.quantite_stock AND m.statut NOT IN ('hors_service', 'perdu')";
    } elseif ($statutFiltre !== '') {
        $sql .= " AND m.statut = ?";
        $params[] = $statutFiltre;
    }
    if ($recherche !== '') {
        $sql .= " AND (m.marque LIKE ? OR m.modele LIKE ? OR m.numero_serie LIKE ?)";
        $like = "%$recherche%";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY t.libelle, m.marque, m.modele";
    return $sql;
}

$params = [];
$sql = construireSql($params, $typeFiltre, $statutFiltre, $recherche) . " LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$materiel = $stmt->fetchAll();

$types = $pdo->query("SELECT code, libelle FROM types_epi ORDER BY libelle")->fetchAll();

$dernierInventaire = $pdo->query("SELECT date_inventaire FROM inventaires ORDER BY date_inventaire DESC LIMIT 1")->fetchColumn();

// Alertes a traiter
$alertes = [];
$alertes['revisions_proches'] = $pdo->query("
    SELECT COUNT(*) FROM materiel
    WHERE date_prochaine_revision IS NOT NULL
    AND date_prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
")->fetchColumn();

$alertes['materiel_perdu'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'perdu'")->fetchColumn();

$alertes['materiel_hors_service'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'hors_service'")->fetchColumn();

$alertes['materiel_en_revision'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'en_revision'")->fetchColumn();

// Construit la query string pour le lien d'export (reprend les memes filtres)
$queryExport = http_build_query(['type' => $typeFiltre, 'statut' => $statutFiltre, 'q' => $recherche]);

$titre_page = 'Inventaire du materiel';
include __DIR__ . '/../includes/header.php';
?>

<h1>Inventaire du materiel</h1>

<?php if (has_min_role('responsable_materiel')): ?>
<div class="card">
  <h2>À traiter</h2>
  <?php if ($alertes['revisions_proches'] > 0): ?>
    <p class="alert warn"><?= (int)$alertes['revisions_proches'] ?> article(s) arrivent à échéance de révision dans les 60 jours.</p>
  <?php endif; ?>
  <?php if ($alertes['materiel_en_revision'] > 0): ?>
    <p><?= (int)$alertes['materiel_en_revision'] ?> article(s) actuellement en révision.</p>
  <?php endif; ?>
  <?php if ($alertes['materiel_perdu'] > 0): ?>
    <p class="alert err"><?= (int)$alertes['materiel_perdu'] ?> article(s) marqué(s) comme perdu.</p>
  <?php endif; ?>
  <?php if ($alertes['materiel_hors_service'] > 0): ?>
    <p class="alert err"><?= (int)$alertes['materiel_hors_service'] ?> article(s) hors service.</p>
  <?php endif; ?>
  <?php if ($alertes['revisions_proches'] == 0 && $alertes['materiel_en_revision'] == 0 && $alertes['materiel_perdu'] == 0 && $alertes['materiel_hors_service'] == 0): ?>
    <p>Aucune alerte en cours.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
  <div>
    <?php if ($dernierInventaire): ?>
      Dernier inventaire complet realise le <strong><?= htmlspecialchars(date('d/m/Y', strtotime($dernierInventaire))) ?></strong>
      &nbsp;-&nbsp; <a href="/materiel/inventaires.php">voir l'historique des inventaires</a>
    <?php else: ?>
      Aucun inventaire enregistre. <a href="/materiel/inventaires.php">Enregistrer un inventaire</a>
    <?php endif; ?>
  </div>
  <a class="btn secondaire" href="/materiel/export_xlsx.php?<?= htmlspecialchars($queryExport) ?>">Exporter en .xlsx</a>
</div>

<div class="card">
  <form method="get" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
    <div class="form-group" style="min-width:200px;">
      <label for="type">Type d'EPI</label>
      <select name="type" id="type">
        <option value="">Tous</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= htmlspecialchars($t['code']) ?>" <?= $typeFiltre === $t['code'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['libelle']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:180px;">
      <label for="statut">Statut</label>
      <select name="statut" id="statut">
        <option value="">Tous</option>
        <?php foreach (['disponible','emprunte','en_revision','hors_service','perdu'] as $s): ?>
          <option value="<?= $s ?>" <?= $statutFiltre === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="min-width:220px;">
      <label for="q">Recherche (marque, modele, n° serie)</label>
      <input type="text" name="q" id="q" value="<?= htmlspecialchars($recherche) ?>">
    </div>
    <div class="form-group">
      <button type="submit" class="btn">Filtrer</button>
    </div>
  </form>
</div>

<div class="card">
  <p><?= count($materiel) ?> article(s) affiche(s) (limite a 500). Clique sur une ligne pour voir la fiche detaillee.</p>
  <p style="color:var(--gris); font-size:0.85rem;">
    💡 Astuce : une fois le tableau clique (ou survole), utilise les <strong>fleches du clavier</strong>
    pour te deplacer horizontalement et verticalement, ou fais glisser la barre grise ci-dessous avec la souris.
  </p>
  <div class="scroll-sync-top" id="scroll-top"><div id="scroll-top-inner"></div></div>
  <div style="overflow-x:scroll;" id="scroll-bottom" tabindex="0">
  <table>
    <thead>
      <tr>
        <th>Type</th><th>Marque</th><th>Modele</th><th>N° serie</th><th>Annee fab.</th>
        <th>Date achat</th><th>1ere util.</th><th>Longueur (cm)</th><th>Diametre (mm)</th>
        <th>Date rebut</th><th>Derniere rev.</th><th>Prochaine rev.</th><th>Sac</th>
        <th>Qte dispo / totale</th><th>Statut</th><th>Piles</th><th>Commentaire</th><th>Modifie le</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($materiel as $m): ?>
      <tr style="cursor:pointer;" onclick="window.location='/materiel/fiche.php?id=<?= $m['id'] ?>'">
        <td><?= htmlspecialchars($m['type_libelle']) ?></td>
        <td><?= htmlspecialchars($m['marque'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['modele'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['numero_serie'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['annee_fabrication'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['date_achat'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['date_premiere_utilisation'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['longueur_cm'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['diametre_mm'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['date_mise_au_rebut'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['date_derniere_revision'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['date_prochaine_revision'] ?? '-') ?></td>
        <td><?= htmlspecialchars($m['sac_rangement'] ?? '-') ?></td>
        <td><?= (int)$m['quantite_disponible'] ?> / <?= (int)$m['quantite_stock'] ?></td>
        <td><span class="statut-<?= htmlspecialchars($m['statut']) ?>"><?= htmlspecialchars($m['statut']) ?></span></td>
        <td>
          <?php if ($m['type_code'] === 'DVA' && has_min_role('responsable_materiel')): ?>
            <button 
              class="btn-piles btn-piles-<?= htmlspecialchars($m['piles'] ?? 'retirées') ?>"
              data-materiel-id="<?= $m['id'] ?>"
              data-etat-actuel="<?= htmlspecialchars($m['piles'] ?? 'retirées') ?>"
              onclick="event.stopPropagation(); togglePiles(this);"
            >
              <?= htmlspecialchars($m['piles'] ?? '-') ?>
            </button>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($m['commentaire'] ?? '-') ?></td>
        <td><?= htmlspecialchars(substr($m['date_maj'], 0, 16)) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($materiel)): ?>
        <tr><td colspan="17">Aucun materiel ne correspond a ces criteres.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  const top = document.getElementById('scroll-top');
  const topInner = document.getElementById('scroll-top-inner');
  const bottom = document.getElementById('scroll-bottom');
  function syncWidth() { topInner.style.width = bottom.scrollWidth + 'px'; }
  syncWidth();
  window.addEventListener('resize', syncWidth);
  top.addEventListener('scroll', () => { bottom.scrollLeft = top.scrollLeft; });
  bottom.addEventListener('scroll', () => { top.scrollLeft = bottom.scrollLeft; });
})();

// Fonction pour basculer l'état des piles des DVA
function togglePiles(button) {
  const materielId = button.dataset.materielId;
  const etatActuel = button.dataset.etatActuel;
  const nouvelEtat = etatActuel === 'mises' ? 'retirées' : 'mises';
  
  fetch('/materiel/toggle_piles.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'id=' + encodeURIComponent(materielId) + '&etat=' + encodeURIComponent(nouvelEtat) + '&csrf=' + encodeURIComponent('<?= csrf_token() ?>')
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      button.textContent = nouvelEtat;
      button.dataset.etatActuel = nouvelEtat;
      button.className = 'btn-piles btn-piles-' + nouvelEtat;
    } else {
      alert('Erreur : ' + (data.error || 'Impossible de mettre à jour l\'état des piles.'));
    }
  })
  .catch(error => {
    alert('Erreur réseau : ' + error.message);
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
