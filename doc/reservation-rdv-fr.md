# Réservation de rendez-vous avec CDav

CDav 6.2.1 propose un calendrier mensuel de réservation relié directement à
l'Agenda Dolibarr, avec un horizon réglable jusqu'à six mois. Les clients
choisissent un jour disponible, puis un ou plusieurs créneaux si le profil
l'autorise. La confirmation crée les rendez-vous natifs affectés au
collaborateur et marqués occupés, accessibles depuis ses clients CalDAV.

## Installer ou mettre à niveau

Activer le module natif Agenda avant CDav. Installer la nouvelle version du
module, puis désactiver et réactiver CDav une fois pour appliquer les schémas
idempotents et actualiser le menu. Les événements restent dans l'Agenda natif ;
les tables de réservation contiennent les profils et les états techniques de
publication, de rejouabilité et de limitation des demandes.
La version 6.1 ajoute une table de liaison technique entre un reçu et ses
événements natifs : la réactivation du module est nécessaire après la copie du
ZIP, y compris pour une mise à niveau depuis 6.0. Les profils et liens publiés
sont conservés ; les deux nouvelles autorisations de multisélection restent
désactivées tant qu'un administrateur ne les active pas.
Les mises à jour 6.1.1 à 6.1.3 ne modifient pas le schéma et conservent les
profils et les publications de 6.1.
La version 6.2 nécessite une nouvelle désactivation/réactivation : elle retire
la limite d'un profil par collaborateur et ajoute les rattachements et les états
des confirmations email. La migration conserve les identifiants, horaires,
activations, liens et reçus existants ; elle peut être rejouée sans doublon.

La réservation est désactivée par défaut. La recette a validé Dolibarr 23.0.4 et
24.0.0 sous PHP 8.4.26 et 8.5.11, avec MariaDB 10.6.23 et des tables
transactionnelles. Les résultats et les dépréciations natives sont détaillés
dans le [rapport de validation](validation-reservation-rdv.md). Les autres
SGBD restent hors de cette matrice.
Respecter également les prérequis de la version Dolibarr installée.

## Configurer un collaborateur

Un administrateur interne gère les profils depuis **Agenda > Rendez-vous en
ligne**, entrée fournie par CDav, ou avec le bouton **Rendez-vous en ligne** de
la page de configuration du module, accessible par son unique roue dentée.
Le chemin direct habituel est `/custom/cdav/admin/booking.php`.

1. Sélectionner un utilisateur interne actif. Il doit posséder les droits natifs
   de lecture et de création de ses événements Agenda. Le collaborateur peut
   rester non administrateur.
2. Choisir son fuseau horaire, la durée des rendez-vous et l'espacement des heures
   de début proposées.
3. Saisir ses plages hebdomadaires, avec jusqu'à deux plages par jour dans
   l'interface. Laisser les deux heures vides pour fermer une plage ; les deux
   bornes d'une plage appartiennent au même jour et les plages ne se chevauchent
   pas.
4. Régler les marges, le préavis et l'horizon, puis enregistrer le profil avec
   **Autoriser les réservations pour ce collaborateur** activé.
5. Activer **Activer la réservation publique**. L'activation globale et celle du
   profil sont toutes deux nécessaires.
6. Utiliser **Créer ou renouveler le lien**, puis copier l'URL affichée.

| Réglage | Valeur initiale | Plage acceptée |
|---|---|---|
| Durée du rendez-vous ou du créneau de base | 30 minutes | 5 à 480 minutes |
| Espacement des débuts proposés | 30 minutes | 5 à 240 minutes |
| Marge avant / après | 0 / 0 minute | 0 à 240 minutes chacune |
| Préavis minimum | 1 440 minutes, soit 1 jour | 0 à 43 200 minutes ou 0 à 30 jours |
| Horizon de réservation | 60 jours | 1 à 180 jours ou 1 à 6 mois calendaires |
| Regrouper des créneaux consécutifs | Non | Autorisation indépendante par profil |
| Réserver des plages séparées ou plusieurs jours | Non | Autorisation indépendante par profil |

