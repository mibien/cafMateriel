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

// Compter les articles dont la date de revision est deja passee
$alertes['revisions_passees'] = $pdo->query("
    SELECT COUNT(*) FROM materiel
    WHERE date_prochaine_revision IS NOT NULL
    AND date_prochaine_revision < CURDATE()
")->fetchColumn();

// Compter les articles dont la date de revision arrive dans les 60 jours (futur)
$alertes['revisions_a_venir'] = $pdo->query("
    SELECT COUNT(*) FROM materiel
    WHERE date_prochaine_revision IS NOT NULL
    AND date_prochaine_revision > CURDATE()
    AND date_prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
")->fetchColumn();

// Total pour compatibilite
$alertes['revisions_proches'] = $alertes['revisions_passees'] + $alertes['revisions_a_venir'];

// Recuperer la liste des articles arrivant a echeance de revision
$materielRevisionsProches = $pdo->query("
    SELECT m.id, m.marque, m.modele, m.numero_serie, m.date_prochaine_revision
    FROM materiel m
    WHERE m.date_prochaine_revision IS NOT NULL
    AND m.date_prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY m.date_prochaine_revision, m.marque, m.modele, m.numero_serie
")->fetchAll();

$alertes['materiel_perdu'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'perdu'")->fetchColumn();

$alertes['materiel_hors_service'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'hors_service'")->fetchColumn();

$alertes['materiel_en_revision'] = $pdo->query("SELECT COUNT(*) FROM materiel WHERE statut = 'en_revision'")->fetchColumn();

// Alerte pour les piles des DVA
$alertes['piles_dva_mises'] = $pdo->query(
    "SELECT COUNT(*) FROM materiel m
    JOIN types_epi t ON t.id = m.type_id
    WHERE t.code = 'DVA' AND m.piles = 'mises'"
)->fetchColumn();

// Recuperer la liste des DVA avec piles mises pour l'encart deroulant
$dvaAvecPiles = $pdo->query(
    "SELECT m.id, m.marque, m.modele, m.numero_serie
    FROM materiel m
    JOIN types_epi t ON t.id = m.type_id
    WHERE t.code = 'DVA' AND m.piles = 'mises'
    ORDER BY m.marque, m.modele, m.numero_serie"
)->fetchAll();


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
    <p class="alert warn">
      <?php
      $parts = [];
      if ($alertes['revisions_passees'] > 0) {
          $parts[] = (int)$alertes['revisions_passees'] . ' article(s) sont arrivé(s) à échéance de révision';
      }
      if ($alertes['revisions_a_venir'] > 0) {
          $parts[] = (int)$alertes['revisions_a_venir'] . ' article(s) arrivent à échéance de révision dans les 60 jours';
      }
      echo implode(' et ', $parts);
      ?>.
    </p>
    <details class="card" style="margin-top: 0.5rem; margin-bottom: 0.5rem;">
      <summary style="cursor: pointer; font-weight: 600; color: var(--bleu-fonce);">
        Voir la liste des articles à réviser
      </summary>
      <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--bordure);">
        <?php if (!empty($materielRevisionsProches)): ?>
          <table style="width: 100%; font-size: 0.92rem;">
            <thead>
              <tr>
                <th>Marque</th>
                <th>Modèle</th>
                <th>N° serie</th>
                <th>Statut</th>
                <th style="text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($materielRevisionsProches as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['marque'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($item['modele'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($item['numero_serie'] ?? '-') ?></td>
                  <td>
                    <?php
                    $dateRevision = $item['date_prochaine_revision'];
                    $identifiant = trim(($item['marque'] ?? '') . ' ' . ($item['modele'] ?? '') . ' ' . ($item['numero_serie'] ?? ''));
                    $dateObj = DateTime::createFromFormat('Y-m-d', $dateRevision);
                    $dateFr = $dateObj ? $dateObj->format('d/m/Y') : $dateRevision;
                    
                    $aujourdhui = new DateTime();
                    $dateRevObj = DateTime::createFromFormat('Y-m-d', $dateRevision);
                    
                    if ($dateRevObj && $dateRevObj < $aujourdhui):
                      echo "La date de révision de l'article " . htmlspecialchars($identifiant) . " est arrivée à échéance le " . $dateFr;
                    else:
                      echo "L'article " . htmlspecialchars($identifiant) . " arrive à échéance de révision le " . $dateFr;
                    endif;
                    ?>
                  </td>
                  <td style="text-align: right;">
                    <a href="/materiel/fiche.php?id=<?= $item['id'] ?>" 
                       class="btn" 
                       style="padding: 0.3rem 0.6rem; font-size: 0.85rem;"
                       onclick="event.stopPropagation();">
                      Voir fiche
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </details>
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
  <?php if ($alertes['piles_dva_mises'] > 0): ?>
    <p class="alert warn"><?= (int)$alertes['piles_dva_mises'] ?> DVA ont encore leurs piles mises.</p>
    <details class="card" style="margin-top: 0.5rem; margin-bottom: 0.5rem;">
      <summary style="cursor: pointer; font-weight: 600; color: var(--bleu-fonce);">
        Voir la liste des DVA avec piles mises
      </summary>
      <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--bordure);">
        <?php if (!empty($dvaAvecPiles)): ?>
          <table style="width: 100%; font-size: 0.92rem;">
            <thead>
              <tr>
                <th>Marque</th>
                <th>Modèle</th>
                <th>N° serie</th>
                <th style="text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dvaAvecPiles as $dva): ?>
                <tr>
                  <td><?= htmlspecialchars($dva['marque'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($dva['modele'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($dva['numero_serie'] ?? '-') ?></td>
                  <td style="text-align: right;">
                    <a href="/materiel/fiche.php?id=<?= $dva['id'] ?>" 
                       class="btn" 
                       style="padding: 0.3rem 0.6rem; font-size: 0.85rem;"
                       onclick="event.stopPropagation();">
                      Voir fiche
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </details>
  <?php endif; ?>
  <?php if ($alertes['revisions_proches'] == 0 && $alertes['materiel_en_revision'] == 0 && $alertes['materiel_perdu'] == 0 && $alertes['materiel_hors_service'] == 0 && $alertes['piles_dva_mises'] == 0): ?>
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
      <?php foreach ($materiel as $item): ?>
        <tr onclick="window.location.href='/materiel/fiche.php?id=<?= $item['id'] ?>'" style="cursor:pointer;">
          <td><?= htmlspecialchars($item['type_libelle'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['marque'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['modele'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['numero_serie'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['annee_fabrication'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['date_achat'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['date_premiere_utilisation'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['longueur'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['diametre'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['date_rebut'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['date_derniere_revision'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['date_prochaine_revision'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['sac'] ?? '-') ?></td>
          <td>
            <?= htmlspecialchars($item['quantite_disponible'] ?? '-') ?> / <?= htmlspecialchars($item['quantite_stock'] ?? '-') ?>
          </td>
          <td><?= htmlspecialchars($item['statut'] ?? '-') ?></td>
          <td>
            <?php if ($item['type_code'] === 'DVA'): ?>
              <button class="btn-piles btn-piles-<?= htmlspecialchars($item['piles'] ?? 'retirées') ?>"
                      data-materiel-id="<?= $item['id'] ?>"
                      data-etat-actuel="<?= htmlspecialchars($item['piles'] ?? 'retirées') ?>"
                      onclick="event.stopPropagation(); togglePiles(this);">
                <?= htmlspecialchars($item['piles'] ?? 'retirées') ?>
              </button>
            <?php else: ?>
              <?= htmlspecialchars($item['piles'] ?? '-') ?>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($item['commentaire'] ?? '-') ?></td>
          <td><?= htmlspecialchars($item['modifie_le'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
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
