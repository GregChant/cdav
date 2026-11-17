# Feuille de route — réservation de rendez-vous dans CDav

Date : 17 novembre 2026. Statut : réalisation et qualification terminées ; livraison locale
CDav 6.0.0 validée. Ce fichier est la référence de l'objectif et de
son suivi de validation.

## 1. Résultat attendu

Partager un lien depuis lequel un client peut choisir un rendez-vous réellement
disponible. Le calcul consulte directement l'agenda Dolibarr du professionnel et
la confirmation crée un événement natif dans ce même agenda. CDav assure ensuite
sa synchronisation avec les appareils du professionnel.

Exemple de recette : un événement occupé de 14 h à 15 h, saisi directement dans
Dolibarr, retire tous les créneaux qui le chevauchent. Si ce rendez-vous est ajouté
après l'affichage de la page publique, la confirmation d'un ancien créneau est
refusée avec une proposition de rafraîchissement.

La réalisation comprend le code, les tests, la revue de qualité, la documentation
et un paquet installable localement. Chaque capacité annoncée doit avoir une
preuve de validation. Un test indispensable non exécuté reste à faire.

Contraintes confirmées : compatibilité Dolibarr 23 et 24 ; validation sous PHP
8.4 et recherche de compatibilité PHP 8.5 sur les deux versions. Privilégier le
code natif Dolibarr avant toute fonction ou tout stockage supplémentaire. La
qualité du code et le respect des conventions Dolibarr font partie des critères
de livraison, avec une revue indépendante.

## 2. Point de départ sauvegardé

Les travaux antérieurs ont été commités avant l'ajout de cette feuille de route :
`e24dcb8` — `feat: complete CDav 5.1 agenda documents, photos and free-busy privacy`.

Ils couvrent les photos des contacts, les documents d'agenda et le flux ICS
Free/Busy confidentiel. Vérifications exécutées pendant la préparation du plan :

| Vérification | Résultat constaté |
|---|---|
| `git diff --check` avant commit | Réussi |
| Lint des 28 fichiers PHP avec PHP 8.4 | Réussi |
| `tests/run.php`, `contact_photos.php`, `attachments.php`, `freebusy.php` avec le cœur 23.0.4 | Réussis |
| Ces quatre suites avec le cœur 24.0 | Réussis |
| Suite HTTP `tests/live_integration.php` | Non relancée dans cette préparation |

Ces résultats ne valident pas la future réservation publique.

## 3. Périmètre de la première version

- Un professionnel et son agenda Dolibarr par lien ; un profil public actif par
  utilisateur. Un second utilisateur sert notamment à vérifier l'isolation.
- Plages hebdomadaires configurables, pauses, durée de rendez-vous, pas des
  créneaux, marges avant/après, préavis et horizon maximum de réservation.
- Fuseau du professionnel explicite, avec `Europe/Zurich` dans les tests de
  référence. Les horaires et le fuseau sont visibles sur la page publique.
- Prise en compte des événements occupés affectés à cet utilisateur, quelle que
  soit leur origine : saisie Agenda, BookCal ou client CalDAV.
- Récurrences et exceptions, journées entières et événements sur plusieurs jours.
- Formulaire limité au nom, au courriel et à un téléphone facultatif ;
  confirmation immédiate à l'écran et référence de réservation.
- Gestion des modifications et annulations par le professionnel dans l'Agenda
  Dolibarr ; leur effet sur les disponibilités est vérifié.
- Fonction désactivée par défaut, y compris après mise à niveau.

Les tâches d'agenda `VTODO`, tâches projet, interventions, salles et autres
ressources ne deviennent pas automatiquement des occupations dans cette V1.
Cette règle doit être visible dans la configuration et documentée. Leur prise en
compte ultérieure nécessitera des règles métier et des tests propres.

Sont reportés : réservation d'équipe, paiement, durées choisies par le client,
validation manuelle, annulation publique, SMS et courriels automatiques. La V1
conserve les coordonnées dans le rendez-vous ; elle ne crée ni ne modifie une
fiche client à partir d'une identité non vérifiée.

## 4. Architecture retenue et limites à respecter

### 4.1 Source métier et calcul

Dolibarr reste la source des événements, des affectations et des droits. Les
écritures métier utilisent `ActionComm` et ses mécanismes natifs. Les éventuelles
tables CDav supplémentaires sont limitées à la configuration sans équivalent
natif et aux états techniques : publication, idempotence, limitation des abus.
Chaque stockage supplémentaire doit être justifié dans le journal de décision.