Chaque collaborateur peut disposer de plusieurs profils nommés. Choisir
**Nouveau profil**, renseigner son nom et ses réglages, puis enregistrer et créer
son lien. Pour modifier un profil existant, le sélectionner dans la liste.
Chaque profil garde son état, ses horaires, ses permissions et son lien propres ;
renouveler ou révoquer un lien ne modifie pas les autres profils. Le nom du profil
est visible sur sa page publique. Tous consultent l'agenda du même collaborateur :
une réservation sur un profil occupe aussi les autres profils de cette personne.

### Période de validité facultative

Les dates **Début de validité** et **Fin de validité** limitent les dates des
rendez-vous proposés, dans le fuseau du collaborateur. Les deux jours sont
inclus : la même date aux deux bornes ouvre une seule journée, et deux dates
peuvent définir une semaine ou une période plus longue.

Chaque borne est facultative. Deux champs vides donnent un profil sans limite
de validité ; une seule borne permet un début ou une fin sans l'autre limite.
Ces dates s'ajoutent aux horaires, au préavis et à l'horizon de réservation.
Un profil valable sans limite peut donc continuer à proposer uniquement les
six prochains mois, au fur et à mesure que cet horizon avance.

Le calendrier ouvre directement le premier mois possible. Les jours hors de
la période ne sont pas réservables et la navigation reste dans les bornes.
Une période passée ou située au-delà de l'horizon affiche qu'aucune date n'est
actuellement ouverte. Chaque partie d'une réservation groupée doit respecter
les mêmes limites, vérifiées à nouveau au moment de confirmer.

### Tiers et projet facultatifs

Le champ **Tiers lié** est facultatif. Après l'avoir choisi, utiliser
**Afficher les projets du tiers**, puis choisir éventuellement un projet.
Cet affichage conserve les réglages saisis sans enregistrer le profil.
Un projet doit être ouvert, appartenir au tiers sélectionné et à l'entité
courante. Le sélecteur exclut les projets brouillons et fermés ; la même règle
est vérifiée côté serveur à l'enregistrement et à la réservation.
Si un projet lié à un ancien profil est fermé, son rattachement reste conservé.
Il faut choisir un autre projet ouvert ou confirmer explicitement le retrait
du lien. Les rendez-vous déjà créés restent dans l'Agenda.
Les modules natifs Tiers et Projets, ainsi que les droits de lecture du
collaborateur sur les objets liés, sont nécessaires pour ces rattachements.

Chaque partie du rendez-vous est liée nativement au tiers et au projet choisis.
Les noms et les listes des tiers/projets restent dans l'administration ; la page
publique affiche seulement le nom du profil choisi par l'administrateur.
Une modification du contexte invalide les formulaires précédemment ouverts.

Le préavis minimum peut être saisi en **minutes** ou en **jours**. Changer
l'unité convertit la valeur automatiquement : 1 440 minutes deviennent 1 jour,
2 jours correspondent à 2 880 minutes. Un jour représente exactement 24 heures
écoulées, y compris lors d'un changement d'heure. Les jours décimaux permettent
de conserver les valeurs existantes : 90 minutes valent 0,0625 jour. La précision
reste d'une minute et le maximum de 30 jours reste inchangé. L'unité choisie est
conservée sur le profil ; les anciens profils restent affichés en minutes.
Cette évolution 6.2.1 ne nécessite aucune migration supplémentaire depuis 6.2.0.

L'horizon en mois conserve le jour et l'heure locaux, en ramenant le jour au
dernier jour valide du mois d'arrivée : six mois après le 31 août donnent le
28 ou 29 février. Les anciens profils exprimés en jours conservent leur unité.

Une durée de 60 minutes avec un pas de 30 minutes peut proposer 9 h et 9 h 30 :
chaque proposition doit disposer d'une heure complète. Les marges encadrent
chaque occupation et chaque nouveau rendez-vous. Avec 10 minutes avant et
15 minutes après, il faut donc 25 minutes entre deux rendez-vous. Les marges
doivent aussi tenir dans la plage d'ouverture.

