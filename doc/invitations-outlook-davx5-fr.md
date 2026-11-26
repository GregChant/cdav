# Invitations Outlook, DAVx⁵ et Agenda Dolibarr

CDav 6.3.1 distingue les réponses personnelles, les changements de
l'organisateur et les invitations extérieures importées dans un agenda
personnel. Le rendez-vous conserve son UID et son identifiant Agenda lors
d'une réponse ou d'une mise à jour ; les liaisons tiers, projet et contacts
ne sont pas réimportées depuis les participants iCalendar.

## Répondre depuis Outlook ou le téléphone

Pour une invitation déjà affectée au collaborateur, sa réponse **Accepté**,
**Provisoire**, **Refusé** ou **En attente** met à jour uniquement sa
participation. Les réponses des autres invités sont conservées, même si le
client en renvoie une ancienne copie. Un refus rend uniquement ce collaborateur
libre ; accepter à nouveau réoccupe son créneau.

Sur une invitation interne active qu’il a acceptée, le collaborateur peut
ensuite choisir **Libre** ou **Occupé** dans son propre calendrier. Ce choix
conserve sa réponse et la disponibilité des autres participants.

Les notes, documents, rappels et liaisons métier restent conservés lorsqu'un
client renvoie une réponse. En particulier, un changement de présentation du
corps ou la perte d'un rappel par Outlook ne remplace pas les données natives.
Le sujet, les dates, l'organisateur et la liste des invités restent contrôlés.
Un simple changement de notes n'est pas une réponse autorisée.

Le téléphone utilise **DAVx⁵ pour synchroniser** et son application Agenda pour
répondre. Après synchronisation, Outlook et les autres clients du même agenda
récupèrent le statut enregistré dans Dolibarr. L'adresse de participation doit
correspondre à l'email de l'utilisateur Dolibarr.

## Modifier ou annuler

L'organisateur ou son délégué disposant du droit de modifier tous les événements
peut corriger le titre, le lieu, les notes libres et la priorité. Pour une
invitation individuelle, il peut aussi changer les horaires, la durée et le
réglage journée entière. Il peut annuler l'invitation explicitement. Chaque
participant reste responsable de sa propre réponse.

Une annulation reçue comme `STATUS:CANCELLED` conserve le rendez-vous et ses
liaisons : CDav l'exporte annulé et les ressources natives deviennent libres.
Les rappels encore en attente sont retirés avec l'API Dolibarr, les rappels
historiques traités sont conservés. L'Agenda natif n'ayant pas de statut générique
d'annulation pour ces événements, le statut iCalendar est conservé dans les
métadonnées CDav, avec l'entrée Agenda devenue libre.

Supprimer une invitation depuis le calendrier de l'organisateur utilise la
suppression native pour tous ses participants, selon les droits. Le retrait
depuis le calendrier d'un invité retire son affectation et transmet son refus ;
il n'annule pas le rendez-vous de l'organisateur. Les rendez-vous ordinaires
sans participants d'invitation conservent leurs règles de suppression partagée.

Les changements de révision et les préconditions ETag empêchent une ancienne
réponse d'écraser un déplacement ou une annulation. Un rejeu inchangé n'ajoute
ni rendez-vous ni notification de réponse.

## Invitation extérieure, notamment Microsoft 365

Le parcours prévu est : réception de l'email dans Outlook, acceptation dans
Outlook, puis synchronisation par CalDav Synchronizer vers CDav. DAVx⁵ récupère
ensuite le même rendez-vous sur le téléphone.

Les changements envoyés par l'organisateur extérieur et traités par Outlook
peuvent actualiser cette copie personnelle, y compris l'annuler. Cette exception
est limitée à un événement créé par CalDAV, possédé uniquement par l'utilisateur
authentifié et sans autre collaborateur affecté. L'UID et l'organisateur restent
identiques. Elle ne permet pas de modifier un rendez-vous natif partagé au nom
d'un organisateur extérieur.

Dans CalDav Synchronizer, vérifier :

- la synchronisation bidirectionnelle du bon calendrier Outlook ;
- le transfert des participants et de l'organisateur ;
- l'email du collaborateur dans l'identité serveur ;
- **Use GlobalAppointmentID for UID attribute** pour les nouveaux événements
  Outlook, afin de conserver un identifiant cohérent avec les invitations.

Les UID déjà synchronisés doivent rester stables. Éviter d'utiliser le sujet et
les dates comme seule règle de rapprochement : deux vrais rendez-vous peuvent
partager ces valeurs. Les réglages des invitations sont décrits dans la
[documentation officielle du connecteur](https://caldavsynchronizer.org/help/documentation/#managing-meetings-and-invites).

CDav accepte les UID iCalendar jusqu'à 1 024 octets, y compris les identifiants
Outlook qui dépassent l'ancienne limite de 255 octets. Après la mise à jour,
réactiver le module CDav dans Dolibarr pour agrandir la colonne des UID. Cette
migration conserve les UID et les adresses des ressources existantes.

Outlook gère les emails entrants et ses propres réponses email. CDav ne relève
pas une boîte Microsoft 365 et n'installe pas de passerelle email iMIP. Depuis
le téléphone, la mise à jour du rendez-vous dans CDav est prise en charge ; un
échange email avec un organisateur extérieur dépend aussi de l'application
Agenda et du transport email utilisé.

## Planification et confidentialité

L'option **Planification CalDAV** (`CDAV_SCHEDULING=1`) active les inbox/outbox
locales et les réponses `POST METHOD:REPLY` des clients qui les utilisent.
La réponse est liée à l'utilisateur authentifié et à une invitation existante ;
elle ne crée jamais un nouvel événement. Les réponses explicites à un
organisateur extérieur n'utilisent pas cet endpoint sans passerelle email.
Le transfert de la réponse par un `PUT` CalDAV fonctionne aussi indépendamment
de l'activation de ces boîtes de planification.

Les événements unitaires et les séries simples dont la ressource conserve un
seul événement maître sont pris en charge. Une modification ou une réponse
ciblant une exception de récurrence, ou une ressource à plusieurs événements,
est refusée explicitement ; un renvoi strictement inchangé reste possible.
Ces cas ne sont pas annoncés comme pris en charge dans cette version.

Les notifications locales sont générées après commit. Leur corps technique
omet les descriptions enrichies afin de ne pas divulguer un projet privé à un
invité. Les clients lisent la description complète par le GET du rendez-vous,
avec leurs propres droits natifs.

## Mise à jour et vérification

La version 6.3.1 n'ajoute pas de schéma depuis 6.3.0. Pour une version plus
ancienne, appliquer aussi la réactivation décrite dans le
[guide de protection métier](protection-metier-dav-fr.md).

La qualification vérifie les payloads CalDAV correspondant à Outlook/CalDav
Synchronizer et DAVx⁵ avec les bibliothèques et bases Dolibarr réelles. Elle
n'automatise pas les applications Outlook, Microsoft 365, DAVx⁵ et Agenda sur
un poste ou un téléphone réel.

Pour la recette sur appareils : accepter une invitation extérieure dans Outlook,
synchroniser le téléphone, répondre ensuite depuis Agenda, puis vérifier le
même UID et un seul RDV. Faire ensuite déplacer et annuler l'invitation par
l'organisateur, synchroniser les deux appareils et vérifier les disponibilités.
