# Implémentation DAV P1/P2 — suivi du but

But actif : implémenter les priorités P1 et P2 de la revue DAV, en conservant
Dolibarr comme source métier et d'autorisation, puis livrer une version testée,
packagée, commitée et poussée.

## Règles de réalisation

- Utiliser en premier les classes, droits, triggers et services natifs Dolibarr.
- Réserver les tables CDav aux états purement protocolaires, à la provenance et
  aux journaux indispensables à DAV.
- Ne jamais annoncer une capacité DAV sans backend persistant, ACL, limites,
  suppressions et tests correspondants.
- Mutualiser les validations et l'accès SQL technique ; ne pas dupliquer les
  règles métier déjà fournies par Dolibarr.
- Toute fonction ayant des effets externes est désactivée par défaut et exige
  une activation explicite.

## P1 — fidélité et synchronisation

- [x] Profils sociaux vCard complets : dictionnaire Dolibarr, `IMPP`,
  `X-SOCIALPROFILE`, alias X/Twitter, variantes iOS, fusion non destructive.
- [x] Sidecar vCard sans perte pour propriétés multiples, labels, anniversaire,
  prononciation et champs `X-*`, avec priorité aux champs métier Dolibarr.
- [x] `VALARM` compatible relié à `ActionCommReminder`, sans déclencher
  email/SMS depuis un PUT DAV ; valeurs non compatibles conservées au sidecar.
- [x] Récurrences compatibles reliées aux champs natifs `ActionComm::recur*` ;
  représentation RFC 5545 complète conservée sans perte.
- [x] WebDAV Sync RFC 6578 : journal monotone par entité et collection,
  tombstones, pagination, token invalide/expiré et isolation ACL.
- [x] Auto-découverte RFC 6764 et URL canonique sûre derrière proxy.
- [x] Conflits/idempotence : ETag, `If-Match`, `If-None-Match`, PUT rejoué,
  UID réutilisé, concurrence et retry après réponse perdue.

## P2 — capacités avancées et sensibles

- [x] Scheduling CalDAV persistant : inbox/outbox, iTIP, déduplication,
  expéditeur/destinataire validés, taille/TTL et aucun email implicite.
- [x] Partage/délégation en lecture/écriture dérivé exclusivement des droits et
  groupes Dolibarr, sans élévation possible depuis DAV.
- [x] Pièces jointes gérées RFC 8607 : POST contrôlé, quota, types autorisés,
  antivirus configurable, URL protégée, remplacement/suppression et nettoyage.
- [x] Options P2 désactivées par défaut et documentation d'exploitation.

## Validation et livraison

- [x] Schémas d'installation et migration idempotents.
- [x] Lint de tous les fichiers PHP.
- [x] Tests unitaires/régression sans base.
- [x] Suite HTTP live complète sur la base Dolibarr locale de test.
- [x] Contrôle de résidus, d'orphelins et restauration de la configuration.
- [x] Revue de duplication et simplification finale.
- [x] ZIP d'installation reproductible dans `dist/`.
- [x] Commit atomique avec état Git propre, puis push de la branche.

## Journal de décision

- Les capacités P2 seront conditionnées par des constantes distinctes et
  resteront inactives après mise à niveau.
- Un test qui échoue interdit d'annoncer la capacité correspondante.

## Preuves de validation

- Dolibarr 23 et Dolibarr 24 : lint PHP complet et suite de régression
  `tests/run.php` réussis avec les dépendances natives de chaque version.
- Dolibarr 23 et Dolibarr 24 : suite HTTP `tests/live_integration.php`
  réussie de bout en bout sur une base explicitement réservée aux tests.
- Activation du module rejouée afin de vérifier les schémas et migrations
  idempotents ; aucun avertissement PHP ni erreur SQL pendant les requêtes DAV.
- Concurrence réelle à plusieurs workers vérifiée pour les PUT conditionnels,
  avec exactement une écriture gagnante et une réponse `412`.
- Après chaque suite : aucun utilisateur, contact, tiers, événement, objet de
  scheduling, pièce jointe gérée ou collection de synchronisation orpheline.
- Les configurations Dolibarr 23/24 et les raccordements `custom/cdav` ont été
  restaurés après les tests.
