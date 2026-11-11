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

## Dépannage : POST /api/account/sources => HTTP 404 « Not found »

Un **404 sur la création de source** intervient avant la validation du lien
Xtream/M3U : `AccountSourcesController::create()` ne renvoie aucun HTTP 404.
Cette réponse vient généralement d'une passerelle o2switch désactivée,
d'un `.htaccess` ou `index.php` déployé trop ancien, ou d'une route Symfony
absente dans le cache.

Sur SSH o2switch, exécute d'abord **sans partager aucun mot de passe** :

```bash
cd ~/nsatellite
bash deploy/o2switch/doctor-account.sh
```

Le diagnostic effectue deux requêtes **anonymes** sans cookie ni accès IPTV :
`GET /api/account/sources` et `POST /api/account/sources` avec `{}`.
Elles doivent toutes deux répondre **HTTP 401** si le routage fonctionne :
aucune source ne peut être créée car elles ne sont pas authentifiées.

- **401 / 401** : la route est accessible ; rechercher alors un problème
  dans la session navigateur ou dans le corps de la requête (qui doit
  produire une erreur 400/422, pas 404).
- **404 / 404** : vérifier les deux flags privés
  `NELSTV_ENABLE_IDENTITY_API=1` et `NELSTV_ENABLE_VIEWER_API=1`,
  puis comparer `public_html/.htaccess` et `public_html/index.php`
  avec les versions dans `deploy/o2switch/public_html/`.
- **401 / 404** : le GET passe, mais pas le POST : comparer la
  configuration Apache et vérifier le blocage par un WAF/hébergement.
- **403** : rechercher une règle Apache, WAF, HTTPS ou d'origine refusée.
- **500** : la migration `source_owners`, les dépendances PHP et le
  schéma MariaDB sont à vérifier ; ce n'est plus le même problème.

La configuration peut être corrigée **uniquement dans le fichier privé**
`~/nsatellite/.env.local` ou l'environnement o2switch, jamais dans
`.env.example`. Ne jamais envoyer le contenu de ce fichier à quelqu'un.
Pour l'API utilisateur, les valeurs nécessaires sont :

```dotenv
NELSTV_ENABLE_IDENTITY_API=1
NELSTV_ENABLE_VIEWER_API=1
```

