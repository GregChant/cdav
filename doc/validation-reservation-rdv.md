# Réservation CDav — décisions et preuves de validation

Date : 17 novembre 2026. État : réalisation validée ; contrôles de distribution en cours.
Les résultats ci-dessous distinguent le code validé et le paquet à vérifier.

Référence : [feuille de route](feuille-de-route-reservation-rdv.md).

## Décisions L0 et inventaire du natif

| Besoin | Natif retenu / raison de l'ajout |
|---|---|
| Rendez-vous et affectation | `ActionComm::create()`, ressources utilisateur, transparence et triggers natifs ; aucune écriture SQL métier directe |
| Autorisation | `User::fetch()`, `loadRights()`, `hasRight()` et utilisateur interne actif ; acteur de création = propriétaire, jamais un compte administrateur délégué au visiteur |
| Entités | Entité du contexte Dolibarr pour le profil ; propriétaire de cette entité ou utilisateur global ; lecture Agenda via `getEntity('agenda')` |
| Événements et récurrences | Lecture/export `CdavLib` sur période explicite, réutilisation des métadonnées et récurrences natives ; Sabre/VObject fourni par chaque cœur |
| Formulaire et administration | Chargement `main.inc.php`, `Form`, `GETPOST`, `newToken`, vérification CSRF native forcée, traductions, configuration et journalisation Dolibarr |
| Transport HTTPS | Mutualisation du contrôle CDav existant, proxy de confiance explicite et exception explicite `CDAV_BOOKING_ALLOW_LOCAL_HTTP=1` sur boucle locale pour les seuls tests publics |
| Profil de réservation | Une table technique par profil/utilisateur : aucune configuration publique d'horaires équivalente dans CDav ; BookCal reste facultatif et expérimental ; pas de deuxième moteur BookCal |
| Rejeu de confirmation | Reçu technique lié à une demande signée et un événement natif ; cet état n'est pas une seconde table de rendez-vous |
| Limitation des abus | Compteurs techniques partagés en base, indexés et expirants ; une session ou une variable PHP ne couvrirait pas plusieurs workers |

Le code du cœur et les bibliothèques fournies restent sans modification.
Les métadonnées du module gardent Dolibarr 23 minimum et PHP 8.0 minimum ; la
matrice visée pour cette livraison est 23/24 × PHP 8.4/8.5.

## Contrat métier et limites

- Un profil par utilisateur et entité ; réglages CDav autonomes.
- Événements Agenda affectés au propriétaire, de type événement (`percent = -1`)
  et occupés. Les tâches, interventions, ressources et événements automatiques
  ne deviennent pas des occupations implicites.
- Un événement occupé sans durée exploitable, une série non prise en charge ou
  une erreur de lecture rend le calcul indisponible. Les erreurs ne libèrent pas
  artificiellement un créneau.
- Heures locales inexistantes ou ambiguës : aucun départ proposé à cette heure.
  La durée réelle de chaque créneau est contrôlée aux changements d'heure.
- Marges autour de chaque rendez-vous et de chaque occupation : le candidat
  `[début − marge avant, fin + marge après)` doit rester dans la plage de travail
  et ne rencontrer aucune occupation élargie. La séparation vaut la somme des
  deux marges : 10 min avant + 15 min après impose 25 min entre RDV, quel que soit
  leur ordre de création.
- Création immédiate dans l'Agenda et confirmation à l'écran ; coordonnées dans
  la note privée ; aucun contact/tiers ni transport email/SMS ajouté par CDav.
  Les triggers configurés par l'exploitant continuent de suivre le cycle natif.
- Le verrou utilisateur sérialise les réservations publiques et utilise la même
  ligne que les mutations CDav. Il n'interdit pas à l'interface Agenda ou à un
  client DAV de superposer ensuite un événement.

## Bornes décidées

| Paramètre | Valeur / contrat |
|---|---|
| Durée / pas | 5–480 min / 5–240 min |
| Marges avant et après | 0–240 min chacune |
| Préavis / horizon | 0–43 200 min / 1–90 jours |
| Horaires | Jusqu'à quatre plages par jour dans le moteur ; deux dans l'écran V1 |
| Lecture publique | Un jour par demande ; période du service bornée à 32 jours |
| Événements / données calendrier | 5 000 événements et 8 MiB au maximum |
| Expansion | 50 000 itérations, règles quotidiennes/hebdomadaires/mensuelles/annuelles ; limite de calcul 2 s |
| Budget de référence R03 | p95 HTTP ≤ 1 s ; mesure CLI du service ≤ 64 MiB alloués sur le jeu de référence, distincte de la mémoire du serveur HTTP |
| Demande de réservation | Ticket signé valable 15 min, lié au profil, à sa configuration et au jeton publié |
| Idempotence | Même ticket et même contenu : un seul événement ; contenu divergent ou ticket expiré : refus |
| Conservation des reçus | Expiration du ticket + 24 h ; ticket expiré toujours refusé après purge, déplacement ou suppression du RDV |
| Corps HTTP public | 8 KiB et nombre de paramètres borné ; paramètres d'autorité non reconnus refusés |
| Fréquence | 240 requêtes/IP/5 min ; 120 lectures ou 20 confirmations/profil/IP/5 min ; 200 confirmations/profil/jour |
| Expiration technique | Purge indexée et bornée à chaque requête ; aucune coordonnée ni adresse IP en clair dans les compteurs |