Le service lit une période explicite. Il ne dépend pas de `CDAV_SYNC_PAST` ou
`CDAV_SYNC_FUTURE` : une série commencée avant cette fenêtre peut encore occuper
une date proposée. Les récurrences natives et celles conservées dans les
métadonnées CDav sont rapprochées sans compter deux fois une même occurrence.

Réutiliser les données de récurrence existantes et les outils Sabre/VObject
fournis avec chaque cœur cible. Définir les traitements des dates sans durée,
des exceptions annulées/déplacées et des données invalides. Une erreur de lecture
ou un plafond d'expansion atteint rend le calcul indisponible ; il ne transforme
pas une période incertaine en créneau libre.

Les intervalles suivent la convention `[début, fin)` : deux RDV peuvent se suivre
exactement si aucune marge n'est demandée. Les marges entourent le candidat et
chaque occupation ; la séparation minimale vaut avant + après, indépendamment
de l'ordre de création des rendez-vous. Le statut numérique Dolibarr ne doit
pas être assimilé sans vérification à une annulation ou à une occupation.

### 4.2 Interface publique

Créer une page publique CDav et un point d'entrée pour obtenir les créneaux et
confirmer la réservation. La page ne livre que les heures réservables, sans les
détails ni les identifiants des événements qui les bloquent.

Les pages publiques BookCal 23/24 examinées n'exposent pas de hook permettant de
remplacer proprement leur calcul. La réservation V1 utilise donc le lien CDav.
Pour limiter les dépendances, privilégier des réglages CDav autonomes. Si la
reprise de plages BookCal existantes réduit effectivement le travail, la décider
en lot L0 et tester aussi l'absence ou la désactivation de BookCal. Ne pas
développer deux moteurs de planification pour cette V1.

### 4.3 Confirmation et concurrence

Dans une transaction, acquérir un verrou par propriétaire compatible avec celui
des écritures CalDAV, puis relire les occupations après acquisition du verrou.
La relecture doit voir les écritures précédemment validées, même avec le niveau
d'isolation du SGBD utilisé. Valider de nouveau le profil, ses horaires, les
droits, la durée, les marges et le jeton avant de créer l'`ActionComm` occupée.

Garanties V1 à démontrer : une seule réservation publique réussit sur des
créneaux qui se chevauchent ; un conflit déjà validé et visible lors de la
confirmation empêche la réservation ; rejouer la même demande ne crée pas un
second événement.

La validité des demandes et la conservation des clés d'idempotence sont fixées
ensemble en L0. Une demande expirée est refusée ; après purge de son état
technique, elle ne doit pas redevenir une nouvelle réservation, même si le
premier événement a été déplacé ou supprimé. Couvrir la période de validité et
les délais de reprise sans imposer un stockage technique illimité.

Limite explicite : le verrou CDav ne constitue pas une interdiction globale des
chevauchements. L'interface Agenda native ne partage pas nécessairement ce
verrou et les écritures DAV permettent de superposer des événements. Un
professionnel peut donc ajouter un RDV concurrent ou ultérieur. Une exclusion
globale entre tous les canaux exigerait une politique native supplémentaire.
Ne pas annoncer cette garantie dans la V1.

### 4.4 Fichiers prévus

Les noms ci-dessous sont indicatifs ; le lot L0 confirme le découpage utile.

| Fichier ou ensemble | Rôle |
|---|---|
| `class/CDavBookingService.php` | Accès aux occupations, génération et confirmation des créneaux ; extraire le calcul pur si cela facilite les tests |
| `public/booking.php` et un point d'entrée HTTP associé si nécessaire | Page publique, lecture bornée des disponibilités et soumission |
| `admin/setup.php`, `core/modules/modCDav.class.php` | Réglages, activation, droits de gestion et lien à copier/révoquer |
| `lib/cdav.lib.php`, au besoin un lecteur dédié | Lecture sur période explicite et réutilisation des récurrences sans modifier la fenêtre de synchronisation habituelle |
| `sql/`, uniquement si nécessaire | État technique minimal, contraintes et migration idempotente |
| `langs/fr_FR/cdav.lang`, `langs/en_US/cdav.lang` | Traductions des réglages, du formulaire et des erreurs |
| `tests/booking.php`, `tests/booking_live.php` | Tests métier déterministes et tests HTTP/base réelle |
| `README.md` et guide français | Installation, exploitation, limites et dépannage |

