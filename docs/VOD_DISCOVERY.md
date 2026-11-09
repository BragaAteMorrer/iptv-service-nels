# Nels TV — VOD, tranche 1

Nouveaux endpoints authentifiés avec jeton de session utilisateur ou jeton d'appareil associé :

- `GET /api/v1/vod/items/{id}` : fiche VOD, titre, type, catégorie, illustration, favori, durée et position enregistrée; aucune URL fournisseur transmise.
- `GET /api/v1/vod/series?page=1&limit=30&q=...` : découverte prudente des séries à partir d'un marqueur explicite S01E02 dans le titre. Renvoie le titre, les saisons détectées et le nombre d'épisodes détectés.

Cette tranche **n'ajoute pas encore la lecture VOD** et ne déduit ni synopsis, ni casting, ni années, ni numéros d'épisode lorsque ces informations ne sont pas fournies. La découverte des séries est provisoire : maximum 5000 lignes d'épisodes inspectées par requête, ordre alphabétique, le champ `partial` indique si les résultats risquent d'être incomplets.

Prochaine amélioration de fond : persister les métadonnées `series_id`, `season`, `episode` transmises par Xtream dans une structure `vod_series` dédiée, alimentée par un import incrémental et indexée côté base. Éviter de scanner à chaque requête les catalogues de 100 000 éléments.

Le service continue d'utiliser les comptes et la bibliothèque des migrations 005 et 006. Aucun déploiement sur o2switch n'a été effectué. La limitation de débit, les autorisations par propriété de source et les tests restent obligatoires avant publication.
