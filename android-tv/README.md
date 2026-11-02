# Nels TV Android TV — prototype initial

Projet Android natif (Kotlin + Media3 ExoPlayer) pour Android TV et Google TV, dont Mi TV Stick compatibles.

Ouvre le dossier `android-tv` dans Android Studio et laisse Gradle synchroniser les dépendances. Lance l'application sur un émulateur Android TV ou un appareil compatible en mode développement.

- Pour les tests locaux, utilise l'adresse IP **LAN du PC hôte**, pas `localhost`. Le `docker-compose.yml` actuel publie uniquement `127.0.0.1:8080`, donc il faudra une passerelle LAN sécurisée avant de tester depuis un vrai stick.
- Saisis l'URL du serveur, puis valide le code affiché dans `devices.html`, depuis ton compte connecté. La TV demande sa session, charge la première page de 100 chaînes, et utilise ExoPlayer HLS avec CH+/CH- ou flèches.
- Le serveur conserve actuellement les URL de streaming dans des tickets à expiration. Les sessions longues nécessiteront un renouvellement.
- Ce prototype **ne persiste pas** le token appareil et ne fournit pas encore de reconnexion automatique, navigation complète ni catalogue VOD.
- Le paramètre `usesCleartextTraffic=true` est uniquement pour les essais sur un LAN HTTP privé. Avant toute diffusion Internet, passer sur HTTPS, retirer cette exception et durcir les autorisations.
- La sécurité de l'API doit être renforcée (limitation de débit, protection du pairing, comptes séparés, tests et reverse proxy) avant une utilisation hors LAN.

## Accès privé depuis un Mi TV Stick (Windows 11 + WSL2)

L'API reste volontairement liée à `127.0.0.1:8080` ; **ne modifie pas Docker Compose pour ouvrir le port sur toutes les interfaces**. Le prototype utilise encore le serveur de développement, des secrets de développement, et une association sans limitation de débit : n'utilise pas Tailscale Funnel ni une redirection de port publique.

1. Sur **Windows**, installe Tailscale, connecte ton compte et vérifie que `http://localhost:8080/api/health` fonctionne depuis ton navigateur Windows (Docker Desktop/WSL doit tourner).
2. Depuis un terminal **PowerShell Windows**, après connexion Tailscale, exécute `tailscale serve --bg http://127.0.0.1:8080`. Affiche l'URL et le statut avec `tailscale serve status`. Tailscale Serve fournit une URL HTTPS accessible **uniquement dans ton tailnet**, avec les ACL de celui-ci.
3. Sur Android TV / Mi TV Stick, installe **Tailscale**, associe-le au même tailnet (QR code ou code de connexion dans l'application).
4. Ouvre l'URL HTTPS `.ts.net` affichée par Tailscale Serve depuis le navigateur du stick pour vérifier l'accès. Dans Nels TV Android, saisis **l'URL de base HTTPS**, sans `/tv.html`.
5. Nels TV affiche un code à six chiffres. Depuis ton PC, ouvre `http://localhost:8080/devices.html`, connecte-toi avec ton compte et approuve le code. La TV récupère son jeton d'appareil et peut demander la liste des chaînes puis une session HLS.

**Attention** : Android TV peut être disponible sur Android 8+ côté Tailscale, mais le client Nels TV lui-même n'a pas encore d'APK validé. Cette procédure n'est pas une preuve de fonctionnement avec le Mi TV Stick. L'accès HTTPS Tailscale ne remplace pas le durcissement de l'API. Il doit être réservé aux appareils personnels autorisés et fermé si tu ne l'utilises pas (`tailscale serve --https=443 off`).

### Corrections dans cette version

- L'interrogation du code TV ne bloque plus le seul exécuteur réseau, ce qui pouvait empêcher ensuite le catalogue de charger.
- ExoPlayer reçoit le MIME type HLS explicitement, car les URL de relais n'ont pas l'extension `.m3u8`.
- Les réponses de zapping obsolètes ne remplacent plus une chaîne sélectionnée ultérieurement.
- Le mode HTTP de l'application est réservé à des adresses LAN de test ; utiliser HTTPS pour tout accès distant.

Documents officiels : https://tailscale.com/docs/reference/tailscale-cli/serve et https://tailscale.com/docs/install/android .