Par exemple, un RDV de 240 minutes avec 60 minutes avant et après nécessite au
moins 360 minutes continues. Des plages 07:00–12:00 et 13:00–17:00 ne conviennent
pas, même avec un agenda vide. Avec un pas de 7 minutes, le premier départ qui
respecte la marge de 60 minutes tombe à 08:03 : il faut alors 363 minutes de
plage. L'administration affiche un avertissement lorsque les réglages ne peuvent
proposer aucun créneau ; elle ne modifie pas les marges automatiquement.

Les horaires sont saisis et affichés dans le fuseau du collaborateur, indiqué
sur la page publique. Le visiteur choisit un horaire dans ce fuseau. Les heures
locales inexistantes ou ambiguës lors d'un changement d'heure ne sont pas
proposées.

## Calendrier mensuel et réservations groupées

Les jours proposant de vrais créneaux sont cliquables et indiquent leur nombre.
Les jours complets, fermés, passés ou exclus par le préavis sont désactivés.
La navigation s'arrête à l'horizon du profil. Le mois est recalculé à partir de
l'Agenda à chaque consultation ; le champ Date reste disponible pour un accès
direct. Une page sans date choisit le premier jour disponible du mois affiché.

Si les deux autorisations de multisélection sont désactivées, le visiteur
choisit un seul créneau, comme auparavant. Sinon, cocher un créneau l'ajoute
automatiquement à la sélection, et le décocher le retire. Sans JavaScript,
utiliser **Ajouter les créneaux cochés**. Sa sélection est conservée lorsqu'il
change de jour, de mois ou de langue. Il peut retirer une unité ou vider sa
sélection avant de renseigner ses coordonnées et de confirmer.

Le client indique aussi l'**objet du rendez-vous** et l'**adresse du rendez-vous**,
tous deux obligatoires pour une nouvelle réservation. L'adresse est saisie par
le client : elle désigne le lieu où le collaborateur doit se rendre. Le sujet
accepte jusqu'à 120 caractères et l'adresse jusqu'à 128 caractères UTF-8, avec
des retours à la ligne. Les champs trop longs sont refusés sans troncature.

L'objet devient le titre natif Agenda et l'adresse son lieu ; ils figurent aussi
dans les notes privées et dans la confirmation affichée au visiteur. Toutes les
parties d'une réservation groupée reprennent le même objet et la même adresse.
Ces valeurs ne sont pas ajoutées aux liens de navigation, aux disponibilités
publiques ou aux réponses JSON de reçu. Les anciens reçus 6.1 sans ces champs
peuvent encore être rejoués, mais ne permettent pas une nouvelle création
incomplète. Modifier l'objet ou l'adresse d'une demande déjà acceptée produit
un conflit au lieu d'un second rendez-vous.

- Deux unités consécutives de 30 minutes forment un seul événement natif de
  60 minutes si le regroupement continu est autorisé. Les marges encadrent
  l'ensemble, sans marge entre les deux unités.
- Des unités séparées par une pause ou sur plusieurs jours forment plusieurs
  événements natifs si le fractionnement est autorisé. Les pauses restent
  libres et les marges s'appliquent autour de chaque partie.
- Les autorisations sont indépendantes : autoriser les plages séparées ne
  permet pas de regrouper des unités consécutives, et inversement. Le serveur
  vérifie les autorisations actuelles au moment de confirmer.

Par exemple, 08:00–12:00 puis 13:00–17:00 sont deux plages séparées par une
pause. Pour les réserver ensemble, activer **Autoriser plusieurs plages séparées
ou plusieurs jours** dans le profil. L'option des créneaux consécutifs seule
ne suffit pas. Avec le fractionnement autorisé et les disponibilités requises,
la sélection représente 480 minutes de rendez-vous, réparties en deux parties.

