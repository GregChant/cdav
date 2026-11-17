# Réservation de rendez-vous avec CDav

CDav 6.0.0 propose une page publique de réservation reliée directement à l'Agenda
Dolibarr. Les clients voient les créneaux disponibles ; la confirmation crée un
rendez-vous natif, affecté au professionnel et marqué occupé. Ce rendez-vous est
ensuite accessible depuis les clients CalDAV habituels.

## Installer ou mettre à niveau

Activer le module natif Agenda avant CDav. Installer la nouvelle version du
module, puis désactiver et réactiver CDav une fois pour appliquer les schémas
idempotents et actualiser le menu. Les événements restent dans l'Agenda natif ;
les tables de réservation contiennent les profils et les états techniques de
publication, de rejouabilité et de limitation des demandes.

La réservation est désactivée par défaut. La matrice cible est Dolibarr 23/24
avec PHP 8.4/8.5 ; les résultats par combinaison et les éventuelles limites du
cœur sont suivis dans la [feuille de route](feuille-de-route-reservation-rdv.md).
Respecter également les prérequis de la version Dolibarr installée.

## Configurer un professionnel

Un administrateur interne gère les profils depuis **Agenda > Rendez-vous en
ligne**, entrée fournie par CDav, ou depuis la page de configuration du module.
Le chemin direct habituel est `/custom/cdav/admin/booking.php`.

1. Sélectionner un utilisateur interne actif. Il doit posséder les droits natifs
   de lecture et de création de ses événements Agenda. Le professionnel peut
   rester non administrateur.
2. Choisir son fuseau horaire, la durée des rendez-vous et l'espacement des heures
   de début proposées.
3. Saisir ses plages hebdomadaires, avec jusqu'à deux plages par jour dans
   l'interface. Laisser les deux heures vides pour fermer une plage ; les deux
   bornes d'une plage appartiennent au même jour et les plages ne se chevauchent
   pas.
4. Régler les marges, le préavis et l'horizon, puis enregistrer le profil avec
   **Autoriser les réservations pour ce professionnel** activé.
5. Activer **Activer la réservation publique**. L'activation globale et celle du
   profil sont toutes deux nécessaires.
6. Utiliser **Créer ou renouveler le lien**, puis copier l'URL affichée.

| Réglage | Valeur initiale | Plage acceptée |
|---|---|---|
| Durée du rendez-vous | 30 minutes | 5 à 480 minutes |
| Espacement des débuts proposés | 30 minutes | 5 à 240 minutes |
| Marge avant / après | 0 / 0 minute | 0 à 240 minutes chacune |
| Préavis minimum | 1 440 minutes, soit 24 h | 0 à 43 200 minutes |
| Horizon de réservation | 60 jours | 1 à 90 jours |

Une durée de 60 minutes avec un pas de 30 minutes peut proposer 9 h et 9 h 30 :
chaque proposition doit disposer d'une heure complète. Les marges encadrent
chaque occupation et chaque nouveau rendez-vous. Avec 10 minutes avant et
15 minutes après, il faut donc 25 minutes entre deux rendez-vous. Les marges
doivent aussi tenir dans la plage d'ouverture.

Les horaires sont saisis et affichés dans le fuseau du professionnel, indiqué
sur la page publique. Le visiteur choisit un horaire dans ce fuseau. Les heures
locales inexistantes ou ambiguës lors d'un changement d'heure ne sont pas
proposées.

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

Le calcul lit les événements de l'Agenda affectés au professionnel et marqués
occupés, qu'ils proviennent de Dolibarr, de BookCal ou d'un client CalDAV. Il
prend en compte les journées entières, les événements sur plusieurs jours, les
séries anciennes, les récurrences et leurs exceptions. La fenêtre habituelle de
synchronisation CDav ne limite pas cette recherche.

Un événement marqué libre ne bloque pas les créneaux. Les tâches à faire
(`VTODO`), tâches projet, interventions, salles et autres ressources n'occupent
pas automatiquement ce calendrier de réservation. Pour bloquer une absence,
créer un événement occupé dans l'Agenda du professionnel.

CDav exporte comme événements les actions dont le champ **Statut / Pourcentage**
est **Non applicable**. Les actions exportées comme tâches à faire restent
exclues du calcul de réservation.

Un événement occupé sans fin exploitable, une récurrence non prise en charge ou
une erreur de lecture interrompt le calcul. La page indique alors une
indisponibilité temporaire au lieu de proposer des heures incertaines. Les
récurrences quotidiennes, hebdomadaires, mensuelles et annuelles sont traitées ;
les anciennes règles `EXRULE`, les exceptions avec `RANGE` ou certaines formes
de `RDATE` non représentables sont refusées explicitement. Corriger les données
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

La confirmation affiche une référence à conserver. Cette version ne génère pas
de courriel ou SMS automatique. Le professionnel traite les déplacements et
annulations directement dans l'Agenda ; le client le contacte pour demander une
modification.

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
5 000 ressources d'agenda et 8 Mio de données calendaires pour une lecture.
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
