# SimpleLogin — Default Configuration Reference

**Version:** 1.1.0
**Last Updated:** July 29, 2026

This document lists every configuration parameter exposed by the plugin (Extensions → Plugins → System - Simplelogin), grouped by the fieldset it appears in, with its default value straight out of the box and what it controls. For a general getting-started guide, see [README.md](README.md); for how these parameters are used internally, see [ARCHITECTURE.md](ARCHITECTURE.md).

Unless noted otherwise, "default" means: the value Joomla uses when the plugin is freshly installed and the administrator has not touched the field yet.

---

## Fieldset: Login Settings (`general`)

| Parameter               | Type  | Default    | Meaning |
| ------------------------ | ----- | ----------- | -------- |
| `landing_page_option`     | radio | `homepage`  | Where a user lands after a successful login: the site homepage, or a chosen menu item. |
| `landing_itemid`          | menu item | *(empty)* | The menu item to redirect to when `landing_page_option = custom`. No effect otherwise. |
| `allow_password_login`    | radio | `0` (No)    | If `No`, the plugin fully replaces core Joomla password login/registration with the passwordless flow, and enforces this by scrambling passwords on every successful passwordless login. If `Yes`, a "log in with password instead" option is also offered. |
| `bulk_hash_passwords`     | button | n/a        | One-off admin action: immediately randomizes the password of every non-admin, non-blocked frontend user, making password login impossible for them going forward. Irreversible; intended to be used right after switching `allow_password_login` to `No` on an existing site. |

## Fieldset: Approval (`approvalinformation`) — new in 1.1.0

| Parameter                    | Type  | Default | Meaning |
| ----------------------------- | ----- | -------- | -------- |
| `require_admin_approval`      | radio | `0` (No) | If `Yes`, a new self-registered account stays blocked after email verification until an administrator explicitly approves it in the plugin's approval table. If `No`, verifying the invite email is enough to activate the account (subject to existing Joomla registration settings). |
| `notify_admin_registration`   | radio | `0` (No) | If `Yes`, the site's `mailfrom` address receives a notification mail on every new registration (see `mail_admin_*` below). Independent of `require_admin_approval`. |
| `approval_report`             | (admin UI) | n/a | Renders the pending-approvals table with Approve/Reject buttons. Only shown when `require_admin_approval = Yes`. |

## Fieldset: Mail Setup (`mail_instellingen`)

| Parameter          | Type  | Default | Meaning |
| -------------------- | ----- | -------- | -------- |
| `mail_format`        | radio | `html`   | Whether all outgoing plugin mail is sent as HTML (rich text, supports embedded local images) or plain text. Switching this toggles which of the paired `*_body` / `*_body_html` fields below is actually used. |

Each of the four mail types below follows the same pattern: a `show_*_mail` switch (default **off** for all four) that only controls whether that field group is *displayed* in the admin form (it does not disable sending the mail itself — the mail is always sent when its flow is triggered), a subject line, a plain-text body, and an HTML body. Only the body matching the current `mail_format` is actually used when sending.

### Login mail

| Parameter | Default |
| ----------- | -------- |
| `show_login_mail` | `0` (hidden by default in the admin form) |
| `mail_login_subject` | `Here's your login link` |
| `mail_login_body` (text) | `#name,` / blank line / `Here's your link to login:` / `#link` / blank line / `Please note that the link is valid for #expiry minutes.` |
| `mail_login_body_html` (HTML) | `<p>#name,</p><p>Here's your link to login:<br><a href="#link">#link</a></p><p>Please note that the link is valid for #expiry minutes.</p>` |

Placeholders available: `#name`, `#link`, `#expiry`, `#sitename`.

### Invite / registration mail

| Parameter | Default |
| ----------- | -------- |
| `show_invite_mail` | `0` |
| `mail_invite_subject` | `Your confirmation link` |
| `mail_invite_body` (text) | `#name,` / blank line / `Here's your link to confirm your registration:` / `#link` / blank line / `Please note that the link is valid for #expiry minutes.` |
| `mail_invite_body_html` (HTML) | `<p>#name,</p><p>Here's your link to confirm your registration:<br><a href="#link">#link</a></p><p>Please note that the link is valid for #expiry minutes.</p>` |

Placeholders available: `#name`, `#link`, `#expiry`, `#sitename`.

### Admin notification mail

Only sent/shown when `notify_admin_registration = Yes`.

| Parameter | Default |
| ----------- | -------- |
| `show_admin_mail` | `0` |
| `mail_admin_subject` | `New registration on #sitename` |
| `mail_admin_body` (text) | `#name (#email) has registered on #sitename.` / blank line / `If admin approval is needed goto to the plugin and decide your action for this new user.` |
| `mail_admin_body_html` (HTML) | `<p>#name (#email) has registered on #sitename.</p><p>If admin approval is needed goto to the plugin and decide your action for this new user.</p>` |

Placeholders available: `#name`, `#email`, `#sitename`. Sent to the site's configured `mailfrom` address, not to the registrant.

### Approval mail — new in 1.1.0

Only relevant/shown when `require_admin_approval = Yes`.