Le secret de signature réutilise l'identifiant secret d'instance Dolibarr. Le
jeton public est aléatoire (256 bits) et seul son hash est enregistré ; sa rotation
invalide aussi les formulaires en cours. Le lien complet n'est affiché qu'à sa
création. Les horaires et la durée sont toujours décidés côté serveur.

## État de référence mesuré

Environnements de test isolés, sans modification des instances existantes :
Dolibarr 23.0.4 et 24.0.0, PHP 8.4.26 et 8.5.11, MariaDB 10.6.23. Quatre serveurs
locaux de quatre workers utilisent des bases explicitement nommées `*_test_*`.

| Contrôle avant validation de la réservation | Résultat |
|---|---|
| Quatre suites rapides existantes × quatre combinaisons | 16 passages réussis |
| Démarrage Dolibarr et activation native Agenda/CDav | 4 combinaisons réussies |
| Suite HTTP DAV existante, configuration normale | 4 passages réussis |
| Suite HTTP DAV existante, `dolibarr_strict_mode=1` | 4 passages réussis ; dépréciations analysées séparément |

Le diagnostic strict a identifié les appels `imagedestroy`, `curl_close` et
`curl_multi_close` de CDav/tests dépréciés sous PHP 8.5. Ces opérations, devenues
inutiles avec les objets PHP 8, sont remplacées par la libération des références.
La validation finale doit être rejouée après cette correction et intégration de
la réservation.

## Résultats de la réservation

| Contrôle | 23 / 8.4 | 23 / 8.5 | 24 / 8.4 | 24 / 8.5 |
|---|---|---|---|---|
| Lint et moteur A01–A07 | Réussi | Réussi | Réussi | Réussi |
| HTTP, natif, concurrence et rejouabilité B01–B06 | Réussi | Réussi | Réussi | Réussi |
| Sécurité/confidentialité S01–S06 | Réussi | Réussi | Réussi | Réussi |
| Utilisation U01–U03 | 7 parcours réussis | 7 parcours réussis | 7 parcours réussis | 7 parcours réussis |
| Migration, régression et nettoyage R01/R02/R04 | Réussi | Réussi | Réussi | Réussi |

### Calcul pur et interface déjà vérifiés

`tests/booking.php` passe A01–A07 sur les quatre combinaisons, avec `E_ALL`.
Seules les dépréciations des fichiers fournis dans Sabre sont comptées à part
(13 par passage) ; les avertissements du module font échouer la suite.

Sur 1 000 événements, 100 séries et 60 jours : p95 du calcul pur
0,316 / 0,281 / 0,317 / 0,310 s (23/8.4, 23/8.5, 24/8.4, 24/8.5), pic mémoire
8 MiB. Cette mesure ne remplace pas celle de la page avec base réelle R03.

`tests/booking_ui.php` valide dix rendus FR/EN par combinaison : champs et labels,
CSRF natif, erreurs associées, confirmation et absence d'injection HTML. Les
pages d'administration ont été ouvertes après connexion native sur les quatre
combinaisons.

Chromium 151.0.7922.34, Playwright 1.62.1 et axe 4.13.0 : parcours public français
sur bureau, anglais en mobile 390 px, réservation au clavier (`201`), formulaire
expiré (`409`) et administration native (`200`). Aucune violation WCAG 2/2.1 AA
signalée par axe sur les pages publiques contrôlées. Les sept parcours couvrent les quatre
combinaisons, avec une absence sur journée entière et la perte réelle d'une
réponse déjà acceptée (`201`), suivie d'un rejeu identique (`200`, même reçu).
Il s'agit d'une vérification
ciblée, sans certification d'accessibilité globale.

PHPCS avec les règles natives Dolibarr 23/24 : aucune erreur ni alerte sur les
sept nouveaux fichiers PHP de production et les quatre nouvelles suites PHP. La revue indépendante fonctionnelle
et sécurité du backend est terminée ; aucun défaut bloquant ou majeur connu
ne reste ouvert dans le périmètre V1.

Le contrôle des lignes modifiées dans les fichiers historiques ne produit
également aucune alerte nouvelle. Le nettoyage de style de la bibliothèque
existante conserve les vingt requêtes SQL représentatives octet pour octet.
Les commentaires/formatages du nouveau test HTTP ont aussi été comparés par AST
pour vérifier l'absence de changement exécutable pendant leur mise en conformité.

Le paquet et ses références de commits restent à vérifier avant de terminer
l'objectif ; les mesures et contrôles fonctionnels sont détaillés ci-dessous.

## Corrections issues des revues et tests

- Chargeur public : recherche du `main.inc.php` aussi par les parents du chemin
  web ; déploiement sous-répertoire + symlinks contrôlé par un serveur temporaire.
