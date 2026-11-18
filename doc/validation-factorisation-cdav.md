# Qualification CDav 6.3.2 : factorisation des duplications

Qualification du 18 novembre 2026, à partir de 6.3.1 (`0e58be9`). L'audit a
été committé séparément (`e9ebc3c`) avant la factorisation (`9c1591a`).

## Résultat et périmètre

Les 25 correspondances exactes de production mesurées par jscpd 5.4.3 passent
à **zéro**, avec le même mode `weak`, les mêmes seuils de 10 lignes / 100 tokens
et les mêmes exclusions. Les trois nouveaux fichiers de production sont inclus
dans les 48 fichiers analysés. La référence de production est vide et le seuil
CI est zéro ; ajouter un clone à la référence ne permet pas de contourner ce seuil.

La factorisation partage le bootstrap global, les constantes ICS/DAV, les
mappings vCard, le chargement des objets natifs, les séquences de verrouillage,
les préfixes iCalendar, les valeurs des tâches commerciales et les réponses
multiget. Les API Dolibarr/Sabre, les droits, les différences contact/tiers/adhérent,
les UID, les liaisons et les protections métier restent en place. Aucun schéma
ni dépendance d'exécution supplémentaire n'est nécessaire depuis 6.3.1.

Les deux correspondances des fixtures de tests sont suivies dans un rapport
séparé. Le résultat zéro de production reste limité au seuil et au mode précisés,
sans prétendre éliminer toute ressemblance sémantique.

## Matrice native et régressions

| Cœur Dolibarr | PHP | Suites PHP du paquet | Suites HTTP natives | Résultat |
|---|---|---:|---:|---|
| 23.0.4 | 8.4.26 | 23 | 3 | Réussi |
| 23.0.4 | 8.5.11 | 23 | 3 | Réussi |
| 24.0.0 | 8.4.26 | 23 | 3 | Réussi |
| 24.0.0 | 8.5.11 | 23 | 3 | Réussi |

Les cœurs sont épinglés aux commits `cb82037066c1c71f7c867482e9e92975222dfc14`
et `769c7db907099643558e77d7002c109cfda919e5`, comme dans le workflow de CI.
Les quatre environnements MariaDB utilisent des copies et bases jetables.
Les emails sortants sont désactivés avant de démarrer leurs serveurs HTTP.

Le paquet extrait passe 92 exécutions de suites PHP, les quatre exécutions
JavaScript et 488 rendus de formulaire. Quatre nouvelles suites couvrent le
bootstrap, les vrais plugins Sabre multiget, les calendriers/tâches et les
différences des trois objets CardDAV. Les suites existantes de protection,
invitations, disponibilité et réservation restent actives.

Les 12 suites HTTP du paquet vérifient les API natives et les scénarios
BL01–BL27 et INV01–INV27, les refus d'accès, les UID, les rejouements, les
verrous, les rollbacks, les notifications après commit et les réservations.
BL18 contrôle désormais aussi une véritable résiliation d'adhérent par DELETE
CardDAV : ligne conservée, type et société inchangés, GET suivant en 404.
La course INV27 conserve un seul événement et une réponse unique à l'organisateur
lorsque deux acceptations s'exécutent simultanément.

36 contrôles HTTP supplémentaires vérifient la connexion native, la page de
configuration, les URL CardDAV/CalDAV/ICS, les deux abonnements ICS signés sans
session, les jetons invalides et les inclusions protégées. Les 33 scripts PHP
de test refusent GET et POST avec un corps vide et un statut 404 sur les quatre
instances, soit 264 contrôles. La désactivation/réactivation native réussit
également dans les quatre environnements.

## Comparaisons avant/après et revue indépendante

- CardDAV : 576 cas par environnement, soit 2 304 comparaisons sans différence.
  Les exports vCard3 et conversions natives vCard4 sont identiques octet par
  octet ; les projections des parseurs des trois types conservent leurs valeurs.
- iCalendar : 192 exports identiques octet par octet avant/après, avec les
  représentations Agenda, tâche VEVENT/VTODO et intervention.
