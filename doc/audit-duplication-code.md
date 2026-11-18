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

## Correspondances à factoriser

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

Le mode par défaut refuse les nouveaux clones absents de l'état de référence
`.jscpd-baseline.json`. Pour actualiser cette référence après une revue explicite :

```sh
bash tests/duplication.sh update-baseline
```

Le contrôle a été éprouvé dans une copie temporaire du dépôt, y compris avec
un chemin contenant des espaces : référence acceptée, véritable copie d'un
fichier PHP refusée, retour au succès après retrait, mauvaise version de l'outil
refusée. Aucun PHP métier ni accès à une base n'est nécessaire à cette preuve.

Les preuves brutes sont conservées localement dans `dist/qa/duplication/`, dont
`scan-manifest.json`, `gate-checks.json` et les rapports par périmètre.

## Objectif de factorisation

La demande suivante porte sur la suppression des 25 correspondances mesurées.
Le seuil reste fixé à 10 lignes / 100 tokens et les fichiers de production ne
doivent pas être masqués pour obtenir un résultat nul. La référence sera mise
à jour après factorisation et qualification des comportements natifs.