- Dolibarr 23 peut effacer `$_POST` après un mauvais jeton CSRF puis poursuivre :
  le contrôleur reconnaît son code d'erreur et retourne `403`, sans avertissement.
- Le refus HTTP tient compte d'une exception locale explicite, désactivée par
  défaut. Un proxy local ne bénéficie plus implicitement de cette exception.
- La désactivation globale est relue sous verrou lors de la confirmation ; les
  tests couvrent une désactivation pendant l'attente du verrou propriétaire.
- Deux demandes simultanées portant le même ticket donnent `201` et `200`, avec
  le même reçu ; deux demandes distinctes se chevauchant donnent `201` et `409`.
- Les marges asymétriques et combinées sont vérifiées dans les deux ordres de
  réservation ; la disponibilité est indépendante de l'ordre d'insertion.
- Une série libre peut porter une exception occupée. La sélection SQL conserve
  donc les séries avec exceptions même si leur maître est libre, et les
  exceptions déplacées même si la date d'origine est hors de la période lue.
- La projection SQL de réservation omet les descriptions, notes et coordonnées.
  Le contrôle de volume porte sur tous les champs variables réellement chargés ;
  le filtre Free/Busy existant ne fournit au moteur que les dates et récurrences.
- Messages JSON : traductions sans entités HTML, échappement réservé au template.

Le contexte public MultiCompany n'est pas commuté par un paramètre client : un
profil d'une autre entité nécessite son routage natif (domaine/contexte
`DOLENTITY`). Cette version garantit l'isolation dans le contexte testé et
n'annonce pas une prise en charge MultiCompany complète. Le frontal web doit
masquer les paramètres sensibles de réservation dans ses journaux d'accès.

### R03 : mesure avec base native et serveur HTTP

Référence locale : AMD Ryzen 7 5800H, MariaDB 10.6.23, Dolibarr 24.0.0,
PHP 8.4.26, serveur de quatre workers. Jeu de 1 000 événements et 100 séries
natives commencées 60 jours avant la période, plus les fixtures fonctionnelles
A07 ; profil d'horizon 60 jours. Requêtes publiques sur un jour, concurrence 1,
20 échantillons après préchauffage : p95 **0,257826 s**, maximum **0,269358 s**.

Le service CLI réalise huit requêtes SQL en **0,124979 s** ; pic mémoire PHP
alloué **20 MiB**, augmentation pendant le calcul **4 MiB**. Ces valeurs CLI ne
sont pas présentées comme la mémoire des workers HTTP. Les budgets de référence
p95 ≤ 1 s et mémoire CLI ≤ 64 MiB sont respectés.

### Récurrences non projetables : correction de provenance

Le test CalDAV réel a mis en évidence une perte antérieure de `RRULE` pour les
séries avec `COUNT`. Une ligne de provenance portant un hash NULL n'est plus
assimilée à une projection native. Les règles avancées restent dans le sidecar,
alors qu'une vraie projection conserve la priorité des changements natifs.
Le ctag inclut une nouvelle version de sérialisation pour forcer la relecture
des calendriers existants après mise à niveau. Les suites DAV des quatre
combinaisons ont été rejouées après cette correction.

### Contrôles de distribution

Les scripts PHP de test refusent les requêtes HTTP par `404` avant tout
chargement ou création de fixtures. La commande commune `tests/qa.sh` exécute
le lint, six suites rapides et, si activé, PHPCS natif et les suites HTTP.
Les événements automatiques Agenda créés par les fixtures sont nettoyés via
`ActionComm::delete()` en préservant l'historique antérieur de l'instance de test.
Les contrôles depuis l'archive et leurs références restent à renseigner.

### Résidus et conservation de l'historique

Les trois suites avec base de données mémorisent le dernier identifiant Agenda
et le nombre d'événements automatiques avant leurs fixtures. En fin de suite,
elles retirent par API native les nouveaux événements automatiques, identifiés
par `fk_action` vers le dictionnaire `c_actioncomm.type = systemauto`. Les codes
d'action effectifs comme `AC_USER_CREATE` ne sont pas utilisés comme identifiant
de type. Un audit témoin présent avant les tests a été conservé lors des suites
DAV, réservation et navigateur. Les témoins sont retirés séparément après cette
preuve ; aucune donnée de test ne reste dans les bases dédiées.

### Dépréciations du cœur et des dépendances

En mode strict, les quatre combinaisons fonctionnent avec les tests décrits.
Des dépréciations non fatales du cœur Dolibarr et de Sabre sont présentes,
notamment les paramètres implicitement nullables de Sabre et la propriété
native `User::$trackid` lors des fixtures utilisateur. Elles sont distinguées
des diagnostics du module ; aucun warning, fatal ou dépréciation provenant du
code CDav final n'a été identifié dans les passages HTTP contrôlés. Le cœur et
ses dépendances ne sont pas patchés et aucune certification générale du cœur
sous PHP 8.5 n'est déduite de ces résultats.
