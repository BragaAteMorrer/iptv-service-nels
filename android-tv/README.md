# Nels TV Android TV — prototype initial

Projet Android natif (Kotlin + Media3 ExoPlayer) pour Android TV et Google TV, dont Mi TV Stick compatibles.

Ouvre le dossier `android-tv` dans Android Studio et laisse Gradle synchroniser les dépendances. Lance l'application sur un émulateur Android TV ou un appareil compatible en mode développement.

- Pour les tests locaux, utilise l'adresse IP **LAN du PC hôte**, pas `localhost`. Le `docker-compose.yml` actuel publie uniquement `127.0.0.1:8080`, donc il faudra une passerelle LAN sécurisée avant de tester depuis un vrai stick.
- Saisis l'URL du serveur, puis valide le code affiché dans `devices.html`, depuis ton compte connecté. La TV demande sa session, charge la première page de 100 chaînes, et utilise ExoPlayer HLS avec CH+/CH- ou flèches.
- Le serveur conserve actuellement les URL de streaming dans des tickets à expiration. Les sessions longues nécessiteront un renouvellement.
- Ce prototype **ne persiste pas** le token appareil et ne fournit pas encore de reconnexion automatique, navigation complète ni catalogue VOD.
- Le paramètre `usesCleartextTraffic=true` est uniquement pour les essais sur un LAN HTTP privé. Avant toute diffusion Internet, passer sur HTTPS, retirer cette exception et durcir les autorisations.
- La sécurité de l'API doit être renforcée (limitation de débit, protection du pairing, comptes séparés, tests et reverse proxy) avant une utilisation hors LAN.
