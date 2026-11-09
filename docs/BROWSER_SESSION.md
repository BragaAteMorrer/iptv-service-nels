# NSatellite — session persistante sur navigateur PC / TV

## Problème corrigé

Les pages `home.html`, `tv.html`, `catalog.html`, `series.html`,
`lineup.html` et `devices.html` demandaient une connexion individuelle :
leurs jetons Bearer vivaient uniquement dans la mémoire de la page.
Changement de page, rechargement ou redémarrage du navigateur = déconnexion.

## Nouvelle session navigateur (7 jours)

- `POST /api/identity/browser/login` réutilise le login habituel avec sa
  limitation de débit et ses sessions DB (hash du jeton stocké côté serveur).
  Le jeton brut est envoyé **uniquement dans un cookie** ; il ne figure pas
  dans la réponse JSON et n'est pas stocké dans le JavaScript.
- Cookie `__Host-nsatellite_session` sur HTTPS, avec attributs
  `Secure; HttpOnly; SameSite=Lax; Path=/`, sans Domain, expiration 7 jours.
  Un cookie de développement séparé est possible en HTTP local.
- `GET /api/identity/browser/session` vérifie l'existence en DB, la validité
  et la révocation de la session ; renvoie uniquement `authenticated`.
- `POST /api/identity/browser/logout` révoque la session dans
  `app_sessions` et fait expirer le cookie. L'ancienne API Bearer,
  utilisée par les appareils clients, reste disponible.
- Un listener Symfony reconnaît le cookie et l'utilise **côté serveur**
  pour l'authentification des contrôleurs déjà existants : le code HTML
  ne lit pas la valeur du cookie.
- Pour chaque requête mutante authentifiée par cookie, le listener exige
  simultanément `Origin` identique à l'origine de la page et
  `X-NSatellite-Request: 1`. Les méthodes et routes restent filtrées
  séparément par la passerelle o2switch. Le JS central applique les
  `credentials: 'same-origin'` et l'en-tête de protection.
- Les pages navigateur utilisent `/assets/nsatellite-auth.js` pour
  leur connexion, leur déconnexion et la restauration de session.
  Pas de `localStorage` ni `sessionStorage` pour des jetons sensibles.
  Le stockage local de **préférences non sensibles** (thème, dernière chaîne)
  est conservé.

Le cookie ne permet jamais d'outrepasser `user_source_access`.
Les sessions terminent au maximum après 7 jours ou dès leur révocation.
Aucune fonction « rester connecté pour toujours ».

## Installation sur o2switch

Les modifications GitHub ne mettent **pas** automatiquement à jour le
serveur. La PR doit être fusionnée dans `main`, puis le déploiement manuel
sur o2switch doit installer la nouvelle passerelle PHP, les pages, et
`assets/nsatellite-auth.js` :

```bash
cd ~/nsatellite
git status --short
git branch --show-current
git fetch origin
git switch main
git pull --ff-only origin main

php -l src/Security/BrowserSessionListener.php
php -l src/Controller/BrowserIdentityController.php
php -l deploy/o2switch/public_html/index.php
php tests/browser_session_security_smoke.php
php tests/o2switch_viewer_route_gate_smoke.php

NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh
bash deploy/o2switch/check-browser.sh
```

**N'exécute pas le changement de branche si `git status --short`
n'est pas vide.** L'installateur réalise sa sauvegarde sous
`var/deploy-backups`, en dehors du DocumentRoot. Vérifier les scripts
via le navigateur (réseau, cache) si des fichiers restent anciens.

Les nouveaux endpoints de session exigent toujours
`NELSTV_ENABLE_IDENTITY_API=1`; le catalogue et l'EPG demandent aussi
`NELSTV_ENABLE_VIEWER_API=1`. Ne pas activer le relais PHP ni ajouter
un fournisseur protégé à l'allowlist HLS publique.

## Vérification manuelle Chrome / Edge / Firefox

1. Se connecter une seule fois sur `/home.html`.
2. Ouvrir `/tv.html`, `/catalog.html`, `/series.html` puis
   `/devices.html` : aucune nouvelle demande de mot de passe.
3. Recharger la page, puis fermer et rouvrir le navigateur :
   session restaurée tant que le cookie et la session DB sont valides.
4. Depuis DevTools > Réseau, vérifier que les réponses de connexion
   n'exposent pas de `access_token` et que le cookie est HttpOnly,
   Secure et SameSite=Lax. Ne jamais copier la valeur du cookie.
5. Modifier un favori / gérer un appareil pour vérifier que les
   requêtes mutantes authentifiées utilisent le header CSRF.
6. Se déconnecter, recharger : la session ne doit pas revenir.
   Rejouer un ancien appel après déconnexion doit recevoir HTTP 401.
7. Tester un appel POST/PATCH/DELETE simulant une origine étrangère :
   le contrôle CSRF doit le refuser (HTTP 403) avec un cookie authentifié.
8. Les appareils/API clients avec un Bearer explicite continuent de
   fonctionner sans les nouveaux cookies.
9. En navigation privée ou si les cookies sont bloqués, la connexion
   persistante peut ne pas fonctionner.

Aucune des étapes précédentes n'a été exécutée sur le navigateur live
depuis GitHub. Elles constituent les critères d'acceptation sur o2switch.