### 4.5 Priorité au code natif et conformité Dolibarr

Avant d'ajouter une fonction ou un stockage, rechercher l'équivalent dans les
deux cœurs cibles et dans CDav. Consigner les API retenues et justifier les ajouts
qui couvrent un manque réel. Éviter les duplications de règles métier et les
surcouches qui ne font que réexposer une API existante.

- Créer et modifier les objets métier via leurs classes natives, en conservant
  validations, affectations, transactions et triggers. Aucun SQL direct de
  remplacement pour écrire les événements ou les fiches clients.
- Utiliser les droits natifs (`User::hasRight`) et les mécanismes d'entité
  (`getEntity`) ; vérifier l'utilisateur actif, les dépendances et le périmètre
  réellement autorisé à chaque action publique.
- Réutiliser le chargement d'environnement, les entrées typées (`GETPOST`), les
  formulaires, protections de soumission, échappements, traductions, constantes
  de configuration et journaux natifs, adaptés au contexte public.
- Utiliser l'abstraction de base Dolibarr et les bibliothèques fournies avec les
  cœurs 23/24. Localiser, expliquer et tester toute adaptation liée à une
  différence de version ; conserver le cœur et les dépendances sans modification.
- Vérifier les métadonnées du module : `phpmin`, `need_dolibarr_version`, droits,
  dépendances, activation et migrations. Maintenir le minimum PHP existant tant
  qu'aucune nécessité technique justifiée n'impose de le relever.
- Relire la structure, les responsabilités, la duplication, la gestion d'erreur
  et les coûts des accès SQL ; corriger les avertissements applicatifs. Identifier
  séparément les dépréciations du cœur ou des bibliothèques, sans masquer
  globalement les erreurs pour obtenir un résultat favorable.

## 5. Lots de réalisation

Avancer dans l'ordre des dépendances. Une case de lot se coche seulement lorsque
ses critères et les preuves correspondantes sont renseignés.

### L0 — cadrage technique et état de référence

- [x] Relever versions Dolibarr/PHP/SGBD, bibliothèques Sabre et environnement de
  tests réellement disponibles ; préparer les quatre combinaisons 23/24 × PHP
  8.4/8.5 et fixer la compatibilité effectivement vérifiable.
- [x] Rejouer les suites existantes et conserver leurs résultats avant modification.
- [x] Inventorier les API natives utiles dans les deux cœurs et justifier les
  services/stockages CDav supplémentaires avant leur implémentation.
- [x] Fixer le stockage du profil, la reprise éventuelle des plages BookCal,
  l'acteur métier de la réservation et ses droits effectifs.
- [x] Définir le comportement des événements sans durée, des données invalides,
  des tâches, des annulations et de la révocation pendant une demande.
- [x] Fixer les bornes : horizon, durée/pas, expansion des récurrences, taille des
  requêtes, fréquence des demandes, validité des formulaires, fenêtre de reprise
  et conservation des états techniques.

Sortie : décisions écrites, jeu de données de recette défini et état initial
mesuré. Aucun résultat nouveau attribué aux seuls tests du point de départ.

### L1 — disponibilités et règles horaires

- [x] Implémenter la lecture par utilisateur/entité/période, y compris anciennes
  séries et récurrences natives ou CDav.
- [x] Normaliser les fuseaux et occurrences, fusionner les occupations, appliquer
  les marges puis soustraire les intervalles aux plages proposées.
- [x] Valider les horaires, pauses, pas, préavis et horizon côté serveur.
- [x] Couvrir les cas métier A01 à A07 avec une horloge de test fixe.

Sortie : calcul déterministe ; aucune date proposée en cas d'incertitude ; tests
métier réussis sur les bibliothèques des deux versions Dolibarr cibles.

### L2 — réservation native, transaction et rejouabilité

- [x] Créer les événements par API native avec affectation, durée, occupation,
  coordonnées et provenance correctes ; vérifier les droits avant l'appel.
- [x] Implémenter verrou, relecture fraîche, contrôle final et rollback complet.
- [x] Ajouter une clé de demande pour l'idempotence, liée au profil et au contenu,
  avec contrainte d'unicité et durée de conservation documentées.
