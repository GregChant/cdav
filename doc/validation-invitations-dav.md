# Qualification CDav 6.3.1 : invitations et réponses

Qualification du 18 novembre 2026 sur le ZIP candidat du commit
`edbf1219af7b4bf1c0615b0fc3c03d65ef1d791b`, SHA-256
`b1c04e1423f8dfc4c81e6f58043f66b9c840b083b6d69cd495ba473a3672cc39`.
Le ZIP final ne diffère de ce candidat que par la documentation ; tous les
fichiers exécutables sont comparés octet par octet avant livraison.

## Matrice exécutée

| Dolibarr | PHP | Suites rapides | DAV et réservations HTTP | INV01–27 | Réactivation |
|---|---|---|---|---|---|
| 23.0.4 | 8.4.26 | Réussi | Réussi | Réussi | Réussi |
| 23.0.4 | 8.5.11 | Réussi | Réussi | Réussi | Réussi |
| 24.0.0 | 8.4.26 | Réussi | Réussi | Réussi | Réussi |
| 24.0.0 | 8.5.11 | Réussi | Réussi | Réussi | Réussi |

Les quatre instances ont leurs propres bases MariaDB et serveurs PHP à plusieurs
workers. Le module testé par HTTP est celui extrait du ZIP, et non un lien vers
les sources en cours de modification. Les identifiants exacts des cœurs et les
versions PHP sont conservés dans `dist/qa/invitation-validation/environments.json`.

## Résultats

- 76 exécutions de suites PHP unitaires et de composants sur le candidat extrait,
  4 exécutions JavaScript et 488 rendus de formulaire, avec lint PHP/JavaScript.
- 12 suites HTTP natives : DAV, réservation simple et réservation groupée sur
  chacune des quatre combinaisons.
- Les nouveaux scénarios INV01–INV27 et les scénarios métier BL01–BL27 passent
  sur les quatre bases. Ils couvrent les réponses, les droits, les révisions,
  les disponibilités, les annulations, la confidentialité et les retours arrière.
- 232 accès GET/POST aux scripts PHP de test sont refusés avec 404 à corps vide.
- Aucun avertissement, dépréciation ou erreur PHP n'est relevé dans les journaux
  HTTP de la qualification finale.

INV27 impose une vraie superposition des requêtes : deux workers sont observés
en attente sur les verrous natifs avant libération de la barrière. Deux réponses
`ACCEPTED` sans précondition ETag réussissent, mais conservent un seul rendez-vous,
un UID et une révision identiques, et produisent une seule réponse à
l'organisateur. Les trois lignes techniques ajoutées correspondent à l'audit
et aux notifications, pas à de nouveaux rendez-vous.

## Comportements vérifiés

Les payloads construits pour CalDav Synchronizer tiennent compte de son
incrément de `SEQUENCE` lors d'une réponse ; ce changement n'est pas importé
comme une nouvelle révision d'organisateur. Le style DAVx⁵ conserve la révision.
Les états accepté, provisoire et refusé sont lus par les deux calendriers du
même événement, sans remplacer les réponses des autres participants ni les
notes et les liaisons natives.

Le refus rend libre uniquement le participant concerné, et une réacceptation
réoccupe son créneau. Un déplacement de l'organisateur, même avec la protection
générale désactivée, conserve les refus et les disponibilités personnelles.
Les vraies préconditions ETag et les anciennes révisions sont contrôlées ; un
organisateur ancien client peut omettre `SEQUENCE` sans désactiver ces contrôles.

Les réponses `POST REPLY` sont liées au principal authentifié, à son affectation,
à l'UID, à l'organisateur et à la révision de l'événement existant. Les identités
substituées et propriétés ambiguës sont refusées sans effet partiel.

Une annulation conserve l'identifiant et les liaisons, libère toutes les
ressources et retire seulement les rappels encore en attente. Un retrait
d'invité reste personnel ; la suppression d'organisateur passe par
`ActionComm::delete()`. Les délégations sont liées aux droits natifs et à
l'identité de l'organisateur prouvée par le propriétaire Agenda.

Les imports extérieurs simulent une invitation Microsoft 365 traitée par
Outlook : import, acceptation, rejeu, changement de sujet/adresse/date/notes puis
annulation. Ils conservent l'UID et l'unique objet natif. L'exception de transport
est refusée sur un événement natif ou partagé, et lorsqu'un organisateur local
ou un UID est substitué.

Les notifications sont calculées depuis les états réellement verrouillés et
committés. Un projet privé visible par l'organisateur reste absent de l'inbox
de l'invité : les descriptions enrichies ne sont pas copiées dans la notification
technique, et la lecture du rendez-vous garde ses propres contrôles natifs.

## Qualité et limites

Les nouvelles classes et suites passent les standards PHPCS Dolibarr 23 et 24.
La dette des anciens fichiers n'est pas présentée comme entièrement corrigée :
la comparaison avec `0cd17f5` constate zéro nouveau diagnostic sur les lignes
ajoutées ou remplacées et aucune augmentation par règle. Une revue indépendante
liée aux empreintes exactes du code ne laisse aucun problème bloquant ouvert.

Les fichiers d'interface de réservation sont identiques à ceux de la version
6.3.0 déjà qualifiée au navigateur. La suite navigateur n'est pas rejouée pour
ce correctif de protocole ; les disponibilités après réponse et annulation sont
vérifiées par les API natives et les tests HTTP.

Les essais ne pilotent pas les applications réelles Outlook, Microsoft 365,
DAVx⁵ ou Agenda et n'envoient aucun email réel. Le transport email des invitations
extérieures reste celui d'Outlook ou de l'application concernée ; CDav n'ajoute
pas de passerelle email. Les modifications ciblant une exception de récurrence
ou une ressource à plusieurs événements restent explicitement refusées.
Le [guide des invitations](invitations-outlook-davx5-fr.md) décrit le paramétrage
et la recette à effectuer sur les appareils.

## Preuves et livraison

Les preuves locales ignorées par Git sont dans `dist/qa/invitation-validation/` :
`candidate-archive.json`, `package-quick-matrix.json`, `archive-native-matrix.json`,
`native-summary.json`, `archive-http-guards.json`, `candidate-reactivation.json`,
`phpcs-*.json`, `independent-review.json`, `archive-cleanup.json` et `teardown.json`.
Les répertoires `package-doli*-php*/` contiennent les journaux et les preuves
d'invitations pour chaque combinaison. Les scripts de reproduction sont
conservés dans les fichiers `.txt` du même répertoire.

La version 6.3.1 ne change pas le schéma depuis 6.3.0. La désactivation puis
réactivation du candidat a été vérifiée sur les quatre instances. Après
qualification, les quatre bases, serveurs et arborescences privés ont été
retirés ; l'instance locale préexistante et les cœurs partagés sont préservés.

Le paquet final est `dist/module_cdav-6.3.1.zip`. Le manifeste local
`dist/qa/booking-release-manifest.json` donne son empreinte SHA-256, le commit
publié et les résultats CI de `master` et `23.0`.
