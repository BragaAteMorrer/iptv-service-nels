# VOD indexée : étape 2 (structure, pas encore ingestion Xtream)

## Installation locale (après migrations 001 à 006)

```bash
git fetch origin
git reset --hard origin/feat/web-source-imports
docker compose up -d --build --force-recreate
docker compose exec -T database psql -v ON_ERROR_STOP=1 -U iptv -d iptv < migrations/007_vod_series.sql
```

La migration 007 crée :
- `vod_series` : clé stable `(source_id, provider_series_id)`, titre, jaquette, synopsis et genre.
- `vod_episodes` : correspondance avec `channels.id`, saison, numéro et titre.

API accessibles avec `Authorization: Bearer <session ou jeton TV>` :
- `GET /api/v1/vod/indexed-series?limit=30&page=1&q=...`
- `GET /api/v1/vod/indexed-series/{id}`

La fiche renvoie les épisodes actifs classés saison/numéro, et les marqueurs de reprise individuels; limite à 1000 épisodes par série, signalée par `truncated`.

## État exact et suite

**Les nouvelles tables sont initialement vides.** Les imports M3U et Xtream existants ne sont pas modifiés. Il faut encore identifier le traitement actuel de `get_series` et `get_series_info` dans l'importeur, puis ajouter une ingestion incrémentale transactionnelle en respectant ses garde-fous SSRF et la gestion chiffrée des identifiants. Ne jamais contacter des URLs de fournisseur sans le validateur d'hôte déjà utilisé par le projet.

Les métadonnées sont liées à des sources : supprimer une source supprime les fiches. L'accès aux sources n'est pas encore cloisonné par propriétaire de compte. Ne pas exposer ces endpoints sur Internet / o2switch avant renforcement de l'authentification, du rate limiting, de l'isolation des comptes et de la gestion des secrets.

La lecture VOD n'est pas encore connectée et aucun APK n'a été construit. Vérifier `php -l` et les tests après mise à jour du dépôt.

## Synchronisation Xtream réelle — ajout

Un nouveau synchroniseur **manuel, borné et indépendant** de l'import TV a été ajouté :

```bash
docker compose exec api php bin/console iptv:vod:sync 1 --offset=0 --limit=10
docker compose exec api php bin/console iptv:vod:sync 1 --offset=10 --limit=10
```

Remplace `1` par l'identifiant de ta source Xtream (visible depuis l'admin). Chaque lancement consulte `get_series`, puis `get_series_info` pour au maximum 25 séries sélectionnées; il importe les vrais identifiants d'épisodes, numéros de saison, titres, affiches et métadonnées fournies. Les erreurs d'une série sont comptabilisées sans afficher les URL et mots de passe du fournisseur. Le paramètre `--offset` permet d'avancer par lots.

La commande est délibérément **non automatique** : ne pas la lancer en cron tant que les limites du fournisseur, le coût de `get_series` répété, la pagination et le verrouillage anti-exécutions simultanées ne sont pas gérés. Les vieux objets `episode` issus de `get_series` restent présents : ils doivent être reclassés/désactivés après vérification. La lecture VOD n'est pas encore développée.

Le client HTTP de cette commande ne suit pas les redirections et utilise le contrôle DNS/allowlist préexistant. Avant de synchroniser, vérifier `IPTV_TRUSTED_HOSTS`, la compatibilité de l'API fournisseur et les droits d'accès.

**Attention :** cette nouvelle commande et ces fichiers n'ont pas été exécutés dans l'environnement Docker de l'utilisateur. Commencer par 1 ou 2 séries en test et vérifier le nombre d'épisodes et la cohérence des fiches avant d'augmenter.

## Correction du catalogue Xtream

`iptv:sync:source` classe maintenant `get_series` dans `vod_series` et non plus comme épisodes fictifs dans `channels`. Le synchroniseur normal de chaînes nécessite donc désormais la migration **007**. Après un `iptv:sync:source` réussi, les anciens enregistrements de séries mal classés comme épisodes sont désactivés uniquement s'ils ne sont pas reliés à `vod_episodes`. Les données et préférences ne sont pas supprimées. L'import de vrais épisodes reste une commande indépendante.

### Ordre de validation conseillé

```bash
docker compose exec -T database psql -v ON_ERROR_STOP=1 -U iptv -d iptv < migrations/007_vod_series.sql
docker compose exec api php -l src/Command/SyncSourceCommand.php
docker compose exec api php -l src/Command/SyncVodCommand.php
docker compose exec api php bin/console iptv:sync:source 1
docker compose exec api php bin/console iptv:vod:sync 1 --offset=0 --limit=2
docker compose exec database psql -U iptv -d iptv -c "SELECT count(*) FROM vod_series;"
docker compose exec database psql -U iptv -d iptv -c "SELECT count(*) FROM vod_episodes;"
```

Adapter l'identifiant `1` à la source du compte. La migration peut être rejouée. **Aucune migration des 100 000 lignes existantes n'est requise** : les métadonnées sont reconstructibles par les identifiants fournisseur. Une sauvegarde est néanmoins conseillée avant la première synchronisation. Le code n'a pas été exécuté contre le fournisseur réel.
