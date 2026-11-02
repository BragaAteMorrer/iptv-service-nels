# Nels TV — Bibliothèque personnelle (première tranche)

La bibliothèque importée (`channels`) reste reconstructible via une synchronisation M3U/Xtream. Les préférences sont conservées dans des tables séparées et liées aux identifiants `source_id + external_id`, pour survivre aux mises à jour de la **même source** sans dépendre des IDs de lignes `channels.id`.

## Installation en local

```bash
git fetch origin
git reset --hard origin/feat/web-source-imports
docker compose up -d --build --force-recreate
docker compose exec -T database psql -v ON_ERROR_STOP=1 -U iptv -d iptv < migrations/006_user_library.sql
```

La migration `005_app_identity.sql` est un préalable obligatoire. Aucun secret n'est à publier.

## API nouvelle

Authentification `Authorization: Bearer <user-session-or-paired-device-token>` :
- `GET /api/v1/me/library?kind=live&page=1&limit=50` : catalogue paginé enrichi des préférences.
- `PATCH /api/v1/me/library/{id}/preferences` : `{"favorite":true}`, `{"hidden":false}`, ou `{"channel_number":12}` pour la télévision.
- `PUT /api/v1/me/library/{id}/progress` : `{"position_seconds":120,"duration_seconds":3600}` pour films et épisodes.

Les champs `stream_url` et les identifiants fournisseur ne sont jamais renvoyés par ces endpoints.

## Limites à corriger avant production

- Les préférences survivent à une resynchronisation qui conserve le même `source_id + external_id`, **pas** à la suppression/recréation d'une source : les contraintes FK effacent les préférences en cas de suppression.
- Si le fournisseur change les IDs, la progression ne peut pas être automatiquement retrouvée.
- La bibliothèque actuellement partagée entre utilisateurs n'est pas isolée par propriétaire de source : ne pas ouvrir l'application à des utilisateurs tiers.
- Pas encore de filtres `favorite`, `group`, recherche, pagination Android TV complète, ou interface de gestion de progression.
- Authentification, rate limiting, confidentialité et HTTPS doivent être durcis avant mise en ligne sur o2switch Cloud.
- Les API v1 sont un contrat initial, non définitif, et n'ont pas encore été testées en environnement de production.