- [x] Exposer un point d'entrée HTTP minimal de confirmation, contrôlé par
  activation, jeton et droits, pour tester le service avant l'ajout du formulaire.
- [x] Exécuter les scénarios B01 à B06 avec plusieurs workers HTTP et connexions DB.

Sortie : un seul événement pour les demandes concurrentes ou rejouées, refus
compréhensible d'un créneau devenu occupé, aucune écriture partielle.

### L3 — publication, administration et parcours client

- [x] Ajouter les réglages, leur validation, le lien public et sa révocation.
- [x] Ajouter la page et compléter le point d'entrée HTTP de L2 ; utiliser une
  validation serveur commune à l'affichage et à la confirmation.
- [x] Implémenter jetons aléatoires dédiés, contrôle des requêtes publiques,
  protection contre les soumissions forcées et limitations des abus.
- [x] Vérifier français/anglais, mobile, clavier, erreurs et confirmation à l'écran.
- [x] Passer les scénarios S01 à S06 et U01 à U03.

Sortie : parcours public complet utilisable sans compte Dolibarr, aucune fuite
des événements internes, impossibilité de réserver avec un lien révoqué.

### L4 — compatibilité, régressions et exploitation

- [x] Passer la matrice Dolibarr/PHP et les suites DAV existantes, dont la suite live.
- [x] Vérifier installation, mise à niveau, activation rejouée et désactivation.
- [x] Mesurer charge, latence, mémoire, requêtes SQL et expansion des récurrences.
- [x] Faire une revue indépendante du code, des droits, des transactions, du
  stockage, de la confidentialité et de la conformité native décrite en 4.5 ;
  corriger les défauts matériels relevés.
- [x] Vérifier le nettoyage des tests et la restauration des configurations.

Sortie : scénarios R01 à R04 réussis, limites d'exploitation mesurées et aucun
défaut bloquant ou majeur ouvert.

### L5 — documentation et livraison locale

- [x] Documenter réglages, horaires/fuseau, lien public, révocation, confidentialité,
  périmètre des occupations, concurrence et procédure de dépannage.
- [x] Consigner commandes, versions, résultats, mesures et éventuelles limites
  dans une preuve de validation versionnée, sans secrets ni données clients.
- [x] Produire un ZIP reproductible et vérifier installation/mise à niveau depuis
  ce ZIP sur les environnements de test ; exclure configurations et données locales.
- [x] Créer des commits cohérents avec une description fidèle de leur validation.
- [x] Vérifier tous les critères de fin et renseigner le bilan de réalisation.

Sortie : module installable et vérifiable localement, documentation à jour et
preuves accessibles depuis cette feuille de route. La publication distante
n'entre pas dans le périmètre de cette livraison.

## 6. Matrice des tests et critères de qualité

### 6.1 Calcul métier — automatisé sans base de données

| ID | Scénario | Résultat à vérifier |
|---|---|---|
| A01 | RDV 14–15 h, chevauchement partiel, occupation incluse/englobante, occupations imbriquées | Aucun créneau chevauchant ; intervalles fusionnés sans trou artificiel |
| A02 | Deux RDV adjacents, pauses, marges avant/après et fin de plage | Bornes `[début, fin)` respectées ; aucun dépassement après application des marges |
| A03 | Minuit, journée entière, plusieurs jours, durée absente/invalide | Traitement conforme aux décisions L0 ; aucune libération silencieuse |
| A04 | `RRULE`, `RDATE`, `EXDATE`, `RECURRENCE-ID`, occurrence déplacée/annulée | Dates exactes et pas de double comptage ; série ancienne toujours prise en compte |
| A05 | Passages été/hiver `Europe/Zurich`, UTC et heures locales | Heures inexistantes/ambiguës traitées explicitement ; heure affichée égale à l'heure enregistrée |
| A06 | Préavis, horizon, date passée, pas/durée/plage invalides | Bornes inclusives/exclusives définies et identiques à l'affichage et à la confirmation |
| A07 | Libre/occupé, autre utilisateur, tâche exclue, données malformées et plafond de calcul | Périmètre métier respecté ; erreur explicite si le résultat n'est pas fiable |

Les tests contrôlent des résultats métier, sans se limiter à rechercher des
chaînes dans le code. Les cas nominaux sont complétés par les bornes et les
échecs ; l'horloge et les données sont fixes pour éviter les tests intermittents.

