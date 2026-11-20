# Disponibilités, documents des rendez-vous et photos

## Partager ses disponibilités

Deux usages sont à distinguer dans Dolibarr 23 et 24.

Depuis CDav 6.0.0, une page publique permet aussi la réservation directe à partir
des occupations de l'Agenda. Un administrateur règle les horaires du
professionnel et génère le lien depuis **Agenda > Rendez-vous en ligne**,
entrée CDav. Voir le [guide de réservation](reservation-rdv-fr.md) pour
l'activation, les droits et les limites de concurrence.

### Consulter les périodes occupées

Dans **Agenda > URL ICS**, copier le lien **Disponibilités uniquement /
Free-Busy** de son utilisateur. Un partenaire peut s'y abonner dans
son calendrier : il voit les périodes occupées sans les détails des rendez-vous.
Ce lien permet la consultation ; il ne contient pas de formulaire de demande.
Le rafraîchissement dépend du logiciel du partenaire. Toute personne qui possède
le lien peut consulter ce flux.

### Choisir un créneau sur une page publique

Le module natif **BookCal / Prise de rendez-vous en ligne** fournit des
calendriers de réservation, des plages de disponibilité et une page publique.
Il reste **expérimental** dans les versions 23 et 24 examinées.

1. Activer Agenda et BookCal dans la configuration des modules. Si BookCal est
   masqué, le réglage natif `MAIN_FEATURES_LEVEL=1` dans **Configuration > Divers**
   affiche les modules expérimentaux.
2. Attribuer les droits BookCal nécessaires à la gestion des calendriers et
   disponibilités.
3. Dans **Agenda > Rendez-vous en ligne**, créer un calendrier et sélectionner
   l'utilisateur propriétaire.
4. Créer les plages de disponibilité liées au calendrier : dates, heures et
   durée des rendez-vous. Valider le calendrier et les plages.
5. Copier le **lien public pour la réservation** affiché sur la fiche du
   calendrier. Le partenaire choisit une heure et saisit ses coordonnées.

BookCal crée un contact ou réutilise un contact existant, puis crée directement
un rendez-vous natif `ActionComm` de type `AC_RDV`, lié à son propriétaire.
CDav peut synchroniser cet événement comme les autres événements Agenda.

**Limites à prendre en compte avant de publier ce lien :** le calcul BookCal
examiné retire seulement certaines heures de début déjà réservées dans le même
calendrier BookCal. Il ne recoupe pas toutes les occupations de l'agenda du
propriétaire et ne vérifie pas tous les chevauchements. Le formulaire crée
directement le rendez-vous, sans circuit d'approbation et sans nouvelle
vérification complète du créneau au moment de l'enregistrement. Le paramètre
`securekey` présent dans le lien de la fiche n'est pas contrôlé par les points
d'entrée publics examinés ; ce lien ne constitue donc pas un accès réservé à
des partenaires désignés.

CDav 6.0.0 fournit sa propre page de réservation, avec recoupement des
occupations de l'Agenda, contrôle final des conflits et confirmation immédiate.
Elle utilise des horaires CDav autonomes et un lien distinct de celui de
BookCal. Le [guide de réservation](reservation-rdv-fr.md) décrit ce parcours ;
la validation manuelle d'une demande reste hors de cette première version.
Le module natif Ressources permet de rattacher une salle à un événement, mais
ne suffit pas à automatiser le contrôle de sa disponibilité.

