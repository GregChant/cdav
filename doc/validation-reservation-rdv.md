# Réservation CDav — décisions et preuves de validation

Date : 17 novembre 2026. État : qualification et livraison locale terminées, CDav 6.0.0.
Les résultats ci-dessous distinguent les sources applicatives et leur distribution.

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

Les sources applicatives sont enregistrées dans `df43a6d`. Les contrôles
fonctionnels et la qualification de l’archive sont détaillés ci-dessous.

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
Les contrôles depuis l’archive sont terminés ; leurs références figurent dans
la section de livraison ci-dessous.

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

## Livraison et vérification de l'archive

Sources applicatives : commit `df43a6d4f3bfed628dd2329f1ed931e056d8d053`.
Le candidat correspondant comporte 83 fichiers et 98 entrées, tous identiques
aux blobs Git ; deux constructions produisent le même SHA-256 :
`d2db9efa064cb2dbcc8ec72cc3b4eb71ddc37ba9e3e568069aa6312ad79a2da4`.
L'audit indépendant n'a identifié ni chemin traversant, doublon, symlink,
configuration locale ou secret dans le paquet.

| Combinaison | Contrôle natif depuis l'extrait | Résultat |
|---|---|---|
| Dolibarr 23 / PHP 8.4 | Installation des nouvelles tables, publication et HTTP local désactivés par défaut, activation rejouée | Réussi |
| Dolibarr 24 / PHP 8.4 | Installation des nouvelles tables, publication et HTTP local désactivés par défaut, activation rejouée | Réussi |
| Dolibarr 23 / PHP 8.5 | Mise à niveau conservant le profil témoin et sa clé publiée | Réussi |
| Dolibarr 24 / PHP 8.5 | Mise à niveau conservant le profil témoin et sa clé publiée | Réussi |

Les quatre serveurs ont été redémarrés après bascule sur l'extrait pour écarter
un cache de chemins. La commande `tests/qa.sh` a ensuite été exécutée depuis
cet extrait sur chaque combinaison : 24 suites rapides, PHPCS natif, quatre
suites HTTP DAV et quatre suites HTTP réservation réussis. Les neuf scripts PHP
de test renvoient `404` par HTTP. Contrôle final : administrateur initial seul,
aucun événement, profil/reçu de réservation, ressource ou collection orpheline.
Aucun diagnostic PHP issu du module extrait n'a été détecté.

L'archive de clôture `96a5735` était construite après la clôture
**documentaire**. Ses fichiers applicatifs, schémas, langues et tests sont
identiques à ceux du candidat exécuté ; seuls les fichiers Markdown de preuve
et de guide sont actualisés. Le manifeste externe
`dist/qa/booking-release-manifest.json` enregistre le commit final, le SHA-256,
la reproductibilité et cette équivalence des sources. La reconstruction ne
requiert aucun outil de paquetage supplémentaire :

```sh
git archive --format=zip --prefix=cdav/ \
  --output=dist/module_cdav-6.0.0.zip HEAD
```

### Renforcement avant publication sur `master`

Le correctif d’administration conserve une seule entrée `setup.php@cdav` dans
le descripteur : Dolibarr affichait une roue par page déclarée. La configuration
générale propose maintenant un bouton « Rendez-vous en ligne » vers la page
existante ; le menu Agenda reste disponible. Le descripteur natif a été chargé
sur les quatre combinaisons pour vérifier l’entrée unique et le menu conservé.

La suite `booking.php` couvre désormais A01–A12 : cinq groupes ajoutent les
bornes/types, horaires jusqu’à `24:00`, durée distincte du pas, horizon en jours
locaux, indépendance du fuseau PHP et sémantique iCalendar. Vingt-quatre
plannings déterministes vérifient l’invariance par ordre/doublons, la monotonie
des occupations et l’absence de chevauchement, y compris les marges. Deux
défauts injectés dans des copies temporaires ont fait échouer A08 et A10
(validation numérique relâchée et horizon remplacé par des secondes fixes).

Les quatre suites historiques partagent un gestionnaire d’erreurs strict :
les avertissements et dépréciations applicatifs font échouer les tests ; seules
les dépréciations du Sabre natif sont comptées séparément. Les six suites, le
lint et PHPCS natif sont automatisés par
[GitHub Actions](../.github/workflows/qa.yml) sur Dolibarr 23.0.4 / 24.0.0 ×
PHP 8.4 / 8.5. Le benchmark temporel reste disponible via
`CDAV_BOOKING_BENCHMARK=1`, hors CI rapide partagée. Le
[guide des tests](../tests/README.md) distingue ces garanties des contrôles
HTTP/base réelle et navigateur de qualification.

