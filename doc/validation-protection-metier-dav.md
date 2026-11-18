# Qualification CDav 6.3.0 : protections métier et projets ouverts

Qualification du 18 novembre 2026. Le paquet candidat provient du commit
`bd4d3dfc33fe9ea465e549e564cb239ffa2f119c`. Les changements ultérieurs concernent
uniquement la documentation ; l'équivalence des fichiers exécutables est
contrôlée avant la génération du ZIP final.

## Matrice exécutée

| Dolibarr | PHP | Contrôles rapides | DAV et réservation HTTP | Navigateur | Migration |
|---|---|---|---|---|---|
| 23.0.4 | 8.4.26 | Réussi | Réussi | Réussi | Réussi |
| 23.0.4 | 8.5.11 | Réussi | Réussi | Réussi | Réussi |
| 24.0.0 | 8.4.26 | Réussi | Réussi | Réussi | Réussi |
| 24.0.0 | 8.5.11 | Réussi | Réussi | Réussi | Réussi |

Les quatre instances utilisent des bases MariaDB isolées, les API natives,
un serveur HTTP à plusieurs workers et le module extrait du ZIP candidat.
Les versions de cœur sont identifiées dans
`dist/qa/business-validation/environments.json` ; cette qualification ne couvre
pas les autres SGBD ni toutes les versions futures des branches Dolibarr.

## Résultats et couverture

- 68 exécutions de suites PHP unitaires et de composants, 4 exécutions de la
  suite JavaScript et 488 rendus de formulaire. Le lint PHP et JavaScript passe.
- 12 suites HTTP natives : DAV, réservation simple et réservation groupée,
  sur chacune des quatre combinaisons. Les cas métier BL01–BL27 couvrent
  notamment la provenance, les déplacements, les champs protégés, les
  participants, les contacts et adhérents, les droits et les transactions.
- 112 cas navigateur, soit 28 par combinaison, incluant clavier, mobile et
  les parcours contrôlés avec axe. Les cinq groupes ajoutés vérifient le
  sélecteur de projets, les envois forgés, la fermeture du projet, le maintien
  d'un ancien rattachement et son retrait explicite.
- 208 accès HTTP GET/POST aux scripts PHP de test : refus 404 à corps vide.
- Activation initiale et migration depuis le schéma sans provenance, sur les
  quatre combinaisons : identifiants préservés, anciens événements protégés,
  réactivation idempotente et origine CalDAV explicite conservée.

Les essais de concurrence vérifient le verrouillage avant lecture des objets
natifs, la conservation d'un tiers/projet réaffecté pendant une écriture DAV,
les préconditions ETag et les réservations concurrentes. Un refus de suppression
par un trigger natif conserve les métadonnées et les octets des pièces jointes
gérées par DAV ; une suppression validée les retire après commit. Les rappels natifs conservent
leurs identifiants, leur provenance et leur état, y compris lorsque la
protection générale est désactivée.

Les droits de projet sont vérifiés pour les descriptions Agenda et les tâches,
avec lecture simple, multiget et invalidation ETag/ctag après changement des
droits sans modification du rendez-vous. Les contacts et interventions suivent
également les contrôles d'accès natifs de leur objet.

## Projets ouverts

Le sélecteur conserve les composants natifs `FormProjets` et `Form::selectarray`
et leur affichage Select2. Les brouillons, projets fermés et projets d'un autre
tiers sont absents de la liste, y compris lorsque la recherche globale des
projets est activée. Une régression de sanitisation native du filtre SQL a été
corrigée et couverte avec le véritable pilote Dolibarr et le navigateur.

Une soumission forcée est refusée sans modifier le profil enregistré. Fermer
le projet invalide aussi un formulaire public déjà ouvert, sans créer de
rendez-vous ni de reçu. Réouvrir le projet rétablit le même lien public. Un
ancien rattachement indisponible reste conservé jusqu'à un remplacement ou
un retrait explicitement confirmé.

## Qualité du code

Les nouvelles classes et les fichiers inclus dans `tests/qa.sh` passent PHPCS
avec les standards natifs des deux versions Dolibarr. Les anciens fichiers DAV
conservent une dette de style : une comparaison avec `6f0dd52` vérifie l'absence
de nouveau diagnostic sur les lignes ajoutées ou remplacées et d'augmentation
par règle. Il ne s'agit donc pas d'une déclaration de conformité complète de
tout le code historique. Deux relectures ciblées n'ont relevé aucun problème
métier bloquant ; les observations de style et de tests ont été corrigées.

Les preuves de comparaison et de revue sont conservées dans
`dist/qa/business-validation/phpcs-*.json` et `independent-review.json`.

## Limites de la recette

La présentation Outlook est vérifiée par le contenu iCalendar et son
aller-retour, sans automatisation de l'application Outlook. Les confirmations
email gardent leur couverture de composants ; la livraison SMTP réelle n'est
pas réexécutée dans cette qualification et les envois des instances jetables
sont désactivés.

Les journaux HTTP relèvent 26 avertissements natifs par combinaison sur la
variable `QUERY_STRING` dans `main.inc.php`, lors des visites sans requête avec
le serveur PHP local. Aucun avertissement, dépréciation ou erreur PHP du module
n'a été relevé pendant cette qualification. Le cœur Dolibarr n'a pas été modifié
pour masquer ces diagnostics ; les décomptes sont conservés séparément dans
`runtime-diagnostics.json`.

Les préférences d'export détaillé, la séparation des permissions de déplacement
et suppression et une protection renforcée par rendez-vous restent dans le
[backlog](feuille-de-route-reservation-rdv.md#compléments-à-prévoir).

## Preuves locales et livraison

Les preuves générées sont ignorées par Git dans `dist/qa/business-validation/` :
`candidate-archive.json`, `package-quick-matrix.json`,
`archive-native-matrix.json`, `archive-http-guards.json`,
`migration-matrix.json`, `archive-cleanup.json` et `teardown.json`.
Les journaux et captures navigateur se trouvent dans les quatre répertoires
`dist/qa/business-package-doli*-php*/`.

Le paquet final est `dist/module_cdav-6.3.0.zip`. Son manifeste local
`dist/qa/booking-release-manifest.json` donne le commit, l'empreinte SHA-256,
les contrôles d'archive et les résultats CI de publication. Les quatre bases,
serveurs et arborescences temporaires ont été retirés après contrôle d'absence
de résidus métier ; l'instance locale préexistante et les cœurs partagés sont
préservés.

Après installation, désactiver puis réactiver une fois CDav pour appliquer la
migration ; voir le [guide de protection métier](protection-metier-dav-fr.md).