### 6.2 Réservation — HTTP et base de données réelle

| ID | Scénario | Résultat à vérifier |
|---|---|---|
| B01 | Réservation publique puis lecture native et CalDAV | Un `ActionComm` correctement affecté et occupé ; événement visible en sync initiale et incrémentale |
| B02 | Page ouverte, puis création/déplacement d'un RDV Agenda ou DAV avant confirmation | Ancienne proposition refusée ; nouvelle lecture correcte ; suppression/libération réouvre les créneaux |
| B03 | Au moins deux workers réservant simultanément le même créneau ou des départs différents qui se chevauchent | Exactement un succès et un événement ; autres réponses de conflit ; aucun succès contradictoire |
| B04 | Créneaux distincts, deux propriétaires, réservation/DAV dans les deux ordres | Réservations indépendantes possibles ; frontière de concurrence documentée ; écritures DAV déjà validées prises en compte |
| B05 | Double clic, réponse perdue, clé identique/contenu différent, rejeu expiré après déplacement/suppression du RDV | Demande encore valide : même réservation ; contenu divergent ou demande expirée : refus, même après purge ; aucune duplication ni mutation implicite |
| B06 | Échec de validation native, trigger en erreur, perte de droits, profil/jeton révoqué avant contrôle final | Rollback complet, aucun état technique incohérent ; nouvelle tentative possible selon le contrat défini |

La concurrence doit être réelle : plusieurs processus HTTP et connexions DB,
départ synchronisé et vérification du nombre de lignes après commit. Une suite
séquentielle ou un serveur mono-worker ne valide pas B03.

### 6.3 Sécurité, confidentialité et résistance aux abus

| ID | Scénario | Résultat à vérifier |
|---|---|---|
| S01 | Jeton absent/invalide/révoqué, fonction désactivée, propriétaire inactif | Aucun créneau ni réservation ; aucune révélation sur l'existence d'un utilisateur |
| S02 | Falsification d'utilisateur, entité, dates, durée, réglages et champs inattendus | Autorité déterminée côté serveur ; aucune lecture/écriture hors du profil publié |
| S03 | Contenus XSS/SQL hostiles, coordonnées trop longues, mauvais encodage et type de requête | Validation/échappement adaptés ; aucune exécution, erreur SQL ou détail interne exposé ; GET sans création d'événement métier |
| S04 | Sentinelles privées dans titres, notes, participants, pièces jointes et métadonnées | Aucune sentinelle dans HTML/JSON/erreurs publics ; aucun identifiant d'événement interne exposé |
| S05 | Requêtes massives, horizon abusif, récurrence coûteuse et soumissions forcées | Limites avant traitement coûteux, réponse `429` si nécessaire ; erreurs sans créneaux trompeurs |
| S06 | Cookies/session, origine de soumission, jeton dans liens et journaux, réponses en cache | Protection adaptée au formulaire public ; pas de délégation de session administrateur ; secrets/coordonnées absents des journaux usuels et caches partagés |

Le jeton de publication est distinct des clés ICS/DAV, généré avec une source
aléatoire cryptographique et stocké sous forme non réversible. Il autorise
uniquement la réservation du profil publié. Prévoir rotation/révocation,
comparaison sûre, absence de ressources tierces susceptibles de recevoir le lien
et politique de référent adaptée. Le possesseur du lien peut réserver ; ce lien
ne vérifie pas l'identité d'un client.

Ne pas copier les exemptions de session/CSRF du flux ICS dans un point d'entrée
qui écrit. Les limites d'abus doivent fonctionner entre plusieurs workers,
respecter la configuration des proxies de confiance et garder une taille bornée.

### 6.4 Utilisation, compatibilité et exploitation

