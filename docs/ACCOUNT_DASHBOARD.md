# NSatellite — Espace personnel et sources IPTV par compte

## Fonctionnalités intégrées

La page `/account.html` utilise **la même session navigateur** (cookie
`HttpOnly`) que l'accueil, la TV, le catalogue, les séries et les appareils.
Elle n'exige jamais `IPTV_ADMIN_TOKEN`.

- Lister uniquement les sources auxquelles le compte est autorisé.
- Reconnaître une source personnelle (`managed=true`) d'un accès partagé
  par l'exploitant (`managed=false`).
- Ajouter une source distante M3U (URL HTTP/HTTPS complète) ou Xtream (adresse de base HTTP/HTTPS, port optionnel, identifiant/mot de passe séparés). MAG ne dispose pas encore d'un connecteur prêt :
  il n'est donc pas proposé comme source fonctionnelle.
- Modifier les paramètres d'une source **personnelle uniquement** en
  ressaisissant tous les identifiants. Aucune valeur chiffrée n'est réaffichée.
- Demander la synchronisation d'une source personnelle. Le panneau indique
  le dernier état de l'import, mais ne lance jamais lui-même un worker en
  requête web. Un compte ne peut pas modifier une source uniquement partagée.
- Limites de 10 sources personnelles et taux de création/synchronisation
  limités par compte/IP.

Une source ajoutée est enregistrée dans `sources`, son propriétaire
dans `source_owners`, son accès dans `user_source_access`, et un travail
dans `import_jobs`, **dans une seule transaction**. Les sources existantes
restent administrées par l'exploitant : la migration n'en attribue
automatiquement la propriété à personne.

Les URL et identifiants fournisseurs sont chiffrés avec
`IPTV_SECRET_KEY`. Les API publiques ne renvoient jamais les URL, mots de
passe ou MAC des sources, seulement des métadonnées non sensibles.

## Migration obligatoire AVANT de déployer le backend

Sur **MariaDB o2switch**, appliquer une seule fois, après les
migrations 001 et 002 déjà présentes :

```
migrations/mariadb/003_source_owners.sql
```

Le plus simple est d'ouvrir **phpMyAdmin** dans cPanel, sélectionner la
base utilisée par NSatellite et importer ce fichier SQL. Vérifier que
`source_owners` existe ensuite. La migration est idempotente et
n'attribue aucun droit par défaut.

Pour une installation PostgreSQL de développement, utiliser
`migrations/009_source_owners.sql`. Ne pas mélanger les deux formats.
Faire une sauvegarde de la base avant l'opération. La mise à jour du code
sans cette migration rendra les nouvelles API « Mes accès » indisponibles.

## Publication o2switch

Vérifier qu'il n'y a pas de changements locaux dans les fichiers suivis,
puis :

```bash
cd ~/nsatellite
git status --short
git pull --ff-only origin main

php -l src/Controller/AccountSourcesController.php
php tests/account_sources_smoke.php
php tests/o2switch_viewer_route_gate_smoke.php
php bin/console cache:clear --env=prod

NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh
bash deploy/o2switch/check-browser.sh
```

L'installateur publie `/account.html` et la passerelle restrictive qui
ajoute uniquement les routes `/api/account/sources`, les modifications
de sources personnelles et la synchronisation. Les API d'administration
globale, le proxy vidéo et les imports de fichiers de développement restent
bloqués dans la production. Aucune variable n'est activée automatiquement.

Les flags privés `NELSTV_ENABLE_IDENTITY_API=1` et
`NELSTV_ENABLE_VIEWER_API=1` sont requis pour les opérations du compte.
**Les utilisateurs n'ont pas le droit de mettre un domaine arbitraire en
liste blanche** : un exploitant valide le vrai nom DNS de chaque fournisseur
dans `IPTV_TRUSTED_HOSTS`, après en avoir contrôlé la légitimité.
Le nom doit résoudre vers des adresses IP publiques et n'être ni local
ni réservé. Les adresses **HTTP et HTTPS**, avec ports explicites (ex. 8080
ou 8443), sont maintenant reconnues. Une connexion HTTP impose une
**confirmation explicite du risque** dans le formulaire : les identifiants
circulent en clair entre le serveur NSatellite et le fournisseur, même si
le portail NSatellite est en HTTPS.