- Bootstrap : 56 comparaisons ancien/nouveau et 20 comparaisons de constantes,
  avec les chemins natifs/custom/externes, les flags et les échecs de chargement.
- Revue indépendante des mappeurs : par combinaison, 294 cas Task, 4 096
  combinaisons commerciales, 144 en-têtes et 72 adresses restent équivalents.
  L'ordre transaction, verrou utilisateur, verrou événement, résolution puis
  contrôle ETag est conservé.
- Contrôle de duplication : succès sans clone, refus après copie d'un vrai
  fichier, refus après ajout du clone à une référence temporaire, puis succès
  après retrait. Les sondes utilisent une copie isolée, y compris un chemin
  contenant des espaces, et sont nettoyées.

Les trois revues croisées sont liées aux empreintes finales des sources.
Aucune anomalie bloquante ne reste ouverte.

## Qualité native et limites de qualification

PHPCS utilise les règles natives des deux cœurs. Les nouveaux helpers, plugins
et tests contrôlés intégralement ne produisent aucun diagnostic. Les neuf
fichiers historiques comparés à leurs blobs exacts de 6.3.1 n'introduisent aucun
diagnostic sur les lignes ajoutées/remplacées et aucune hausse par règle.

| Fichier historique | Dolibarr 23 : avant → après | Dolibarr 24 : avant → après |
|---|---:|---:|
| `class/CardDAVDolibarr.php` | 835 → 305 | 839 → 306 |
| `class/CalDAVDolibarr.php` | 479 → 432 | 481 → 434 |
| `lib/cdav.lib.php` | 191 → 177 | 191 → 177 |
| `ics.php` | 40 → 20 | 40 → 20 |
| `server.php` | 57 → 36 | 57 → 36 |

Une dette de style historique reste donc identifiée ; le résultat n'est pas
présenté comme une conformité intégrale des anciens fichiers. Les diagnostics
du module restent fatals dans les unités. Les journaux HTTP ne contiennent
aucun avertissement, notice, dépréciation ou erreur du module. Une requête sans
query string produit encore un avertissement natif `main.inc.php` par instance,
identifié séparément dans les preuves.

Les cinq fichiers d'interface de réservation sont identiques à 6.3.1. Les
parcours navigateur ne sont pas réexécutés pour cette factorisation ; les pages
dont le bootstrap change sont contrôlées par HTTP réel. Les applications Outlook,
Microsoft 365 et Agenda/DAVx⁵ ne sont pas pilotées. Le transport SMTP n'est pas
requalifié ici ; les tests de contenu et de frontière transactionnelle des
notifications restent exécutés.

## Paquet, preuves et contrôle continu

Le paquet final est `dist/module_cdav-6.3.2.zip`. Le manifeste local
`dist/qa/booking-release-manifest.json` contient son SHA-256 et les commits.
L'archive est reproductible, ses chemins/CRC sont vérifiés et ses fichiers
correspondent aux blobs Git. Tous les fichiers autres que Markdown sont
identiques au candidat extrait et qualifié ; seules les documentations finales
sont ajoutées après la qualification.

Les preuves locales sont dans `dist/qa/refactor-validation/`, notamment
`native-summary.json`, `archive-native-matrix.json`, `native-bootstrap-http.json`,
`carddav-before-after.json`, les revues indépendantes et le comparatif PHPCS.
Les rapports de duplication sont dans `dist/qa/duplication/production/` et
`tests/`. Le nettoyage vérifie les données métier/techniques et les orphelins,
puis retire uniquement les quatre bases, serveurs et arbres privés de ce test.
Les cœurs partagés, le service existant sur 8023 et le cache navigateur sont
préservés.

La CI exécute désormais cinq jobs par branche : la matrice Dolibarr/PHP et un
contrôle de duplication dédié avec rapports HTML/JSON conservés 14 jours.
Le [guide d'audit](audit-duplication-code.md) donne les commandes reproductibles.
