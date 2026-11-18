# Qualification des états de rendez-vous — CDav 6.4.0

Le code applicatif `eebd4a6` ajoute un état global natif aux rendez-vous Agenda
et le synchronise avec CalDAV. Le paquet extrait du commit de qualification
`0c0462b` est validé sur les quatre combinaisons Dolibarr/PHP. Les octets de
production sont identiques à `eebd4a6` ; seul l'en-tête navigateur du client
HTTP de test a été ajouté dans le commit de qualification.

## Comportement livré

Le champ complémentaire `actioncomm.cdav_status` utilise l'API native
`ExtraFields` et les déclencheurs `ACTION_CREATE` / `ACTION_MODIFY`. Ses valeurs
sont `TENTATIVE`, `CONFIRMED` et `CANCELLED`, avec des libellés français et anglais.
Le défaut SQL vide préserve les métadonnées DAV historiques ; les nouveaux
rendez-vous natifs sont confirmés par défaut. La réactivation est idempotente.

Le propriétaire ou un utilisateur autorisé à modifier tous les agendas peut
changer l'état global. Une réponse individuelle `PARTSTAT` reste personnelle.
Les changements DAV sont contrôlés avant les effets de synchronisation et
réévalués après verrouillage natif et vérification de l'ETag.

Un état provisoire conserve l'occupation native. L'annulation conserve le
rendez-vous et ses liaisons, libère toutes les affectations, supprime les seuls
rappels en attente et préserve les rappels traités. Elle ne permet pas de
restaurer le même rendez-vous par synchronisation. Un renvoi identique ne
réécrit pas l'objet et n'avance pas sa révision.

Les métadonnées d'un rendez-vous natif sans invitation restent minimales : le
changement d'état n'importe pas sa description enrichie ni ses liaisons dans
le stockage technique DAV. Les journées entières et les durées nulles conservent
leurs dates natives lors d'un changement d'état seul.

## Compatibilité des formulaires et clients

CalDav Synchronizer utilise `X-MICROSOFT-CDO-BUSYSTATUS` pour son choix Outlook
« Provisoire ». CDav reconnaît les modifications de cet indice sans laisser
un indice inchangé écraser un `STATUS` explicitement modifié par un autre
client. L'indice exporté tient aussi compte d'une réponse personnelle
provisoire ou en attente, sans modifier l'état global. Le préfixe de titre
`Cancelled: ` ajouté par Outlook est reconnu exactement lors d'une annulation
explicite et de son renvoi ; il ne remplace pas le titre natif.

Dans le cœur 24 qualifié, le contrôleur Agenda utilise des clés de session par
rendez-vous tandis que le widget natif lit encore la clé historique commune.
Sans adaptation, la case **Occupé** manque dans un formulaire neuf et
l'enregistrement libère le propriétaire. `AgendaForm` adapte uniquement cette
lecture au moment du rendu natif, après les contrôles d'accès. Le `finally`
restaure la clé historique ; les sélections propres à chaque onglet ne sont
jamais réécrites. L'installation est limitée à la fiche Agenda 24 et respecte
une classe de formulaire déjà remplacée par un autre module.

Le formulaire natif réécrit également les ressources des participants lors
d'une modification. Le déclencheur préserve leurs métadonnées cachées, y
compris les réponses RFC, vides ou `NULL`, pour les affectations maintenues.
Le choix explicite libre/occupé de l'utilisateur reste appliqué.

## Matrice et contrôles

| Cœur natif figé | PHP 8.4.26 | PHP 8.5.11 |
| --- | --- | --- |
| Dolibarr 23.0.4, `cb82037066c1c71f7c867482e9e92975222dfc14` | Paquet validé | Paquet validé |
| Dolibarr 24.0.0, `769c7db907099643558e77d7002c109cfda919e5` | Paquet validé | Paquet validé |

Les 26 suites PHP sont exécutées sur les quatre combinaisons : **104
exécutions**, avec lint, JavaScript et **488 rendus** du formulaire public.
Les tests de règles d'état, d'invitation et d'export sont complétés par un
test du vrai rendu `Form`, avec une frontière fictive pour la lecture des
utilisateurs : deux onglets, occupation, propriétés du formulaire et restauration
de session après une exception du parent.

La suite HTTP existante conserve ses 27 scénarios métier et 27 scénarios
d'invitation par environnement, ainsi que le REPORT/GET de 200 rendez-vous
natifs fictifs. Six groupes supplémentaires couvrent :

- Le champ natif, les écritures Outlook/DAV, les permissions, les états invalides,
  les ETags, les renvois identiques et la disponibilité de réservation.