L’archive locale est reconstruite après ces changements. Les anciennes preuves
d’installation et de parcours restent celles du candidat `df43a6d` ; elles ne
sont pas présentées comme une nouvelle qualification complète du correctif.
Le manifeste précise les fichiers modifiés depuis ce candidat.

### Évolution 6.1 : calendrier, sélection et identité

La suite de la demande ajoute le calendrier mensuel, l'horizon de six mois
calendaires, deux permissions de sélection indépendantes par professionnel et
l'identité native de l'entreprise et du professionnel. Les profils existants
gardent leurs horaires, leur horizon en jours et leur lien ; les deux nouvelles
permissions sont désactivées par défaut. Une table technique supplémentaire
relie un reçu aux `ActionComm` natifs, sans recopier leurs dates ni coordonnées.

Avant empaquetage, les neuf suites rapides passent sur les quatre combinaisons
Dolibarr 23.0.4 / 24.0.0 × PHP 8.4.26 / 8.5.11, avec lint et PHPCS natif. Les
tests de rendu couvrent 58 situations FR/EN par combinaison. Les tests métier
ajoutent les règles A13/A14, la présentation mensuelle, M01–M07 pour la sélection,
les photos natives, leurs miniatures, le refus des traversées et symlinks, les
limites d'octets et de pixels et les liens de contact.

Les tests HTTP et MariaDB réels de groupe passent sur les quatre combinaisons :
créneau continu unique, trois parties séparées, permissions falsifiées refusées,
rejeu canonique, modification/suppression d'une partie native, conversion en
tâche native, rollback à la deuxième création et course entre deux workers
attendant effectivement le verrou du même propriétaire. Le rejeu d'une partie
déplacée, rendue libre ou convertie en tâche renvoie `410`, sans nouvelle création.

Une première exécution parallèle a échoué à observer les deux workers dans les
cinq secondes. Les passages isolés et les quatre passages finaux sont réussis.
La preuve de concurrence conserve l'exigence de deux attentes et filtre
maintenant le propriétaire exact, avec une attente maximale de dix secondes.
Cet ajustement ne remplace pas la barrière SQL par une temporisation arbitraire.

Les 48 parcours navigateur (12 par combinaison) contrôlent les deux langues,
le clavier, le mobile, les mois et leur limite, les paniers, les retraits, les
confirmations et les refus de permission. Le logo est réellement servi par le
point d'accès natif public, la photo est chargée et les coordonnées et options
de masquage de l'utilisateur sont vérifiées. Axe ne signale aucune violation
sur les pages contrôlées ; il ne s'agit pas d'une certification globale.

Le navigateur a détecté un conflit avec le nom natif `action=add` : Dolibarr le
traitait comme une mutation exigeant un jeton CSRF en GET. La sélection de
présentation utilise désormais `selection_action`, sans jeton dans l'URL et sans
relâcher la protection POST. Les confirmations vident les paramètres GET avec
`action="?"`. Ces deux comportements ont des assertions de non-régression.

Les instances de qualification utilisent uniquement des bases jetables
`cdav_monthly_test_*`, quatre workers HTTP chacune et des répertoires privés.
Les fixtures passent par les API natives pour les utilisateurs et événements ;
les fichiers, constantes, reçus et historiques automatiques ajoutés sont retirés
après chaque suite. L'instance préexistante est conservée.

Preuves locales : `dist/qa/monthly-browser-matrix.json`, les quatre dossiers
`dist/qa/monthly-doli*/`, `dist/qa/multi-native-*-php*.json` et
`dist/qa/release-6.1-*.log`. La qualification du ZIP et son empreinte sont
consignées dans le manifeste de livraison.

