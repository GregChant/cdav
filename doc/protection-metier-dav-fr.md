# Protection des données métier avec CalDAV et CardDAV

CDav 6.3.0 conserve Dolibarr comme source des données métier. Les écritures
utilisent les classes natives et leurs transactions et triggers.

## Rendez-vous

Dans **Configuration du module CDav → Protection des données métier**, l’option
**Protéger les rendez-vous Dolibarr** est activée par défaut
(`CDAV_PROTECT_NATIVE_EVENTS=1`). Elle concerne les événements Agenda créés dans
Dolibarr, les rendez-vous issus de la réservation publique et les anciens
événements dont l’origine n’est pas certaine.

Depuis un logiciel CalDAV, un événement protégé peut être déplacé en conservant
sa durée et son caractère journée entière. Son identifiant reste identique.
Le sujet, le lieu, les notes, les participants, les affectations, les documents,
les rappels et la récurrence se modifient dans Dolibarr. Une tentative de
modification de ces données reçoit une erreur CalDAV explicite ; les différences
de pliage ou d’ordre des propriétés ne constituent pas une modification métier.
L’interface du logiciel agenda peut néanmoins présenter ces champs comme
éditables : CDav contrôle l’enregistrement côté serveur.

Les rappels natifs relatifs encore en attente suivent le déplacement, sans
changer leur destinataire, leur canal ou leur état et sans créer de nouvel
envoi. La révision de planification est avancée par le serveur. Les notifications
de déplacement ou d’annulation sont traitées après validation de la transaction.
Les écritures verrouillent également l’objet natif pour conserver une liaison
changée simultanément dans Dolibarr ou dans un autre calendrier.

La suppression reste soumise aux droits Agenda natifs. Pour un événement affecté
à plusieurs collaborateurs, elle retire uniquement l’affectation au calendrier
concerné. Le retrait de la dernière affectation supprime le rendez-vous par
`ActionComm::delete()`. Le tiers, le projet et les contacts liés restent des
objets indépendants. Déplacer un rendez-vous entre deux calendriers change ses
affectations : cette opération se gère dans Dolibarr pour un événement protégé.

Les nouveaux événements créés depuis CalDAV restent modifiables depuis CalDAV.
Leur origine est enregistrée uniquement lors de leur création ; une lecture ou
une mise à jour d’un événement existant ne peut pas lui attribuer cette origine.
Sur tous les événements existants, les participants renvoyés par CalDAV ne
peuvent pas remplacer le tiers ou les contacts natifs.

Désactiver l’option autorise les modifications CalDAV habituelles des événements
Dolibarr. La conservation de leurs liaisons tiers et contacts reste obligatoire.

## Contacts, tiers et adhérents

La protection des liaisons CardDAV est permanente. Les coordonnées usuelles
restent éditables selon les droits Dolibarr, tandis que les données métier
suivantes sont conservées sur les objets existants :

- identité de synchronisation (UID) ;
- rattachement d’un contact ou d’un adhérent à un tiers ;
- catégories métier ;
- statut et confidentialité du contact ;
- champs de gestion du tiers et de l’adhérent qui ne sont pas des coordonnées.

L’absence d’`ORG`, de catégories ou de confidentialité dans une vCard n’efface
pas les valeurs Dolibarr. Une modification explicite des propriétés protégées
est refusée. À la création d’un contact, une organisation ne peut être rattachée
que si elle correspond sans ambiguïté à un tiers accessible dans Dolibarr.

Les contacts liés à un compte utilisateur se modifient et se désactivent dans
Dolibarr. CDav accepte leur renvoi inchangé sans écriture native, mais refuse
leurs modifications et leur suppression distante : `Contact::update()` peut
sinon répercuter les coordonnées sur le compte utilisateur lié. Les mises à jour
d’adhérents utilisent les paramètres natifs qui empêchent la synchronisation
indirecte d’un utilisateur ou d’un tiers lié.

Un `DELETE` CardDAV reste une désactivation native, sans suppression physique de
l’objet ni de ses relations. Il nécessite les droits et l’accès métier à cet
objet.

## Mise à jour

Après installation du paquet 6.3.0, désactiver puis réactiver une fois le module
CDav pour ajouter la provenance technique `actioncomm_cdav.dav_origin`. Les
identifiants, URI et UID existants sont conservés. Tous les anciens mappings
reçoivent la valeur protégée ; leur présence ne prouve pas une création CalDAV.
Une réactivation ultérieure conserve les origines déjà enregistrées.

Les anciens événements créés depuis CalDAV peuvent donc devenir protégés après
migration. Cette règle évite d’inférer une provenance que les anciennes versions
n’enregistraient pas. Une modification métier refusée doit être effectuée dans
Dolibarr.

## Présentation dans Outlook et les logiciels agenda

La description utilise des sections en texte simple : **Rendez-vous**, **Client
et contact**, puis **Projet**. Les paragraphes du projet et les coordonnées sont
conservés, sans pictogramme répété devant chaque ligne. L’adresse du rendez-vous
reste distincte de l’adresse du tiers. La référence d’une réservation vérifiée
figure en bas avec un libellé lisible.

Cette présentation ne change pas les notes stockées dans Dolibarr. Le renvoi
d’une description inchangée conserve les notes natives, y compris leur format
riche. Pour un événement modifiable depuis CalDAV, les blocs client et projet
restent des informations métier ; seule la partie consacrée aux notes peut être
réimportée. Les coordonnées structurées d’une réservation publique se gèrent
dans Dolibarr, y compris lorsque la protection générale est désactivée.

Une synchronisation complète peut être nécessaire pour qu’un logiciel agenda
remplace la présentation qu’il avait mise en cache. Les tests contrôlent le
contenu iCalendar et son aller-retour ; l’affichage dans l’application Outlook
n’est pas automatisé dans cette qualification.