| ID | Scénario | Résultat à vérifier |
|---|---|---|
| U01 | Parcours téléphone et bureau, français et anglais | Créneau, fuseau, durée et confirmation lisibles ; traductions complètes |
| U02 | Navigation au clavier, labels, focus, lecteur d'écran et erreurs | Champs nommés, erreur associée au champ, confirmation annoncée, choix indépendant de la couleur |
| U03 | Aucun créneau, conflit après affichage, connexion interrompue, rafraîchissement | Message utile, retour aux disponibilités et reprise sans réservation doublée |
| R01 | Suites actuelles CardDAV/CalDAV/ICS, photos et documents | Résultats inchangés hors évolutions explicitement prévues ; pas de warnings applicatifs nouveaux ni d'erreurs SQL |
| R02 | Installation/migration rejouées, désactivation/réactivation, ancien profil révoqué | Schémas idempotents, réglages conservés selon le contrat, aucune activation automatique ni résurrection d'un jeton révoqué |
| R03 | Charge avec 1 000 événements, 100 séries et un horizon de 60 jours | Mesures reproductibles ; cible initiale p95 ≤ 1 s pour les disponibilités sur l'environnement local de référence, à confirmer en L0 |
| R04 | Fin de suite et échecs provoqués | Aucune donnée test résiduelle, fichier orphelin ou constante altérée ; nettoyage dans un bloc garanti |

Pour R03, consigner matériel, versions, nombre de workers, simultanéité, nombre
de requêtes, p95, mémoire maximale et volume SQL. Fixer en L0 les budgets mémoire
et temps maximum ainsi que le comportement au dépassement. Un cache éventuel ne
remplace jamais le contrôle final dans la transaction.

## 7. Exécution des contrôles

### 7.1 Contrôles existants à conserver

À exécuter depuis la racine du module pour chacun des cœurs cibles, avec une
version PHP compatible :

```sh
git diff --check
rg --files -g '*.php' -0 | xargs -0 -r -n 1 php -l
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs php tests/run.php
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs php tests/freebusy.php
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs php tests/contact_photos.php
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs php tests/attachments.php
```

La matrice de réalisation ci-dessous distingue la qualité du module du bon
fonctionnement dans une instance complète. Les résultats antérieurs de la
section 2 ne valident pas encore la réservation.

| Dolibarr | PHP | Lint et suites CDav | Démarrage Dolibarr | HTTP/base et concurrence | Statut de compatibilité |
|---|---|---|---|---|---|
| 23 | 8.4 | Réussi | Réussi | Réussi | Module validé ; voir les preuves |
| 24 | 8.4 | Réussi | Réussi | Réussi | Module validé ; voir les preuves |
| 23 | 8.5 | Réussi | Réussi | Réussi | Module validé ; dépréciations natives consignées |
| 24 | 8.5 | Réussi | Réussi | Réussi | Module validé ; dépréciations natives consignées |

Exécuter explicitement les scripts avec `php8.4` et `php8.5` lorsque ces binaires
sont disponibles, et utiliser un serveur HTTP de la même version pour les tests
live. Consigner les versions exactes du cœur et des dépendances. Le lint seul
ne constitue pas une preuve de compatibilité.

Pour PHP 8.5, rechercher et corriger les incompatibilités propres à CDav. Si le
cœur ou une bibliothèque fournie empêche une combinaison, reproduire et décrire
l'obstacle, les contrôles tentés et le périmètre non validé, sans annoncer un
support complet. Les dépréciations connues des dépendances sont identifiées
séparément ; elles ne justifient pas de masquer les warnings applicatifs. Vérifier
aussi le minimum PHP annoncé par le module lorsque les modifications l'affectent.

### 7.2 Contrôles à ajouter

Commandes proposées pour les nouveaux scripts, à rendre exécutables aux lots
correspondants ; ces fichiers n'existent pas encore au stade de ce plan :

```sh
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs php tests/booking.php
CDAV_LIVE_TEST=1 CDAV_BOOKING_TEST_FIXTURES=1 \
  DOLIBARR_ROOT=/chemin/vers/instance-test/htdocs \
  CDAV_BOOKING_LIVE_URL=http://127.0.0.1/custom/cdav/public/booking.php \
  php tests/booking_live.php
```

Réutiliser les protections de la suite live existante : opt-in explicite, base
nommée comme base de test, comptes/événements préfixés, nettoyage par API native,
restauration des constantes et secrets limités au processus de test. Les
scénarios de concurrence exigent un serveur multi-worker. Utiliser une instance
réellement isolée et vérifier sa configuration avant toute écriture de test.

Rejouer également la suite HTTP actuelle :

```sh
CDAV_LIVE_TEST=1 \
  DOLIBARR_ROOT=/chemin/vers/instance-test/htdocs \
  CDAV_LIVE_URL=http://127.0.0.1/custom/cdav/server.php/ \
  php tests/live_integration.php
```

