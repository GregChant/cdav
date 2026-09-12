# Découverte automatique CalDAV/CardDAV

Les URI `/.well-known/caldav` et `/.well-known/carddav` sont situées à la
racine du domaine et ne peuvent donc pas être installées par un module placé
dans `custom/cdav`. Ajouter une des règles suivantes au virtual host, puis
recharger le serveur web. La cible utilise ensuite `dol_buildpath()` pour
respecter l'URL configurée par Dolibarr.

## Apache

```apache
RewriteEngine On
RewriteRule ^/\.well-known/(caldav|carddav)$ /custom/cdav/well-known.php [R=307,L,NE]
```

Si le répertoire personnalisé est directement monté sous `/cdav`, remplacer
`/custom/cdav/` par `/cdav/`.

## nginx

```nginx
location ~ ^/\.well-known/(caldav|carddav)$ {
    return 307 /custom/cdav/well-known.php;
}
```

Le test attendu est une redirection vers le point d'entrée `server.php`, sans
identifiants dans l'URL et sans changement d'entité via la chaîne de requête.