Le contrôle des résidus a aussi détecté une affectation Agenda orpheline après
la suite DAV historique. Un reproducteur limité aux API natives a établi la
cause : `Societe::delete()` crée un audit de suppression puis supprime ses
`actioncomm` liés par cascade SQL, sans leurs ressources. Le nettoyage des tiers
de fixture supprime désormais leurs événements récents via `ActionComm::delete()`
avant la suppression du tiers, dont le déclencheur est désactivé pour ce seul
nettoyage. La suite compare exactement les identifiants des ressources orphelines
avant et après, pour préserver tout état antérieur et refuser tout résidu nouveau.
Le cœur Dolibarr et le comportement de suppression de l'application restent
inchangés. La preuve et les traces sans arguments sont dans
`dist/qa/package-6.1-native-cascade-diagnostic.json`.

Le candidat corrigé `e06b9ed` contient 92 fichiers et 109 entrées ; son SHA-256
est `b9a2a8c6d84a8e590a14725af8ba1c7e7895be6fbe146a404680571cec7489a4`.
L'installation native et la mise à niveau passent sur les quatre combinaisons.
Les deux profils témoins au format 6.0 conservent leur configuration, leur état
et leur clé ; les anciens liens répondent `200` après réactivation. Les neuf
suites rapides, le lint et PHPCS natif ont été exécutés depuis l'extrait, et les
14 scripts PHP de tests et d'aide renvoient `404` par HTTP.

Les douze suites HTTP (DAV, réservation simple et réservation groupée sur chaque
combinaison) et les 48 parcours navigateur passent depuis le premier extrait.
Après le correctif du seul nettoyage DAV, les quatre suites DAV sont relancées
depuis le nouveau ZIP et passent en parallèle. La comparaison des archives prouve
que le code de production, les schémas, langues et parcours navigateur sont
identiques entre ces deux candidats. Chaque base retrouve son administrateur
initial, aucun événement, aucune ressource, aucun profil, reçu ou mapping, et
aucun nouvel orphelin. Un passage supplémentaire a aussi prouvé qu'un orphelin
antérieur est préservé, sans purge globale.

Le rapport complet est `dist/qa/package-6.1-validation.json`, avec ses commandes
dans `dist/qa/package-6.1-validation/README.md`. Les logs de l'instance PHP
intégrée signalent quelques avertissements natifs de `main.inc.php` sur une
clé `QUERY_STRING` absente dans des probes sans requête ; aucun diagnostic PHP
du module extrait n'est relevé. Le cœur partagé n'a pas été modifié.

L'archive finale 6.1 est reconstruite après clôture documentaire. Ses fichiers
hors Markdown restent identiques au candidat corrigé exécuté. Le manifeste
`dist/qa/booking-release-manifest.json` enregistre le commit final, l'empreinte,
la reproductibilité et les références des preuves natives et CI.

Les quatre serveurs et bases jetables ont été supprimés après qualification,
ainsi que leurs configurations et secrets temporaires. L'instance préexistante,
les cœurs partagés et le cache du navigateur sont conservés. Le contrôle final
des ports et de l'absence des bases est enregistré dans
`dist/qa/package-6.1-validation/teardown.json`.

Les preuves locales sanitizées sont dans `dist/qa/` :

- `package-validation.json` : installation, mise à niveau, identité des blobs,
  suites depuis l'archive, protections HTTP des tests et résidus.
- `package-validation/README.md` : commandes de reproduction sur instances isolées.
- `booking-reference-environment.json`, `booking-quick-matrix.json`,
  `booking-dav-matrix.json`, `booking-http-matrix.json` : versions et résultats.
- `booking-native-benchmark.json`, `booking-residues.json`,
  `booking-history-preservation.json` : charge et nettoyage natif.
- `booking-ui-23-php84/`, `booking-ui-23-php85/`, `booking-ui-24-php84/` et
  `booking-ui-24-php85/` : sept parcours navigateur par combinaison et captures.

Ces artefacts locaux sont ignorés par Git. Les journaux web bruts et les
identifiants temporaires ne font pas partie des livrables de validation.

## Sujet et adresse d'intervention — 6.1.1

Le client renseigne le sujet et l'adresse d'intervention. `ActionComm::create()`
enregistre le sujet comme libellé et l'adresse comme lieu, sur chaque partie
d'une réservation. Les notes privées et la confirmation affichent aussi ces
détails. Aucun schéma supplémentaire ni mise à niveau SQL n'est nécessaire.

