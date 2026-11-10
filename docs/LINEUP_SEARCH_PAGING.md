# NSatellite — grand bouquet sur navigateur PC et télévision

La liste des chaînes n'est plus limitée aux dix premières pages (1 000
entrées). La page `/tv.html` lit une première page de 100 chaînes, puis
charge les suivantes à la demande. L'API `GET /api/lineup` reste unique
et protégée par la même authentification et les autorisations de sources.

## API (appareil ou navigateur connecté uniquement)

| Paramètre | Usage |
|---|---|
| `page`, `limit` | Pages (taille de 1 à 100), avec `total` et `items` |
| `q` | Recherche insensible à la casse dans les noms/catégories et numéro exact s'il est numérique |
| `group` | Catégorie exacte |
| `channel_number` | Numéro personnel exact (1–99999) |
| `channel_id` | Identifiant de chaîne exact, notamment pour les liens depuis l'accueil |
| `favorites=1` | Favoris du compte courant |
| `include_hidden=1` | Inclure les chaînes masquées, selon les droits habituels |

La recherche reste bornée à 100 caractères, le filtre catégorie à 200.
Les paramètres `channel_number` et `channel_id` sont strictement validés.
Les filtres d'accès par compte, source et canal actif sont appliqués
**avant** pagination et comptage. Le front ne reçoit jamais les URL de
streaming depuis l'API catalogue.

## Comportement navigateur

- Les 100 premières chaînes s'affichent rapidement. Le bouton « Charger
  100 chaînes supplémentaires » permet d'élargir le cache à la demande,
  sans télécharger tout un bouquet de 100 000 entrées.
- La recherche de la page, la recherche de la barre latérale et le filtre
  favoris consultent l'API serveur, y compris pour les chaînes non chargées.
  « 100 résultats supplémentaires » parcourt ensuite les pages suivantes.
- Saisie d'un numéro de chaîne : recherche serveur si l'entrée n'est pas
  en mémoire ; les numéros non définis, masqués et non autorisés ne sont
  pas jouables.
- Un lien `/tv.html?channel=ID` peut retrouver une chaîne accessible
  même si son ID est en dehors des premières pages.
- Le menu décodeur plein écran conserve le cache local et propose
  « Charger plus de chaînes » ; les catégories s'enrichissent au fur
  et à mesure du chargement.
- Les chaînes découvertes par recherche sont mémorisées pour faciliter
  le zapping, mais le cache local n'est **pas** une copie complète ni un
  classement exhaustif du bouquet. Les touches CH+/CH− s'étendent
  progressivement quand le lecteur atteint la fin des pages en mémoire.

## Tests

```bash
php tests/lineup_server_search_smoke.php
node --test tests/tv_large_catalog_contract.test.mjs
node tests/check_static_pages.mjs
```

Le test PHP utilise SQLite en mémoire avec 1 205 chaînes, vérifie les
pages au-delà de 1 000, le filtrage de sources, les numéros, les favoris,
les liens profonds et les erreurs de validation. GitHub Actions exécute
les tests sur PR et `main`.

La recherche SQL `LOCATE` ou `POSITION` sur noms/catégories peut être
coûteuse sur des imports très volumineux ; les benchmarks MariaDB réels,
les index et la pagination par curseur resteront à évaluer après mesures.
La nouvelle API ne corrige **pas** les limitations de lecture HLS/TS
des fournisseurs.

## Publication o2switch

Une fois la PR fusionnée et les tests verts, actualiser la copie Git du
serveur et republier les pages avec l'installateur explicite. L'API utilise
la route déjà permise `/api/lineup`, aucun nouvel endpoint public.
Aucune migration SQL, aucun paramètre provider et aucun contournement CORS.

Les modifications GitHub ne sont pas automatiquement déployées sur
`nsatellite.bragaatemorrer.fr`.
