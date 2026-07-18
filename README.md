# SimpleLogin - Passwordless Authentication for Joomla

**🔐 Log in without passwords. Securely. Simply.**

SimpleLogin is a powerful Joomla system plugin that enables **passwordless authentication** via secure email links. Users can log in to your website by requesting a login link sent to their email address — no password required. New users can also register without creating a password, receiving an activation link instead.

---

## ✨ Features

### 🔑 Passwordless Login

- **One-click login**: Users receive a secure link via email
- **Time-limited**: Links expire after a configurable period (default: 15 minutes)
- **Single-use**: Each link can only be used once
- **Secure**: Uses cryptographically secure tokens

### 👤 Passwordless Registration

- **Easy signup**: New users can register with just name and email
- **Email verification**: Accounts are activated via email link
- **No password needed**: Users never need to create or remember a password
- **Duplicate prevention**: Existing users receive a login link instead

### 🛡️ Security Features

- **Rate limiting**: Prevents brute force attacks (configurable per IP and user)
- **Cooldown period**: Enforces minimum wait time between requests
- **Scanner detection**: Blocks automated bots and scanners
- **Token revocation**: Old tokens are automatically invalidated
- **Password enforcement**: Optionally disable password login entirely

### 📊 Admin Features

- **Comprehensive logging**: Track all login attempts and actions
- **Throttle monitoring**: View active rate-limited requests
- **Log export**: Export logs via email for analysis
- **Bulk operations**: Hash all existing passwords to enforce passwordless-only
- **Customizable**: Configure all aspects of the plugin

### 🌍 Multi-Language Support

- **English**: Full support
- **Dutch (Nederlands)**: Full support
- **Easy to translate**: All strings are language-ready

---

## 📥 Installation

### Method 1: Joomla Installer (Recommended)

