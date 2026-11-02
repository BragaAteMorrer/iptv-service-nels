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


## Prototype expérimental ajouté

- `POST /api/v1/vod/items/{id}/session` exige un compte connecté ou un appareil associé. Retourne un ticket de lecture chiffré de 10 minutes sans URL ni mot de passe Xtream exposés.
- `GET /api/v1/vod/range?ticket=...` supporte les demandes `Range` d'un MP4, par fenêtres de 2 MiB, exige un retour upstream **206** et valide les en-têtes `Content-Range`. Si le fournisseur refuse les ranges, le lecteur échoue sans télécharger tout le fichier.
- `series.html` peut lancer un épisode et enregistrer la progression avec `PUT /api/v1/me/library/{id}/progress`.

**Limites :** seul MP4 est tenté. Xtream peut fournir MKV, TS, codecs ou extensions différents. Le fournisseur peut refuser le Range ou renvoyer une redirection CDN (désactivée dans ce prototype). Les tickets expirent en 10 minutes ; une longue lecture nécessitera un renouvellement de session. Les sessions ne sont pas encore limitées par utilisateur au niveau du ticket. Le lecteur n'a pas été exécuté ni validé sur le vrai fournisseur.

**Ne pas publier ce relais PHP** sur o2switch Cloud, ni ouvrir le port Docker sur Internet : bande passante et ressources serveur incompatibles avec un relais continu. La production demandera un transport vidéo autorisé et spécialisé, ou un contrat de lecture directe compatible avec la confidentialité des identifiants.

### Essai local

```bash
git fetch origin
git reset --hard origin/feat/web-source-imports
docker compose up -d --build --force-recreate
docker compose exec api php -l src/Controller/VodPlaybackController.php
```

Ouvre `http://localhost:8080/series.html`, connecte-toi, choisis un épisode déjà synchronisé puis « Lire ». Si le fournisseur ne propose pas un MP4 range-compatible, l'échec est attendu : ne pas modifier le contrôle du Range ou exposer l'URL fournisseur pour contourner cette protection.
