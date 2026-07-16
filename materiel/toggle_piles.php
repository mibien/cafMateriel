<?php
require_once __DIR__ . '/../auth.php';
require_min_role('responsable_materiel');

header('Content-Type: application/json');

$pdo = db();

// Vérification du jeton CSRF
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$etat = $_POST['etat'] ?? '';

// Validation de l'état
if (!in_array($etat, ['mises', 'retirées'])) {
    echo json_encode(['success' => false, 'error' => 'État invalide.']);
    exit;
}

// Vérification que l'article existe et est un DVA
$stmt = $pdo->prepare("
    SELECT m.id, t.code as type_code
    FROM materiel m
    JOIN types_epi t ON m.type_id = t.id
    WHERE m.id = ?
");
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article || $article['type_code'] !== 'DVA') {
    echo json_encode(['success' => false, 'error' => 'Article introuvable ou non un DVA.']);
    exit;
}

// Mise à jour de l'état des piles
$stmt = $pdo->prepare("UPDATE materiel SET piles = ? WHERE id = ?");
$stmt->execute([$etat, $id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Aucune modification effectuée.']);
}