Depuis la version 6.1.3, l'ajout de créneaux ramène directement à la sélection.
Une combinaison refusée affiche son motif près du panier, avec les actions
pour retirer un créneau ou vider la sélection. La durée totale et les périodes
ne sont affichées qu'après validation ; le formulaire de confirmation reste
absent tant que la sélection ne respecte pas le profil et les disponibilités.

Le pas doit permettre d'enchaîner les unités : choisir par exemple 30 minutes
pour une durée de base de 60 minutes. Deux unités qui se chevauchent sont
refusées. Une sélection est limitée à 24 unités sur 8 jours locaux distincts ;
elle peut dépasser 8 heures de durée totale si les horaires le permettent.
Un changement de jour reste une partie distincte.

La confirmation crée toutes les parties dans une même transaction native. Si
l'une est devenue occupée ou si une création échoue, aucune partie n'est créée.
Un reçu et une référence communs permettent le rejeu sans doublon. Si une partie
est supprimée, déplacée ou rendue libre dans Dolibarr, le rejeu ne réaffirme pas
l'ancienne réservation : il demande de contacter le collaborateur.

## Entreprise et collaborateur affichés

La page reprend le nom et le logo de l'entreprise configurés dans Dolibarr,
puis le nom, la photo, l'adresse e-mail, le téléphone professionnel et le mobile
du collaborateur sélectionné. Les champs non renseignés sont omis. Les options
natives de masquage public de cet utilisateur pour la photo et les coordonnées
sont respectées.

L'icône de l'onglet reprend en priorité la miniature du logo carré configuré
dans Dolibarr, comme l'interface native, puis ses versions de plus grande taille.
Le logo ordinaire reste utilisé dans l'en-tête et en repli si aucun logo carré
n'est disponible. Renseigner le **logo carré** dans la configuration de
l'entreprise pour garantir le bon format dans l'onglet. Les deux utilisent le
point d'accès public natif `viewimage.php`. La photo utilise les fichiers natifs
de l'utilisateur, avec préférence pour sa miniature, et est
intégrée à la page ; aucune activation de la carte virtuelle publique ni nouvelle
copie de photo n'est nécessaire. Les photos PNG, JPEG, GIF et WebP jusqu'à 1 Mio
sont prises en charge. Les SVG et fichiers trop volumineux sont omis.

## Partager, renouveler ou retirer un lien

L'URL ressemble à :

```text
https://exemple.ch/custom/cdav/public/booking.php?key=JETON_DE_PUBLICATION
```

Le jeton de publication est aléatoire et distinct des accès DAV/ICS. Le module
conserve son empreinte ; le lien complet est affiché une seule fois, lors de sa
génération. Le copier avant de quitter la page. Toute personne qui reçoit ce
lien peut réserver un créneau ; aucun compte Dolibarr client n'est nécessaire.

Renouveler le lien invalide le précédent et les anciens formulaires associés.
Si le lien a été perdu, en générer un nouveau et remplacer les liens partagés.
**Révoquer le lien et désactiver le profil** retire sa publication sans supprimer
les rendez-vous déjà créés. Réactiver ensuite le profil nécessite de générer un
nouveau lien. La désactivation globale interrompt les nouvelles réservations de
tous les profils ; elle ne remplace pas la révocation de leurs liens.

La page peut être partagée par lien depuis un site ou une signature. Sa
protection interdit l'affichage dans une iframe. Elle charge ses ressources
localement et n'appelle pas de service de réservation tiers.

## Occupations prises en compte

Le calcul lit les événements de l'Agenda affectés au collaborateur et marqués
occupés, qu'ils proviennent de Dolibarr, de BookCal ou d'un client CalDAV. Il
prend en compte les journées entières, les événements sur plusieurs jours, les
séries anciennes, les récurrences et leurs exceptions. La fenêtre habituelle de
synchronisation CDav ne limite pas cette recherche.

