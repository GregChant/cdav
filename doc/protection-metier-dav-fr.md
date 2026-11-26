# Protection des données métier avec CalDAV et CardDAV

CDav 6.3.1 conserve Dolibarr comme source des données métier. Les écritures
utilisent les classes natives et leurs transactions et triggers.

## Rendez-vous

Dans **Configuration du module CDav → Protection des données métier**, l’option
**Protéger les rendez-vous Dolibarr** est activée par défaut
(`CDAV_PROTECT_NATIVE_EVENTS=1`). Elle concerne les événements Agenda créés dans
Dolibarr, les rendez-vous issus de la réservation publique et les anciens
événements dont l’origine n’est pas certaine.

Depuis Outlook ou un autre client CalDAV, le propriétaire, ou un utilisateur
autorisé à modifier tous les événements, peut changer le titre, le lieu, les
notes libres et la priorité du rendez-vous. Pour un rendez-vous individuel,
il peut aussi changer l’heure de début, l’heure de fin et le réglage **Journée
entière**. Un changement de lieu porte sur l’adresse de cette visite. Lorsque
le lieu est vide, l’agenda peut afficher l’adresse du tiers à titre indicatif.
Les coordonnées du tiers se modifient sur sa fiche Dolibarr.

Le réglage **Libre/Occupé** porte sur l’affectation au calendrier concerné.
Dans le corps du rendez-vous, modifier les notes de la visite en laissant les
sections tâche, client, projet et documents telles qu’affichées. Les participants,
les liens métier et les règles de récurrence se gèrent dans Dolibarr. Si une
modification touche aussi une de ces données protégées, le serveur refuse
l’enregistrement entier. Le rendez-vous conserve son UID.

Depuis 6.3.1, une invitation permet aussi au participant authentifié de répondre
sans modifier les autres données métier. Son refus n'annule pas le rendez-vous
des autres. L'organisateur peut annuler explicitement son invitation ; les
déplacements et les délégations suivent les droits Agenda natifs. Les détails
et le cas des invitations extérieures sont décrits dans le
[guide Outlook et DAVx⁵](invitations-outlook-davx5-fr.md).

Les rappels natifs relatifs encore en attente suivent le déplacement, sans
changer leur destinataire, leur canal ou leur état et sans créer de nouvel
envoi. La révision de planification est avancée par le serveur. Les notifications
de déplacement ou d’annulation sont traitées après validation de la transaction.
Les écritures des rendez-vous et des contacts verrouillent également l’objet
natif pour conserver une liaison changée simultanément dans Dolibarr ou dans
un autre calendrier.

Un rappel créé dans Dolibarr et renvoyé à l’identique par CalDAV conserve son
identifiant et sa provenance. Il n’est pas recréé, y compris lorsque la protection
générale des rendez-vous est désactivée. Les rappels déjà traités ne sont pas
réarmés par la synchronisation.

Quand les rappels navigateur Dolibarr et leur synchronisation CDav sont activés,
un collaborateur peut ajouter, modifier et retirer ses propres rappels CalDAV
de type `DISPLAY` relatifs au début du rendez-vous. Les rappels créés dans
Dolibarr se gèrent dans Dolibarr.

La suppression reste soumise aux droits Agenda natifs. Pour un événement affecté
à plusieurs collaborateurs, elle retire uniquement l’affectation au calendrier
concerné. Pour une invitation, la suppression par l'organisateur annule le
rendez-vous pour tous, selon ses droits ; le retrait par un invité reste
personnel. Le retrait de la dernière affectation supprime le rendez-vous par
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

La description présente les **notes du rendez-vous**, puis la **Tâche** liée si
l’utilisateur peut la consulter. Ce bloc contient sa référence, son libellé et
un lien direct vers la fiche Dolibarr. Les sections **Client et contact** et
**Projet** suivent. Les paragraphes du projet et les coordonnées sont
conservés, sans pictogramme répété devant chaque ligne. L’adresse du rendez-vous
reste distincte de l’adresse du tiers. La référence d’une réservation vérifiée
figure en bas avec un libellé lisible.

La description détaillée d’un projet nécessite l’accès natif à ce projet. L’accès
au rendez-vous seul n’accorde pas cet accès. Les tâches projet et interventions
suivent également les droits de lecture de leur objet natif.

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
