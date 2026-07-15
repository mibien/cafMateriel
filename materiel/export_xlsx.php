<?php
/**
 * export_xlsx.php - genere un fichier .xlsx minimal (sans librairie externe)
 * a partir de l'inventaire, avec les memes filtres que liste.php.
 * Utilise ZipArchive (extension PHP standard, activee par defaut chez la plupart des hebergeurs).
 */
require_once __DIR__ . '/../auth.php';
require_login();

if (!class_exists('ZipArchive')) {
    die("L'extension PHP ZipArchive n'est pas activee sur ce serveur. Contacte ton hebergeur pour l'activer (extension standard, generalement deja presente chez OVH).");
}

$pdo = db();
$typeFiltre = $_GET['type'] ?? '';
$statutFiltre = $_GET['statut'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$sql = "SELECT m.*, t.libelle AS type_libelle
        FROM materiel m JOIN types_epi t ON t.id = m.type_id WHERE 1=1";
$params = [];
if ($typeFiltre !== '') { $sql .= " AND t.code = ?"; $params[] = $typeFiltre; }
if ($statutFiltre === 'emprunte') {
    $sql .= " AND m.quantite_disponible < m.quantite_stock AND m.statut NOT IN ('hors_service', 'perdu')";
} elseif ($statutFiltre !== '') {
    $sql .= " AND m.statut = ?"; $params[] = $statutFiltre;
}
if ($recherche !== '') {
    $sql .= " AND (m.marque LIKE ? OR m.modele LIKE ? OR m.numero_serie LIKE ?)";
    $like = "%$recherche%"; $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql .= " ORDER BY t.libelle, m.marque, m.modele";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Type', 'Marque', 'Modele', 'N° serie', 'Annee fab.', 'Date achat', '1ere utilisation',
    'Longueur (cm)', 'Diametre (mm)', 'Date rebut', 'Derniere revision', 'Prochaine revision',
    'Sac rangement', 'Qte stock', 'Qte disponible', 'Statut', 'Commentaire', 'Modifie le'
];

$colKeys = [
    'type_libelle', 'marque', 'modele', 'numero_serie', 'annee_fabrication', 'date_achat',
    'date_premiere_utilisation', 'longueur_cm', 'diametre_mm', 'date_mise_au_rebut',
    'date_derniere_revision', 'date_prochaine_revision', 'sac_rangement', 'quantite_stock',
    'quantite_disponible', 'statut', 'commentaire', 'date_maj'
];

/** Echappe une valeur pour l'inclure dans une cellule XML de type inlineStr */
function xlsx_escape(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Construit une ligne <row> avec des cellules texte "inline" (pas de sharedStrings necessaire) */
function xlsx_build_row(int $rowIndex, array $values): string
{
    $cells = '';
    $col = 'A';
    foreach ($values as $v) {
        $ref = $col . $rowIndex;
        $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape((string)$v) . '</t></is></c>';
        $col++;
    }
    return '<row r="' . $rowIndex . '">' . $cells . '</row>';
}

$sheetRows = xlsx_build_row(1, $headers);
$i = 2;
foreach ($rows as $r) {
    $ligne = [];
    foreach ($colKeys as $k) {
        $ligne[] = $r[$k] ?? '';
    }
    $sheetRows .= xlsx_build_row($i, $ligne);
    $i++;
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>' . $sheetRows . '</sheetData>'
    . '</worksheet>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Inventaire" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '</Relationships>';

$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
$zip->open($tmpFile, ZipArchive::OVERWRITE);
$zip->addEmptyDir('_rels');
$zip->addEmptyDir('xl');
$zip->addEmptyDir('xl/_rels');
$zip->addEmptyDir('xl/worksheets');
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

$nomFichier = 'inventaire_materiel_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);
exit;
