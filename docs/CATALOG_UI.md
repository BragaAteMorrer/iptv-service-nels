# Interface Catalogue Nels TV (phase UI 1)

Le fichier `public/catalog.html` contient maintenant une interface responsive pour TV en direct, films et épisodes.

## Essayer en local

1. Appliquer les migrations `005_app_identity.sql` et `006_user_library.sql`.
2. Sur `/devices.html`, initialiser le compte personnel puis se connecter.
3. Ouvrir `/catalog.html` et se connecter avec le même compte.
4. Choisir TV / Films / Épisodes, parcourir les catégories et ajouter des favoris.
5. La liste personnelle passe par `GET /api/v1/me/library`, les favoris par `PATCH /api/v1/me/library/{id}/preferences`.
6. Le bouton « Voir la TV » ouvre le décodeur actuel. Celui-ci demande encore une connexion distincte.

## Périmètre réel

- Layout adapté aux petites tailles d'écran et affiches/logo HTTPS quand fournis.
- Les filtres Favoris, recherche texte et catégorie sont désormais appliqués **à l'ensemble du catalogue côté PostgreSQL**, avec un total et une pagination cohérents. La recherche ILIKE peut nécessiter un index trigramme selon la volumétrie et les quotas PostgreSQL o2switch.
- L'application ne présente pas encore les saisons/épisodes regroupés, les fiches films, ni la lecture VOD.
- Les jetons de connexion restent en mémoire du navigateur et ne sont pas enregistrés en stockage persistant. La navigation vers le décodeur demande une nouvelle connexion.
- L'API du catalogue personnel nécessite `006_user_library.sql`. L'accès historique via jeton administrateur reste fonctionnel.
- Les contenus multimédias sont ceux du fournisseur déclaré ; seuls des flux/abonnements autorisés doivent être utilisés.
- Les comptes, l'association des appareils et la bibliothèque doivent être sécurisés et isolés par utilisateur avant exposition publique chez o2switch.