Références du code natif : [BookCal](https://github.com/Dolibarr/dolibarr/blob/23.0/htdocs/bookcal/README.md),
[calcul des créneaux en v24](https://github.com/Dolibarr/dolibarr/blob/24.0/htdocs/public/bookcal/bookcalAjax.php),
[enregistrement public en v24](https://github.com/Dolibarr/dolibarr/blob/24.0/htdocs/public/bookcal/index.php),
[module Ressources](https://wiki.dolibarr.org/index.php/Module_Resources).

## Pièces jointes des rendez-vous

Les fichiers déposés dans l'onglet **Documents** d'un rendez-vous Dolibarr sont
exportés en propriétés iCalendar `ATTACH`. Dans CalDAV, leur URL passe par le
serveur CDav et accepte la même authentification que l'agenda, sans exiger une
session de navigateur. L'accès est contrôlé sur le rendez-vous. Les liens HTTPS
natifs restent également exportés.

Lorsque le rendez-vous contient au moins un fichier ou un lien dans l’onglet
**Documents**, la description CalDAV affiche **Documents du rendez-vous** avec
l’adresse de cet onglet. Ce lien unique reste valable lorsque les fichiers
changent. Il ouvre Dolibarr et applique les droits Agenda de l’utilisateur
connecté. CDav génère cette ligne à l’export et conserve les notes natives lors
d’un aller-retour de synchronisation.

Pour permettre les dépôts depuis un client, activer **Pièces jointes gérées des
rendez-vous** dans **Configuration du module CDav**, puis régler la taille
maximale, le nombre de fichiers par rendez-vous et le quota par utilisateur.

Deux mécanismes sont pris en charge :

- un client RFC 8607 peut ajouter, remplacer ou retirer une pièce jointe gérée ;
- un client qui envoie un `ATTACH;VALUE=BINARY;ENCODING=BASE64` dans son calendrier
  dépose maintenant un vrai fichier dans le répertoire natif des documents
  Agenda. CDav remplace la donnée intégrée par un lien de téléchargement géré.
  Un nouvel envoi identique réutilise le document.

Les droits natifs d'écriture Agenda s'appliquent, y compris pour un utilisateur
non administrateur sur ses propres rendez-vous. Par défaut, les rendez-vous
créés dans Dolibarr et les anciens événements d'origine incertaine restent
protégés. Leurs documents se modifient dans Dolibarr. Voir le
[guide de protection des rendez-vous](protection-metier-dav-fr.md#rendez-vous).
Le téléchargement exige un droit de lecture sur le rendez-vous. Les limites,
contrôles de nom et de type de fichier et contrôles antivirus natifs s'appliquent.
Lorsque les dépôts sont désactivés, un envoi binaire est refusé explicitement.
Un lien HTTPS externe reste un lien ; son contenu distant n'est pas téléchargé
automatiquement dans Dolibarr.

### Outlook et Android

- **Outlook avec CalDav Synchronizer** synchronise le rendez-vous. Le connecteur
  recrée le contenu iCalendar sans reprendre les propriétés `ATTACH` lors d'une
  modification Outlook. CDav conserve les pièces jointes existantes lorsqu'une
  modification de ce connecteur omet ces propriétés. Les fichiers se gèrent dans
  l'onglet **Documents** de Dolibarr. [Code du connecteur Outlook](https://github.com/aluxnimm/outlookcaldavsynchronizer/blob/c7bb23187c6b3a409fcb1c9f2d9cb1160f759a69/CalDavSynchronizer/Implementation/Events/EventEntityMapper.cs#L185-L207)
- **DAVx⁵ avec un agenda Android standard** conserve certaines propriétés
  iCalendar en arrière-plan. Le fournisseur de calendriers Android ne permet
  pas aux agendas d'échanger, d'afficher ou de modifier ces pièces jointes de
  façon standard. [Documentation DAVx⁵](https://manual.davx5.com/integration.html#extended-event-properties)

Les abonnements ICS restent en lecture seule ; les URL de documents natifs
qu'ils contiennent utilisent les contrôles d'accès de Dolibarr.

## Photos des contacts

L'image est intégrée dans la vCard transmise au client, dans cet ordre :

1. Photo individuelle enregistrée sur le contact.
2. Logo du tiers lié, si la photo individuelle est absente, introuvable ou
   inutilisable et si un logo exploitable existe.

Le carnet des tiers transmet aussi leur logo. Les droits de lecture du contact
ou du tiers restent nécessaires. Une modification du fichier image seul est
prise en compte par les indicateurs de synchronisation ; le client peut donc
rafraîchir l'image sans modification des coordonnées.

## Vérification

Les régressions sans base de données se lancent avec un cœur Dolibarr local :

```sh
DOLIBARR_ROOT=/chemin/vers/htdocs php tests/run.php
DOLIBARR_ROOT=/chemin/vers/htdocs php tests/contact_photos.php
DOLIBARR_ROOT=/chemin/vers/htdocs php tests/attachments.php
DOLIBARR_ROOT=/chemin/vers/htdocs php tests/freebusy.php
```

La suite `tests/live_integration.php`, réservée à une base de test explicite,
vérifie les documents natifs, les octets des pièces jointes, les droits d'accès
et le repli vers le logo au travers de vraies requêtes DAV.