Prévoir un point d'entrée commun pour les vérifications locales/CI, sans imposer
un nouveau framework. Si un analyseur statique est utilisé, le configurer sur les
fichiers modifiés et les API natives, conserver son résultat et traiter ses
alertes pertinentes. La revue manuelle reste nécessaire pour les droits et les
transactions. Une preuve navigateur, automatisée si l'outillage est disponible,
complète les tests HTTP pour U01 à U03.

## 8. Critères de fin de l'objectif

- [x] L0 à L5 terminés et décisions de périmètre appliquées.
- [x] A01–A07, B01–B06, S01–S06, U01–U03 et R01–R04 vérifiés ; chaque ligne a une
  preuve ou une justification de périmètre actée avant réalisation.
- [x] Tests rapides et live réussis sur la matrice annoncée ; aucune vérification
  indispensable remplacée par un test simulé ou une affirmation.
- [x] Dolibarr 23 et 24 validés sous PHP 8.4 ; combinaisons PHP 8.5 testées, avec
  correction des incompatibilités CDav et preuve des éventuelles limites externes.
- [x] Concurrence réelle, idempotence et rollback démontrés sur le SGBD cible.
- [x] Revue indépendante terminée ; aucun défaut bloquant/majeur, fuite de données
  ou possibilité de double réservation publique connue laissée ouverte.
- [x] Conformité native de la section 4.5 revue, API réutilisées documentées et
  ajouts CDav justifiés ; métadonnées et migrations cohérentes avec la compatibilité.
- [x] Documentation, paquet installable et procédure de désactivation vérifiés.
- [x] Preuves de validation, commits et limites réelles renseignés ci-dessous.

Texte de référence pour l'objectif :

> Réaliser la réservation publique CDav conformément à
> `doc/feuille-de-route-reservation-rdv.md`, en suivant L0 à L5. Garder Dolibarr
> comme source métier, compléter les tests et les preuves à chaque lot, puis
> livrer le module documenté, testé et packagé localement. Valider Dolibarr 23 et
> 24 sous PHP 8.4 et rechercher la compatibilité PHP 8.5 sur les deux versions,
> en documentant toute limite du cœur ou des dépendances. Privilégier les API,
> droits, classes métier et conventions natifs Dolibarr avant d'ajouter des
> fonctions. Faire une revue qualité indépendante. Ne déclarer l'objectif atteint
> que lorsque les critères de fin du fichier sont satisfaits.

## 9. Suivi et preuves

### Journal de décision

| Date | Lot | Décision et raison | Conséquence sur les tests |
|---|---|---|---|
| 2026-11-17 | Préparation | Plan de réservation publique CDav ; agenda principal dans Dolibarr ; objectif à créer ultérieurement | Tous les lots de réservation restent à réaliser |
| 2026-11-17 | L0 | Cadrage natif, profil autonome, événements occupés, erreurs fermées, ticket 15 min et bornes techniques | [Décisions et preuves](validation-reservation-rdv.md) |
| 2026-11-17 | L5 | Archive reproductible, installations et mises à niveau natives contrôlées sur les quatre combinaisons ; suites lancées depuis l’extrait | Qualification terminée et sources applicatives gelées au commit `df43a6d` |
| 2026-11-17 | Contraintes de l'objectif | Demande de réalisation avec priorité au code natif, revue qualité et compatibilité Dolibarr 23/24, PHP 8.4 et si possible 8.5 | Matrice explicite de quatre combinaisons ; preuves séparées CDav et instance complète |

### Journal de validation à compléter pendant la réalisation

| Date / commit | Lot / IDs | Environnement et commande ou procédure | Résultat et preuve | Limite / suite à donner |
|---|---|---|---|---|
| 2026-11-17 / `df43a6d` | L0–L5 / les 26 scénarios | Dolibarr 23/24 × PHP 8.4/8.5 ; tests métier, HTTP, concurrence, navigateur, PHPCS et archive installée | [Décisions et preuves](validation-reservation-rdv.md) ; rapports JSON dans `dist/qa/` | Dépréciations natives et limites V1 documentées |
| 2026-11-17 / renforcement avant publication | Non-régression continue | Six suites sans base × quatre combinaisons, A08–A12 et 24 plannings déterministes ; PHPCS natif 23/24 et validation du workflow | Réussis localement ; [guide des tests](../tests/README.md), CI sur push/PR | Suites HTTP/navigateur de qualification conservées, hors CI rapide |