Un événement marqué libre ne bloque pas les créneaux. Les tâches à faire
(`VTODO`), tâches projet, interventions, salles et autres ressources n'occupent
pas automatiquement ce calendrier de réservation. Pour bloquer une absence,
créer un événement occupé dans l'Agenda du collaborateur.

CDav exporte comme événements les actions dont le champ **Statut / Pourcentage**
est **Non applicable**. Les actions exportées comme tâches à faire restent
exclues du calcul de réservation.

Un événement occupé sans fin exploitable, une récurrence non prise en charge ou
une erreur de lecture interrompt le calcul. La page indique alors une
indisponibilité temporaire au lieu de proposer des heures incertaines. Les
récurrences quotidiennes, hebdomadaires, mensuelles et annuelles sont traitées ;
les anciennes règles `EXRULE` restent hors du périmètre validé. Les exceptions
avec `RANGE` ou certaines formes de `RDATE` non représentables sont refusées
explicitement. Corriger les données
concernées dans l'Agenda ou dans le client qui les a créées.

## Ce qui se passe à la confirmation

Le client choisit une date et un créneau, renseigne son nom, son courriel et
éventuellement son téléphone, puis confirme. Les coordonnées sont conservées
avec le rendez-vous ; cette version ne crée ni ne modifie automatiquement une
fiche tiers/contact à partir de ces informations.

Le service revérifie l'agenda avant de créer l'événement natif. Si le créneau est
devenu occupé depuis l'affichage de la page, le client reçoit un message et des
disponibilités actualisées. Deux réservations publiques qui se chevauchent ne
peuvent pas toutes deux être confirmées.

La confirmation affiche une référence à conserver et un bouton **Réserver un
autre rendez-vous**. Ce bouton ouvre une nouvelle sélection dans le même profil,
avec de nouvelles disponibilités et un nouveau formulaire. Les coordonnées et
la sélection précédente ne sont pas ajoutées à son URL. Le collaborateur traite
les déplacements et annulations directement dans l'Agenda ; le client le contacte
pour demander une modification.

### Emails de confirmation

Après validation de toutes les parties du rendez-vous, CDav utilise `CMailFile`
et les paramètres email natifs de Dolibarr pour envoyer deux confirmations
séparées : au réservant et au collaborateur. Configurer l'expéditeur et le
transport SMTP dans Dolibarr, et une adresse email valide sur la fiche du
collaborateur. Un email masqué sur la page publique reste utilisable pour cet
envoi interne ; il n'est pas divulgué au client.

Les messages reprennent le sujet, l'adresse, les périodes, le fuseau et la
référence. Chaque destinataire reçoit son message sans copie à l'autre. Les
états d'envoi sont conservés sur le reçu pour empêcher les doublons lors d'un
rejeu. Une erreur SMTP ou un envoi dont le résultat reste incertain ne supprime
pas le rendez-vous : la confirmation affiche un avertissement et la référence
reste valable. Aucun nouvel essai automatique n'est lancé après une tentative
incertaine. Les SMS et les confirmations d'écritures DAV restent hors périmètre.

Le formulaire reste valide 15 minutes. Le rejeu d'une même demande encore valide
retrouve sa réservation et ne crée pas un second rendez-vous. Une demande
expirée doit être remplacée par un nouveau formulaire ; elle ne redevient pas
valide après purge de l'état technique. Les reçus techniques sont conservés
jusqu'à 24 heures après l'expiration du formulaire, puis purgés progressivement.

La protection de concurrence couvre les réservations publiques et le refus
d'une occupation déjà validée lors du contrôle final. L'Agenda natif et les
clients DAV peuvent toujours créer volontairement des événements superposés.
Le module n'interdit pas globalement tout chevauchement saisi par un utilisateur.

## HTTPS, proxies et confidentialité

Servir la page publique en HTTPS. Si un reverse proxy termine TLS, déclarer
uniquement ses adresses de connexion exactes dans `CDAV_TRUSTED_PROXY_IPS` et
transmettre le protocole HTTPS selon la configuration décrite dans le
[guide de découverte et transport](well-known-webserver.md). Les en-têtes
transmis par un proxy non déclaré ne suffisent pas à autoriser la connexion.

