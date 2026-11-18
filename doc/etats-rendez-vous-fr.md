# État des rendez-vous dans Dolibarr et CDav

La version 6.4.0 ajoute le champ complémentaire natif **État du rendez-vous** à
la fiche Agenda. Il distingue trois états : **Provisoire**, **Confirmé** et
**Annulé**. Le titre du rendez-vous et son avancement restent indépendants.

## Installation et utilisation

1. Installer le paquet CDav 6.4.0 puis désactiver et réactiver le module une
   fois pour installer le champ et activer le déclencheur natif.
2. Ouvrir la fiche d'un rendez-vous dans **Agenda**, puis **Modifier**.
3. Choisir son état, enregistrer et lancer la synchronisation des clients.

Les rendez-vous et leurs liens existants sont conservés. Un champ ancien vide
reprend l'état déjà enregistré dans les métadonnées DAV, ou **Confirmé** en
l'absence de métadonnées. La réactivation ne remplace pas un état provisoire
ou annulé par une valeur par défaut.

Sur Dolibarr 24, CDav réutilise le widget natif d'affectation avec une adaptation
de session : elle rétablit notamment la case **Occupé** sans mélanger les
sélections de rendez-vous ouverts dans plusieurs onglets. Les fichiers du
cœur Dolibarr restent inchangés.

## État, disponibilité et réponses aux invitations

| Information | Rôle |
| --- | --- |
| Provisoire / Confirmé | État global du rendez-vous ; ne change pas sa case native **Occupé** |
| Annulé | Conserve la fiche et ses liens, libère toutes les affectations et supprime les rappels à venir |
| Occupé / Libre | Disponibilité personnelle utilisée pour les réservations |
| Accepté / Provisoire / Refusé d'un participant | Réponse individuelle à une invitation, distincte de l'état global |

Un rendez-vous **Provisoire** dont la case **Occupé** est cochée continue donc
à empêcher une réservation sur la même période. L'annulation conserve
l'historique des rappels déjà traités. Un rendez-vous annulé ne peut pas être
réactivé par synchronisation ; créer un nouveau rendez-vous pour le remplacer.

Le propriétaire du rendez-vous, ou un utilisateur disposant du droit natif de
modifier tous les agendas, peut changer l'état global. Un participant peut
répondre à sa propre invitation sans confirmer ou annuler celle des autres.
Les rendez-vous natifs protégés conservent leurs tiers, projets, contacts,
notes et autres informations métier lors d'un changement d'état.
Pour un rendez-vous sans invitation, effectuer le changement d'état séparément
des modifications de titre, de notes ou d'autres informations métier ; un
déplacement conservant la durée peut accompagner le changement d'état.

## Outlook et applications CalDAV

CDav exporte l'état standard `STATUS` et le champ de disponibilité
`X-MICROSOFT-CDO-BUSYSTATUS` utilisé par CalDav Synchronizer. Le passage Outlook
**Afficher comme : Provisoire** est reconnu pour le propriétaire d'un
rendez-vous ; le retour à **Occupé** le confirme. Une modification explicite
de `STATUS` par un autre client reste prise en compte si ce champ Microsoft
est simplement renvoyé sans changement.

L'annulation standard `STATUS:CANCELLED` est distincte du choix Outlook
**Libre**. Une réponse personnelle `PARTSTAT:TENTATIVE` ne rend pas provisoire
l'ensemble du rendez-vous partagé.

L'identifiant du rendez-vous reste stable et sa révision est avancée lors
d'un changement réel. Un renvoi identique n'avance pas la révision. Les
préconditions ETag et les contrôles métier sont vérifiés avant écriture et
dans la transaction native.

Les invitations envoyées par courriel continuent d'utiliser le transport du
client Outlook. Ce champ ne crée pas de passerelle d'envoi de courriels iMIP.
La qualification utilise les bibliothèques natives et des requêtes HTTP
fictives ; l'affichage final sur les appareils Outlook et DAVx⁵ doit être
vérifié après installation.

Références techniques : [code officiel de CalDav Synchronizer](https://github.com/aluxnimm/outlookcaldavsynchronizer/blob/master/CalDavSynchronizer/Implementation/Events/EventEntityMapper.cs),
[statut iCalendar](https://www.rfc-editor.org/rfc/rfc5545#section-3.8.1.11),
[guide des invitations](invitations-outlook-davx5-fr.md).