La validation spécifique du formulaire est centralisée dans `BookingDetails` ;
les API natives gardent la validation de l'email, l'échappement HTML et l'écriture
de l'Agenda. Les bornes sont de 120 caractères UTF-8 pour le sujet et 128 pour
l'adresse multiligne, compatible avec `location`, sans troncature. Les fins de
ligne CRLF/CR sont normalisées en LF. Une nouvelle réservation sans ces champs
échoue avec `422`. Un ancien reçu peut encore être rejoué avec sa forme 6.1 ;
un changement des détails d'une même demande donne `409`, sans second événement.

Les détails ne circulent pas dans les liens de navigation ni les JSON publics.
Un POST qui les place en paramètres d'URL est refusé avant mutation. La revue
indépendante a vérifié le contrôleur, la validation, l'idempotence et le rendu.

La matrice source Dolibarr 23.0.4/24.0.0 × PHP 8.4.26/8.5.11 passe : dix suites
rapides par combinaison, lint PHP complet et PHPCS natif, 288 rendus FR/EN,
quatre suites HTTP simples et quatre groupées, 48 parcours navigateur sans
violation axe. Les régressions couvrent limites Unicode, saisies invalides,
multilignes, stockage natif, export CalDAV, confidentialité, concurrence,
rejeux historiques et absence de doublons. Le format `LOCATION` CalDAV existant
aplatit les retours à la ligne en espaces ; l'Agenda conserve l'adresse multiligne.

Une première exécution concurrente de tests surchargés a manqué l'observation
de leur barrière, avec deux réponses métier `201` sur des créneaux distincts.
Le délai d'observation est porté à dix secondes et vise exactement la requête
de verrouillage du propriétaire ; le nombre de workers exigés reste inchangé.
La matrice finale séquentielle réussit. Aucun diagnostic PHP du module ni
résidu de fixture n'est présent. Les preuves sont dans
`dist/qa/details-validation/source-matrix.json`, `source-cleanup.json`,
`runtime-diagnostics.json` et les dossiers `dist/qa/details-doli*/`.

Le candidat `537193e`, SHA-256
`8c27a037e4056239f577b3f36ccfebee6de2553b3ed1629277fada386f59549a`,
est qualifié depuis son extrait dans les quatre instances natives. Ses 94
fichiers correspondent aux blobs Git avant et après les essais. Les 40 suites
rapides, 288 rendus, lint complet, PHPCS natif, huit suites HTTP et 48 parcours
navigateur réussissent depuis cet extrait. Les quinze scripts PHP développeur
répondent `404` avec un corps vide en GET et POST sur chaque combinaison, soit
120 vérifications. Les bases reviennent à leur état initial, sans orphelin.

Le bilan est `dist/qa/details-validation/archive-summary.json` ; les matrices,
protections HTTP, diagnostics, résidus et commandes de reproduction figurent
dans le même dossier. Aucun diagnostic PHP du module n'est relevé. Les huit
avertissements natifs `QUERY_STRING` de `main.inc.php`, liés aux métadonnées du
serveur PHP de développement, sont conservés dans la preuve des diagnostics.
La CI du candidat sur `23.0` réussit également sur les quatre combinaisons.

L'archive finale est reconstruite après cette seule clôture Markdown ; tous
ses fichiers hors Markdown restent identiques au candidat exécuté. Son commit,
son empreinte reproductible et les références CI sont consignés dans
`dist/qa/booking-release-manifest.json`.

Les quatre serveurs, bases et configurations jetables ont été supprimés ;
l'instance préexistante sur le port 8023, les configurations des deux cœurs
partagés et le cache Chromium sont préservés. La preuve de retrait figure dans
`dist/qa/details-validation/teardown.json`.

## Icône de l'onglet — 6.1.2

La page publique déclare désormais une icône d'onglet avec le même logo que
l'en-tête de l'entreprise. La balise utilise l'URL native `viewimage.php`, déjà
validée et échappée, autorisée par la CSP actuelle. Elle est omise lorsque le
logo manque, est invalide ou que le profil n'est pas disponible. Aucun nouvel
endpoint, helper, schéma ni réglage global n'est ajouté.

