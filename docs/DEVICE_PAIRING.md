# Nels TV — Phase 1: identité et association d'appareils

Prototype **local uniquement**. Ne pas exposer ces routes à Internet pour l'instant.

## Installation

Après avoir récupéré la branche `feat/web-source-imports` et reconstruit Docker, applique la migration :

```sh
docker compose exec -T database psql -v ON_ERROR_STOP=1 -U iptv -d iptv < migrations/005_app_identity.sql
```

Ouvre `http://localhost:8080/devices.html`.

1. Crée le premier compte avec le jeton administrateur existant, ton email et un mot de passe d'au moins 12 caractères.
2. Connecte-toi : l'API retourne un jeton bearer à durée de vie de sept jours, stocké uniquement en mémoire dans cette page.
3. Simule un démarrage TV : le serveur crée un code aléatoire à six chiffres, valable cinq minutes, et un identifiant secret de demande d'association.
4. Depuis le compte connecté, approuve le code TV.
5. La TV interroge le statut avec son identifiant secret et récupère **une seule fois** un jeton appareil. Les jetons et codes sont hachés en base.
6. Révoque un appareil depuis la liste.

## API

- `POST /api/identity/bootstrap` : première création, en-tête `X-Admin-Token`
- `POST /api/identity/login` : email et mot de passe
- `POST /api/identity/logout` : révocation du bearer
- `POST /api/devices/pair/start` : nom de l'appareil
- `POST /api/devices/pair/approve` : bearer utilisateur et code
- `POST /api/devices/pair/status` : polling TV avec `pairing_id`
- `GET /api/devices` / `DELETE /api/devices/{id}` : bearer utilisateur

## Limites avant accès Internet

Le projet utilise encore `APP_DEBUG=1`, la base locale avec mot de passe de développement, et le serveur HTTP PHP intégré. Il manque impérativement : TLS/HTTPS de bout en bout, reverse proxy, limitation des tentatives de connexion et de codes, verrouillage après trop d'erreurs, cookies ou stockage natif sécurisé des jetons, rotation et expiration des jetons appareil, authentification par appareil sur les routes catalogue/lecture, isolation multi-utilisateurs des sources, audit, migrations automatisées, et tests d'intégration. **Le jeton appareil ne donne encore aucun accès aux chaînes** : il matérialise seulement l'association.

Ne pas ouvrir les ports Docker sur Internet ni publier de jetons ou URL d'abonnement.
