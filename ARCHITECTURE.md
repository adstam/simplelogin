# SimpleLogin Plugin - Architecture Documentation

**Version:** 1.1.0
**Last Updated:** July 29, 2026
**Author:** Ad Stam (Product Owner)
**Architect:** AI Assistant
**Target Platform:** Joomla 6.x (PHP 8.1+)

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Component Diagram](#component-diagram)
4. [Database Design](#database-design)
5. [File Structure](#file-structure)
6. [Class Structure](#class-structure)
7. [Registration & Approval Workflow](#registration--approval-workflow)
8. [Security Architecture](#security-architecture)
9. [Security Review — 1.1.0](#security-review--110)
10. [Data Flow](#data-flow)
11. [Configuration](#configuration)
12. [Dependencies](#dependencies)
13. [Known Limitations & Future Considerations](#known-limitations--future-considerations)
14. [Appendix](#appendix)

---

## 🎯 Overview

### Purpose

SimpleLogin is a Joomla system plugin that enables **passwordless authentication** via email links. Users can log in to the frontend by requesting a secure login link sent to their email address. The plugin also supports **passwordless registration**, where new users can create accounts and receive an activation link via email, optionally gated behind manual admin approval.

### Key Features

- ✅ Passwordless login via email links
- ✅ Passwordless user registration
- ✅ Configurable link expiry (default: 15 minutes for login, 30 minutes for registration/invite)
- ✅ Rate limiting (per IP and per user) and cooldown between requests
- ✅ Scanner/bot detection, including a two-step GET→POST token flow that never performs the actual login on a bare GET request (defeats link-preview bots and mail scanners)
- ✅ Comprehensive logging and auditing (7 log categories)
- ✅ Multi-language support (English, Dutch, German, French, Spanish)
- ✅ Optional password login fallback
- ✅ Customizable email templates (plain text or HTML, with local-image CID embedding)
- ✅ Admin dashboard with logs, throttle monitoring, and log export
- ✅ **NEW in 1.1.0**: Manual admin approval workflow for new registrations (`require_admin_approval`)
- ✅ **NEW in 1.1.0**: Approve/Reject admin UI (`ApprovalReportField`) with reason-tracked rejection mail
- ✅ **NEW in 1.1.0**: HTML mail template support with local-image CID embedding and validation
- ✅ **NEW in 1.1.0**: Variable-insertion buttons (`#name`, `#link`, ...) in the mail template editors, working consistently across the "None", TinyMCE and JCE editors
- ✅ **NEW in 1.1.0**: Image error notifications to the site administrator when an embedded template image is missing or too large

### Target Audience

- **End Users**: Site visitors who want to log in or register without a password
- **Site Administrators**: Manage plugin configuration, approve/reject registrations, view logs, monitor security
- **Developers**: Extend or customize plugin functionality

---

## 🏗️ System Architecture

### Architecture Pattern

The plugin follows a **modular, trait-based architecture** with a small service layer for cross-cutting concerns (mail):

```
┌───────────────────────────────────────────────────────────────┐
│                        SimpleLogin Plugin                       │
├───────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐│
│  │   Main Plugin     │  │      Traits       │  │    Services     ││
│  │   (Extension)      │  │  (Modular Logic)   │  │  (DI-injected)   ││
│  └─────────────────┘  └──────────────────┘  └────────────────┘│
└───────────────────────────────────────────────────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐   ┌───────────────────┐   ┌─────────────────┐
│  Event Handlers   │   │  LoginFlowTrait     │   │   MailService     │
│  onAfterInitialise │   │  RegisterFlowTrait  │   │  (send + CID embed)│
│  onAfterRender     │   │  SecurityTrait      │   │                  │
│  onAfterRoute      │   │  UtilityTrait       │   │                  │
│  onUserAfterSave   │   │  LogTrait           │   │                  │
│  onAjaxSimplelogin │   │  AjaxTrait          │   │                  │
│  onContentPrepareForm│ │                    │   │                  │
└─────────────────┘   └───────────────────┘   └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌───────────────────────────────────────────────────────────────┐
│                        Joomla CMS Core                          │
└───────────────────────────────────────────────────────────────┘
```

### Design Principles

1. **Separation of Concerns**: Each trait/service handles a specific domain
2. **Single Responsibility**: Each method has one clear purpose
3. **Security First**: All user input is validated/escaped, rate limiting prevents abuse, tokens are hashed at rest
4. **Fail-safe defaults**: Unknown log statuses/types fall back to a safe, non-throttled default rather than throwing
5. **Extensibility**: New flows (e.g. the 1.1.0 approval workflow) are added as new trait methods without touching the core routing in `handleInitialise()`

---

## 📊 Component Diagram

```
Frontend request
      │
      ▼
onAfterInitialise (LoginFlowTrait::handleInitialise)
      │
      ├── sl_task=register ──────────► RegisterFlowTrait::handleRegister()
      │                                       │
      │                                       ├── GET  → show register form
      │                                       └── POST → create user (block=1 if
      │                                                   require_admin_approval),
      │                                                   send invite mail
      │
      ├── simplelogin=1 + selector/validator (GET)
      │        └──► LoginFlowTrait::handleTokenFlow()
      │                    ├── type=invite ──► RegisterFlowTrait::handleInviteActivation()
      │                    └── type=login  ──► shows "logging in…" + auto-submitting
      │                                         hidden POST form (no login happens on GET)
      │
      ├── simplelogin=1 + selector/validator (POST)
      │        ├── type=invite ──► RegisterFlowTrait::handleInvitePostActivation()
      │        └── type=login  ──► LoginFlowTrait::handleTokenPost()
      │                                  └── password_verify() → handleTokenLogin()
      │
      └── simplelogin=1 (POST, no token) ──► LoginFlowTrait::handlePost()
                                                    └── SecurityTrait rate/cooldown
                                                        checks → sendLoginLink()

onAfterRender  → injects overlay/register layout HTML into the page
onAjaxSimplelogin (com_ajax) → AjaxTrait dispatcher (admin-only actions:
                                HashPasswords, GetLogRows, PurgeLogRows,
                                ExportLog, ApproveUser, RejectUser)
onContentPrepareForm → client-side image-URL validation script for the
                        plugin's own configuration form (admin only)
```

---

## 🗃️ Database Design

### Entity-Relationship Diagram

```mermaid
erDiagram
    simple_login ||--o{ simple_login_throttle : "1-to-many"
    simple_login ||--o{ simple_login_log : "1-to-many"
    users ||--o{ simple_login : "1-to-many"
    users ||--o{ simple_login_throttle : "1-to-many"
    users ||--o{ simple_login_log : "1-to-many"

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

### Table Descriptions

#### `#__simple_login`

Stores login and invite/registration tokens. The `token` column holds a `password_hash()` of the validator half of the selector/validator pair — the raw validator is never stored, only ever emailed once.

| Column     | Type                    | Description                        | Example               |
| ---------- | ----------------------- | ----------------------------------- | --------------------- |
| `id`       | INT UNSIGNED            | Primary key                         | `1`                   |
| `user_id`  | INT UNSIGNED            | Joomla user ID                      | `42`                  |
| `selector` | CHAR(16)                | Public token identifier (looked up) | `a1b2c3d4e5f6g7h8`     |
| `token`    | VARCHAR(255)            | Hashed validator (`password_hash`)  | `$2y$10$...`           |
| `type`     | ENUM('login','invite')  | Token purpose                       | `login`                |
| `created`  | DATETIME                | Creation timestamp                  | `2026-07-05 10:00:00`  |
| `expires`  | DATETIME                | Expiration timestamp                | `2026-07-05 10:15:00`  |
| `used`     | TINYINT(1)              | Whether token was used              | `0` or `1`             |

**Indexes:** PRIMARY (`id`), UNIQUE (`selector`), INDEX (`user_id`, `expires`, `used`)

#### `#__simple_login_throttle`

Tracks request frequency for rate limiting and security monitoring. Rows are periodically purged (`throttle_cleanup_time`, default 60 minutes) during `cleanup()` after a successful login.

| Column       | Type            | Description                    |
| ------------ | --------------- | -------------------------------- |
| `id`         | BIGINT UNSIGNED | Primary key                      |
| `user_id`    | INT UNSIGNED    | Joomla user ID (nullable)        |
| `username`   | VARCHAR(150)    | Username (nullable)              |
| `ip`         | VARBINARY(16)   | Packed IP address (IPv4/IPv6)    |
| `status`     | VARCHAR(50)     | Action type                      |
| `login_id`   | INT UNSIGNED    | Reference to `simple_login.id`   |
| `created`    | DATETIME        | Timestamp                        |

**Indexes:** PRIMARY (`id`), INDEX (`ip+created`, `user_id+created`, `login_id+created`, `status+created`)

#### `#__simple_login_log`

Audit log for all plugin actions.

| Column       | Type            | Description             |
| ------------ | --------------- | -------------------------- |
| `id`         | BIGINT UNSIGNED | Primary key                |
| `type`       | ENUM            | Log category (see below)   |
| `user_id`    | INT UNSIGNED    | Joomla user ID (nullable)  |
| `username`   | VARCHAR(150)    | Username (nullable)        |
| `email_hash` | CHAR(64)        | SHA-256 hash of email      |
| `ip`         | VARBINARY(16)   | Packed IP address          |
| `user_agent` | VARCHAR(512)    | Browser user agent         |
| `status`     | VARCHAR(50)     | Specific action             |
| `login_id`   | INT UNSIGNED    | Reference to `simple_login.id` |
| `created`    | DATETIME        | Timestamp                   |

**Indexes:** PRIMARY (`id`), INDEX (`type+created`, `status+created`, `user_id`, `login_id`)

**1.1.0 migration** (`sql/updates/mysql/1.1.0.sql`): widens the `type` ENUM to also accept `ImageError` (the enum's earlier `admin_approved_registration`/`admin_rejected_registration` entries are *status* values, not `type` values — see [Security Review — 1.1.0](#security-review--110)).

### Log Types & Statuses

| Type                | Description                    | Example Statuses                                             |
| ------------------- | -------------------------------- | ---------------------------------------------------------------- |
| `AccountEvent`      | User account related events      | `password_updated`, `register_success`, `admin_approved_registration`, `admin_rejected_registration` |
| `DebugDiagnostics`  | Debug-only diagnostic info       | `invite_email_not_found`, `token_row_missing`                     |
| `DebugFlowTrace`    | Debug flow tracing               | `core_login_blocked`, `simplelogin_triggered`                     |
| `DebugRequestTrace` | Request parameter tracing        | `selector_xxx`, `validator_present_yes`                            |
| `InviteFlow`        | Registration/invitation flow     | `invite_sent`, `invite_activated`, `invite_pending_approval`       |
| `LoginFlow`         | Login flow events                | `link_request`, `link_sent`, `login_success`, `token_hit`          |
| `SecurityIncident`  | Security related events          | `rate_limited_ip`, `rate_limited_user`, `scanner_detected`         |
| `ImageError`        | *(new in 1.1.0)* Mail image CID embedding problems | `image_not_found`, `image_too_large`         |

---

## 📁 File Structure

```
plg_system_simplelogin/
├── ARCHITECTURE.md
├── DEFAULT.md
├── README.md
├── script.php                       # postflight/uninstall: cache clearing
├── simplelogin.xml
│
├── src/
│   ├── Extension/
│   │   └── Simplelogin.php          # Main plugin class, event subscriptions
│   │
│   ├── Field/                       # Custom admin form fields
│   │   ├── ApprovalReportField.php  # NEW 1.1.0: pending-approval table
│   │   ├── BodybuttonsField.php
│   │   ├── ExportlogField.php
│   │   ├── HashpasswordsField.php
│   │   ├── LogreportField.php
│   │   ├── RegistrationStatusField.php # NEW 1.1.0: local, live mirror of
│   │   │                           # com_users.allowUserRegistration for showon
│   │   └── ThrottlereportField.php
│   │
│   ├── Helper/
│   │   └── ReportHelper.php         # Query helpers for the admin fields
│   │
│   ├── Service/
│   │   ├── MailService.php          # Sending + HTML placeholder handling
│   │   │                             # + local-image CID embedding (1.1.0)
│   │   └── MailServiceInterface.php
│   │
│   ├── Traits/
│   │   ├── AjaxTrait.php             # com_ajax dispatcher + admin-only actions
│   │   ├── LoginFlowTrait.php
│   │   ├── LogTrait.php
│   │   ├── RegisterFlowTrait.php     # incl. invite/approval flows
│   │   ├── SecurityTrait.php
│   │   └── UtilityTrait.php
│   │
│   └── tmpl/
│       ├── approvals.php            # NEW 1.1.0: approve/reject admin UI
│       ├── logs.php
│       ├── logs_table.php
│       └── throttle.php
│
├── services/
│   └── provider.php                 # DI: registers MailServiceInterface + plugin
│
├── layouts/
│   └── simplelogin/
│       ├── overlay.php              # Login modal (frontend)
│       └── register.php             # Registration modal (frontend)
│
├── language/
│   ├── de-DE/ · en-GB/ · es-ES/ · fr-FR/ · nl-NL/
│
├── media/
│   └── js/
│       ├── bodybuttons.js           # Variable-insertion buttons, editor-agnostic (1.1.0)
│       ├── hashpasswords.js
│       ├── logreport.js
│       └── simplelogin.js           # Frontend overlay behaviour (autosubmit/redirect)
│
└── sql/
    ├── install.mysql.utf8.sql
    ├── uninstall.mysql.utf8.sql
    └── updates/mysql/1.1.0.sql
```

---

## 🏛️ Class Structure

`Simplelogin` (extends `CMSPlugin`) composes its behaviour entirely from traits:

| Trait                | Responsibility                                                                 |
| --------------------- | --------------------------------------------------------------------------------- |
| `LoginFlowTrait`      | Main routing (`handleInitialise`), overlay rendering (`handleRender`), login/token flows, sending login links |
| `RegisterFlowTrait`   | Registration form handling, invite-token activation (GET + POST), sending invite links |
| `SecurityTrait`       | Rate limiting (IP/user), cooldown, scanner/preflight detection, password enforcement |
| `LogTrait`            | Central `log()` method, status→type/throttle/debug-only definition table, IP/UA/email hashing helpers |
| `UtilityTrait`        | Token generation/consumption, cleanup, email validation, username generation, activation-marker helpers, PRG redirect |
| `AjaxTrait`           | `onAjaxSimplelogin` dispatcher and the six admin-only AJAX actions (hash passwords, get/purge logs, export log, approve/reject user) |

`MailService` (implements `MailServiceInterface`) is injected via the DI container (`services/provider.php`) rather than instantiated directly, so it can be swapped/mocked.

---

## 🔐 Registration & Approval Workflow

New in 1.1.0: registrations can require manual admin approval before the new account is usable.

```
POST register form
      │
      ▼
User created: block = (require_admin_approval ? 1 : 0)
              activation = 'sl-pending:<random>'
      │
      ▼
Invite mail sent (selector/validator link, type=invite)
      │
      ▼
User clicks invite link (GET) ──► handleInviteActivation()
      │
      ├── require_admin_approval = 0
      │        └──► consume token inline, block stays as set, account usable,
      │             a login link is sent immediately
      │
      └── require_admin_approval = 1
               └──► consume token, clear `activation` (email now verified),
                    block stays = 1 → account now sits in the
                    "pending approval" admin queue
                         │
                         ▼
               Admin opens Plugins → System - Simplelogin → sees the
               pending-approval table (ApprovalReportField / approvals.php)
                         │
              ┌──────────┴──────────┐
              ▼                     ▼
         Approve                Reject
    (block = 0, sends       (sends rejection mail with
     approval mail if        admin-supplied reason, THEN
     already activated)      permanently deletes the user
                              + usergroup mappings)
```

**Important scoping note:** the pending-approval query (`ReportHelper::getPendingApprovals()`) intentionally restricts itself to blocked users that have an associated `#__simple_login` row of `type = 'invite'`. This ties the list — and therefore what Approve/Reject can act on — specifically to accounts that went through this plugin's own registration flow. See [Security Review — 1.1.0](#security-review--110) for why this matters.

---

## 🔒 Security Architecture

- **Tokens**: selector (public, indexed lookup) + validator (secret, 32 random bytes, never stored — only its `password_hash()` is). Login only ever completes on a **POST**; a bare GET on a token link never logs anyone in or activates anything, which defeats link-preview bots/scanners that only issue GET requests.
- **Token minimum age**: `token_min_age_seconds` (default 5s) rejects a POST that arrives implausibly fast after token creation.
- **Rate limiting**: per-IP and per-user, with separate configurable limits/windows, backed by `#__simple_login_throttle`.
- **Cooldown**: a short mandatory wait between requests for the same IP/user, independent of the rate limit counters.
- **Scanner/bot detection**: user-agent signature matching (curl, wget, python, headless browsers, ...) plus a token-hit-frequency check (`detectScannerPreflight`) that flags unusually rapid repeat hits on the same token.
- **CSRF**: all state-changing frontend POSTs (`login form`, `register form`, `token POST`, `invite POST`) and all admin AJAX actions check the Joomla form/session token before doing anything.
- **Authorization**: every admin AJAX action additionally requires `core.manage` on `com_plugins`.
- **Password enforcement**: when password login is disabled, a successful passwordless login (or the bulk "Hash Passwords" admin action) overwrites the account's password with a random, unusable hash.
- **Output escaping**: all admin report templates (`logs_table.php`, `throttle.php`, `approvals.php`) and both frontend overlay layouts escape every dynamic value with `htmlspecialchars()`.
- **Local-only image embedding**: HTML mail templates may only embed images from `/media/` or `/images/` on the same host; anything else is left as a normal (non-embedded) `<img>` reference or dropped, never fetched server-side — this rules out SSRF via template images.

---

## 🛡️ Security Review — 1.1.0

As part of preparing the 1.1.0 release, the codebase was reviewed for defects that would not necessarily surface through normal manual testing. The following issues were found and **fixed directly in this release**:

1. **Orphaned `onExtensionBeforeSave` event subscription.** `getSubscribedEvents()` declared a handler for `onExtensionBeforeSave` with no corresponding method anywhere in the class. Because this is a *system* plugin, this event is dispatched by Joomla core on saving **any** extension (plugins, modules, templates, ...) sitewide — not just this one. Depending on the exact core version's defensiveness around missing listener methods, this could throw a fatal error when an administrator saves an unrelated extension. The dead subscription (and its now-unused helper, `extractImageUrlsFromHtml()`, a leftover from an abandoned server-side validation approach later replaced by the client-side check in `onContentPrepareForm()`) has been removed.

2. **Over-broad pending-approvals query.** `ReportHelper::getPendingApprovals()` had its intended `activation LIKE 'sl-pending:%'` filter commented out, causing it to return **every** blocked user on the site, not just pending Simplelogin registrations. Since the admin "Reject" action permanently deletes the listed user, this meant an administrator could unintentionally delete an unrelated account that had been blocked for other reasons, simply by using this queue. Root cause: after email verification, `activation` is cleared to `''` (see the approval workflow above), which is indistinguishable from a normally-active account that was later blocked — so the naive filter couldn't be simply restored. Fixed by scoping the query to blocked users that have an associated invite-type token row in `#__simple_login`, which reliably identifies accounts that came through this plugin's own registration flow regardless of their current `activation` value.

3. **Silently disabled audit logging for approve/reject actions.** The `admin_approved_registration` and `admin_rejected_registration` status keys were used in `AjaxTrait` but had no entry in `LogTrait::getStatusDefinition()`'s status→type map. The fallback default for an unrecognized status is `debugonly = true`, so — outside of Joomla's debug mode — these two sensitive, brand-new 1.1.0 admin actions were never written to the audit log at all. Both statuses now have an explicit, non-debug-only definition. (A related cosmetic issue was also cleaned up: those two strings had been added to the `type` allow-list in `log()`, even though they are status keys, not type categories — they're correctly mapped to `type = AccountEvent` now instead.)

4. **Unescaped placeholder substitution in HTML mail.** `MailService::sendMail()` substituted `#name`/`#email`/`#reason`/... into the mail body with a raw `str_replace()`, with no HTML-escaping. Since `#name` (registration name) and `#reason` (admin-typed rejection reason) can contain arbitrary text, a user registering with an HTML-bearing name would have that HTML embedded verbatim into HTML-formatted admin/approval/rejection emails. Fixed by escaping all placeholder values with `htmlspecialchars()` when (and only when) the mail is being sent in HTML mode — the subject line is deliberately left unescaped, since mail subjects are plain text, not HTML. As part of the same fix, the sequential `str_replace()` loop (which could double-substitute if one placeholder's *value* happened to contain another placeholder's *token*, e.g. a user named literally `#link`) was replaced with a single simultaneous `strtr()` pass, which cannot exhibit that ordering bug.

5. **Two parameters read in code have no admin-UI field.** `password_login_itemid` (the menu item used for the password-login fallback link) and `token_min_age_seconds` (anti-replay minimum token age) are both read via `$this->params->get(...)` but have no corresponding `<field>` in `simplelogin.xml`. They are harmless — the code falls back to sensible hardcoded defaults (`0` and `5` respectively) — but an administrator currently has **no way to change them** short of editing the plugin parameters directly in the database. Left as-is for 1.1.0 (not a security defect, just an incomplete UI), documented in [DEFAULT.md](DEFAULT.md), and flagged for either exposing a field or removing the illusion of configurability in 1.2.0.

6. **IP-based rate limiting and IP-based cooldown were silently non-functional.** `#__simple_login_throttle.ip` is a `VARBINARY(16)` column; `LogTrait::getPackedIp()` returns the IP as a hex string specifically so it can be converted back to binary with `UNHEX()` at the SQL level. The INSERT path (`LogTrait::log()`) did this correctly (`UNHEX($db->quote($packedIp))`), but the two read paths in `SecurityTrait` — `isRateLimitedIp()` and the IP branch of `isCooldown()` — compared the binary column directly against the *un-converted* hex string (`ip = '7f000001'` instead of `ip = UNHEX('7f000001')`). A `VARBINARY` column can never equal a literal ASCII hex string, so both checks always evaluated their `COUNT(*)` as `0` and therefore always returned `false` — meaning no IP was ever rate-limited or made to wait out a cooldown, while every attempt was still faithfully recorded in the throttle table (which is why the problem wasn't visible from the admin reports alone; the data looked complete, only the enforcement was silently inert). Per-user rate limiting (`isRateLimitedUser()`, keyed on the plain integer `user_id`) was unaffected. This was found only through active testing (deliberately exceeding the configured limit), not through code review or the report tables looking "wrong" — a good illustration of why enforcement logic needs to be tested by trying to break it, not just by inspecting whether it logs. Fixed by wrapping both comparisons in `UNHEX()`, matching the INSERT path. Per the product owner, this discrepancy likely dates back to an early pre-1.0 refactor of how the IP address is stored (moving from a plain-string to a packed-binary representation for privacy/storage reasons), where the write side was updated to match but these two read sites were missed; rate limiting reportedly did work correctly in earlier pre-release sprints, before that storage change.

7. **PHP 8.2 deprecation silently corrupted the approve/reject AJAX JSON response.** `MailService::processImagesForCidEmbedding()` prepared the mail body for `DOMDocument::loadHTML()` with `mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8')` — a call whose `'HTML-ENTITIES'` target encoding was deprecated in PHP 8.2. On a normal page load a stray deprecation notice just gets lost in the rendered HTML, but this same code path also runs inside the `com_ajax`/`format=json` **Approve**/**Reject** admin endpoints (any time an HTML-format mail is actually sent), where PHP's deprecation text — if `display_errors` shows deprecations, as is common on non-production sites — gets written to the response body *before* the JSON envelope, making the response invalid JSON. The browser's `response.json()` then throws, the UI's `.catch()` handler fires, and the admin sees the generic `PLG_SYSTEM_SIMPLELOGIN_ERR_GENERIC` message — even though the underlying PHP request had already completed successfully (the account was updated and the mail was actually sent; a deprecation notice doesn't halt execution). This is why the error was reported as "not fatal" and reproduced specifically on the one Approve path that sends mail (approving an already-verified registration) while the no-mail Approve path (approving a registration that hasn't been email-verified yet) was unaffected — and, being in the same shared `sendMail()` code path, the Reject action (which always sends a mail) was equally exposed whenever mail is sent in HTML mode. Fixed by replacing the deprecated call with the current, non-deprecated technique for telling `DOMDocument::loadHTML()` its input is UTF-8 (prefixing an `<?xml encoding="UTF-8">` declaration, which libxml consumes as a hint and strips from the parsed output). This is the same category of issue as the constructor/`getConfig()`/`getLanguage()->load()` deprecations addressed earlier in 1.1.0 — the product owner has a broader deprecation-removal pass planned for 1.2.0; this particular instance was fixed immediately rather than deferred, since — unlike the others, which only ever wrote a line to Joomla's deprecation log — it was user-visible and broke a real admin workflow.

8. **Per-user rate limiting counted each successful login-link request twice, halving the effective limit.** `SecurityTrait::isRateLimitedUser()` counted throttle rows with `status IN ('login_attempt_existing', 'link_sent')`. For a normal login-form submission for an existing, activated user, `LoginFlowTrait::handlePost()` logs `login_attempt_existing` and then calls `sendLoginLink()`, which — on success — logs `link_sent` itself, for the *same* request. Both statuses are `throttle => true`, so one submitted request produced two counted rows, meaning users hit `rate_limit_user_max` after only half as many actual attempts as configured (e.g. a limit of 5 triggered after 2–3 real submissions). `isRateLimitedIp()` was not affected — its status list never included `link_sent`. Fixed by counting only `login_attempt_existing` in `isRateLimitedUser()`, which is logged exactly once per submitted login-form request. `link_sent` is also logged (without a preceding `login_attempt_existing`) from the invite-activation flow in `RegisterFlowTrait`, when a login link is sent automatically right after a registration is activated/approved — that path was deliberately left out of this counter, since it isn't independently repeatable (the invite token it depends on can only be consumed once), so it didn't need its own throttle accounting here. Found by the product owner reasoning through the query rather than by reproducing a failure — a good example of the general lesson from finding 6 above (test/verify enforcement logic directly), applied this time to reading the SQL itself rather than exercising the UI.

9. **Registration-related config fields stayed visible even when Joomla's own "Allow User Registration" setting is off.** SimpleLogin's own registration feature is unusable whenever site-wide self-registration is disabled in `com_users`, but `simplelogin.xml` had no `showon` condition tying the invite/admin-notification/approval-workflow/rejection-mail fields (and the approval fieldset's settings) to that global setting — they were gated only on this plugin's own `allow_password_login` toggle, so they stayed fully visible and editable regardless of the Joomla-level setting, which could mislead an administrator into thinking they'd configured something that will never actually be used. Getting this right took two failed attempts, both worth recording since they contradict what the relevant Joomla documentation implies is supported:
   - **First attempt**, `showon="com_users.allowUserRegistration:1"` on the relevant fieldset(s): does not work. Joomla's `showon` is resolved client-side, by JavaScript that looks for a form control with a matching name *in the current form's own DOM*. A different component's global configuration value (`com_users.allowUserRegistration`) is never rendered anywhere on this plugin's own configuration screen, so there is nothing for that JS to find; the condition silently fails open (shows everything) rather than erroring.
   - **Second attempt**, `showon` set directly on the `<fieldset>` element rather than on individual fields (using a value that *does* resolve to a real, local field): despite fieldset-level `showon` being described as supported, it did not visibly show/hide the fieldset in this Joomla installation when tested directly — confirmed by the product owner, who had already tried this in earlier development and never gotten it to work. Per-field `showon`, by contrast, works reliably. Given that, per-field `showon` is now treated as the only mechanism relied on in this codebase; fieldset-level `showon` is avoided until/unless it's independently reconfirmed to work.
   - **Working fix**: a new custom field type, `RegistrationStatusField` (`src/Field/RegistrationStatusField.php`, type `registrationstatus`), reads `ComponentHelper::getParams('com_users')->get('allowUserRegistration')` **server-side** and renders it as a genuine hidden `<input>` — a real, local field (`registration_enabled`) that Joomla's `showon` JS can find, because it actually exists in this form's DOM. `filter="unset"` on its XML definition means the value is never written into the plugin's own saved parameters; it's recomputed fresh from the live Joomla setting on every page load. Every registration-related field's `showon` now includes `registration_enabled:1` directly (e.g. `mail_invite_body_html` → `registration_enabled:1[AND]mail_format:html[AND]show_invite_mail:1[AND]allow_password_login:0`), following the same per-field pattern already used for `allow_password_login`. A new note field, `no_userregistration` (mirroring the existing `no_simplelogin_registration` note used for the `allow_password_login:1` case), explains to the administrator why the approval fieldset is otherwise empty when self-registration is off.
   - **Known remaining limitation, deliberately accepted for 1.1.0**: this hides every *field*, but the fieldset still always appears as a selectable tab in the plugin's configuration screen (now showing only the explanatory note when self-registration is off, rather than a blank tab). Actually removing the tab itself would require fieldset-level conditional visibility, which is exactly the mechanism that didn't work above. The product owner has flagged this as worth a closer look for 1.2.0, if a cleaner way to conditionally suppress an entire tab turns up — see [Known Limitations](#known-limitations--future-considerations).

The following items were **identified but intentionally left for a product decision** (not auto-fixed, since they involve trade-offs rather than unambiguous bugs) — see [Known Limitations](#known-limitations--future-considerations):

- The registration endpoint has no rate limiting/scanner-detection, unlike the login endpoint.
- The GET token-flow reveals whether a selector is valid/used/expired without validating the secret validator first (low severity — no account data is exposed).
- IP-based rate limiting uses `REMOTE_ADDR` only, with no reverse-proxy/`X-Forwarded-For` awareness.

One additional, zero-risk hygiene fix was applied directly: a PHPUnit test stub (`src/Service/MailServiceTest.php`) that shipped inside the packaged plugin — referencing a dev-only dependency (`PHPUnit\Framework\TestCase`) not present on a production Joomla install — was removed from this release package. It was inert (its namespace didn't match its file path, so nothing could ever autoload it), but had no place in a production zip either way. Keep a copy in your development repository if you plan to build out a real test suite for 1.2.0.

This review was scoped to correctness and security defects for the 1.1.0 release, not a general code-quality/duplication pass — that is planned for 1.2.0.

---

## 🔄 Data Flow

See [Component Diagram](#component-diagram) above for the routing overview. The two flows most relevant to 1.1.0:

**Registration with admin approval:**
```
POST /index.php?sl_task=register
  → RegisterFlowTrait::handleRegister()
     → create user (block=1), sendInviteLink()
GET invite link
  → LoginFlowTrait::handleTokenFlow() → type=invite
     → RegisterFlowTrait::handleInviteActivation()
        → require_admin_approval=1 → consume token, clear activation,
          leave block=1 → "pending approval" state
Admin clicks Approve (AJAX, ApproveUser)
  → AjaxTrait::ajaxApproveUser()
     → CSRF + core.manage check → set block=0 → sendApprovalEmail()
```

**Registration rejected:**
```
Admin clicks Reject (AJAX, RejectUser) + supplies a reason
  → AjaxTrait::ajaxRejectUser()
     → CSRF + core.manage check
     → sendRejectionEmail(reason)   [sent BEFORE deletion, so the user
                                     is still resolvable for the mail]
     → DELETE FROM #__users, DELETE FROM #__user_usergroup_map
```

---

## ⚙️ Configuration

See **[DEFAULT.md](DEFAULT.md)** for the full list of configuration parameters, their default values, and what each one controls. This architecture document intentionally does not duplicate that reference.

---

## 📦 Dependencies

- **Joomla 6.x** core (Session, Factory, Mailer, User, Router, Uri, Layout, Log, HTML, Form, DI container)
- **PHP 8.1+** (uses constructor property promotion is not used, but relies on `str_contains`/`str_starts_with`/`str_ends_with`, typed properties, and arrow functions — all PHP 8.0+/8.1+ features)
- No third-party Composer packages beyond what Joomla core already provides
- `MailServiceTest.php` depends on PHPUnit, a dev-only dependency not present in a production Joomla install (see [Known Limitations](#known-limitations--future-considerations))

---

## 🧭 Known Limitations & Future Considerations

Planned for **1.2.0** (explicitly out of scope for the 1.1.0 security/defect review):

- General technical-debt pass: reduce duplication between `LoginFlowTrait`/`RegisterFlowTrait` (e.g. the repeated "resolve return URL from referrer" and "check user exists/not blocked" blocks), and review method length/cohesion.
- Investigate whether there's a clean way to conditionally hide an entire fieldset/tab (not just its individual fields) based on a runtime value, for the registration-related tab when `com_users.allowUserRegistration` is off. Fieldset-level `showon` did not work when tried directly (see security review finding 9); for 1.1.0 the tab stays visible with an explanatory note and no editable fields, which is functionally sufficient but not as clean as hiding the tab itself. Suggested by the product owner.
- Build out a real PHPUnit test suite (in a top-level `tests/` folder, excluded from the install package via `.gitattributes`/build tooling) around `MailService` and the token helpers — the previous ad-hoc test stub has been removed from this release's package.
- A small number of additional security-hardening items (around request throttling and token handling) are tracked for 1.2.0 in a separate, non-public planning document, since discussing their specifics ahead of a fix would be more useful to an attacker than to a reader of this document.

---

## 📖 Appendix

### Version History (architecture-relevant highlights)

- **1.1.0** — Admin approval workflow, HTML mail + CID image embedding, editor-agnostic variable buttons, security/defect review (see above).
- **1.0.x** — Initial passwordless login/registration, rate limiting, logging, `MailService` introduction.

### Related Documents

- [README.md](README.md) — end-user and administrator getting-started guide
- [DEFAULT.md](DEFAULT.md) — full configuration parameter reference with default values
