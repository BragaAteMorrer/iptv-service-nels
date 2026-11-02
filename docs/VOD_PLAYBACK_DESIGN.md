# Nels TV — lecture VOD, état et prérequis techniques

La VOD se prépare en deux blocs indépendants :

1. **Métadonnées** : `vod_series`, `vod_episodes`, position de reprise en `user_watch_progress`, interface `series.html`.
2. **Transport vidéo** : pas encore raccordé. La lecture en direct utilise un relais HLS de développement qui n'est pas un relais VOD MP4.

## Pourquoi les films ne démarrent pas encore

L'endpoint actuel `/api/playback/relay` est adapté aux playlists et à leurs petits segments, mais un MP4 complet requiert **HTTP Range** avec :
- l'en-tête `Range: bytes=...` en amont,
- les statuts `206 Partial Content` et `416 Range Not Satisfiable`,
- `Content-Range`, `Accept-Ranges`, `Content-Length`,
- la propagation rigoureuse des erreurs upstream, et l'annulation du streaming à la déconnexion.
- Le contrôle SSRF et des redirections à chaque requête, y compris les URL de CDN.
- Des tickets courts qui ne divulguent jamais les identifiants Xtream, avec contrôle du compte/appareil.

Le relais actuel limite les réponses à 32 MiB et ne fournit pas ces garanties; **ne pas le faire passer pour un lecteur MP4 fonctionnel**. Le serveur mutualisé o2switch Cloud ne doit pas devenir un proxy vidéo permanent. Pour une lecture distancielle, étudier un lecteur autorisé côté appareil ou un serveur de transport séparé avec contrôle d'accès et limites de débit.

## Expérience utilisateur préparée

- `/series.html` liste les séries/saisons/épisodes et la position de reprise sauvegardée.
- La lecture VOD et les boutons de reprise restent désactivés tant qu'aucun service vidéo VOD compatible n'est implémenté.
- Un lecteur valide devra envoyer la position par `PUT /api/v1/me/library/{id}/progress` après connexion, à fréquence bornée et lors de la fermeture.

## Priorités avant la production

- Validation sur deux séries avec le fournisseur.
- Isolation des sources par compte, limites de débit et sessions expirables.
- Ajout des extensions container de `get_vod_info` et `get_series_info`, si fournies.
- Tests d'intégration du transport vidéo et de la reprise.
- Ne jamais placer de jeton de session ni URL à identifiants dans les logs.
