# Nels TV — Cible o2switch Cloud (architecture, pas encore déployée)

## Périmètre

Héberger l'API Symfony, les interfaces web/PWA, la base PostgreSQL, les comptes,
les appareils, le catalogue et l'EPG sur l'offre mutualisée Cloud. L'application
Android TV et les futurs clients mobiles et bureau consomment la même API HTTPS.

**Ne pas déployer la branche actuelle directement** : APP_DEBUG=1, mot de passe
PostgreSQL en dur dans services.yaml, administration par jeton unique, tickets
de lecture expérimentaux, login et association sans limite de débit suffisante,
cloisonnement par compte absent, journalisation et gestion des secrets à auditer.

## Topologie cible

- `api.<domaine>` : Symfony HTTP en HTTPS, document root dans `public/`.
- `tv.<domaine>` : frontend responsive PWA (statique, éventuellement même origine).
- PostgreSQL o2switch avec compte SQL dédié à droits minimaux et sauvegardes.
- Tâches cron courtes pour synchronisation des sources, EPG et nettoyage des sessions.
- Applications clientes : Android TV (Media3), Android/iOS, PC (web/PWA).
- Flux : lecture directe uniquement via contrat fournisseur compatible avec protection
  des identifiants; sinon relais de streaming spécialisé et autorisé sur infrastructure
  séparée, pas via un processus PHP permanent sur le mutualisé.

## Vérifications préalables o2Panel

1. Confirmer avec le support les restrictions d'appels sortants HTTP(S), ports
   de fournisseurs, synchronisations longues, vidéos relayées, quotas de cron,
   versions/extensions PHP et politiques de bande passante.
2. Choisir PHP 8.4 (extensions pdo_pgsql, sodium, xmlreader, simplexml, dom).
3. Vérifier le chemin binaire PHP CLI, Composer 2, Git, accès SSH, cron et
   PostgreSQL depuis l'espace d'hébergement.
4. Préparer les sous-domaines et certificats TLS (Let's Encrypt).
5. Créer le document root de Symfony pointant uniquement vers `public/` :
   ne jamais exposer `src/`, `config/`, `var/`, `vendor/` ni `.env`.

## Migration du prototype local

1. Maintenir Docker/WSL comme environnement de développement.
2. Externaliser le DSN PostgreSQL des valeurs codées en dur et créer la
   configuration de production par variables d'environnement non versionnées.
3. Ajouter migrations versionnées idempotentes/transactionnelles et index sur
   les grandes tables (plus de 100 000 éléments).
4. Remplacer le worker infini par commandes cron bornées en temps,
   reprise sur erreur et verrou empêchant les exécutions concurrentes.
5. Authentification : limitation de débit, blocage des abus de code TV,
   sessions courtes et renouvelables, authentification appareil avec rotation
   et révocation, autorisation par utilisateur/source. Protéger tous les endpoints.
6. Auditer la sérialisation des playlists HLS, les tickets et les redirections :
   pas d'identifiants ni de tickets dans les logs.
7. Mettre APP_ENV=prod et APP_DEBUG=0; caches Symfony, OPcache, déploiement
   atomique avec rollback et contrôles de santé.
8. Tester sur un environnement de préproduction isolé : import, EPG,
   login, appairage, catalogue, PWA, lecture HLS, Android TV.
9. Importer la base PostgreSQL en préservant les données, avec sauvegarde
   et contrôle des compteurs; conserver IPTV_SECRET_KEY secrète pour les champs
   chiffrés déjà stockés, ou les ré-encrypter avant bascule.
10. Basculer le DNS seulement après validation; garder Docker comme rollback.

## Ce qui reste hors périmètre du mutualisé

- Docker Compose et les conteneurs : resteront sur machine de développement.
- Proxy vidéo HLS permanent ou transcodage FFmpeg à l'échelle.
- Distribution publique des flux IPTV et contournement de limitations des fournisseurs.

## Compatibilité appareils

Toutes les applications utilisent des endpoints JSON stables, versionnés
(`/api/v1/...` à prévoir). La lecture s'appuie sur des sessions compatibles
avec les capacités locales. Android TV : Media3/ExoPlayer et télécommande ;
mobile : interface tactile ; PC : PWA; LG/Samsung : portages ultérieurs.
Une authentification appareil distincte des accès administrateur est requise.

## Vérifications avant mise en ligne

- Confirmer les conditions d'utilisation et droits du fournisseur IPTV, y
  compris multi-appareils, géographie et nombre de connexions.
- HTTPS partout et secrets jamais envoyés aux clients ni dans les journaux.
- Limiter le coût réseau; une bande passante annoncée « illimitée » ne garantit
  pas l'autorisation d'un relais vidéo intensif sur mutualisé.
- Sécurité et tests avant toute ouverture d'un compte à Internet.

Références :
https://faq.o2switch.fr/espace-client/offre/offre-unique-cloud/
https://faq.o2switch.fr/guides/php/heberger-application-symfony/
https://faq.o2switch.fr/cpanel/outils-avances/
