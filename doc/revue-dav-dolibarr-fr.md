# Revue des possibilités DAV à intégrer à CDav

Date de la revue : 12 septembre 2026. Cibles examinées et testées :
Dolibarr 23 et 24, Sabre/DAV 4.6 et branche amont CDav 3.2.1 (`046acd9`).

## Principe d'architecture

Dolibarr doit rester la source métier et le point d'autorisation. Une écriture
DAV doit donc appeler la classe métier native (`Contact`, `Societe`, `Adherent`,
`ActionComm`, `Task`, `Fichinter`, `Link`, `Categorie`, etc.), afin de conserver
les validations, droits, transactions et triggers. Une table CDav n'est
acceptable que pour une donnée protocolaire sans équivalent Dolibarr, ou pour
la provenance nécessaire à une synchronisation sûre.

Un capability DAV ne doit jamais être annoncé parce que Sabre/DAV fournit un
plugin. Il ne doit l'être qu'après implémentation complète du stockage, des
droits, des changements et des suppressions attendus par la norme.

## État et décisions

| Fonction | Source à réutiliser | Décision | Priorité |
|---|---|---|---|
| Contacts, tiers, adhérents | APIs métier Dolibarr | Déjà raccordé aux créations/mises à jour natives. Un `DELETE` CardDAV désactive l'objet; seule l'interface Dolibarr peut le supprimer définitivement. | P0, fait |
| Rendez-vous et tâches d'agenda | `ActionComm`, ressources et triggers Dolibarr | Déjà raccordé. Les participants connus deviennent des ressources natives. | P0, fait |
| Tâches projet et interventions | `Task`, `Fichinter`, `FichinterLigne` | Déjà raccordé aux méthodes natives; ne pas réintroduire les anciens `INSERT/UPDATE` directs. | P0, fait |
| Pièces jointes de rendez-vous | `Link`, répertoire agenda et `document.php` | Les URI `ATTACH` HTTPS sûres deviennent des liens natifs; les liens Dolibarr et fichiers agenda sûrs ressortent en CalDAV. Les blobs inline restent dans les métadonnées, sans écriture silencieuse sur disque. | P0, fait |
| Documents WebDAV | Module DAV natif Dolibarr | Déléguer à `/dav/fileserver.php/`. Ne jamais monter `DOL_DATA_ROOT` dans le serveur calendrier/contact. | P0, fait |
| Transport TLS/proxy | HTTPS direct + IP de proxy explicitement approuvée | Basic est refusé en HTTP hors boucle locale de test. Les en-têtes HTTPS transférés ne sont acceptés que depuis `CDAV_TRUSTED_PROXY_IPS`. | P0, fait |
| Profils sociaux vCard | Implémentation amont CDav 3.2.1 + dictionnaire `c_socialnetworks` | Implémenté après adaptation aux objets natifs : `IMPP`, `X-SOCIALPROFILE`, alias X/Twitter, variante iOS et fusion sans effacer les réseaux inconnus. | P1, fait |
| Conservation vCard sans perte | Sabre/VObject + table de métadonnées CDav | Sidecar limité aux propriétés sans champ natif : valeurs multiples et labels, prononciation, anniversaire et champs `X-*`. Dolibarr reste prioritaire pour ses champs métier. | P1, fait |
| Alarmes | `ActionCommReminder` | Les `VALARM` relatifs `DISPLAY` compatibles peuvent être projetés par utilisateur avec consentement explicite. Toutes les alarmes restent conservées sans perte et aucun email/SMS implicite n'est émis. | P1, fait |
| Récurrence | Champs natifs `ActionComm::recur*` + métadonnées CDav | Le sous-ensemble représentable est raccordé aux champs natifs; RRULE/RDATE/EXDATE/RECURRENCE-ID restent conservés intégralement au sidecar. | P1, fait |
| WebDAV Sync (`sync-token`) | RFC 6578 + état natif Dolibarr | Journal monotone par entité/collection avec réconciliation de l'état natif, tombstones, pagination, rétention et refus des tokens invalides/expirés. | P1, fait |
| Auto-découverte | RFC 6764, configuration web | Endpoint `well-known.php`, redirections documentées pour `/.well-known/caldav` et `/.well-known/carddav`, URL canonique sûre derrière proxy. | P1, fait |
| Conflits et idempotence | ETag/If-Match, mapping URI/UID | PUT identique rejouable, UID sous une autre URI refusé, préconditions ETag et concurrence vérifiées par la suite live. | P1, fait |
| Planification CalDAV | Sabre Scheduling + agenda/Event Organization Dolibarr | Inbox/outbox persistantes, iTIP local, validation expéditeur/destinataire, déduplication, taille/TTL et aucun transport iMIP implicite. Option désactivée par défaut. | P2, fait |
| Partage/délégation | Droits et groupes Dolibarr + Sabre proxy/sharing | ACL et proxy read/write dérivés exclusivement des droits Agenda natifs; mutation DAV des délégations interdite. Option désactivée par défaut. | P2, fait |
| Pièces jointes gérées | RFC 8607 + répertoire Agenda natif | POST add/update/remove, quotas, pipeline fichier/antivirus Dolibarr, types actifs refusés, identifiants aléatoires, URL protégée par ACL et nettoyage. Option désactivée par défaut. | P2, fait |
| Abonnements distants | Sabre SubscriptionSupport | Faible priorité. Exige stockage, rafraîchissement, cache, limitation de taille et protection SSRF/DNS rebinding. Il est souvent plus sûr de laisser cette fonction au client. | P3 |
| Propriétés mortes / personnalisées | Sabre PropertyStorage | À ajouter uniquement pour les propriétés qu'un client doit réellement relire; backend Dolibarr avec `entity`, ACL, limites et nettoyage. | P3 |

