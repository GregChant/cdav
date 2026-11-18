# Audit de duplication du code CDav

Mesure du 18 novembre 2026 sur `0e58be9` (CDav 6.3.1), avec jscpd 5.4.3.
L'outil est installé localement ; il n'ajoute aucune dépendance d'exécution à
Dolibarr. L'analyse lit les sources sans charger PHP ni ouvrir une base.

## État initial

| Périmètre | Seuil | Fichiers analysés | Correspondances | Lignes rapportées | Pourcentage |
|---|---|---:|---:|---:|---:|
| Production, tokens identiques | 10 lignes / 100 tokens | 45 | 25 | 604 | 3,58 % |
| Tests, tokens identiques | 10 lignes / 100 tokens | 31 | 2 | 54 | 0,43 % |
| Production, noms et valeurs normalisés | 10 lignes / 100 tokens | 45 | 64 | 1 465 | 8,68 % |
| Production, recherche plus fine | 5 lignes / 50 tokens | 51 | 77 | 1 035 | 6,10 % |

Le mode `weak` ignore les commentaires et espaces lors de la comparaison des
tokens. Les positions de début et de fin englobent toutefois les lignes du
fichier ; les 604 lignes rapportées incluent des recouvrements entre paires.
Elles ne désignent pas 604 lignes uniques que l'on pourrait supprimer.
Les fichiers trop courts pour le seuil ne contribuent pas aux totaux : 55
fichiers de production sont candidats, 45 sont retenus au seuil principal.

Le code PHP, JavaScript et SQL est analysé séparément des tests, sans le cœur
Dolibarr, les dépendances, `dist` ni les données locales. La recherche normalisée
est exploratoire : elle rapproche aussi des structures dont les valeurs et les
règles métier diffèrent. Les pourcentages de ces modes ne sont pas équivalents.

La comparaison avec `0cd17f5` (6.3.0) ne signale aucun nouveau clone exact aux
seuils principaux. Une revue indépendante confirme qu'aucune des 25 paires
exactes ne recouvre les lignes de production modifiées en 6.3.1.

## Périmètre de la factorisation

Les 25 paires se concentrent dans les parseurs et sérialiseurs CardDAV, le
chargement de Dolibarr et les constantes partagées par ICS/CalDAV, les branches
Agenda/projets et les réponses multiget. La factorisation doit conserver :

- les mappings et API natives distincts des contacts, adhérents et tiers ;
- les autorisations, transactions, verrous, UID et protections des liaisons ;
- les particularités de dates VEVENT/VTODO et de confidentialité des réservations ;
- l'ordre des validations avant notification et sous verrou natif.

Les traitements PUT et DELETE après commit se ressemblent dans le mode
normalisé. Leur rôle diffère : publication de l'état canonique d'un côté,
annulation ou refus personnel de l'autre. Ils ne sont pas un clone exact
supplémentaire introduit par 6.3.1.

## Rejouer le contrôle

```sh
bash tests/duplication.sh
bash tests/duplication.sh report
bash tests/duplication.sh tests
```

Le script impose jscpd 5.4.3. Il utilise `CDAV_JSCPD_BIN` si renseigné, sinon
la commande installée `jscpd`, ou `npx` avec cette version exacte.
La configuration de production est `.jscpd.json`. Les rapports HTML/JSON sont
écrits dans `dist/qa/duplication/production/` et `tests/`.

Depuis 6.3.2, le mode par défaut impose **zéro duplication de production**
(`--threshold 0`) et refuse aussi les nouveaux clones. La référence
`.jscpd-baseline.json` est vide. Ajouter un clone à cette référence ne suffit
pas à faire passer le contrôle. Pour régénérer les empreintes après une revue :

```sh
bash tests/duplication.sh update-baseline
```

Le contrôle a été éprouvé dans une copie temporaire du dépôt, y compris avec
un chemin contenant des espaces : référence acceptée, véritable copie d'un
fichier PHP refusée, retour au succès après retrait, mauvaise version de l'outil
refusée. Aucun PHP métier ni accès à une base n'est nécessaire à cette preuve.

Les preuves brutes sont conservées localement dans `dist/qa/duplication/`, dont
`scan-manifest.json`, `gate-checks.json` et les rapports par périmètre.

## Résultat 6.3.2

Les **25 correspondances exactes de production sont supprimées** : 0 paire,
0 ligne rapportée et 0 %, sur 48 fichiers analysés. Le mode, les seuils
10 lignes / 100 tokens et les exclusions de production restent inchangés.
Les trois nouveaux fichiers de production sont analysés eux aussi.
Les deux correspondances initiales des fixtures de tests restent mesurées
séparément : 54 lignes rapportées, 0,41 % sur 35 fichiers ; elles ne sont pas
masquées et ne sont pas une dette de production acceptée dans la référence.

Les traitements communs sont regroupés dans des helpers limités à leur rôle :
chargement global de Dolibarr, constantes ICS/DAV, génération vCard et parsing,
chargement des objets natifs, verrouillage/résolution Agenda, dates iCalendar,
valeurs de tâches commerciales et réponse multiget. Les différences métier
restent explicites et les API Dolibarr/Sabre gardent la maîtrise des écritures,
autorisations et formats. Aucun schéma ni dépendance d'exécution n'est ajouté.

La CI ajoute un job indépendant avec jscpd 5.4.3 épinglé et conserve ses rapports
HTML/JSON. Un clone réel injecté dans une copie temporaire fait échouer le job,
y compris après ajout à une référence temporaire. Son retrait rétablit le succès.
La [qualification 6.3.2](validation-factorisation-cdav.md) détaille les tests
unitaires, les comparaisons avant/après, les suites HTTP natives et PHPCS.

Zéro correspondance à ce seuil ne signifie pas absence de toute ressemblance
sémantique ou de petits blocs similaires. Les contrôles avant transaction et
sous verrou restent tous deux exécutés ; leur rôle temporel est conservé.