`CDAV_BOOKING_ALLOW_LOCAL_HTTP=1` constitue une exception explicite pour des
tests locaux isolés sur une adresse de boucle locale. Sa valeur par défaut est
`0` ; ce réglage reste désactivé pour l'exploitation HTTPS.

Les réponses publiques ne sont pas mises en cache et n'affichent ni titre,
notes, participants, documents, ni identifiant des événements qui bloquent les
créneaux. Le navigateur reçoit une politique sans référent et sans ressource
tierce. Le formulaire conserve les protections de soumission natives Dolibarr.

Le jeton figurant dans l'URL, adapter également les journaux du serveur web, du
proxy, du WAF et des outils de suivi : journaliser le chemin sans sa chaîne de
requête, ou masquer les paramètres `key`, `token` et `request`. Ne pas enregistrer
les corps des formulaires de réservation. Vérifier tous les journaux d'accès,
y compris les journaux secondaires ; une politique du navigateur ne retire pas
une URL déjà reçue par le serveur. Réserver les traces de débogage SQL à un
diagnostic contrôlé, car les API natives y manipulent les notes du rendez-vous.

Les profils sont rattachés à l'entité Dolibarr courante. Dans une installation
MultiCompany, le visiteur anonyme doit arriver sur le contexte d'entité natif
correspondant, par exemple par son domaine et sa configuration `DOLENTITY`.
Un lien généré dans une session d'entité 2 ne commute pas automatiquement un
visiteur anonyme de l'entité par défaut vers l'entité 2. La commutation d'entité
par paramètre public est refusée ; un support MultiCompany complet n'est pas
annoncé par cette version.

## Limites et dépannage

Les limites ci-dessous portent sur des fenêtres fixes de protection contre les
abus. Une soumission refusée peut aussi compter comme tentative.

| Portée | Limite |
|---|---|
| Ensemble des demandes par IP de connexion reconnue par le mécanisme natif | 240 demandes par fenêtre de 5 minutes |
| Consultation d'un profil depuis cette IP | 120 demandes par fenêtre de 5 minutes |
| Soumission d'un profil depuis cette IP | 20 tentatives par fenêtre de 5 minutes |
| Soumission d'un profil, toutes IP confondues | 200 tentatives par fenêtre de 24 heures |
| Corps ou chaîne de requête publique | 8 Kio ; pièces jointes exclues |

Le plafond quotidien porte sur les tentatives, pas uniquement sur les RDV
confirmés. Les visiteurs partageant une même IP reconnue peuvent partager les
limites correspondantes ; vérifier ce comportement avec le proxy déployé. Une
limitation renvoie HTTP `429`. Attendre la fin de la fenêtre concernée avant de
réessayer.

Le calcul possède aussi des limites de volume et d'expansion : au maximum
5 000 ressources d'agenda, 1 Mio par ressource et 8 Mio au total pour une lecture,
avec des plafonds de 10 000 étapes par série et 50 000 étapes cumulées.
Un dépassement ou une erreur de calcul entraîne une indisponibilité explicite.
Les plafonds protègent le service ; ils ne doivent pas être relevés pour masquer
des données anormales sans mesure de charge préalable.

Si aucun créneau ne s'affiche, vérifier le jour et le fuseau, les plages,
la durée avec ses marges, le préavis, l'horizon et les occupations. Si le lien
est indisponible, vérifier les activations globale/profil, le jeton courant,
l'utilisateur actif, ses droits Agenda, HTTPS et le contexte d'entité. Après une
mise à niveau, vérifier que le module a été réactivé pour charger ses schémas.

## Contrôles de qualité

Les tests rapides utilisent les bibliothèques natives du cœur indiqué :

