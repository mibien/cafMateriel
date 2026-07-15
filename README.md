# Gestion du materiel du club - Front-office par role

## Deploiement sur ton hebergement OVH

1. **Base de donnees** : si ce n'est pas deja fait, execute dans phpMyAdmin, dans l'ordre :
   - `01_schema.sql`
   - `02_import_materiel.sql`
   - `03_update_roles.sql` (ajout du role administrateur)

2. **Fichiers** : upload tout le contenu de ce dossier `club-materiel/` a la racine de ton hebergement web OVH (via FTP/SFTP, cf. Manager OVH > Hebergement > FTP-SSH).

3. **Configuration** : ouvre `config.php` et renseigne :
   ```php
   define('DB_HOST', 'deferlecwvmikael.mysql.db');
   define('DB_NAME', 'deferlecwvmikael');
   define('DB_USER', 'ton_identifiant_ovh');
   define('DB_PASS', 'ton_mot_de_passe_ovh');
   ```
   (identifiants visibles dans ton Manager OVH, rubrique Bases de donnees)

4. **Creer ton compte administrateur** : va sur `https://tondomaine.fr/setup/creer_admin.php`,
   remplis le formulaire, puis **SUPPRIME CE FICHIER DU SERVEUR** immediatement apres
   (il ne demande aucun mot de passe pour y acceder, donc il ne doit pas rester en ligne).

5. Connecte-toi sur `https://tondomaine.fr/login.php` avec ton compte administrateur.
   De la, tu peux :
   - nommer le president (page "Nommer un responsable")
   - le president nomme ensuite les responsables materiel et les encadrants
   - creer directement des comptes depuis "Comptes utilisateurs" (page admin) si tu preferes

## Structure du projet

```
club-materiel/
├── config.php              # identifiants BDD (a completer)
├── db.php                  # connexion PDO
├── auth.php                # session + controle des roles
├── login.php / logout.php
├── index.php                # tableau de bord (contenu selon role)
├── admin/utilisateurs.php          # administrateur : gestion des comptes
├── president/nominations.php       # president/admin : nommer les responsables
├── responsable/
│   ├── materiel.php                # ajouter du materiel, changer son statut
│   ├── demandes.php                 # approuver/refuser les demandes d'emprunt
│   ├── sorties.php                  # enregistrer une sortie de materiel
│   └── retours.php                  # enregistrer un retour
├── encadrant/
│   ├── demande_form.php             # faire une demande d'emprunt
│   └── mes_emprunts.php             # historique personnel
├── materiel/liste.php               # inventaire consultable par tous
├── includes/header.php, footer.php  # gabarit commun + navigation par role
├── assets/style.css
└── setup/creer_admin.php            # a supprimer apres le premier usage
```

## Hierarchie des roles

`administrateur` > `president` > `responsable_materiel` > `encadrant`

Chaque page utilise `require_min_role('xxx')` : un role superieur herite automatiquement
des droits des roles inferieurs (ex: le president voit aussi tout ce que voit un responsable materiel).

## Points a securiser avant mise en production reelle

- Forcer HTTPS (OVH fournit un certificat Let's Encrypt gratuit, a activer dans le Manager).
- Supprimer `setup/creer_admin.php` juste apres la creation du compte admin.
- Mettre en place une politique de mot de passe (le formulaire de creation de compte
  n'impose actuellement qu'un minimum de 8 caracteres).
- Prevoir une page "mot de passe oublie" si besoin (non incluse dans cette version).
- Sauvegardes regulieres de la base (phpMyAdmin > Exporter, ou automatiser via cron OVH).