1. **Download** the plugin package (`simplelogin.zip`) from the [releases page](https://github.com/adstam/simplelogin/releases)
2. **Go to** Joomla Administrator → System → Extensions → Install
3. **Upload** the package file and click "Upload & Install"
4. **Enable** the plugin: Extensions → Plugins → System - Simplelogin → Enable

### Method 2: Manual Installation

1. **Extract** the plugin files to `/plugins/system/simplelogin/`
2. **Go to** Joomla Administrator → System → Extensions → Discover
3. **Discover** the plugin and install it
4. **Enable** the plugin

### Method 3: Git Clone (Developers)

```bash
cd /path/to/joomla/plugins/system
git clone https://github.com/adstam/simplelogin.git simplelogin
```

Then install via Joomla Extensions → Discover.

---

## ⚙️ Configuration

After installation, configure the plugin:

1. **Go to** Extensions → Plugins → System - Simplelogin
2. **Click** on the plugin name to edit settings
3. **Configure** the options as needed
4. **Save** your changes

### 🔧 Main Settings

#### General Configuration


| Setting                         | Description                          | Recommended Value            |
| ------------------------------- | ------------------------------------ | ---------------------------- |
| **Landing Page Option**         | Where users go after login           | Homepage or Custom           |
| **Landing Page Menu Item**      | Custom page for post-login redirect  | Select your page             |
| **Login with Password Allowed** | Allow traditional password login     | No (for full passwordless)   |
| **Bulk Password Hashing**       | Hash all existing frontend passwords | Use when disabling passwords |


#### Email Templates

Customize the email messages sent to users:

**Login Email:**

- **Subject**: The email subject line
- **Body**: The email content. Use placeholders:
  - `#name` - User's name
  - `#link` - Login link (auto-added if not in body)
  - `#expiry` - Link validity in minutes

**Registration Email:**

- **Subject**: The activation email subject
- **Body**: The activation email content. Same placeholders as above.

### 🔒 Security Settings

#### Base Limits


| Setting                                | Description                           | Default | Recommended |
| -------------------------------------- | ------------------------------------- | ------- | ----------- |
| **Link Expiry (minutes)**              | How long login links are valid        | 15      | 15-60       |
| **Registration Link Expiry (minutes)** | How long registration links are valid | 30      | 30-120      |
| **Cooldown (seconds)**                 | Minimum wait between requests         | 30      | 30-60       |


#### Rate Limits


| Setting                   | Description                        | Default | Recommended |
| ------------------------- | ---------------------------------- | ------- | ----------- |
| **Max Requests per IP**   | Max attempts from one IP           | 10      | 5-20        |
| **IP Window (minutes)**   | Time window for IP rate limiting   | 5       | 5-15        |
| **Max Requests per User** | Max attempts per user              | 5       | 3-10        |
| **User Window (minutes)** | Time window for user rate limiting | 10      | 10-30       |


#### Logging & Cleanup


| Setting                        | Description                       | Default | Recommended |
| ------------------------------ | --------------------------------- | ------- | ----------- |
| **Record Retention (minutes)** | How long to keep throttle records | 60      | 60-1440     |
| **Log Retention (days)**       | How long to keep audit logs       | 30      | 7-90        |


---

## 🚀 Getting Started

### For Site Visitors (End Users)

#### Logging In

1. **Find the login link** on your website (usually in the user menu or login module)
2. **Enter your email address** in the form
3. **Click "Send login link"**
4. **Check your email** for a message from the website
5. **Click the link** in the email to log in automatically
6. **You're logged in!** No password needed

#### Registering a New Account

1. **Find the registration link** on your website
2. **Enter your name and email address**
3. **Click "Register"**
4. **Check your email** for an activation link
5. **Click the activation link** to confirm your account
6. **You'll receive a login link** automatically after activation

### For Administrators

#### First-Time Setup

1. **Install and enable** the plugin
2. **Configure** the email templates with your branding
3. **Test** the login flow with a test user account
4. **Monitor** the logs in the plugin settings
5. **Optionally disable** password login if desired

#### Testing the Plugin

1. Create a test user account (Users → Manage → Add)
2. Go to the frontend and request a login link
3. Check that the test user receives the email
4. Verify the link works and logs the user in
5. Check the admin logs for the login attempt

#### Disabling Password Login

To enforce passwordless-only authentication:

1. Go to plugin settings
2. Set **"Login with Password Allowed"** to **No**
3. Click **"Hash all frontend passwords"** button
4. Save settings

⚠️ **Warning**: This will make it impossible for users to log in with passwords. Ensure all users have access to their email accounts.

---

## 🎨 Frontend Integration

### Adding Login/Registration Links

The plugin automatically intercepts core Joomla login and registration pages. To add login/registration to your site:

#### Option 1: Use Joomla Menu Items

1. **Create a menu item** → Users → Login Form
2. The plugin will automatically redirect to passwordless login
3. If password login is allowed, users can switch to password login

#### Option 2: Custom Login Button

Add a link to trigger the login overlay:

```html
<a href="index.php?simplelogin=1">Login with Email</a>
```

#### Option 3: Custom Registration Button

Add a link to trigger the registration overlay:

```html
<a href="index.php?sl_task=register">Create Account</a>
```

#### Option 4: Module Integration

Create a custom HTML module with:

```html
<div class="simplelogin-buttons">
    <a href="index.php?simplelogin=1" class="btn btn-primary">Login</a>
    <a href="index.php?sl_task=register" class="btn btn-secondary">Register</a>
</div>
```

### Styling the Overlay

The plugin includes default styling for the login/registration overlays. To customize:

1. **Override the layout files** in your template:
  - Copy `/plugins/system/simplelogin/layouts/simplelogin/overlay.php` to `/templates/YOUR_TEMPLATE/html/layouts/simplelogin/overlay.php`
  - Modify as needed
2. **Add custom CSS** to your template:

```css
/* Custom SimpleLogin overlay styling */
#simplelogin-overlay .sl-modal {
    background: #f8f9fa;
    border-radius: 8px;
}
#simplelogin-overlay .sl-btn-primary {
    background: #007bff;
    border-color: #007bff;
}
```

---

## 📊 Monitoring & Maintenance

### Viewing Logs

1. Go to **Extensions → Plugins → System - Simplelogin**
2. Scroll to the **Logging** section
3. Use the **Log Report** to view recent activity
4. Filter by type if needed

### Log Types


| Type                 | Description                                           |
| -------------------- | ----------------------------------------------------- |
| **LoginFlow**        | User login attempts and successes                     |
| **InviteFlow**       | Registration and activation events                    |
| **SecurityIncident** | Rate limiting, scanner detection                      |
| **AccountEvent**     | User account changes                                  |
| **Debug***           | Debug information (only when Joomla debug is enabled) |


### Exporting Logs

1. In the plugin settings, go to the **Logging** section
2. Click **"Export Log (last 24 hours)"**
3. Logs will be emailed to the site administrator

### Throttle Monitoring

1. Go to the **Active Sessions** section in plugin settings
2. View current throttle records
3. Monitor for suspicious activity

### Cleanup

The plugin automatically cleans up:

- Expired tokens
- Old throttle records (based on retention setting)
- Old log entries (based on retention setting)

---

## ❓ Troubleshooting

### Common Issues

#### Users don't receive emails

**Check:**

1. ✅ Joomla mail settings are configured correctly
2. ✅ Test emails work via System → Global Configuration → Server → Mail Settings
3. ✅ Emails are not in spam folder
4. ✅ Plugin email templates have valid subjects and bodies
5. ✅ User email addresses are valid and verified

**Solution:**

- Test with a known working email address
- Check Joomla mail queue if using queued emails
- Verify SMTP settings if using SMTP

#### Login links don't work

**Check:**

1. ✅ Link hasn't expired (check expiry time in settings)
2. ✅ Link hasn't been used already (single-use)
3. ✅ User account is not blocked
4. ✅ User account is activated
5. ✅ Token exists in database (`#__simple_login` table)

**Solution:**

- Request a new login link
- Check plugin logs for errors
- Verify database records exist

#### Registration links don't work

**Check:**

1. ✅ Link hasn't expired (default: 30 minutes)
2. ✅ Link hasn't been used already
3. ✅ User account still exists (not deleted due to expiry)

**Solution:**

- Register again with the same email
- Check if the user already exists
- Verify database records

#### "Too many attempts" error

**Check:**

1. ✅ Rate limiting settings in plugin configuration
2. ✅ IP address hasn't exceeded rate limit
3. ✅ User hasn't exceeded rate limit
4. ✅ Cooldown period has passed

**Solution:**

- Wait for the rate limit window to expire
- Try from a different IP address
- Adjust rate limiting settings if too restrictive

#### "Request denied" or "Access denied" error

**Check:**

1. ✅ User agent is not blocked (not a bot/scanner)
2. ✅ CSRF token is valid
3. ✅ User has permission to perform the action

**Solution:**

- Try with a regular browser
- Clear browser cache and cookies
- Check for suspicious user agents

### Debugging

#### Enable Debug Mode

1. Go to **System → Global Configuration → System**
2. Set **Debug System** to **Yes**
3. Set **Debug Language** to **Yes** (optional)

This will:

- Log debug information to `/logs/plg_system_simplelogin.php`
- Add debug entries to the log table (type: `Debug*`)
- Show detailed error messages

#### Check Database Tables

The plugin uses three database tables:

1. `**#__simple_login**` - Active tokens
  ```sql
   SELECT * FROM #__simple_login WHERE used = 0;
  ```
2. `**#__simple_login_throttle**` - Rate limiting data
  ```sql
   SELECT * FROM #__simple_login_throttle ORDER BY created DESC LIMIT 100;
  ```
3. `**#__simple_login_log**` - Audit logs
  ```sql
   SELECT * FROM #__simple_login_log ORDER BY created DESC LIMIT 100;
  ```

#### Clear Caches

If changes don't appear:

1. Clear Joomla cache: System → Clear Cache
2. Clear browser cache
3. The plugin automatically clears relevant caches on install/update

---

## 🔄 Upgrading

### From Previous Versions

1. **Backup** your website and database
2. **Download** the latest version
3. **Install** via Joomla Extensions → Install
4. **Clear cache** if needed

### Update Server

The plugin includes an update server for automatic updates:

- Update server URL: `https://raw.githubusercontent.com/adstam/simplelogin/main/update.xml`
- Joomla will automatically check for updates

---

## 🚫 Uninstalling

### Before Uninstalling

1. **Backup** your database
2. **Inform users** that passwordless login will no longer be available
3. **Re-enable password login** if it was disabled
4. **Export logs** if you need them for records

### Uninstall Process

1. Go to **Extensions → Manage → Manage**
2. Search for **System - Simplelogin**
3. Select the plugin and click **Uninstall**
4. The plugin and all its data will be removed

**Note:** Uninstalling removes:

- Plugin files
- Database tables (`#__simple_login`, `#__simple_login_throttle`, `#__simple_login_log`)
- Plugin configuration

---

## 📜 Changelog

### Version 1.0.41 (July 5, 2026)

- Initial stable release
- Passwordless login via email links
- Passwordless registration with email activation
- Comprehensive security features (rate limiting, cooldown, scanner detection)
- Full logging and monitoring
- Multi-language support (English, Dutch)
- Admin dashboard with logs and reports

---

## 🤝 Contributing

### Reporting Issues

If you encounter any bugs or have feature requests:

1. Check the [troubleshooting section](#troubleshooting)
2. Verify the issue still exists in the latest version
3. Create an issue on [GitHub](https://github.com/adstam/simplelogin/issues) with:
  - Clear description of the problem
  - Steps to reproduce
  - Expected vs actual behavior
  - Joomla version, PHP version, plugin version
  - Any relevant error messages

### Development Setup

1. Fork the repository on GitHub
2. Clone your fork locally
3. Install in a Joomla development environment
4. Make your changes
5. Test thoroughly
6. Submit a pull request

### Coding Standards

- Follow [Joomla Coding Standards](https://docs.joomla.org/J4.x:Joomla_Coding_Standards)
- Use PSR-12 for PHP code
- Include PHPDoc comments for all methods
- Keep methods focused and single-purpose
- Write secure code (validate all input, escape all output)

---

## 📄 License

SimpleLogin is released under the **GNU General Public License version 2 or later** (GPLv2+).

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
```

---

## 🙏 Credits

- **Product Owner & Concept**: Ad Stam
- **Architecture & Development**: AI Assistant
- **Testing & Feedback**: [Contributors](https://github.com/adstam/simplelogin/graphs/contributors)
- **Inspiration**: Magic link authentication patterns from modern web applications

---

## 📞 Support

### Documentation

- This README file
- [Architecture Documentation](simplelogin-architecture) (technical details)

### Community

- GitHub Discussions: [https://github.com/adstam/simplelogin/discussions](https://github.com/adstam/simplelogin/discussions)
- GitHub Issues: [https://github.com/adstam/simplelogin/issues](https://github.com/adstam/simplelogin/issues)

### Professional Support

For professional support, custom development, or consulting:

- **Website**: [https://demo.adstam.nl](https://demo.adstam.nl)
- **Email**: [info@adstam.nl](mailto:info@adstam.nl)

---

**SimpleLogin - Making authentication simple, secure, and password-free.** 🎉

*Documentation last updated: July 5, 2026 | Plugin version: 1.0.41*