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