| Parameter | Default |
| ----------- | -------- |
| `show_approval_mail` | `0` |
| `mail_approval_subject` | `Your registration has been approved` |
| `mail_approval_body` (text) | `#name,` / blank line / `Your registration has been approved. You can now login here:` / `#link` / blank line / `This link is valid for #expiry minutes.` |
| `mail_approval_body_html` (HTML) | `<p>#name,</p><p>Your registration has been approved. You can now login here:<br><a href="#link">Click here to login</a></p><p>This link is valid for #expiry minutes.</p>` |

Placeholders available: `#name`, `#link`. Only actually sent if the account had already completed email verification at the time of approval (see [ARCHITECTURE.md](ARCHITECTURE.md#registration--approval-workflow)).

### Rejection mail — new in 1.1.0

Only relevant/shown when `require_admin_approval = Yes`.

| Parameter | Default |
| ----------- | -------- |
| `show_rejection_mail` | `0` |
| `mail_rejection_subject` | `Your registration has been rejected` |
| `mail_rejection_body` (text) | `#name,` / blank line / `Your registration has been rejected.` / blank line / `Reason: #reason` |
| `mail_rejection_body_html` (HTML) | `<p>#name,</p><p>Your registration has been rejected.</p><p>Reason: #reason</p>` |

Placeholders available: `#name`, `#reason` (the reason text is typed by the admin at reject-time, and — since 1.1.0's security fixes — is HTML-escaped before being inserted into an HTML-format mail).

## Fieldset: Security (`security`)

### Base limits

| Parameter                  | Default | Meaning |
| ---------------------------- | -------- | -------- |
| `expiry_minutes`              | `15`     | How long a login link stays valid. |
| `invite_expiry_minutes`       | `30`     | How long an invite/registration link stays valid. Also used as the fallback minimum (`max(1, ...)`) if misconfigured. |
| `request_cooldown_seconds`    | `30`     | Minimum wait enforced between consecutive login link requests for the same IP or user, independent of the rate-limit counters below. |

### Rate limits

| Parameter                | Default | Meaning |
| --------------------------- | -------- | -------- |
| `rate_limit_ip_max`         | `10`     | Max login-link requests allowed from one IP within the window below. |
| `rate_limit_ip_window`      | `5`      | Window, in minutes, for the per-IP rate limit. |
| `rate_limit_user_max`       | `5`      | Max login-link requests allowed for one user account within the window below. |
| `rate_limit_user_window`    | `10`     | Window, in minutes, for the per-user rate limit. |

### Not exposed in the admin UI (code defaults only)

These two are read by the code but have no corresponding admin field in this release — an administrator cannot currently change them without editing the stored plugin parameters directly:

| Parameter                | Code default | Meaning |
| --------------------------- | -------------- | -------- |
| `token_min_age_seconds`     | `5`            | A token POST arriving less than this many seconds after the token was created is rejected as implausible (anti-automation safeguard). |
| `password_login_itemid`     | `0`            | Menu item ID to link to for the "log in with a password instead" fallback option, when `allow_password_login = Yes`. `0` falls back to the core `com_users` login view. |

## Fieldset: Sessions (`throttle_report`)

| Parameter                | Default | Meaning |
| --------------------------- | -------- | -------- |
| `throttle_cleanup_time`     | `60`     | Minutes after which old rows in `#__simple_login_throttle` are purged. Cleanup runs as a side effect of a successful login (`UtilityTrait::cleanup()`), not on a schedule. |
| `throttle_report`           | (admin UI) | n/a — renders the live throttle table (view-only). |

## Fieldset: Logging (`log_report`)

| Parameter               | Default | Meaning |
| -------------------------- | -------- | -------- |
| `log_export`               | (admin UI) | n/a — button that emails the last 24h of log rows plus the raw plugin log file to the site's `mailfrom` address. |
| `log_retention_days`       | `30`     | Days after which rows in `#__simple_login_log` are purged (same cleanup trigger as the throttle table above). `0` disables log cleanup entirely. |
| `log_report`               | (admin UI) | n/a — renders the filterable/deletable log table. |

---

## Quick reference: "out of the box" behaviour

With a fresh install and no configuration changes, SimpleLogin will:

- Fully replace core password login/registration on the frontend (`allow_password_login = No`).
- Send **HTML** email (`mail_format = html`), using the built-in default templates listed above — but note that `show_login_mail`, `show_invite_mail`, `show_admin_mail`, `show_approval_mail` and `show_rejection_mail` all default to **off** in the admin form; this only hides the fields from view; the corresponding mail is still sent whenever its flow runs, using the default body shown above.
- **Not** require admin approval for new registrations (`require_admin_approval = No`) and **not** notify the admin of new registrations (`notify_admin_registration = No`).
- Expire login links after 15 minutes and invite links after 30 minutes, with a 30-second cooldown between requests.
- Allow at most 10 requests per IP per 5 minutes, and 5 requests per user per 10 minutes.
- Purge throttle rows older than 60 minutes and log rows older than 30 days, opportunistically on every successful login.

If you are enabling `require_admin_approval` for the first time, also switch on `show_approval_mail` and `show_rejection_mail` so you can see and, if desired, customize the wording those two new 1.1.0 mail templates use — they are sent regardless of whether their fields are visible in the admin form.
