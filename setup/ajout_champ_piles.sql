-- Ajout du champ 'piles' pour les Detecteurs de victimes d'avalanche (DVA)
-- Exécuter ce fichier APRES avoir vidé la table materiel ou sur une base existante

-- 1. Ajouter la colonne 'piles' avec valeurs possibles : 'mises' ou 'retirées'
ALTER TABLE materiel ADD COLUMN piles ENUM('mises', 'retirées') DEFAULT 'mises';

-- 2. Mettre à jour les DVA existants pour qu'ils aient les piles "mises" par défaut
UPDATE materiel m
JOIN types_epi t ON m.type_id = t.id
SET m.piles = 'mises'
WHERE t.code = 'DVA';

-- 3. Mettre à jour les autres articles (non-DVA) pour qu'ils aient les piles "retirées" (ou NULL si préféré)
-- Optionnel : si tu veux que les non-DVA aient une valeur par défaut
-- UPDATE materiel m
-- LEFT JOIN types_epi t ON m.type_id = t.id
-- SET m.piles = 'retirées'
-- WHERE t.code != 'DVA' OR t.code IS NULL;