### Exemples de formats

- **Xtream Codes** : choisir « Xtream » ; adresse de base
  `http://serveur.exemple:8080` ; saisir l'identifiant et le mot de passe
  dans leurs champs respectifs. Ne pas coller `player_api.php` ni
  `get.php` dans l'adresse Xtream.
- **Playlist M3U** : choisir « M3U » et coller l'adresse **complète** :
  `http://serveur.exemple:8080/get.php?username=EXEMPLE&password=EXEMPLE&type=m3u_plus&output=ts`.
  Garder les paramètres `username`, `password`, `type` et `output`.
- Le formulaire peut reconnaître un `get.php` collé en mode Xtream et
  sélectionner M3U automatiquement.

Ces exemples sont fictifs. N'expose jamais les identifiants réels dans
un ticket GitHub ou une capture. L'URL M3U peut inclure des secrets dans
ses paramètres, qui sont chiffrés en base côté serveur et **ne sont pas
réaffichés** après enregistrement. L'approbation du domaine via
`IPTV_TRUSTED_HOSTS` reste obligatoire : un format valide ne donne jamais
automatiquement accès à un domaine arbitraire.

**Attention :** `output=ts` concerne le format des flux annoncés par
l'abonnement, pas la capacité du décodeur PC à lire les flux TS. La
présence du bouquet dans la liste ne garantit toujours pas la lecture
dans le navigateur, notamment avec TS, CORS ou les restrictions du fournisseur.

## Importer les chaînes

L'ajout M3U ou Xtream **crée un job**, il n'importe pas les milliers
de chaînes dans la requête HTTP. Le traitement utilise le worker existant :

```bash
cd ~/nsatellite
php bin/console iptv:jobs:run --env=prod
```

Configurer ou vérifier la tâche cron existante dans cPanel : sans worker,
les jobs restent en état `queued`. Les fournisseurs doivent autoriser
l'utilisation des accès et l'import des métadonnées. La présence des
chaînes dans le catalogue ne garantit pas leur lecture en direct : les
limitations CORS/HLS/TS et les droits du fournisseur s'appliquent toujours.

## Test d'acceptation avant ouverture publique

1. Compte A : connexion sur `/home.html`, ouverture de `/account.html`
   sans seconde connexion ; le compte voit ses propres sources et les
   éventuelles sources attribuées.
2. Création d'une source de test **autorisée**, avec hôte public HTTP(S) approuvé (et confirmation de risque HTTP si nécessaire) :
   un job `queued` apparaît et une seule nouvelle ligne de droits
   est liée au compte A.
3. Lancement du worker sur un environnement de test : statut
   `ready`/ `error` après exécution ; les erreurs ne divulguent pas
   le mot de passe.
4. Compte B : aucune source A, sauf si une autorisation explicite a
   été accordée. La modification d'une source partagée doit être refusée.
5. Rechargement TV/catalogue/séries : session et favoris conservés.
6. Un appel avec cookie depuis une autre origine doit être rejeté ;
   une session TV (jeton de device) ne peut pas créer une source.
7. Vérifier que les anciennes API globales
   `/api/sources`, `/api/imports` restent bloquées publiquement.
8. Tester le comportement après plusieurs erreurs et retour arrière
   grâce au répertoire `var/deploy-backups/`.

### Ce qui n'est pas encore prévu dans ce lot

Le panneau est **personnel**, pas un super-administrateur multi-comptes.
La création d'autres comptes et le partage d'une source existante entre
comptes restent des opérations réservées à l'exploitant/SSH. Les imports
M3U depuis un fichier local, les connexions MAG et le streaming universel
ne sont pas activés depuis ce panneau.
