# Qualification CDav 6.3.3 : lectures CalDAV et sérialisation

Qualification du 18 novembre 2026, sur la base 6.3.2 (`5bbbe75`). Le correctif
applicatif est committé dans `e9ef77c`.

## Problème reproduit et correction

Un arrêt PHP après 60 secondes dans `Sabre\VObject\Component::serialize()`
confirme un dépassement de temps côté serveur. L'emplacement de l'arrêt ne
suffit pas à attribuer toute la durée à Sabre : la lecture CDav recalculait les
mêmes représentations pour le listing, le validateur et la réponse REPORT.
Les enrichisseurs conservaient aussi leurs arbres temporaires jusqu'au passage
du ramasse-miettes PHP.

Le correctif réutilise les représentations déjà calculées dans un cache attaché
à l'instance de backend de la requête. Seuls GET, HEAD, PROPFIND et REPORT
l'activent. Il conserve des tableaux et octets sérialisés, sans arbre VObject,
avec une charge comptabilisée maximale de 8 Mio et 2 048 entrées, listes incluses.
Les notes natives et autres métadonnées internes contribuent au budget.
La mémoire totale du processus PHP n'est pas limitée à ces 8 Mio.

Les contextes séparent acteur, entité, langue, fuseau et calendrier. Les droits
Agenda sont vérifiés avant chaque accès au cache. Une écriture, un contrôle de
version, une cible de pièce jointe ou un verrou propriétaire vide et désactive
définitivement ce cache avant les lectures métier sensibles.

Les filtres restent évalués par le validateur natif Sabre, y compris pour les
récurrences et exceptions. Les UID et les ETags calculés sur les octets exacts
restent inchangés. Le ctag de collection est désormais dérivé du même ensemble
URI/ETag que le journal de synchronisation ; il peut changer une première fois
après installation, sans changement des événements.

En cas de saturation, les lectures natives restent disponibles. Une absence
dans le listing ne devient pas un GET 404 artificiel. Les alias ayant la forme
d'une URI interne doivent correspondre au même identifiant et à la même source
native, sinon l'optimisation est désactivée. Une lecture SQL défaillante provoque
une erreur explicite ; aucune liste partielle n'est publiée comme photographie
complète du calendrier.

Les enrichisseurs et la normalisation finale libèrent leurs arbres temporaires
avec `destroy()` dans des `finally`. Les bibliothèques natives Dolibarr/Sabre et
la limite de temps PHP de production ne sont pas modifiées.

## Mesures sur données fictives

Le test `tests/calendar_read_cost.php` exécute de vrais REPORT XML par Sabre,
le backend CDav et l'exporteur de production. La frontière SQL seule utilise
des curseurs en mémoire. Les seuils portent sur le nombre d'opérations, jamais
sur la durée d'un runner.

| Objets fictifs | Exports avant → après | Relectures SQL unitaires avant → après | Requêtes SQL comptées avant → après |
|---|---:|---:|---:|
| 100 | 299 → 100 | 199 → 0 | 1 098 → 302 |
| 1 000 | 2 999 → 1 000 | 1 999 → 0 | 10 998 → 3 002 |

Le test échoue sur 6.3.2 et réussit avec le correctif. Les réponses complètes
avec/sans cache sont comparées, ainsi que les UID, ETags, filtres, exceptions de
récurrence et métadonnées natives. Les droits, les limites, les erreurs SQL,
les changements de contexte et l'invalidation avant écriture sont couverts.
Un compteur placé avant le gestionnaire strict détecte même les diagnostics
PHP dont l'exception serait ensuite interceptée par un enrichisseur.

Un corpus supplémentaire de 24 cas conserve les octets des GET, listings,
ETags et exports anonymes de réservation. Sur un cas comportant six pièces
jointes inline fictives de 1 Mio, le pic mémoire mesuré passe de 84 à 26 Mio
sur les quatre combinaisons. Ces mesures locales ne prédisent pas la latence
du serveur de production ou de sa base.

## Matrice native du paquet extrait

| Dolibarr | PHP | Suites PHP | Suites HTTP | Événements natifs du test de lecture | REPORT mesuré |
|---|---|---:|---:|---:|---:|
| 23.0.4 | 8.4.26 | 24 | 3 | 200 | 0,866 s |
| 23.0.4 | 8.5.11 | 24 | 3 | 200 | 0,862 s |
| 24.0.0 | 8.4.26 | 24 | 3 | 200 | 0,870 s |
| 24.0.0 | 8.5.11 | 24 | 3 | 200 | 0,865 s |

Les cœurs sont épinglés aux commits `cb82037066c1c71f7c867482e9e92975222dfc14`
et `769c7db907099643558e77d7002c109cfda919e5`. Les serveurs privés de test gardent
`max_execution_time=60`. Les 800 rendez-vous de lecture sont créés par l'API
native ActionComm, puis retirés par le nettoyage natif des fixtures. Chaque
REPORT restitue les 200 objets attendus, sans doublon, avec le même corps et
ETag qu'un GET séparé, et sans modifier les rendez-vous.

Les 96 exécutions de suites PHP, quatre exécutions JavaScript, 488 rendus de
formulaire et 12 suites HTTP passent. Les tests BL01–BL27 et INV01–INV27
conservent les protections métier, les verrous, les reçus après commit, les
courses concurrentes, les pièces jointes et les réponses d'invitation.
Les réservations restent testées contre l'Agenda natif. Les 36 contrôles de
bootstrap et les 280 refus HTTP des 35 scripts PHP de test passent également.

PHPCS applique les règles natives des deux cœurs : aucun diagnostic sur les
nouveaux fichiers, aucun ajout sur les lignes modifiées et aucune hausse par
règle dans les fichiers historiques comparés à 6.3.2. Leur dette de style
antérieure reste identifiée. Le contrôle jscpd conserve zéro correspondance de
production aux mêmes seuils de 10 lignes / 100 tokens.

Les journaux HTTP de qualification ne contiennent aucun diagnostic PHP du
module. L'avertissement natif `QUERY_STRING` déjà identifié apparaît une fois
par instance et reste compté séparément. Les trois revues indépendantes sont
liées aux empreintes finales des sources ; aucun problème bloquant ne reste
ouvert.

## Livraison et limites

L'archive est `dist/module_cdav-6.3.3.zip`. Le manifeste local
`dist/qa/booking-release-manifest.json` porte son SHA-256 et ses commits.
Les octets exécutables sont identiques au candidat extrait et qualifié ; seules
les documentations finales sont ajoutées ensuite. CRC, chemins, correspondance
avec Git et reproductibilité sont vérifiés.

Les preuves anonymes sont conservées dans `dist/qa/calendar-performance-validation/`
et les corpus fictifs dans `dist/qa/calendar-performance-investigation/` et
`dist/qa/calendar-read-performance/`. Aucune donnée d'agenda, adresse, identité,
URL ou journal brut de production n'a été copié dans ces preuves. Les quatre
bases, serveurs et arbres privés sont retirés après vérification des données et
des orphelins ; les cœurs et services partagés sont préservés.

Cette qualification utilise MariaDB et des données fictives. Elle ne reproduit
pas le contenu du calendrier de production, ne pilote pas Outlook/DAVx⁵ et ne
requalifie pas SMTP ni les parcours navigateur de réservation, dont les fichiers
sont identiques à 6.3.2. Le délai sur l'hébergement réel reste à vérifier après
installation. Aucun changement de schéma ni réactivation n'est nécessaire
depuis 6.3.2 ; remplacer les fichiers du module suffit.
