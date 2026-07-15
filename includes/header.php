<?php
/**
 * includes/header.php
 * Attend eventuellement une variable $titre_page definie avant l'include.
 */
require_once __DIR__ . '/../auth.php';
$user = current_user();
$titre_page = $titre_page ?? 'Materiel du club';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titre_page) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="topbar">
  <div class="brand">🏔️ Materiel du club</div>
  <?php if ($user): ?>
  <div class="nav">
    <a href="/materiel/liste.php">Inventaire</a>

    <?php if (has_min_role('encadrant')): ?>
      <a href="/encadrant/demande_form.php">Demander un emprunt</a>
      <a href="/encadrant/mes_emprunts.php">Mes emprunts</a>
    <?php endif; ?>

    <?php if (has_min_role('responsable_materiel')): ?>
      <a href="/responsable/materiel.php">Gerer le materiel</a>
      <a href="/responsable/demandes.php">Demandes en attente</a>
      <a href="/responsable/emprunts.php">Enregistrer un emprunt</a>
      <a href="/responsable/retours.php">Enregistrer un retour</a>
    <?php endif; ?>

    <?php if (has_min_role('president')): ?>
      <a href="/president/nominations.php">Nommer un responsable</a>
    <?php endif; ?>

    <?php if (has_min_role('administrateur')): ?>
      <a href="/admin/utilisateurs.php">Comptes utilisateurs</a>
    <?php endif; ?>

    <span>
      <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>
      <span class="badge-role"><?= htmlspecialchars($user['role']) ?></span>
    </span>
    <a href="/logout.php">Deconnexion</a>
  </div>
  <?php endif; ?>
</div>
<div class="container">