Après sauvegarde et validation de la configuration, republier le frontal
contrôlé par GitHub (l'installateur réalise une sauvegarde privée) :

```bash
cd ~/nsatellite
git status --short
git pull --ff-only origin main
php bin/console cache:clear --env=prod
NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh
bash deploy/o2switch/doctor-account.sh
```

Si le répertoire Git contient des changements suivis non enregistrés,
les résoudre avant l'actualisation. La commande `doctor-account.sh`
n'envoie aucun cookie, mot de passe, jeton admin ni identifiant fournisseur.
Les valeurs 0/1 vérifiées en CLI peuvent être différentes des paramètres
définis dans cPanel pour Apache/PHP-FPM : les codes HTTP réels font foi.

**Côté formulaire**, tant que `GET /api/account/sources` retourne
HTTP 404, l'ajout est maintenant désactivé et un message indique les
contrôles à effectuer. Cette protection évite de répéter des soumissions
inutiles ; elle ne contourne pas les flags de sécurité de l'hébergement.

## Dépannage : « Enregistrement impossible. Vérifie la migration source_owners. »

Ce message historique apparaissait pour **toute** erreur d'enregistrement.
Il ne prouve pas que la table est absente : l'échec pouvait venir de
l'encryption (IPTV_SECRET_KEY), d'un schéma SQL différent, d'une contrainte
d'intégrité, d'un compte sans droits ou de la création du job.

Le backend indique désormais un **code d'étape non sensible** :
\`source_limit\`, \`encryption\`, \`insert_source\`, \`insert_ownership\`,
\`insert_grant\`, \`queue_import\` ou \`commit\`.
Aucune erreur SQL brute ni donnée fournisseur n'est renvoyée à l'utilisateur.
L'entrée du journal PHP contient uniquement le code d'étape, la classe
d'exception et un code sans données personnelles.

### Vérification en lecture seule sur o2switch

\`\`\`bash
cd ~/nsatellite
git status --short
git pull --ff-only origin main

php bin/doctor-account-storage.php
\`\`\`

Cette commande **ne modifie aucune table**, n'essaie pas de contacter le
fournisseur et ne révèle pas les valeurs des variables privées. Elle vérifie :
- La connexion à la base active de l'application ;
- L'existence des tables et colonnes utilisées à la création
  (\`sources\`, \`source_owners\`, \`user_source_access\`, \`import_jobs\`,
  \`app_users\` et \`app_sessions\`) ;
- Le fonctionnement du chiffrement/déchiffrement avec la clé
  \`IPTV_SECRET_KEY\`, uniquement **en mémoire**.

Si \`source_owners\` est manquante, sauvegarder d'abord la base, puis
appliquer **uniquement** la migration
\`migrations/mariadb/003_source_owners.sql\` via phpMyAdmin à la base
utilisée par l'application. Ne pas relancer \`001_schema.sql\` contre une
base existante. Si le diagnostic dit \`IPTV_SECRET_KEY\` invalide, régler
la configuration privée sans réinitialiser une clé déjà utilisée : changer
une clé existante rendrait illisibles les accès enregistrés précédemment.

Si tous les prérequis sont verts mais qu'une création échoue, relever
uniquement le **code d'étape** retourné par le formulaire et les logs
d'application **expurgés des identifiants**. Le test de création avec
SQLite jetable permet de vérifier l'ensemble
source → propriétaire → autorisation → job :

\`\`\`bash
php tests/account_source_creation_smoke.php
\`\`\`

Ce test ne modifie pas MariaDB et n'appelle aucun fournisseur IPTV.


## MariaDB : erreur SQL 1064 « near LIMIT 1 » dans le worker

Le worker `php bin/console iptv:jobs:run --env=prod` doit réserver
atomiquement un job `queued` avant de contacter un fournisseur. MariaDB
11.4 exige de placer `LIMIT 1` **avant** `FOR UPDATE SKIP LOCKED`.
L'ancienne requête plaçait `LIMIT 1` à la fin et produisait une erreur
SQLSTATE 42000 / 1064.

Requête correcte (transaction InnoDB obligatoire) :

```sql
SELECT * FROM import_jobs WHERE status='queued'
ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED;
```

Le worker conserve le verrou pendant qu'il marque le job `running`,
puis termine la transaction **avant** le téléchargement. Deux workers
ne doivent donc pas réserver le même job. Une CI MariaDB 11.4 vérifie
la syntaxe, la réservation concurrente et les états.

Si un import a échoué **sur cette requête**, aucun job n'a normalement
été réservé : ne supprime pas la source ni les identifiants enregistrés.

Déployer uniquement le code, puis lancer **une** invocation du worker :

```bash
cd ~/nsatellite
git status --short
git pull --ff-only origin main
php -l src/Command/RunImportJobsCommand.php
php bin/console iptv:jobs:run --env=prod
```

Pour consulter l'état des jobs sans divulguer les URL :

```sql
SELECT id, source_id, status, attempts, finished_at
FROM import_jobs ORDER BY id DESC LIMIT 10;
```

Si le job passe à `failed`, cela signifie que le worker a atteint
l'étape de synchronisation et qu'il faudra diagnostiquer la source, le
schéma du catalogue ou les accès fournisseur séparément. Une importation
volumineuse peut prendre du temps ; ne pas lancer plusieurs workers en
boucle pendant son exécution.


## Synchronisation : message générique « Synchronization failed »

Quand le worker peut réserver un job mais que le fournisseur ou le
catalogue provoque une erreur, l'ancien synchroniseur affichait toujours
le même message : cela ne permettait pas de distinguer une panne réseau
d'une erreur SQL et pouvait masquer une migration manquante.

Le synchroniseur affiche désormais exclusivement **une étape et une
catégorie non sensibles** ; par exemple :

\`\`\`text
Synchronization failed: stage=xtream_live_request category=provider_http http_status=403
Synchronization failed: stage=m3u_request category=network_tls_timeout
Synchronization failed: stage=xtream_series_catalogue_write category=database_sql
Synchronization failed: stage=authorize_endpoint category=trusted_host_or_dns
Synchronization failed: stage=xtream_movie_request category=provider_invalid_json
\`\`\`

Ce sont des exemples, **pas les erreurs constatées pour une source réelle**.
Les exceptions brutes de transport peuvent contenir des URL M3U avec
\`username\` et \`password\` ; elles ne sont donc jamais imprimées ni
transmises telles quelles. Le numéro HTTP éventuel est sûr.

### Examiner la source et les prérequis sans tenter de téléchargement

\`\`\`bash
cd ~/nsatellite
git status --short
git pull --ff-only origin main
php bin/doctor-sync-source.php
\`\`\`

Par défaut, ce diagnostic examine la **dernière source créée**, avec son
identifiant numérique. Pour en choisir une :

\`\`\`bash
php bin/doctor-sync-source.php 123
\`\`\`

Il vérifie en lecture seule le schéma réel des tables \`channels\`,
\`vod_series\` et \`vod_episodes\` suivant le type de source, le
déchiffrement, les métadonnées de dernière tâche, et l'hôte approuvé
résolu vers un DNS public **depuis PHP CLI**. Il ne télécharge rien du
fournisseur et ne montre jamais les liens ou identifiants.

### Relancer un job qui est déjà \`failed\`

Après une tentative, le worker marque le job \`failed\` en cas d'échec.
\`iptv:jobs:run\` ne réserve **que** des jobs \`queued\` ; une nouvelle
invocation sans nouvelle tâche ne réessaie donc pas l'ancien job.

Il faut utiliser \`https://nsatellite.bragaatemorrer.fr/account.html\`
et cliquer **Synchroniser** sur sa source personnelle pour ajouter un
nouveau job, puis, dans un terminal :

\`\`\`bash
php bin/console iptv:jobs:run --env=prod
\`\`\`

Ne pas supprimer puis recréer la source, ne pas multiplier les
synchronisations en parallèle, ne pas partager les URL d'accès ni les
messages bruts des clients HTTP.


## Réseau Xtream : stage=xtream_live_request / network_tls_timeout

L'ancien code classait **toutes** les erreurs de transport en
\`network_tls_timeout\`. Cette catégorie ne prouvait pas qu'il s'agissait
d'un timeout. Le synchroniseur différencie désormais les principales
erreurs de cURL (sans afficher leur message, qui peut contenir les
identifiants dans une URL) :

- \`network_timeout\` : délai de connexion ou réponse dépassé ;
- \`network_connect_refused\` : connexion TCP refusée / impossible ;
- \`network_dns_or_proxy\` : résolution DNS ou proxy ;
- \`network_tls_handshake\`, \`network_tls_certificate\` : TLS ;
- \`network_connection_closed\` : coupure / réponse vide ;
- \`network_transport\` : autre erreur, à diagnostiquer.

Pour le cas **Xtream**, lancer le diagnostic de réseau limité, indépendant
de la file d'import :

\`\`\`bash
cd ~/nsatellite
git pull --ff-only origin main
php bin/doctor-xtream-network.php 1
\`\`\`

L'argument est l'ID de **la source NSatellite**, pas un identifiant Xtream.
Sans argument, le script examine la dernière source Xtream.

Le script :

1. Déchiffre localement **uniquement l'adresse du serveur** et contrôle
   l'hôte par \`IPTV_TRUSTED_HOSTS\` (DNS public et IP épinglée) ;
2. Teste la connexion TCP sans identifiant avec un délai de 5 s ;
3. Effectue un GET **sans identifiants** vers \`player_api.php\`, sans
   redirection, et avec des délais plafonnés ;
4. N'affiche que la catégorie de résultat et le code HTTP éventuel
   (200/401/403 peuvent tous démontrer que le service HTTP répond) ;
5. Ne modifie aucune table, aucun job et aucune source.

Les tests HTTP peuvent contacter le fournisseur ; les codes 401/403
**sans identifiants** n'indiquent pas à eux seuls que le compte Xtream
est refusé. Si le TCP échoue, le port distant peut être filtré par
l'hébergeur, refusé par le serveur, ou bloqué pour une autre raison.
Si TCP fonctionne mais pas HTTP, étudier les proxys/cURL et les options
réseau avant d'augmenter aveuglément les timeouts ou de désactiver TLS.

Ne jamais faire de \`curl -v\` avec une URL contenant des credentials,
ni publier de \`.env.local\`, ni désactiver \`verify_peer\` ou la liste
blanche SSRF. Ne pas partager d'IP privée, d'URL Xtream complète ou de
sortie contenant des identifiants.
