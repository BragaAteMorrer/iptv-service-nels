# Nels TV — nsatellite.bragaatemorrer.fr (o2switch Cloud)

## Adresse retenue

- Web : https://nsatellite.bragaatemorrer.fr/
- API : https://nsatellite.bragaatemorrer.fr/api/
- Symfony : code source hors document root, domaine pointé vers le répertoire `public/`.

**Ce fichier ne signifie pas que le site a été déployé.** Il décrit la configuration à effectuer sur o2Panel une fois les prérequis validés.

## Étapes dans o2Panel

1. Vérifier que le sous-domaine `nsatellite.bragaatemorrer.fr` répond en HTTPS avec un certificat valide.
2. Vérifier la version PHP CLI et web, extensions `pdo_pgsql`, `sodium`, `xmlreader`, `simplexml`; PHP 8.4 convient si disponible.
3. Vérifier **la version PostgreSQL** avec `SELECT version();`. Le schéma Nels TV utilise `ON CONFLICT`, pris en charge depuis PostgreSQL 9.5. Certaines installations o2switch anciennes proposent PostgreSQL 9.2 : **ne pas lancer les migrations sur une base 9.2**. Une base PostgreSQL moderne externe ou une adaptation de schéma serait nécessaire.
4. Définir la racine du domaine sur `/home/<compte>/nels-tv/public` plutôt que `/home/<compte>/nels-tv`.
5. Cloner le dépôt en dehors du dossier web, sur une version révisée et testée. Ne pas déployer telle quelle la branche actuelle : sécurité et transport vidéo en chantier.
6. Placer les variables d'environnement hors Git (fichier non public), `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET`, `DATABASE_URL`, `IPTV_ADMIN_TOKEN`, `IPTV_SECRET_KEY`, `IPTV_TRUSTED_HOSTS`.
7. Installer `composer install --no-dev --optimize-autoloader`, vider/réchauffer le cache, tester `/api/health`, puis migrer sur une base de préproduction compatible.
8. Remplacer le worker Docker permanent par des commandes CLI lancées via cron, bornées et protégées contre les doubles exécutions.
9. Tester les imports M3U/Xtream, le catalogue, les comptes et l'association de TV avant l'ouverture générale.

## Configuration DBL

Le fichier `config/services.yaml` lit désormais `DATABASE_URL` au lieu de figer le serveur `database` et le mot de passe `iptv` propre à Docker. L'environnement local reste défini par `docker-compose.yml` et la variable existante.

Exemple de syntaxe DSN, **pas d'identifiants réels** :

```dotenv
APP_ENV=prod
APP_DEBUG=0
DATABASE_URL="postgresql://UTILISATEUR:MOT_DE_PASSE@127.0.0.1:5432/NOM_BASE?charset=utf8"
```

Les secrets Xtream/M3U ne doivent jamais être envoyés au navigateur ni enregistrés dans les journaux.

## Limites bloquantes avant publication

- Risque de brute force sur login et association TV, absence de limites de débit.
- Séparation des sources entre comptes non implémentée.
- Relais HLS et MP4 expérimentaux qui ne conviennent pas à un mutualisé; les laisser indisponibles en production tant qu'une stratégie de lecture autorisée et sûre n'est pas validée.
- Contrôles de charge des imports, de sauvegardes et de compatibilité PostgreSQL à confirmer.
- Le sous-domaine n'a pas été vérifié comme actuellement fonctionnel par l'agent.

Guide o2switch : https://faq.o2switch.fr/guides/php/heberger-application-symfony/
