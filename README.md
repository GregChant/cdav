# CDav module for Dolibarr

## What is it ?

This branch targets Dolibarr 23.0 and 24.0 and adds CardDAV / CalDAV and ICS synchronisation. It uses Dolibarr's bundled [Sabre/DAV](http://sabre.io/dav/) server library.

You can :

 * Read and edit calendars through CalDAV
 * Read and edit project tasks through CalDAV
 * Read and edit intervention cards through CalDAV
 * Read and edit address books through CardDAV
 * Read calendars through ICS Full version or only Free/Busy (hide details)
 * Reuse Dolibarr's native DAV module for controlled WebDAV document access
 * Generate project tasks from documents like proposals and/or orders

Each user can access his/her contacts and thirdparties address books (public and own private contacts), his/her own calendar and other users calendars according to his/her rights.

Dolibarr contact informations fill personnal informations in client software cards (including contact photo).

Society (thirdparty) informations (to which contact is attached) fill professional informations in client software cards.

Three adress books are proposed to sync : Contacts, Thirdparties and Members. If you want to modify a thirdparty infomation, do it in thirdparties address book.

It is possible to select which contacts to sync with CDAV_CONTACT_TAG configuration value in Home / Setup / Other setup. Enter a contact tag value and then only contacts with this tag will be synced (empty value for all).

Calendar records with "Status / Percentage" set to "Not applicable" are converted to events in CalDAV (VEVENT), others are converted to tasks (VTODO).

Recurring events are kept as one DAV resource. Their recurrence rules, exceptions,
invitations and alarms are preserved in the CDav metadata table while Dolibarr
continues to own the event's title, dates, notes, location and availability.

Version 4.0.2 normalizes rich-text descriptions with Dolibarr's native cleaner
before CalDAV/CardDAV export, produces RFC-compliant folded calendar/card data,
localizes contact civilities, repairs Full/Free-Busy ICS subscriptions, and uses
Dolibarr's native permission/loading/logging APIs to prevent PHP warning storms.

Version 4.0.3 completes the French interface, replaces invalid sentence-based
translation keys, and localizes CardDAV address-book names and private ICS
Free/Busy labels using the Dolibarr user's language.

Version 4.0.4 hardens two-way synchronization. CardDAV creations and updates use
Dolibarr's native Contact, ThirdParty and Member APIs, preserve external resource
names and UIDs, clear fields removed on a phone, and resolve company links from
the vCard organization. CalDAV writes use the native ActionComm, Project Task and
Intervention APIs; recurrence, invitations, attendance and alarms are retained.
Deleting a contact, third party or member from a DAV client only deactivates the
Dolibarr record. Definitive deletion remains possible exclusively in Dolibarr.

Version 4.0.5 hardens the DAV transport and protocol surface. Basic
authentication now requires HTTPS except for loopback integration tests,
request bodies have a configurable global size limit, decoded traversal paths
are rejected, sensitive responses are not cached, and the Sabre/DAV version is
not disclosed. Set `CDAV_ALLOW_INSECURE_HTTP` only for a deliberately isolated
test environment; it must remain disabled in production.

When TLS terminates on a reverse proxy, list that proxy's exact connection IP
in `CDAV_TRUSTED_PROXY_IPS`. Forwarded HTTPS headers from every other address
are ignored so a direct HTTP caller cannot enable Basic authentication merely
by spoofing `X-Forwarded-Proto`.

Version 4.0.6 integrates appointment attachments with Dolibarr's native
document model. Credential-free HTTPS `ATTACH` URIs are mirrored through the
native `Link` API and appear on the appointment Documents tab; native links and
securely served agenda files are exported back as `ATTACH`. Inline attachments
remain lossless CalDAV metadata but are not silently written to disk. The CDav
endpoint no longer publishes a raw filesystem tree: document WebDAV is
delegated to Dolibarr's native DAV module.

Version 5.0.0 completes the P1/P2 reliability work. It adds real RFC 6578
incremental sync tokens and tombstones, lossless vCard extensions and social
profiles, native-compatible recurrences and opt-in browser reminders. Optional
CalDAV delegation, local RFC 6638 inbox/outbox scheduling and RFC 8607 managed
attachments are backed by persistent stores and Dolibarr ACLs. Conflicting
UIDs and replayed PUTs are handled deterministically. Managed files use the
native Agenda document directory and upload security pipeline.

