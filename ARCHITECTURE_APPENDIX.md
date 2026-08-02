# SimpleLogin Plugin — Architecture Appendix

**Component:** `plg_system_simplelogin`
**Geteste versie:** 1.1.05 (Joomla 6.x, PHP 8.1+)
**Laatst bijgewerkt:** 2 augustus 2026

**Doel van dit document:** documenteert de actuele implementatie — bestanden, klassen, database, afhankelijkheden, bekende beperkingen — en het ADR-archief. Voor de architectuurprincipes die hieraan ten grondslag liggen, zie [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Inhoudsopgave

1. [Bestandsstructuur](#bestandsstructuur)
2. [Klassenstructuur](#klassenstructuur)
3. [Component diagram (routing)](#component-diagram-routing)
4. [Registratie- en goedkeuringsflow (implementatie)](#registratie--en-goedkeuringsflow-implementatie)
5. [Standaardinstellingen] #Standaard instellingen
6. [Database](#database)
7. [Afhankelijkheden](#afhankelijkheden)
8. [Beveiligingsreview 1.1.0](#beveiligingsreview-110)
9. [Bekende beperkingen](#bekende-beperkingen)
10. [Versiegeschiedenis](#versiegeschiedenis)
11. [Architecture Decision Records](#architecture-decision-records)

---

## Bestandsstructuur

```
plg_system_simplelogin/
├── ARCHITECTURE.md
├── ARCHITECTURE_APPENDIX.md
├── DEFAULT.md
├── README.md
├── CHANGELOG.md
├── script.php                       # postflight/uninstall: cache clearing
├── simplelogin.xml
│
├── src/
│   ├── Extension/
│   │   └── Simplelogin.php          # Hoofdklasse, event-subscripties
│   │
│   ├── Field/                       # Custom admin form fields
│   │   ├── ApprovalreportField.php  # Wachtrij goedkeuring (tabel)
│   │   ├── BodybuttonsField.php
│   │   ├── ExportlogField.php
│   │   ├── HashpasswordsField.php
│   │   ├── LogreportField.php
│   │   ├── RegistrationstatusField.php  # Lokale, live mirror van
│   │   │                                # com_users.allowUserRegistration
│   │   │                                # t.b.v. showon (zie ADR-0005)
│   │   └── ThrottlereportField.php
│   │
│   ├── Helper/
│   │   └── ReportHelper.php         # Queryhelpers voor de admin fields
│   │
│   ├── Service/
│   │   ├── MailService.php          # Verzending + HTML-placeholders
│   │   │                             # + local-image CID-embedding
│   │   └── MailServiceInterface.php
│   │
│   ├── Traits/
│   │   ├── AjaxTrait.php             # com_ajax dispatcher + admin-only acties
│   │   ├── LoginFlowTrait.php
│   │   ├── LogTrait.php
│   │   ├── RegisterFlowTrait.php     # incl. invite/goedkeuringsflow
│   │   ├── SecurityTrait.php
│   │   └── UtilityTrait.php
│   │
│   └── tmpl/
│       ├── approvals.php            # Approve/reject admin UI
│       ├── logs.php
│       ├── logs_table.php
│       └── throttle.php
│
├── services/
│   └── provider.php                 # DI: registreert MailServiceInterface + plugin
│
├── layouts/
│   └── simplelogin/
│       ├── overlay.php              # Login-modal (frontend)
│       └── register.php             # Registratie-modal (frontend)
│
├── language/
│   ├── de-DE/ · en-GB/ · es-ES/ · fr-FR/ · nl-NL/
│
├── media/
│   └── js/
│       ├── bodybuttons.js           # Variabele-invoegknoppen, editor-agnostisch
│       ├── hashpasswords.js
│       ├── logreport.js
│       └── simplelogin.js           # Frontend overlay-gedrag (autosubmit/redirect)
│
└── sql/
    ├── install.mysql.utf8.sql
    ├── uninstall.mysql.utf8.sql
    └── updates/mysql/1.1.0.sql
```

---

## Klassenstructuur

`Simplelogin` (extends `CMSPlugin`) stelt zijn gedrag volledig samen uit traits:

| Trait | Verantwoordelijkheid |
|---|---|
| `LoginFlowTrait` | Hoofdrouting (`handleInitialise`), overlay-rendering (`handleRender`), login-/tokenflows, versturen van loginlinks |
| `RegisterFlowTrait` | Registratieformulier, invite-tokenactivatie (GET + POST), versturen van invite-links |
| `SecurityTrait` | Rate limiting (IP/user), cooldown, scanner-/preflight-detectie, wachtwoordafdwinging |
| `LogTrait` | Centrale `log()`-methode, status→type/throttle/debug-only-definitietabel, IP/UA/e-mail-hashing |
| `UtilityTrait` | Tokengeneratie/-consumptie, cleanup, e-mailvalidatie, gebruikersnaamgeneratie, activatiemarker-helpers, PRG-redirect |
| `AjaxTrait` | `onAjaxSimplelogin`-dispatcher en de zes admin-only AJAX-acties (hash passwords, get/purge logs, export log, approve/reject user) |

`MailService` (implementeert `MailServiceInterface`) wordt via de DI-container (`services/provider.php`) geïnjecteerd in plaats van direct geïnstantieerd, zodat de service vervangbaar/mockbaar is.

---

## Component diagram (routing)

```
Frontend request
      │
      ▼
onAfterInitialise (LoginFlowTrait::handleInitialise)
      │
      ├── sl_task=register ──────────► RegisterFlowTrait::handleRegister()
      │                                       │
      │                                       ├── GET  → toon registratieformulier
      │                                       └── POST → maak gebruiker aan (block=1 indien
      │                                                   require_admin_approval), stuur invite
      │
      ├── simplelogin=1 + selector/validator (GET)
      │        └──► LoginFlowTrait::handleTokenFlow()
      │                    ├── type=invite ──► RegisterFlowTrait::handleInviteActivation()
      │                    └── type=login  ──► toont "bezig met inloggen…" + auto-submittend
      │                                         verborgen POST-formulier (geen login op GET)
      │
      ├── simplelogin=1 + selector/validator (POST)
      │        ├── type=invite ──► RegisterFlowTrait::handleInvitePostActivation()
      │        └── type=login  ──► LoginFlowTrait::handleTokenPost()
      │                                  └── password_verify() → handleTokenLogin()
      │
      └── simplelogin=1 (POST, geen token) ──► LoginFlowTrait::handlePost()
                                                    └── SecurityTrait rate/cooldown
                                                        checks → sendLoginLink()

onAfterRender  → injecteert overlay/register-layout HTML in de pagina
onAjaxSimplelogin (com_ajax) → AjaxTrait-dispatcher (admin-only: HashPasswords,
                                GetLogRows, PurgeLogRows, ExportLog, ApproveUser, RejectUser)
onContentPrepareForm → client-side afbeelding-URL-validatie voor het eigen
                        configuratieformulier (alleen admin)
```

---

## Registratie- en goedkeuringsflow (implementatie)

```
POST register form
      │
      ▼
Gebruiker aangemaakt: block = (require_admin_approval ? 1 : 0)
              activation = 'sl-pending:<random>'
      │
      ▼
Invite-mail verstuurd (selector/validator link, type=invite)
      │
      ▼
Gebruiker klikt invite-link (GET) ──► handleInviteActivation()
      │
      ├── require_admin_approval = 0
      │        └──► token direct verwerkt, block blijft zoals gezet,
      │             account bruikbaar, loginlink direct verstuurd
      │
      └── require_admin_approval = 1
               └──► token verwerkt, activation geleegd (e-mail nu geverifieerd),
                    block blijft = 1 → account in "pending approval"-wachtrij
                         │
                         ▼
               Admin opent Plugins → System - Simplelogin → ziet de
               pending-approval-tabel (ApprovalreportField / approvals.php)
                         │
              ┌──────────┴──────────┐
              ▼                     ▼
         Approve                Reject
    (block = 0, verstuurt    (verstuurt afkeurmail met
     approval-mail indien     door admin ingevoerde reden,
     al geactiveerd)          VERVOLGENS permanente verwijdering
                               van gebruiker + usergroup-mappings)
```

**Scoping van de wachtrij:** `ReportHelper::getPendingApprovals()` beperkt zich bewust tot geblokkeerde gebruikers mét een gekoppelde `#__simple_login`-rij van `type = 'invite'`. Dit koppelt de lijst — en dus wat Approve/Reject kan raken — specifiek aan accounts die via déze plugin zijn geregistreerd. Zie [Beveiligingsreview, bevinding 2](#beveiligingsreview-110) en [ADR-0006](#adr-0006-goedkeuringswachtrij-scopen-via-invite-token-niet-via-generieke-blocked-status) voor de achtergrond.

---

## Standaard instellingen

Dit hoofdstuk vermeldt elke configuratieparameter die door de plugin wordt aangeboden (Extensies → Plugins → Systeem - Simplelogin), gegroepeerd per veldset waarin deze verschijnt, met de standaardwaarde 'out-of-the-box' en wat de parameter regelt. Zie [README.md](README.md) voor een algemene handleiding om te beginnen; zie [ARCHITECTURE.md](ARCHITECTURE.md) voor hoe deze parameters intern worden gebruikt.

Tenzij anders vermeld betekent "standaard": de waarde die Joomla gebruikt wanneer de plugin nieuw is geïnstalleerd en de beheerder het veld nog niet heeft aangepast.

---

### Veldset: Inloginstellingen (`general`)

| Parameter | Type | Standaard | Betekenis |
| --- | --- | --- | --- |
| `landing_page_option` | radio | `homepage` | Waar een gebruiker terechtkomt na een succesvolle inlog: de homepage van de site, of een gekozen menu-item. |
| `landing_itemid` | menu item | *(leeg)* | Het menu-item waarnaar wordt omgeleid wanneer `landing_page_option = custom`. Anders geen effect. |
| `allow_password_login` | radio | `0` (Nee) | Indien `Nee`, vervangt de plugin de standaard Joomla-wachtwoordinlog/-registratie volledig door de wachtwoordloze stroom, en dwingt dit af door wachtwoorden te husselen (scramblen) bij elke succesvolle wachtwoordloze inlog. Indien `Ja`, wordt er ook een optie "inloggen met wachtwoord" aangeboden. |
| `bulk_hash_passwords` | knop | n.v.t. | Eenmalige beheerdersactie: randomizeert onmiddellijk het wachtwoord van elke non-admin, niet-geblokkeerde frontend-gebruiker, waardoor inloggen met wachtwoord voor hen voortaan onmogelijk wordt. Onomkeerbaar; bedoeld om te gebruiken direct nadat `allow_password_login` op `Nee` is gezet op een bestaande site. |

### Veldset: Goedkeuring (`approvalinformation`) — nieuw in 1.1.0

| Parameter | Type | Standaard | Betekenis |
| --- | --- | --- | --- |
| `require_admin_approval` | radio | `0` (Nee) | Indien `Ja`, blijft een nieuw zelfgeregistreerd account geblokkeerd na e-mailverificatie totdat een beheerder het expliciet goedkeurt in de goedkeuringstabel van de plugin. Indien `Nee`, is het verifiëren van de uitnodigings-e-mail voldoende om het account te activeren (onder voorbehoud van bestaande Joomla-registratie-instellingen). |
| `notify_admin_registration` | radio | `0` (Nee) | Indien `Ja`, ontvangt het `mailfrom`-adres van de site bij elke nieuwe registratie een notificatie-e-mail (zie `mail_admin_*` hieronder). Onafhankelijk van `require_admin_approval`. |
| `approval_report` | (beheerders-UI) | n.v.t. | Toont de tabel met in afwachting zijnde goedkeuringen met Goedkeuren/Afwijzen-knoppen. Alleen zichtbaar wanneer `require_admin_approval = Ja`. |

### Veldset: E-mailinstellingen (`mail_instellingen`)

| Parameter | Type | Standaard | Betekenis |
| --- | --- | --- | --- |
| `mail_format` | radio | `html` | Of alle uitgaande e-mails van de plugin worden verzonden als HTML (opgemaakte tekst, ondersteunt ingesloten lokale afbeeldingen) of platte tekst. Het wijzigen hiervan bepaalt welke van de gekoppelde `*_body` / `*_body_html` velden hieronder daadwerkelijk wordt gebruikt. |

Elk van de vier e-mailtypen hieronder volgt hetzelfde patroon: een `show_*_mail` schakelaar (standaard **uit** voor alle vier) die alleen bepaalt of die veldgroep wordt *weergegeven* in het beheerdersformulier (het schakelt het verzenden van de e-mail zelf niet uit — de e-mail wordt altijd verzonden wanneer de stroom wordt geactiveerd), een onderwerpregel, een platte-tekst body en een HTML body. Alleen de body die overeenkomt met het huidige `mail_format` wordt daadwerkelijk gebruikt bij het verzenden.

#### Inlog-e-mail

| Parameter | Standaard |
| --- | --- |
| `show_login_mail` | `0` (standaard verborgen in het beheerdersformulier) |
| `mail_login_subject` | `Here's your login link` |
| `mail_login_body` (tekst) | `#name,` / lege regel / `Here's your link to login:` / `#link` / lege regel / `Please note that the link is valid for #expiry minutes.` |
| `mail_login_body_html` (HTML) | `<p>#name,</p><p>Here's your link to login:<br><a href="#link">#link</a></p><p>Please note that the link is valid for #expiry minutes.</p>` |

Beschikbare placeholders: `#name`, `#link`, `#expiry`, `#sitename`.

#### Uitnodiging / registratie-e-mail

| Parameter | Standaard |
| --- | --- |
| `show_invite_mail` | `0` |
| `mail_invite_subject` | `Your confirmation link` |
| `mail_invite_body` (tekst) | `#name,` / lege regel / `Here's your link to confirm your registration:` / `#link` / lege regel / `Please note that the link is valid for #expiry minutes.` |
| `mail_invite_body_html` (HTML) | `<p>#name,</p><p>Here's your link to confirm your registration:<br><a href="#link">#link</a></p><p>Please note that the link is valid for #expiry minutes.</p>` |

Beschikbare placeholders: `#name`, `#link`, `#expiry`, `#sitename`.

#### Beheerdersnotificatie-e-mail

Alleen verzonden/getoond wanneer `notify_admin_registration = Ja`.

| Parameter | Standaard |
| --- | --- |
| `show_admin_mail` | `0` |
| `mail_admin_subject` | `New registration on #sitename` |
| `mail_admin_body` (tekst) | `#name (#email) has registered on #sitename.` / lege regel / `If admin approval is needed goto to the plugin and decide your action for this new user.` |
| `mail_admin_body_html` (HTML) | `<p>#name (#email) has registered on #sitename.</p><p>If admin approval is needed goto to the plugin and decide your action for this new user.</p>` |

Beschikbare placeholders: `#name`, `#email`, `#sitename`. Verzonden naar het ingestelde `mailfrom`-adres van de site, niet naar de registrant.

#### Goedkeurings-e-mail — nieuw in 1.1.0

Alleen relevant/getoond wanneer `require_admin_approval = Ja`.

| Parameter | Standaard |
| --- | --- |
| `show_approval_mail` | `0` |
| `mail_approval_subject` | `Your registration has been approved` |
| `mail_approval_body` (tekst) | `#name,` / lege regel / `Your registration has been approved. You can now login here:` / `#link` / lege regel / `This link is valid for #expiry minutes.` |
| `mail_approval_body_html` (HTML) | `<p>#name,</p><p>Your registration has been approved. You can now login here:<br><a href="#link">Click here to login</a></p><p>This link is valid for #expiry minutes.</p>` |

Beschikbare placeholders: `#name`, `#link`. Alleen daadwerkelijk verzonden als het account op het moment van goedkeuring de e-mailverificatie al had afgerond (zie [ARCHITECTURE.md](ARCHITECTURE.md#registration--approval-workflow)).

#### Afwijzings-e-mail — nieuw in 1.1.0

Alleen relevant/getoond wanneer `require_admin_approval = Ja`.

| Parameter | Standaard |
| --- | --- |
| `show_rejection_mail` | `0` |
| `mail_rejection_subject` | `Your registration has been rejected` |
| `mail_rejection_body` (tekst) | `#name,` / lege regel / `Your registration has been rejected.` / lege regel / `Reason: #reason` |
| `mail_rejection_body_html` (HTML) | `<p>#name,</p><p>Your registration has been rejected.</p><p>Reason: #reason</p>` |

Beschikbare placeholders: `#name`, `#reason` (de reden wordt door de beheerder ingevoerd bij het afwijzen en is — sinds de beveiligingsfixes van 1.1.0 — HTML-escaped voordat deze in een HTML-e-mail wordt ingevoegd).

### Veldset: Beveiliging (`security`)

#### Basislimieten

| Parameter | Standaard | Betekenis |
| --- | --- | --- |
| `expiry_minutes` | `15` | Hoe lang een inloglink geldig blijft. |
| `invite_expiry_minutes` | `30` | Hoe lang een uitnodigings-/registratielink geldig blijft. Wordt ook gebruikt als terugvalminimum (`max(1, ...)`) bij verkeerde configuratie. |
| `request_cooldown_seconds` | `30` | Minimale wachttijd die wordt afgedwongen tussen opeenvolgende inloglinkaanvragen voor hetzelfde IP of dezelfde gebruiker, onafhankelijk van de rate-limit-tellers hieronder. |

#### Rate limits (snelheidslimieten)

| Parameter | Standaard | Betekenis |
| --- | --- | --- |
| `rate_limit_ip_max` | `10` | Maximaal toegestane inloglinkaanvragen vanaf één IP binnen het onderstaande venster. |
| `rate_limit_ip_window` | `5` | Venster, in minuten, voor de rate-limit per IP. |
| `rate_limit_user_max` | `5` | Maximaal toegestane inloglinkaanvragen voor één gebruikersaccount binnen het onderstaande venster. |
| `rate_limit_user_window` | `10` | Venster, in minuten, voor de rate-limit per gebruiker. |

#### Niet zichtbaar in de beheerders-UI (alleen code-standaarden)

Deze twee worden door de code uitgelezen maar hebben geen bijbehorend beheerdersveld in deze release — een beheerder kan ze momenteel niet wijzigen zonder de opgeslagen pluginparameters rechtstreeks te bewerken:

| Parameter | Code-standaard | Betekenis |
| --- | --- | --- |
| `token_min_age_seconds` | `5` | Een token-POST die minder dan dit aantal seconden na het aanmaken van het token aankomt, wordt geweigerd als ongeloofwaardig (beveiliging tegen automatisering). |
| `password_login_itemid` | `0` | Menu-item ID om naartoe te linken voor de terugvaloptie "inloggen met een wachtwoord", wanneer `allow_password_login = Ja`. `0` valt terug op de standaard `com_users` inlogweergave. |

### Veldset: Sessies (`throttle_report`)

| Parameter | Standaard | Betekenis |
| --- | --- | --- |
| `throttle_cleanup_time` | `60` | Minuten waarna oude rijen in `#__simple_login_throttle` worden opgeschoond. Opschonen draait als bijeffect van een succesvolle inlog (`UtilityTrait::cleanup()`), niet via een planning (cron). |
| `throttle_report` | (beheerders-UI) | n.v.t. — toont de live throttle-tabel (alleen lezen). |

### Veldset: Logging (`log_report`)

| Parameter | Standaard | Betekenis |
| --- | --- | --- |
| `log_export` | (beheerders-UI) | n.v.t. — knop die de logrijen van de afgelopen 24 uur plus het ruwe plugin-logbestand e-mailt naar het `mailfrom`-adres van de site. |
| `log_retention_days` | `30` | Dagen waarna rijen in `#__simple_login_log` worden opgeschoond (zelfde opschoontrigger als de throttle-tabel hierboven). `0` schakelt log-opschoning volledig uit. |
| `log_report` | (beheerders-UI) | n.v.t. — toont de filterbare/verwijderbare logtabel. |

---

### Snelreferentie: standaardgedrag

Bij een schone installatie zonder configuratiewijzigingen zal SimpleLogin:

- De standaard Joomla-wachtwoordinlog/-registratie aan de frontend volledig vervangen (`allow_password_login = Nee`).
- **HTML** e-mail verzenden (`mail_format = html`), met behulp van de ingebouwde standaardsjablonen die hierboven staan vermeld — maar let op dat `show_login_mail`, `show_invite_mail`, `show_admin_mail`, `show_approval_mail` en `show_rejection_mail` in het beheerdersformulier allemaal standaard op **uit** staan; dit verbergt alleen de velden; de bijbehorende e-mail wordt nog steeds verzonden wanneer de stroom wordt uitgevoerd, met de bovenstaande standaardinhoud.
- **Geen** beheerdersgoedkeuring vereisen voor nieuwe registraties (`require_admin_approval = Nee`) en de beheerder **niet** op de hoogte stellen van nieuwe registraties (`notify_admin_registration = Nee`).
- Inloglinks laten verlopen na 15 minuten en uitnodigingslinks na 30 minuten, met een afkoelperiode (cooldown) van 30 seconden tussen aanvragen.
- Maximaal 10 aanvragen per IP per 5 minuten en 5 aanvragen per gebruiker per 10 minuten toestaan.
- Throttle-rijen ouder dan 60 minuten en logrijen ouder dan 30 dagen opschonen bij elke succesvolle inlog.

Als u `require_admin_approval` voor het eerst inschakelt, zet dan ook `show_approval_mail` en `show_rejection_mail` aan, zodat u de formulering van deze twee nieuwe 1.1.0-e-mailsjablonen kunt zien en desgewenst aanpassen — ze worden verzonden ongeacht of hun velden zichtbaar zijn in het beheerdersformulier.

## Database

### Entity-Relationship Diagram

```mermaid
erDiagram
    simple_login ||--o{ simple_login_throttle : "1-op-veel"
    simple_login ||--o{ simple_login_log : "1-op-veel"
    users ||--o{ simple_login : "1-op-veel"
    users ||--o{ simple_login_throttle : "1-op-veel"
    users ||--o{ simple_login_log : "1-op-veel"

    simple_login {
        int id PK
        int user_id FK
        char(16) selector
        varchar(255) token
        enum('login','invite') type
        datetime created
        datetime expires
        tinyint(1) used
    }

    simple_login_throttle {
        bigint id PK
        int user_id FK
        varchar(150) username
        char(64) email_hash
        varbinary(16) ip
        varchar(50) status
        int login_id FK
        datetime created
    }

    simple_login_log {
        bigint id PK
        enum type
        int user_id FK
        varchar(150) username
        char(64) email_hash
        varbinary(16) ip
        varchar(512) user_agent
        varchar(50) status
        int login_id FK
        datetime created
    }

    users {
        int id PK
        varchar(150) username
        varchar(255) email
        varchar(255) password
        varchar(255) activation
        tinyint(1) block
        ...
    }
```

### `#__simple_login`

Bewaart login- en invite-/registratietokens. De kolom `token` bevat een `password_hash()` van de validator-helft van het selector/validator-paar — de ruwe validator wordt nooit opgeslagen, alleen ooit gemaild.

| Kolom | Type | Omschrijving | Voorbeeld |
|---|---|---|---|
| `id` | INT UNSIGNED | Primary key | `1` |
| `user_id` | INT UNSIGNED | Joomla user ID | `42` |
| `selector` | CHAR(16) | Publieke token-identifier (lookup) | `a1b2c3d4e5f6g7h8` |
| `token` | VARCHAR(255) | Gehashte validator (`password_hash`) | `$2y$10$...` |
| `type` | ENUM('login','invite') | Tokendoel | `login` |
| `created` | DATETIME | Aanmaaktijdstip | `2026-07-05 10:00:00` |
| `expires` | DATETIME | Vervaltijdstip | `2026-07-05 10:15:00` |
| `used` | TINYINT(1) | Of token gebruikt is | `0` of `1` |

**Indexen:** PRIMARY (`id`), UNIQUE (`selector`), INDEX (`user_id`, `expires`, `used`)

### `#__simple_login_throttle`

Houdt aanvraagfrequentie bij voor rate limiting en beveiligingsmonitoring. Rijen worden periodiek opgeruimd (`throttle_cleanup_time`, default 60 minuten) via `cleanup()` na een succesvolle login.

| Kolom | Type | Omschrijving |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key |
| `user_id` | INT UNSIGNED | Joomla user ID (nullable) |
| `username` | VARCHAR(150) | Gebruikersnaam (nullable) |
| `ip` | VARBINARY(16) | Packed IP-adres (IPv4/IPv6) |
| `status` | VARCHAR(50) | Actietype |
| `login_id` | INT UNSIGNED | Verwijzing naar `simple_login.id` |
| `created` | DATETIME | Tijdstip |

**Indexen:** PRIMARY (`id`), INDEX (`ip+created`, `user_id+created`, `login_id+created`, `status+created`)

### `#__simple_login_log`

Auditlog voor alle plugin-acties.

| Kolom | Type | Omschrijving |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key |
| `type` | ENUM | Logcategorie (zie tabel hieronder) |
| `user_id` | INT UNSIGNED | Joomla user ID (nullable) |
| `username` | VARCHAR(150) | Gebruikersnaam (nullable) |
| `email_hash` | CHAR(64) | SHA-256-hash van e-mail |
| `ip` | VARBINARY(16) | Packed IP-adres |
| `user_agent` | VARCHAR(512) | Browser user agent |
| `status` | VARCHAR(50) | Specifieke actie |
| `login_id` | INT UNSIGNED | Verwijzing naar `simple_login.id` |
| `created` | DATETIME | Tijdstip |

**Indexen:** PRIMARY (`id`), INDEX (`type+created`, `status+created`, `user_id`, `login_id`)

**Migratie 1.1.0** (`sql/updates/mysql/1.1.0.sql`): verbreedt de `type`-ENUM met `ImageError` (de eerdere `admin_approved_registration`/`admin_rejected_registration`-waarden zijn *status*-waarden, geen `type`-waarden — zie [Beveiligingsreview, bevinding 3](#beveiligingsreview-110)).

### Log-types & statussen

| Type | Omschrijving | Voorbeeldstatussen |
|---|---|---|
| `AccountEvent` | Gebruikersaccount-gerelateerde events | `password_updated`, `register_success`, `admin_approved_registration`, `admin_rejected_registration` |
| `DebugDiagnostics` | Debug-only diagnostische info | `invite_email_not_found`, `token_row_missing` |
| `DebugFlowTrace` | Debug flow tracing | `core_login_blocked`, `simplelogin_triggered` |
| `DebugRequestTrace` | Request-parameter tracing | `selector_xxx`, `validator_present_yes` |
| `InviteFlow` | Registratie-/uitnodigingsflow | `invite_sent`, `invite_activated`, `invite_pending_approval` |
| `LoginFlow` | Login-flow events | `link_request`, `link_sent`, `login_success`, `token_hit` |
| `SecurityIncident` | Beveiligingsgerelateerde events | `rate_limited_ip`, `rate_limited_user`, `scanner_detected` |
| `ImageError` | Problemen bij CID-embedding van mailafbeeldingen | `image_not_found`, `image_too_large` |

---

## Afhankelijkheden

- **Joomla 6.x** core (Session, Factory, Mailer, User, Router, Uri, Layout, Log, HTML, Form, DI-container)
- **PHP 8.1+** (gebruikt `str_contains`/`str_starts_with`/`str_ends_with`, typed properties, arrow functions)
- Geen third-party Composer-packages naast wat Joomla core al aanbiedt (zie [ADR-0002](#adr-0002-geen-third-party-composer-dependencies))

---

## Beveiligingsreview 1.1.0

Ter voorbereiding van de 1.1.0-release is de codebase gecontroleerd op defecten die niet noodzakelijk via normaal handmatig testen aan het licht komen. De volgende bevindingen zijn gevonden en **direct in deze release opgelost**:

1. **Ongebruikte `onExtensionBeforeSave`-eventsubscriptie.** `getSubscribedEvents()` declareerde een handler zonder bijbehorende methode. Omdat dit een *system*-plugin is, wordt dit event sitewide gedispatcht bij het opslaan van elke extensie — niet alleen deze. Kon, afhankelijk van de defensiviteit van de core-versie, een fatale fout veroorzaken bij het opslaan van een ongerelateerde extensie. De dode subscriptie (en de bijbehorende ongebruikte helper `extractImageUrlsFromHtml()`) is verwijderd.

2. **Te brede pending-approvals-query.** `ReportHelper::getPendingApprovals()` had zijn beoogde `activation LIKE 'sl-pending:%'`-filter uitgecommentarieerd, waardoor **elke** geblokkeerde gebruiker werd geretourneerd, niet alleen wachtende SimpleLogin-registraties. Omdat "Reject" een gebruiker permanent verwijdert, kon een beheerder onbedoeld een ongerelateerd account verwijderen. Root cause: na e-mailverificatie wordt `activation` geleegd naar `''`, ononderscheidbaar van een normaal actief, later geblokkeerd account. Opgelost door de query te scopen op geblokkeerde gebruikers mét een gekoppelde invite-tokenrij in `#__simple_login`. Zie ook [ADR-0006](#adr-0006-goedkeuringswachtrij-scopen-via-invite-token-niet-via-generieke-blocked-status).

3. **Stilzwijgend uitgeschakelde auditlogging voor approve/reject.** De statussen `admin_approved_registration` en `admin_rejected_registration` hadden geen entry in `LogTrait::getStatusDefinition()`'s status→type-tabel. De fallback voor een onbekende status is `debugonly = true`, dus deze twee gevoelige, nieuwe admin-acties werden buiten debugmodus nooit gelogd. Beide statussen hebben nu een expliciete, niet-debug-only definitie.

4. **Onge-escapete placeholdersubstitutie in HTML-mail.** `MailService::sendMail()` substitueerde `#name`/`#email`/`#reason`/... met een ruwe `str_replace()`, zonder HTML-escaping. Een gebruiker die zich registreerde met HTML-dragende naam kreeg die HTML verbatim ingebed in HTML-mails. Opgelost door alle placeholderwaarden te escapen met `htmlspecialchars()` wanneer de mail in HTML-modus wordt verstuurd (onderwerpregel bewust niet geëscaped, want platte tekst). De sequentiële `str_replace()`-lus is tegelijk vervangen door één simultane `strtr()`-pass, die niet kan dubbel-substitueren.

5. **Twee parameters worden in code gelezen zonder admin-UI-veld.** `password_login_itemid` en `token_min_age_seconds` worden gelezen via `$this->params->get(...)` maar hebben geen `<field>` in `simplelogin.xml`. Onschadelijk (harde fallback-defaults `0` en `5`), maar een beheerder kan ze niet aanpassen. Bewust laten staan voor 1.1.0, gedocumenteerd in [DEFAULT.md](DEFAULT.md), geagendeerd voor 1.2.0.

6. **IP-gebaseerde rate limiting en cooldown waren stilzwijgend inactief.** `#__simple_login_throttle.ip` is `VARBINARY(16)`; de INSERT-kant converteerde correct met `UNHEX()`, maar `SecurityTrait::isRateLimitedIp()` en de IP-tak van `isCooldown()` vergeleken de binaire kolom direct met een niet-geconverteerde hex-string. Een `VARBINARY`-kolom kan nooit gelijk zijn aan een letterlijke ASCII-hex-string, dus beide checks retourneerden altijd `false` — geen enkele IP werd ooit geratelimiteerd, terwijl elke poging wél trouw werd weggeschreven (vandaar dat het probleem niet zichtbaar was in de admin-rapportages). Per-user rate limiting was niet geraakt. Alleen gevonden door actief te testen (de limiet bewust overschrijden), niet via codereview. Opgelost door beide vergelijkingen in `UNHEX()` te verpakken.

7. **PHP 8.2-deprecation corrumpeerde stilzwijgend de approve/reject AJAX JSON-response.** `MailService::processImagesForCidEmbedding()` gebruikte `mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8')` — de doelencoding `'HTML-ENTITIES'` is deprecated sinds PHP 8.2. Op een normale paginaweergave verdwijnt een stray deprecation-notice in de gerenderde HTML, maar ditzelfde codepad draait ook binnen de `com_ajax`/`format=json` Approve/Reject-eindpunten, waar PHP's deprecation-tekst — als `display_errors` deprecations toont — vóór de JSON-envelope in de response terechtkomt en de JSON ongeldig maakt. De browser's `.catch()` toont dan de generieke foutmelding, terwijl de onderliggende actie al succesvol was voltooid. Opgelost door de deprecated aanroep te vervangen door de huidige, niet-deprecated techniek (`<?xml encoding="UTF-8">`-declaratie die libxml als hint consumeert).

8. **Per-user rate limiting telde elk geslaagd loginlink-verzoek dubbel.** `SecurityTrait::isRateLimitedUser()` telde rijen met `status IN ('login_attempt_existing', 'link_sent')`. Voor een normale login-formulierinzending logt `LoginFlowTrait::handlePost()` `login_attempt_existing` en roept vervolgens `sendLoginLink()` aan, die bij succes zélf `link_sent` logt — voor hetzelfde verzoek. Eén ingediend verzoek produceerde dus twee getelde rijen, waardoor gebruikers na de helft van het geconfigureerde aantal pogingen al geratelimiteerd werden. `isRateLimitedIp()` was niet geraakt. Opgelost door in `isRateLimitedUser()` alleen `login_attempt_existing` te tellen. Gevonden door de product owner die de query doorredeneerde, niet door reproductie van een storing.

9. **Registratiegerelateerde configuratievelden bleven zichtbaar ondanks uitgeschakelde Joomla-registratie.** `simplelogin.xml` had geen `showon`-conditie die de invite-/goedkeuringsvelden koppelde aan `com_users.allowUserRegistration`. Twee mislukte pogingen, beide het vastleggen waard:
   - **Eerste poging**, `showon="com_users.allowUserRegistration:1"` op fieldset-niveau: werkt niet. Joomla's `showon` wordt client-side geresolved door JavaScript dat zoekt naar een formulierveld met matchende naam **in het DOM van het eigen formulier**. Een waarde uit een andere component wordt daar nooit gerenderd, dus de conditie faalt stil (toont alles) in plaats van een fout te geven.
   - **Tweede poging**, `showon` direct op het `<fieldset>`-element: werkte niet zichtbaar in deze Joomla-installatie, ook niet met een lokaal veld — bevestigd door de product owner, die dit al eerder zonder succes had geprobeerd. Per-veld `showon` werkt wél betrouwbaar.
   - **Werkende oplossing:** zie [ADR-0005](#adr-0005-registrationstatusfield-als-lokale-mirror-voor-showon).
   - **Resterende, bewust geaccepteerde beperking voor 1.1.0:** het tabblad zelf blijft altijd zichtbaar (nu met alleen een toelichtende notitie in plaats van een lege tab). Zie [Bekende beperkingen](#bekende-beperkingen).

Gevonden maar **bewust niet automatisch opgelost** (afwegingen, geen ondubbelzinnige bugs — zie [Bekende beperkingen](#bekende-beperkingen)):

- Het registratie-eindpunt heeft geen rate limiting/scanner-detectie, in tegenstelling tot het login-eindpunt.
- De GET-tokenflow onthult of een selector geldig/gebruikt/verlopen is vóórdat de secret validator gecontroleerd wordt (lage ernst — geen accountdata wordt blootgesteld).
- IP-gebaseerde rate limiting gebruikt alleen `REMOTE_ADDR`, zonder reverse-proxy/`X-Forwarded-For`-bewustzijn.

Eén aanvullende, risicoloze opschoning: een PHPUnit-teststub (`src/Service/MailServiceTest.php`) die in de gepackagede plugin zat, is verwijderd uit de productiezip (inert, maar hoorde daar sowieso niet thuis).

Deze review was gescoped op correctheids- en beveiligingsdefecten voor de 1.1.0-release, niet op een algemene kwaliteits-/duplicatiepas — die staat gepland voor 1.2.0.

---

## Bekende beperkingen

Gepland voor **1.2.0**:

- Algemene technical-debt-pas: duplicatie tussen `LoginFlowTrait`/`RegisterFlowTrait` verminderen (o.a. herhaalde "resolve return URL"- en "check user exists/not blocked"-blokken), methodelengte/cohesie doorlichten.
- Onderzoeken of het tabblad voor registratie-instellingen volledig verborgen kan worden (niet alleen de velden erin) wanneer `com_users.allowUserRegistration` uitstaat. Fieldset-level `showon` werkte niet bij directe test (zie beveiligingsreview, bevinding 9); voor 1.1.0 blijft de tab zichtbaar met een toelichtende notitie.
- Een echte PHPUnit-testsuite opbouwen (in een top-level `tests/`-map, uitgesloten van de installatiepackage) rond `MailService` en de token-helpers.
- Een klein aantal aanvullende beveiligingsverhardingen (rond throttling en tokenhandling) is voor 1.2.0 geagendeerd in een separaat, niet-publiek planningsdocument.
- Het registratie-eindpunt krijgt mogelijk dezelfde rate limiting/scanner-detectie als het login-eindpunt (product owner-afweging, nog geen besluit).

---

## Versiegeschiedenis

- **1.1.0** (2026-07-29) — Admin-goedkeuringsflow, HTML-mail + CID-afbeeldingsembedding, editor-agnostische variabele-knoppen, beveiligings-/defectreview (zie hierboven).
- **1.0.5** (2026-07-05) — Bugfixrelease: overlay toont alleen neutrale melding na link-opvraag; meldingsstijl gecorrigeerd.
- **1.0.x** — Initiële wachtwoordloze login/registratie, rate limiting, logging, introductie `MailService`.

---

## Architecture Decision Records

Vaste template per ADR: Status, Datum, Context, Beslissing, Consequenties, Alternatieven overwogen. Een geaccepteerde ADR wordt niet met terugwerkende kracht gewijzigd; een herzien besluit krijgt een nieuwe ADR die de vorige expliciet "supersedet" (zie [WAYOFWORK.md](WAYOFWORK.md)).

### Inhoudsopgave ADR's

| Nr | Titel | Status | Datum |
|---|---|---|---|
| [ADR-0001](#adr-0001-trait-gebaseerde-architectuur-met-geïnjecteerde-mailservice) | Trait-gebaseerde architectuur met geïnjecteerde MailService | Accepted | 2025 (pre-1.0) |
| [ADR-0002](#adr-0002-geen-third-party-composer-dependencies) | Geen third-party Composer-dependencies | Accepted | 2025 (pre-1.0) |
| [ADR-0003](#adr-0003-selectorvalidator-tokens-met-verplichte-post-voor-login) | Selector/validator-tokens met verplichte POST voor login | Accepted | 2025 (pre-1.0) |
| [ADR-0004](#adr-0004-packed-binary-opslag-van-ip-adressen) | Packed binary opslag van IP-adressen | Accepted | 2025 (pre-1.0) |
| [ADR-0005](#adr-0005-registrationstatusfield-als-lokale-mirror-voor-showon) | RegistrationStatusField als lokale mirror voor `showon` | Accepted | 2026-07-29 |
| [ADR-0006](#adr-0006-goedkeuringswachtrij-scopen-via-invite-token-niet-via-generieke-blocked-status) | Goedkeuringswachtrij scopen via invite-token, niet via generieke blocked-status | Accepted | 2026-07-29 |

---

### ADR-0001: Trait-gebaseerde architectuur met geïnjecteerde MailService

**Status:** Accepted
**Datum:** 2025 (pre-1.0)

**Context**
Een Joomla system-plugin heeft één centraal aanknopingspunt (`getSubscribedEvents()`) en gedraagt zich in de praktijk als één samenhangende requesthandler voor meerdere domeinen (login, registratie, beveiliging, logging). Een architectuur moest gekozen worden die deze domeinen scheidt zonder overhead die niet in verhouding staat tot de omvang van de plugin.

**Beslissing**
Businesslogica wordt verdeeld over traits, één per domein, samengesteld in de hoofdklasse. Alleen functionaliteit die vervangbaar/mockbaar moet zijn — op dit moment uitsluitend mailverzending — wordt als losse service via DI geïnjecteerd achter een interface (`MailServiceInterface`).

**Consequenties**
- Domeinen zijn gescheiden en individueel leesbaar zonder een eigen DI-graaf per domein.
- Traits delen impliciet de staat van de hoofdklasse (`$this->params`, `$this->app`, ...), wat lichte koppeling geeft die bij een servicelaag-per-domein niet zou bestaan.
- Nieuwe cross-cutting concerns die vervangbaar moeten zijn (bijv. een toekomstige SMS-service) volgen hetzelfde patroon als `MailService`, niet het traitpatroon.

**Alternatieven overwogen**
- *Eigen servicelaag per domein via DI*: verworpen als speculatieve architectuur voor een plugin van deze omvang; voegt DI-boilerplate toe zonder dat er een concrete behoefte is om deze domeinen los van elkaar te vervangen of te mocken.
- *Alles in de hoofdklasse*: verworpen, schendt Single Responsibility en maakt de klasse onhandelbaar naarmate flows toenemen.

---

### ADR-0002: Geen third-party Composer-dependencies

**Status:** Accepted
**Datum:** 2025 (pre-1.0)

**Context**
Joomla core biedt al Session-, Mailer-, HTML-, Form- en DI-functionaliteit. De vraag was of voor specifieke onderdelen (bijv. tokengeneratie, HTML-parsing voor CID-embedding) een gespecialiseerd package toegevoegd zou worden.

**Beslissing**
Geen third-party Composer-packages naast wat Joomla core al aanbiedt. HTML-parsing voor CID-embedding gebeurt met de ingebouwde `DOMDocument`, tokens met PHP's eigen `random_bytes()`/`password_hash()`.

**Consequenties**
- Geen extra kwetsbaarheidsoppervlak of versiebeheer-last van externe packages.
- Update-installaties blijven eenvoudig: geen `composer install` nodig als onderdeel van de Joomla-extensie-installatie.
- Sommige taken (zoals de PHP 8.2 `mb_convert_encoding`-deprecation, zie beveiligingsreview bevinding 7) vragen meer eigen zorgvuldigheid dan wanneer een onderhouden package dat zou afvangen.

**Alternatieven overwogen**
- *Gespecialiseerd HTML-sanitisation-package*: verworpen; `DOMDocument` volstaat voor de beperkte, lokale CID-embeddingbehoefte en voorkomt een Composer-afhankelijkheid in een Joomla-extensiecontext waar dat installatiecomplicaties kan geven.

---

### ADR-0003: Selector/validator-tokens met verplichte POST voor login

**Status:** Accepted
**Datum:** 2025 (pre-1.0)

**Context**
E-mailclients, linkscanners en preview-bots volgen links in mails automatisch via GET-requests, vaak vóórdat de daadwerkelijke ontvanger de mail zelf opent. Een simpel "klik-om-in-te-loggen"-token zou hierdoor per ongeluk geconsumeerd kunnen worden door een bot in plaats van de gebruiker.

**Beslissing**
Tokens bestaan uit een publieke selector (lookup-sleutel) en een geheime validator (nooit opgeslagen, alleen gehasht). Een GET op de link toont alleen een pagina met een auto-submittend, verborgen POST-formulier; de daadwerkelijke login/activatie voltrekt zich uitsluitend op POST.

**Consequenties**
- Linkscanners en preview-bots die alleen GET doen, kunnen een token nooit consumeren.
- Een echte gebruiker ervaart geen extra klik: het formulier submit automatisch via JavaScript.
- Gebruikers zonder JavaScript kunnen niet automatisch inloggen via de link (bewust geaccepteerde trade-off; niet apart gedocumenteerd als beperking omdat dit inherent is aan de beveiligingseis, geen implementatiegat).

**Alternatieven overwogen**
- *Directe GET-login*: verworpen, kwetsbaar voor bot-consumptie van tokens.
- *Extra bevestigingsstap voor de gebruiker (klik nogmaals)*: verworpen als onnodige wrijving; het auto-submittende formulier bereikt dezelfde beveiliging zonder gebruikersactie.

---

### ADR-0004: Packed binary opslag van IP-adressen

**Status:** Accepted
**Datum:** 2025 (pre-1.0)

**Context**
IP-adressen worden bewaard voor rate limiting en auditlogging. Zowel IPv4 als IPv6 moesten ondersteund worden, en opslag/privacy-overwegingen speelden mee bij de keuze van het kolomtype.

**Beslissing**
IP-adressen worden opgeslagen als `VARBINARY(16)` (packed binary), niet als leesbare string. Schrijven gebeurt via `UNHEX()` op een hex-representatie van het packed adres.

**Consequenties**
- Compacte, uniforme opslag voor zowel IPv4 als IPv6.
- Elke leesactie moet consistent dezelfde `UNHEX()`-conversie toepassen als de schrijfactie — het missen hiervan is precies wat maakte dat rate limiting een tijd lang stil faalde (zie beveiligingsreview, bevinding 6). Deze ADR legt vast waarom de kolom binary is; toekomstige code die deze kolom raakt moet de conversie expliciet toepassen, niet aannemen dat een stringvergelijking werkt.

**Alternatieven overwogen**
- *Leesbare VARCHAR-opslag*: verworpen vanwege minder compacte opslag en minder eenduidige IPv4/IPv6-normalisatie; ook overwogen vanuit privacy-oogpunt (packed binary is niet direct leesbaar bij een losse databasedump-inspectie, al is dit geen vervanging voor echte encryptie).

---

### ADR-0005: RegistrationStatusField als lokale mirror voor `showon`

**Status:** Accepted
**Datum:** 2026-07-29 (sprint 1.1.0)

**Context**
Registratiegerelateerde configuratievelden moesten verborgen worden wanneer Joomla's eigen `com_users.allowUserRegistration` uit staat, omdat de plugin's eigen registratiefunctionaliteit dan sowieso onbruikbaar is. Twee voor de hand liggende pogingen faalden (zie beveiligingsreview, bevinding 9): `showon` verwijzend naar een andere component se instelling werkt niet, omdat Joomla's `showon`-JavaScript alleen zoekt binnen het DOM van het eigen formulier; en fieldset-level `showon` bleek in deze installatie niet betrouwbaar te werken, ook niet met een lokaal veld.

**Beslissing**
Een nieuw custom field type, `RegistrationStatusField` (type `registrationstatus`), leest `ComponentHelper::getParams('com_users')->get('allowUserRegistration')` server-side en rendert dit als een echt, verborgen `<input>`-veld (`registration_enabled`) in het eigen formulier — met `filter="unset"` zodat de waarde nooit in de plugin's eigen parameters wordt opgeslagen, maar bij elke paginaweergave opnieuw wordt berekend. Elk registratiegerelateerd veld's `showon` bevat voortaan `registration_enabled:1`, volgens hetzelfde per-veld-patroon dat al gebruikt werd voor `allow_password_login`.

**Consequenties**
- Per-veld `showon` is de enige `showon`-mechaniek waarop in deze codebase vertrouwd wordt; fieldset-level `showon` wordt vermeden totdat onafhankelijk bevestigd is dat het werkt.
- Het patroon (custom field die een externe/globale instelling lokaal spiegelt voor `showon`-doeleinden) is herbruikbaar voor toekomstige velden die van een instelling buiten het eigen formulier afhangen.
- Bekende beperking: dit verbergt individuele velden, niet het tabblad zelf — zie [Bekende beperkingen](#bekende-beperkingen).

**Alternatieven overwogen**
- `showon` rechtstreeks naar `com_users.allowUserRegistration`: niet werkend, zie Context.
- Fieldset-level `showon` op een lokaal veld: niet betrouwbaar werkend in de geteste Joomla-installatie, ook eerder al zonder succes geprobeerd door de product owner.
- Volledig handmatige documentatie/waarschuwing zonder conditionele zichtbaarheid: verworpen, want lost de kern van het probleem (misleidende schijnbare configureerbaarheid) niet op.

---

### ADR-0006: Goedkeuringswachtrij scopen via invite-token, niet via generieke blocked-status

**Status:** Accepted
**Datum:** 2026-07-29 (sprint 1.1.0)

**Context**
De pending-approvals-wachtrij moet exact de accounts tonen die via de eigen registratieflow zijn aangemaakt en op goedkeuring wachten. Een naïeve `activation LIKE 'sl-pending:%'`-filter bleek onbetrouwbaar: na e-mailverificatie wordt `activation` geleegd naar `''`, ononderscheidbaar van een normaal actief account dat later om een andere reden geblokkeerd is. Omdat "Reject" een account permanent verwijdert, is een foutieve match hier geen cosmetisch probleem maar een dataverlies-risico.

**Beslissing**
De wachtrij-query scoped op geblokkeerde gebruikers die bovendien een gekoppelde rij van `type = 'invite'` hebben in `#__simple_login`. Dit identificeert betrouwbaar accounts die door déze plugin zijn geregistreerd, ongeacht de actuele waarde van `activation`.

**Consequenties**
- Approve/Reject kan nooit een account raken dat om een andere reden dan deze plugin's registratieflow geblokkeerd is.
- Elke toekomstige wijziging aan de goedkeuringsflow moet deze scoping-eis expliciet in stand houden; het is een architectuurprincipe (zie [ARCHITECTURE.md](ARCHITECTURE.md#registratie--en-goedkeuringsflow-conceptueel)), niet een detail dat losstaat van de query-implementatie.

**Alternatieven overwogen**
- *Filteren op `activation LIKE 'sl-pending:%'`*: verworpen, faalt na e-mailverificatie (zie Context) — dit was de daadwerkelijke bug die werd hersteld.
- *Apart, eigen kolom op `#__users` om SimpleLogin-herkomst te markeren*: verworpen als onnodige schema-uitbreiding; de bestaande koppeling via `#__simple_login.type = 'invite'` volstaat en vermijdt een wijziging aan Joomla's eigen `#__users`-tabel.