### Bilan final à compléter

- Commit de réalisation : `df43a6d` ; clôture documentaire dans l’historique Git.
- Preuves : [rapport de validation](validation-reservation-rdv.md), `dist/qa/package-validation.json` et `dist/qa/booking-release-manifest.json`. Paquet : `dist/module_cdav-6.0.0.zip`.
- Matrice validée : Dolibarr 23.0.4 et 24.0.0 × PHP 8.4.26 et 8.5.11, MariaDB 10.6.23 ; sept parcours navigateur par combinaison.
- Limites : périmètre V1 de la section 3, routage natif nécessaire pour les autres entités, absence d’interdiction globale des chevauchements créés depuis l’Agenda/DAV, dépréciations du cœur/Sabre consignées.

## Évolutions demandées après la livraison 6.0

- [x] Remplacer le choix à l'aveugle d'une date par un mois avec jours réellement
  disponibles, navigation bornée et maintien du choix direct par date.
- [x] Permettre jusqu'à six mois calendaires, avec borne de fin de mois et
  conservation des anciens profils exprimés en jours.
- [x] Expliquer les profils dont durée, marges et pas ne permettent aucun créneau.
- [x] Autoriser indépendamment par profil les créneaux consécutifs et les
  plages séparées ou réparties sur plusieurs jours ; conserver les deux options
  désactivées par défaut.
- [x] Créer toutes les parties via `ActionComm`, dans une transaction commune,
  avec contrôle des droits, marges, disponibilités, concurrence et rejouabilité.
- [x] Reprendre le logo et le nom de l'entreprise, puis l'identité, la photo et
  les coordonnées professionnelles natives en respectant les masquages publics.
- [x] Utiliser aussi le logo natif de l'entreprise comme icône de l'onglet de
  réservation (correctif 6.1.2).
- [x] Ajouter tests métier, rendu et HTTP réels ; exécuter les 48 parcours
  navigateur sur la matrice 23/24 × PHP 8.4/8.5 et contrôler le style natif.
- [x] Qualifier le ZIP 6.1 installé et mis à niveau : profils et liens conservés,
  36 exécutions de suites rapides, douze suites HTTP, 48 parcours navigateur et
  quatre validations DAV après correction du nettoyage des tiers de test.
- [x] Publier les commits et retirer les instances jetables après conservation
  des preuves.

## Sujet et adresse d'intervention demandés après la livraison 6.1

- [x] Demander au client un sujet et une adresse d'intervention, obligatoires
  pour toute nouvelle réservation ; conserver les valeurs en cas d'erreur.
- [x] Utiliser les champs natifs `ActionComm::label` et `location`, sans table
  supplémentaire ; répéter les détails sur chaque partie d'un rendez-vous.
- [x] Respecter les limites natives sans troncature : sujet de 120 caractères
  UTF-8, adresse multiligne de 128 caractères ; normaliser les fins de ligne.
- [x] Afficher les détails échappés dans la confirmation, les exclure des URL
  et des réponses publiques de disponibilité et de reçu.
- [x] Préserver le rejeu des anciens reçus 6.1 et refuser la création incomplète ;
  inclure les nouveaux détails dans le contrôle d'idempotence.
- [x] Ajouter les tests métier et les régressions HTTP/navigateur ; valider les
  quatre combinaisons Dolibarr 23/24 × PHP 8.4/8.5 et la revue indépendante.
- [x] Qualifier le paquet 6.1.1 : 40 suites rapides, 288 rendus, huit suites HTTP,
  48 parcours navigateur, PHPCS natif et 120 contrôles HTTP des scripts de test.
- [x] Préparer la livraison reproductible pour `master` et `23.0` ; retirer les
  quatre instances jetables après conservation des preuves dans
  `dist/qa/details-validation/`, dont `teardown.json`.

## Références locales

- [Guide actuel : disponibilités, documents et photos](disponibilites-documents-photos-fr.md).
- [Bibliothèque de lecture/export Agenda](../lib/cdav.lib.php).
- [Écritures CalDAV et verrou utilisateur existant](../class/CalDAVDolibarr.php).
- [Filtre de confidentialité Free/Busy](../class/CDavFreeBusyFilter.php).
- [Suite HTTP et base réelle existante](../tests/live_integration.php).
- [Principes de la réalisation DAV P1/P2](implementation-p1-p2.md).