Dolibarr remains the business source of truth. Contact, third-party, member,
agenda, project-task and intervention writes use native business classes. A
CardDAV DELETE only deactivates a contact, third party or member; permanent
deletion is available only from Dolibarr.

Automatic tasks generation in projects with services from linked Propositions and/or Orders 
Module setup offer you to :

 * generate tasks from linked docuement(s) OR not
 * synchronize project tasks as calendar events AND/OR todo tasks 
 * set up 3 initial tasks that will appear before services coming from document(s)
 * set up 3 final tasks that will appear after services coming from document(s)
 * define user role in project to select user to attribute on generated tasks from document(s)
 * define user role on new project task creation
 * define start and end time of a working day
 * restrict services to be converted as task by specifying a tag
 * force generation of tasks for each service lines from attached documents with cdav duration if tag is missing

Durations are retrieved from service's card if defined (minutes, hours, days or weeks only), otherwise from extrafield filled in documents
All tasks are begining at the starting date of the project, at the begining of the working day
Multi-day durations tasks are maintained as a single task, eg from 31/07/2018 at 8am to 02/08/2018 at 7pm
 
Usage :

 * Manually create a project, link it to a third party, and set up the date ; leave it in draft status
 * Attach document(s) : proposals or orders including at least 1 concerned service.
 * Affect contact(s) with correct role
 * Validate project : all tasks are created ; use your ics client software to retrieve and drag-drop events if necessary
 
Notes :

 * Description of services are useful to create subtasks if '- ' are detected at the begining of a line ; then, with DAVx⁵ (CalDAV/CardDAV Synchronization and Client) and Tasks (Keep track of your list of goals) you will be able to use checkboxes for these tasks
 * If you chose to synchronize project tasks as calendar events AND todo tasks, modifying a task will autoamticaly modify the corresponding event and reciprocally.
 * Tasks can be modified from client application but not cancelled : Dolibarr keep trace of last affectation
 * After generating tasks, you can modify/complete each of them or create more tasks manually (here too you can fill description zone with '- ' at the begining of lines to create subtasks)


## Help improvements