```sh
DOLIBARR_ROOT=/chemin/vers/dolibarr/htdocs \
  CDAV_PHP_BIN=php8.4 \
  CDAV_PHPCS_STANDARD=/chemin/vers/dolibarr/dev/setup/codesniffer/ruleset.xml \
  bash tests/qa.sh
```

Répéter avec les deux cœurs et PHP 8.4/8.5. La suite inclut le calcul métier,
les récurrences, les changements d'heure, le rendu du formulaire public et les
régressions DAV existantes. Une réussite du lint seul ne prouve pas le bon
fonctionnement d'une combinaison.

Pour HTTP/base réelle, utiliser un serveur multi-worker et une arborescence
exclusivement réservée aux tests, dont la base contient `test` dans son nom :

Les fixtures de ces suites utilisent `Europe/Zurich`. Aligner le fuseau serveur
du PHP CLI et de l'instance HTTP pour que les API natives stockent et relisent
les dates dans le même contexte. Exemple de serveur local sur Linux :

```sh
PHP_CLI_SERVER_WORKERS=4 php8.4 -d date.timezone=Europe/Zurich \
  -S 127.0.0.1:9124 -t /chemin/vers/instance-test/htdocs
```

```sh
CDAV_LIVE_TEST=1 \
  CDAV_BOOKING_TEST_FIXTURES=1 \
  DOLIBARR_ROOT=/chemin/vers/instance-test/htdocs \
  CDAV_BOOKING_LIVE_URL=http://127.0.0.1:9124/custom/cdav/public/booking.php \
  php8.4 tests/booking_live.php
```

Cette suite utilise des fixtures natives, dont un trigger temporaire pour
provoquer un échec contrôlé, et restaure la configuration. Exécuter une seule
suite à la fois sur une instance de test : le nettoyage retire par API native
les événements automatiques produits depuis son point de départ et préserve
l'historique antérieur. La suite HTTP DAV
`tests/live_integration.php` reste également requise. Les dépréciations du cœur
ou de ses dépendances doivent être consignées séparément des défauts CDav.

Le contrôle navigateur est facultatif pour l'exploitation du module et fait
partie des preuves de recette. Préparer Node, Playwright, axe et Chromium dans
un répertoire d'outillage extérieur au module, puis exécuter sur une instance
de test locale :

```sh
CDAV_LIVE_TEST=1 \
  CDAV_BOOKING_TEST_FIXTURES=1 \
  DOLIBARR_ROOT=/chemin/vers/instance-test/htdocs \
  CDAV_BOOKING_BROWSER_ROOT=/chemin/vers/instance-test/htdocs \
  CDAV_BOOKING_LIVE_URL=http://127.0.0.1:9124/custom/cdav/public/booking.php \
  CDAV_PLAYWRIGHT_MODULE=/chemin/vers/outillage/node_modules/playwright \
  CDAV_AXE_MODULE=/chemin/vers/outillage/node_modules/@axe-core/playwright \
  php8.4 tests/booking_browser.php
```

Les deux chemins d'instance doivent désigner la même arborescence isolée. Le
script prépare un utilisateur et un profil natifs jetables, exécute le parcours
FR/EN, clavier, mobile et erreurs, puis nettoie les événements/utilisateur et
restaure les activations et compteurs techniques dans un bloc de nettoyage
garanti. Exécuter cette recette seule sur son instance pendant ce nettoyage.
Les identifiants temporaires restent dans un fichier privé, supprimé à la fin.

`CDAV_NODE_BIN` peut sélectionner le binaire Node et
`CDAV_CHROMIUM_EXECUTABLE` un Chromium déjà installé. Si l'environnement de test
isolé l'exige, `CDAV_BROWSER_NO_SANDBOX=1` transmet cette option au navigateur de
recette. Le résumé JSON et les captures sans coordonnées réelles sont écrits
dans `dist/qa/` ; `CDAV_BOOKING_BROWSER_OUTPUT` permet de choisir un autre dossier.
Ils ne contiennent ni jeton de publication, ni mot de passe, ni corps privé des
événements.
