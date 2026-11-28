# Tests de CDav

La commande rapide ne nécessite ni base, ni serveur HTTP, ni configuration de
production. Elle utilise les bibliothèques du cœur Dolibarr choisi et Node.js
pour les régressions du script de sélection, sans paquet npm supplémentaire :

```sh
DOLIBARR_ROOT=/chemin/dolibarr/htdocs \
CDAV_PHP_BIN=php8.4 \
CDAV_PHPCS_STANDARD=/chemin/dolibarr/dev/setup/codesniffer/ruleset.xml \
bash tests/qa.sh
```

## Protection contre les régressions

Le [workflow GitHub](../.github/workflows/qa.yml) exécute cette commande pour
chaque pull request et chaque push sur `23.0` ou `master`, avec PHP 8.4 et 8.5
sur Dolibarr 23.0.4, 24.0.0 et la révision `f747b0d6` de la branche officielle
`24.0`, qui déclare la version 24.0.4. Un échec de test, de syntaxe ou de PHPCS
fait échouer le job. Les six combinaisons sont exécutées indépendamment.
Un job dédié exécute `bash tests/duplication.sh` avec jscpd 5.4.3. La
production doit rester à zéro correspondance au seuil 10 lignes / 100 tokens,
même après modification de la référence. Les tests ont un rapport séparé.
Les rapports HTML/JSON sont conservés comme artefacts de CI. Voir
[l'audit reproductible](../doc/audit-duplication-code.md).

| Suite sans base | Comportements contrôlés |
|---|---|
| `sync_bootstrap.php` | Portée globale native, flags d'entrée, déploiements custom/externes, échec du chargement et constantes d'entité |
| `multiget.php` | Vrais plugins Sabre, ordre/href absents ou filtrés, statuts ACL, XML, Prefer/Brief, vCard/jCard/jCal et expansion |
| `calendar_factorization.php` | Identités et dates ev/pe/pt/fi, enveloppes/fragments, progrès Task et priorités des lignes commerciales |
| `calendar_read_cost.php` | Vrais REPORT Sabre sur 100/1000 objets fictifs, budget de rendus/SQL, ETags/récurrences, cache borné, contexte, routage et invalidation avant écriture |
| `appointment_status.php` | Priorité des états natifs et DAV historiques, compatibilité Outlook, tâches et réponses individuelles distinctes, libération des affectations |
| `agenda_form.php` | Vrai rendu natif des affectations, compatibilité Dolibarr 24, occupation et sélections de deux onglets indépendants, restauration de session même après erreur |
| `carddav_refactoring.php` | Différences contact/tiers/adhérent, préférences vCard3/4, retraits, désactivation par API native, rollback et contacts liés |
| `run.php` | Lecture vCard/iCalendar, champs absents/remplacés, photos, participants, récurrences, rappels et rejet des données incompatibles |
| `freebusy.php` | Suppression des données privées sans changer les occupations, récurrences, exceptions et fuseaux |
| `contact_photos.php` | Photo personnelle, logo du tiers, repli, entité propriétaire, ETag et limites des fichiers, avec fichiers temporaires et doublure SQL ciblée |
| `attachments.php` | Décodage et limites des pièces jointes, contenu interdit, accès aux documents, traversée de chemin et nettoyage différé des fichiers |
| `business_policy.php` | Provenance native ou CalDAV, protection par défaut, ancien schéma et migration idempotente |
| `contact_business.php` | Conservation des tiers, catégories, statuts, confidentialité et identifiants, contacts liés à un utilisateur |
| `calendar_policy.php` | Déplacements à durée constante, propriétés protégées, descriptions réimportées et rappels natifs conservés |
| `calendar_description.php` | Sections lisibles, texte riche natif conservé, contexte client/projet, reçu vérifié et absence de données privées dans l'export anonyme |
| `invitation_policy.php` | Réponses personnelles, révisions Outlook/DAVx⁵, annulation, protections métier, import extérieur limité et UID stable |
| `invitation_scheduling.php` | Broker Sabre réel, notifications après commit, confidentialité des inbox, POST REPLY authentifié et délégation de l'organisateur |
| `booking.php` | Créneaux réellement libres, bornes, marges, pauses, journées entières, récurrences, changements d’heure, préavis, horizon et validation des profils |
| `booking_projects.php` | Statuts projet natifs, conservation des liens indisponibles, retrait explicite et véritable sanitisation/rendu du sélecteur Dolibarr |
| `booking_calendar.php` | Vue mensuelle, correspondance jours/créneaux, une lecture Agenda par mois, navigation bornée, années bissextiles et confidentialité |
| `booking_multi.php` | Permissions indépendantes, regroupement, pauses, limites, refus des chevauchements, marges et horaires locaux |
| `booking_identity.php` | Coordonnées publiques, logo natif, photo et miniature, bornes des images et isolation des fichiers utilisateur |
| `booking_details.php` | Objet et adresse du client obligatoires, bornes UTF-8 natives, adresses multilignes normalisées et compatibilité des anciens reçus |
| `booking_notification.php` | Enveloppes et langues séparées, coordonnées privées, contenu des confirmations et frontière transactionnelle, sans envoyer d'email |
| `booking_publication.php` | Chiffrement et authentification par profil, clé d'instance privée, restauration et refus du stockage sous la racine Web |
| `booking_validity.php` | Validité facultative d'un profil, jour/semaine, bornes inclusives, profils illimités, horizon/préavis et changements d'heure |
| `booking_ui.php` | Rendus FR/EN du formulaire et du calendrier mensuel, CSRF natif, labels, erreurs accessibles et échappement HTML, analysés avec DOM |
| `booking_selection_js.cjs` | Sélection/désélection par GET, changements rapides, repli manuel, retour navigateur et absence de POST automatique |

Les tests de réservation utilisent une horloge fixe. Des cas déterministes
vérifient que l’ordre et les doublons des occupations ne changent pas les
créneaux, et qu’ajouter une occupation ne peut pas créer de disponibilité.
Le pas de proposition est testé indépendamment de la durée du rendez-vous.

PHP est contrôlé en mode strict : les avertissements et dépréciations du module
font échouer les suites. Les dépréciations du Sabre fourni par Dolibarr sont
identifiées séparément. PHPCS applique les règles natives du cœur ciblé aux
fichiers de réservation/transport et aux nouvelles suites ; les anciens fichiers
conservent une dette de style et ne sont pas déclarés entièrement conformes.

## Ce que ces suites ne prouvent pas

Ces scripts sont des tests unitaires et de composants, pas une mesure exhaustive
de couverture. Certaines anciennes assertions de `run.php` recherchent encore du
texte dans les sources ; elles ne prouvent pas le comportement transactionnel.
Les doublures SQL ne valident pas les requêtes contre MariaDB.

`booking_live.php`, `booking_multi_live.php`, `booking_profiles_live.php` et
`live_integration.php` contrôlent les vrais accès HTTP,
droits natifs, écritures, verrouillages, courses concurrentes, rejouabilité et
rollback. `booking_browser.php` et `booking_browser.cjs` contrôlent les parcours
réels avec Playwright et axe. Ils nécessitent une instance jetable avec base
explicitement nommée comme base de test ; ils ne sont pas lancés par la CI rapide.
Leurs commandes et les résultats de qualification sont dans le
[guide de réservation](../doc/reservation-rdv-fr.md#contrôles-de-qualité) et le
[rapport de validation](../doc/validation-reservation-rdv.md).
La qualification 6.3 des protections métier, des descriptions et des projets
ouverts est détaillée dans son
[rapport dédié](../doc/validation-protection-metier-dav.md). Les cas HTTP
BL01–BL27 sont appelés par `live_integration.php`, avec de vrais objets,
verrous natifs, droits, rappels et refus de suppression transactionnelle.
`invitation_live_cases.php` complète cette suite avec les réponses Outlook/DAVx⁵,
les notifications, les changements de révision, les disponibilités personnelles,
les annulations et les imports extérieurs. Les payloads sont vérifiés par HTTP
contre Dolibarr ; les applications Outlook, Microsoft 365 et Agenda sur téléphone
ne sont pas pilotées par ces tests.
Les résultats 6.3.1 sont détaillés dans le
[rapport des invitations](../doc/validation-invitations-dav.md).

Les suites `calendar_policy.php` et `invitation_policy.php` rejouent en CI
des écritures successives de clients différents sur le même objet. Les tests
vérifient la révision après un renvoi identique, les horaires et priorités
explicites, ainsi que les réponses des invités d’une série. Sur une instance
jetable, `agenda_todo_live_cases.php` vérifie aussi l’ETag et les valeurs
natives après un passage d’Outlook à un autre client et une réouverture dans
Dolibarr. Pour reproduire un nouvel écart, conserver dans le test une version
anonymisée des seuls champs iCalendar nécessaires et de leur ordre d’envoi.

`status_live_cases.php`, appelé par la même suite HTTP, vérifie les états de
rendez-vous depuis le vrai formulaire Agenda et les écritures DAV. Les cas
couvrent les permissions, les réponses et disponibilités des participants,
les révisions et renvois identiques, l'annulation avec rappels, les journées
entières au changement d'heure et les champs historiques vides.
La [qualification 6.4.0](../doc/validation-etats-rendez-vous.md) documente les
104 exécutions PHP, 12 suites HTTP, 304 gardes et contrôles natifs complémentaires.

La suite `booking_profiles_live.php` exige aussi un serveur SMTP local de test
sur `127.0.0.1:9125` et `CDAV_BOOKING_MAIL_SINK` vers son répertoire privé.
Dans Dolibarr, renseigner `localhost` comme hôte SMTP et `9125` comme port.
Elle vérifie les profils indépendants, liens tiers/projet et droits natifs,
la concurrence entre profils, les deux messages réels, l'absence de renvoi,
le rollback et l'échec SMTP après commit. Elle contrôle la conservation du lien
lors de la migration, son stockage chiffré, le rattachement et la révocation.
La suite navigateur vérifie aussi le lien actif après rechargement, la copie
dans le presse-papiers et le rattachement depuis l'administration. Aucun email
ne doit partir vers un transport de production lors des qualifications natives.

Le benchmark temporel reste séparé des tests déterministes pour éviter les échecs
liés à la charge d’un runner partagé :

```sh
DOLIBARR_ROOT=/chemin/dolibarr/htdocs \
CDAV_BOOKING_BENCHMARK=1 php8.4 tests/booking.php
```

Pour chaque correction métier, ajouter un cas reproduisant le défaut, vérifier
qu’il échoue avant correction puis qu’il réussit après. Pour une modification
des droits, du SQL, des transactions ou des contrôleurs, compléter par la suite
HTTP/base réelle concernée. Pour une modification du parcours client, compléter
par les contrôles navigateur. Un job vert ne remplace pas ces vérifications.

La [qualification 6.3.2](../doc/validation-factorisation-cdav.md) complète ces
preuves par des comparaisons avant/après des parseurs/sérialiseurs et par la
résiliation réelle d'un adhérent via CardDAV dans BL18, avec conservation de
sa ligne, de son type et de sa société native.

La [qualification 6.3.3](../doc/validation-performances-calendrier.md) ajoute
`calendar_read_live_cases.php`, appelé par la suite native : 200 rendez-vous
ActionComm fictifs par combinaison, REPORT/GET identiques et aucune mutation.
Les serveurs de qualification conservent leur limite PHP de 60 secondes. Le
benchmark du composant reste informatif ; les assertions portent sur les appels :

```sh
DOLIBARR_ROOT=/chemin/dolibarr/htdocs \
CDAV_READ_COST_BENCHMARK=1 php8.4 tests/calendar_read_cost.php
```

Le contrôle compte les diagnostics PHP avant délégation au gestionnaire strict,
y compris lorsqu'un enrichisseur intercepte l'exception correspondante.