- L'état d'une invitation partagée, les réponses individuelles et l'historique
  des rappels après annulation native.
- Les vrais POST Agenda avec connexion, session et CSRF natifs, les métadonnées
  des participants, une valeur forgée et l'absence de réarmement d'un rappel annulé.
- Les journées entières, avec et sans invitation, au changement d'heure suisse.
- Une ancienne valeur SQL vide, une métadonnée provisoire et une migration
  répétée sans écriture lors de la consultation.
- Les droits d'un propriétaire non administrateur, avec la possibilité de
  décocher explicitement **Occupé**.

Les suites avec barrières de concurrence utilisent deux instances par vague
pour limiter la contention locale. Les assertions et les délais de test sont
conservés. Les 48 contrôles natifs ciblés vérifient également l'évaluation réelle
des permissions et les hooks de formulaire.

## Qualité du code

Le contrôle de duplication conserve les seuils de 10 lignes / 100 tokens :
**zéro correspondance de production**, sur 52 fichiers analysés.

Les nouveaux fichiers et suites passent PHPCS avec les règles natives 23 et
24 sans diagnostic. Les fichiers historiques sont comparés au commit
`e1dce12`, avec empreintes des contenus, contrôle des lignes ajoutées et
comparaison du nombre de diagnostics par règle : aucune hausse.

| Fichier historique | Dolibarr 23 : avant → après | Dolibarr 24 : avant → après |
| --- | --- | --- |
| `class/CalDAVDolibarr.php` | 421 → 421 | 423 → 423 |
| `lib/cdav.lib.php` | 172 → 172 | 172 → 172 |
| `core/modules/modCDav.class.php` | 52 → 52 | 52 → 52 |
| `class/actions_cdav.class.php` | 0 → 0 | 0 → 0 |
| `server.php` | 36 → 36 | 36 → 36 |

Deux revues indépendantes portent sur les règles, les écritures, l'export,
les métadonnées, les permissions et l'adaptation du formulaire natif. Leurs
rapports locaux lient les conclusions aux empreintes des fichiers contrôlés.

## Paquet et limites

Le candidat est construit par `git archive` depuis `0c0462b`, puis extrait
dans quatre installations isolées. Ses 104 exécutions de suites PHP, quatre
suites JavaScript, 12 suites HTTP, 304 gardes HTTP des fichiers de test et
36 contrôles de démarrage passent. Les quatre réactivations natives passent
également. Le candidat compte 144 fichiers et son SHA-256 est
`d9c4325b3a45c68274bac206637574f34b4c94c8446bd70937bc0c8e5d3d334c`.

Le paquet final ajoute uniquement la documentation de qualification à ce
candidat. Sa construction vérifie les CRC, les chemins, l'absence de liens
symboliques, la correspondance de tous les fichiers au commit Git et la
reproductibilité des octets. Tous les fichiers autres que Markdown sont
identiques à ceux du candidat qualifié. Les vérifications du paquet, de son
nettoyage et de sa publication sont consignées dans
`dist/qa/appointment-status-validation/`. Le manifeste de distribution est
`dist/qa/booking-release-manifest.json`.

Toutes les données utilisées sont fictives, l'envoi de courriels vers
l'extérieur est désactivé et les fichiers du cœur partagé restent inchangés.
Le nettoyage des quatre bases conserve uniquement l'administrateur et les
données natives de référence attendues : aucun enregistrement métier,
technique de test ou extrafield orphelin ne subsiste. Les limites PHP des
serveurs isolés restent à 60 secondes. Aucun diagnostic PHP du module n'est
observé ; les seuls avertissements restants proviennent de `QUERY_STRING`
absent sous le serveur PHP de test et sont isolés dans les rapports natifs.
Les tests des clients sont protocolaires : Outlook, Microsoft 365 et les
applications Agenda du téléphone ne sont pas pilotés graphiquement.

Un changement d'état d'un rendez-vous sans invitation doit être séparé des
modifications de titre ou de notes ; un déplacement conservant sa durée peut
l'accompagner. L'envoi d'invitations externes reste assuré par le client de
messagerie, sans nouvelle passerelle iMIP.

Voir le [guide d'installation et d'utilisation](etats-rendez-vous-fr.md), le
[code officiel de CalDav Synchronizer](https://github.com/aluxnimm/outlookcaldavsynchronizer/blob/master/CalDavSynchronizer/Implementation/Events/EventEntityMapper.cs)
et la [définition iCalendar de l'état](https://www.rfc-editor.org/rfc/rfc5545#section-3.8.1.11).