La revue indépendante confirme cette modification limitée au rendu. Les dix
suites rapides, dont 72 rendus FR/EN par combinaison, le lint complet et PHPCS
natif passent sur Dolibarr 23.0.4/24.0.0 × PHP 8.4.26/8.5.11. Un contrôle du
HTML rendu sur 24/PHP 8.5 confirme la correspondance entre icône et logo et
l'absence d'icône pour les trois cas manquant, hostile et profil indisponible.
Les logs sont dans `dist/qa/favicon-validation/`. Les suites HTTP et navigateur
de 6.1.1 ne sont pas relancées pour cette seule déclaration HTML ; aucun serveur
ni base de test n'est créé. L'archive finale correspond aux blobs Git et son
empreinte reproductible est enregistrée dans le manifeste local de livraison.

## Sélections refusées et logo carré — 6.1.3

Le signalement porte sur 08:00–12:00 et 13:00–17:00 avec la seule permission de
regroupement consécutif. La pause exige la permission indépendante des plages
séparées. Le refus était converti en erreur générique affichée en haut de page,
alors que le panier masquait son alerte locale et présentait une durée de zéro.

Le service propage désormais uniquement deux clés publiques autorisées pour
ces refus de permissions ; les autres détails internes restent masqués. Le
panier invalide affiche son motif échappé avec `role="alert"`, sans durée ni
périodes trompeuses. L'ajout GET cible le panier et permet de retirer un créneau
pour rétablir la confirmation. Les règles de disponibilité, marges, droits,
concurrence, transactions et rejeu restent celles de la réservation native.

Le précédent favicon reprenait le logo d'en-tête. La version 6.1.3 préfère la
miniature carrée `logo_squarred_mini`, comme `main.inc.php` dans les deux cœurs,
puis le petit logo carré et l'original carré. Le logo ordinaire reste celui de
l'en-tête et sert de repli. Les URL utilisent `viewimage.php`, l'entité courante
et la même validation locale ; aucun traitement d'image, endpoint, réglage ou
schéma supplémentaire n'est ajouté.

La matrice rapide Dolibarr 23.0.4/24.0.0 × PHP 8.4.26/8.5.11 passe : 40 suites,
360 rendus FR/EN, lint complet et PHPCS natif. Les régressions couvrent les
deux refus distincts, les véritables créneaux jointifs, les huit heures en deux
périodes autorisées, les erreurs échappées et visibles, le logo carré distinct,
ses priorités et replis, les chemins invalides et la confidentialité des profils
indisponibles. Les preuves sont dans `dist/qa/selection-validation/`.

La revue indépendante finale n'identifie aucun défaut concret. Le passage natif
de stabilisation sur 24/PHP 8.4 réussit, puis le candidat `ea0a401`, SHA-256
`57a0c339d2a7e572a1c508b95cf08d1147b077e65b56ecda675fc6d585aebeb0`,
est qualifié depuis son extrait sur les quatre combinaisons. Les 94 fichiers
restent identiques à l'archive après essais : 40 suites rapides, 360 rendus,
lint et PHPCS, quatre suites HTTP groupes et 60 parcours navigateur passent,
sans violation axe. Les quinze scripts développeur renvoient `404` et un corps
vide en GET et POST, soit 120 vérifications.

Le navigateur vérifie le cas de la capture en FR et EN : erreur dans la zone
visible après ajout, retrait qui rétablit le formulaire puis une réservation,
et huit heures réparties en deux événements lorsque le profil l'autorise.
Le test HTTP prouve aussi le rollback natif de la première partie si la seconde
échoue. Le favicon carré et le logo rectangulaire d'en-tête sont distincts,
servis avec les dimensions attendues par le point d'accès public natif.

Les preuves sont dans `dist/qa/selection-validation/archive-summary.json`, les
matrices, contrôles et captures associés. Les fixtures, constantes et images
sont restaurées ; aucun événement résiduel ni orphelin n'est présent. Aucun
diagnostic PHP du module n'est relevé. Les seize avertissements `QUERY_STRING`
du serveur PHP de développement dans `main.inc.php` natif sont documentés
séparément. La CI du candidat réussit également sur les quatre combinaisons.

La clôture ajoute uniquement de la documentation Markdown au candidat exécuté.
Le ZIP final correspond à son commit Git ; les fichiers hors Markdown restent
identiques à l'extrait qualifié. Son empreinte reproductible et les références
CI sont consignées dans `dist/qa/booking-release-manifest.json`.

Les quatre serveurs, bases et configurations jetables sont supprimés après
qualification. Le port 8023, les configurations des cœurs partagés et le cache
Chromium sont préservés ; la preuve figure dans
`dist/qa/selection-validation/teardown.json`.