If you find the module is useful and want to finance improvements, consider to pay it on [Dolistore](https://www.dolistore.com/fr/modules/526-Synchronisation-CardDAV---CalDAV---ICS.html)

## How to install

PHP 8.0+ and Dolibarr 23.0+ are required. Version 5.0.0 is integration-tested
against local Dolibarr 23.0 and 24.0 installations.

Dolibarr native calendar module must be activated *before* installing CDav module.

* Clone repository _git clone https://github.com/Befox/cdav.git_ and install cdav directory in dolibarr/htdocs/
* Or unzip [last release](https://github.com/Befox/cdav/archive/master.zip), rename _cdav-master_ to _cdav_ and copy it into dolibarr/htdocs/

Enable CDav module in Interfaces Modules list.

It would add a link in Agenda left menu and in Contacts left menu to access DAV / ICS URLs.

Use these URLs in your CardDAV or CalDAV client software.

## How to upgrade

* Disable CDav module in Interfaces Modules list.
* Unzip last version or _git pull_ in dolibarr/htdocs/cdav
* Enable CDav module in Modules list.

After updating an existing installation, disable and enable the module once so
Dolibarr's module loader applies the idempotent schemas and migrations. The
`llx_cdav_*` tables contain only protocol state that has no native equivalent:
stable resource mappings, lossless vCard/iCalendar data, native-link/reminder
provenance, RFC 6578 journals, scheduling queues and managed-attachment
metadata. Foreign keys bind event/user metadata to the native lifecycle.

## Security-sensitive options

RFC 6578 sync is always available and retains 180 days of journal history by
default. The following features remain disabled after installation or upgrade
and must be enabled deliberately in the module setup page:

* `CDAV_NATIVE_REMINDERS`: projects only compatible relative `DISPLAY` alarms
  to Dolibarr browser reminders; email and SMS are never generated by DAV.
* `CDAV_DELEGATION`: exposes proxy principals derived from native Agenda
  all-actions rights. DAV clients cannot change those memberships.
* `CDAV_SCHEDULING`: enables persistent local iTIP inbox/outbox handling. No
  iMIP email transport is installed.
* `CDAV_MANAGED_ATTACHMENTS`: enables protected RFC 8607 uploads with native
  Dolibarr filename/antivirus checks, size/count/user quotas and active-content
  rejection.

Keep `CDAV_ALLOW_INSECURE_HTTP` disabled outside an isolated test system. Set
`CDAV_TRUSTED_PROXY_IPS` only to exact reverse-proxy connection addresses. See
[`doc/well-known-webserver.md`](doc/well-known-webserver.md) for HTTPS proxy and
RFC 6764 discovery configuration.

The lightweight regression suite can be run without a Dolibarr database:

    DOLIBARR_ROOT=/path/to/dolibarr/htdocs php tests/run.php

The opt-in live suite exercises Basic authentication and real HTTP
CardDAV/CalDAV requests against an explicitly named test database. It creates
an isolated Dolibarr user and uniquely prefixed records, verifies native
Contact, Societe and ActionComm data, then removes the test data and restores
the previous CDav constants:

    CDAV_LIVE_TEST=1 \
    DOLIBARR_ROOT=/path/to/test-dolibarr/htdocs \
    CDAV_LIVE_URL=http://127.0.0.1/custom/cdav/server.php/ \
    php tests/live_integration.php

The live suite refuses to run unless the configured database name contains
`test`. It covers native contact/company/event round trips, soft deletion,
vCard/iCalendar losslessness, recurrence/reminders, links and managed files,
scheduling/delegation, RFC 6578 initial/delta/deletion sync, UID conflicts,
conditional requests, locks, collection mutation attempts, multi-user ACLs,
malformed/hostile XML, traversal attempts and request/attachment limits.


## DAV URLs

### Thunderbird

[Thunderbird](https://www.thunderbird.net) (with [Lightning](https://addons.mozilla.org/thunderbird/addon/lightning/), [TBSync](https://addons.thunderbird.net/thunderbird/addon/tbsync/) and its [Provider for CalDAV/CardDAV](https://addons.thunderbird.net/thunderbird/addon/dav-4-tbsync/) addons) needs a precise URL for each address book and calendar :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/calendars/<connected-user-login>/<calendar-user-id>-cal-<calendar-user-login>

    https://server.example.com/dolibarr/htdocs/cdav/server.php/addressbooks/<connected-user-login>/default/

### DAVx⁵

[DAVx⁵](https://www.davx5.com/) can detect automatically address book and all existing calendars (if an event exists) with generic DAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/server.php

You can use a tasks application to manage Dolibarr tasks (VTODO) on Android. DAVx⁵ is compatible with [OpenTasks](https://github.com/dmfs/opentasks).

In CDav configuration, you can activate a QRCode display to autoconfigure DAVx⁵.

Be carefull, if you use https, DAVx⁵ needs a valid SSL certificate, excluding auto-signed certificates.

DAVx⁵ is also available on [F-Droid](https://f-droid.org/packages/at.bitfire.davdroid/).

### iOS

iOS uses _principals_ url to grab list of CalDAV or CardDAV resources :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/principals/<connected-user-login>

### WebDAV

Document WebDAV is intentionally handled by Dolibarr's native DAV module and
its public/private/ECM settings. CDav no longer exposes the complete Dolibarr
data directory through the calendar/contact endpoint, even to administrators,
because that would bypass document-level scoping and could expose unrelated
backups or temporary files.

    https://server.example.com/dolibarr/htdocs/dav/fileserver.php/

## Troubleshooting

To test cdav module, you can use DAVx⁵ url https://server.example.com/dolibarr/htdocs/cdav/ in a web browser. Error messages are clearer.

### Apache web server

Apache *rewrite* module is necessary if you use fcgi or php-fpm mode. In this case, .htacess file in cdav module has to be read by Apache or reported in your Apache configuration.

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

or

    <IfModule mod_fastcgi.c>
    	<IfModule mod_rewrite.c>
    		RewriteEngine on
    		RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    	</IfModule>
    </IfModule>

or (this is usefull on Plesk when nginx is proxying Apache)

    FcgidPassHeader AUTHORIZATION

It is recommanded to *disable* these Apache modules : *dav* / *dav_fs* / *dav_lock*

### nginx web server

To solve authentication loop, add these directives to your nginx "location" rubrique : 

    fastcgi_param PHP_AUTH_USER $remote_user;
    fastcgi_param PHP_AUTH_PW $http_authorization;

or

    fastcgi_pass_header Authorization;

### nginx reverse proxy

To solve authentication loop, add this directive to your nginx "location" rubrique :

    proxy_pass_header Authorization;