## Éléments à ne pas porter tels quels

La branche amont déclare `SyncSupport`, `SchedulingSupport` et
`SubscriptionSupport`, mais ses méthodes de changements renvoient `null` et
les stores d'abonnement/planification sont vides. Les annoncer produit une
fausse capacité et peut provoquer des synchronisations incomplètes. Un
`MAX(tms)` ou un ctag n'est pas un sync-token : il ne permet pas de retourner les
URI supprimées.

De même, monter un `DAV\FS\Directory` sur la racine des données Dolibarr donne
à un administrateur DAV un accès brut à des répertoires qui ne suivent pas les
droits documentaires métier. Ce comportement a été retiré au profit du module
DAV natif.

## Couverture cible par vagues

La vague P0 sécurise et fiabilise le périmètre actuel : APIs métier natives,
droits, isolation d'entité, soft-delete CardDAV, pièces jointes par `Link`,
limites de ressources et absence d'exposition brute des documents.

La vague P1 implémentée améliore la fidélité réelle entre Outlook, iOS,
Android/DAVx5 et Dolibarr : profils sociaux, sidecar vCard, rappels compatibles,
récurrences natives compatibles, journal de changements et auto-découverte.

La vague P2 implémentée ajoute des fonctions qui ont des effets externes ou une
surface de sécurité importante. Elles restent optionnelles, auditées et testées
avec plusieurs comptes avant activation.

Le détail d'implémentation et les preuves de validation sont suivis dans
[`implementation-p1-p2.md`](implementation-p1-p2.md).

## Références

- [Module CDav Dolibarr](https://wiki.dolibarr.org/index.php/Module_cdav_%28sync_CalDAV/CardDAV_server%29_EN)
- [Module DAV natif Dolibarr](https://wiki.dolibarr.org/index.php/Module_DAV)
- [Sabre/DAV : synchronisation](https://sabre.io/dav/sync/)
- [Sabre/DAV : planification](https://sabre.io/dav/scheduling/)
- [Sabre/DAV : délégation](https://sabre.io/dav/caldav-proxy/)
- [RFC 6578 : WebDAV Sync](https://www.rfc-editor.org/rfc/rfc6578.html)
- [RFC 8607 : pièces jointes CalDAV gérées](https://www.rfc-editor.org/rfc/rfc8607.html)
