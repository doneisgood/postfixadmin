# Per-Domain OIDC — Design Proposal

**Status:** Draft for discussion
**Author:** @doneisgood (FrogPond.Cloud)
**Related:** PR #1144 (global OIDC support), @TrapoSAMA suggested this feature

## Problem

PR #1144 adds global OIDC — one IdP for all admins. This works for single-tenant deployments but doesn't support:

1. **Per-domain IdPs** — Domain A uses Keycloak, Domain B uses Google
2. **Domain-scoped auto-provisioning** — OIDC users should get domain-admin rights, not super-admin
3. **Multi-tenant hosting** — one PostfixAdmin instance serving multiple organizations with their own IdPs

## Goals

| Goal | Description |
|------|-------------|
| Global OIDC | Config-file IdP lets any admin log in (permissions assigned by super-admin) |
| Domain OIDC | Database-configured per-domain IdP grants domain-scoped permissions |
| Migration path | Existing global OIDC users are not broken |
| UI-managed | Super-admin configures per-domain OIDC through the web UI |

## Authentication Methods

PostfixAdmin supports multiple authentication methods that can be combined:

| Method | Status | Configured Via | Notes |
|--------|--------|----------------|-------|
| **Local Password** | Built-in | Always available | Default admin method, always enabled |
| **OIDC** | Implemented | `$CONF['additional_auth'] = ['oidc']` | Per-domain or global IdP (Keycloak, Google, etc.) |
| **LDAP** | Planned | `$CONF['additional_auth'][] = 'ldap'` | Not yet implemented; aspirational |
| **SAML** | Planned | `$CONF['additional_auth'][] = 'saml'` | Not yet implemented; aspirational |

The `additional_auth` array is designed for extensibility. Any combination can be enabled:
```php
// Examples:
$CONF['additional_auth'] = ['oidc'];                    // OIDC only
$CONF['additional_auth'] = ['oidc', 'ldap'];             // OIDC + LDAP
$CONF['additional_auth'] = ['ldap'];                    // LDAP only (local password always available)
$CONF['additional_auth'] = [];                          // Local password only (default)
```

Local password auth is **always available** regardless of `additional_auth` — it cannot be disabled.

## Configuration

### Global OIDC — Config File

```php
// config.local.php — global OIDC for any admin
$CONF['oidc'] = [
    'client_id'     => 'postfixadmin',
    'client_secret' => '...',
    'issuer_url'    => 'https://keycloak.example.com/realms/master',
    'scopes'        => 'openid email profile',
    'login_button_text' => 'Login with Keycloak',
];
```

- Stays in `config.local.php` (not UI-managed)
- Users authenticating via this IdP get permissions that the super-admin already assigned them
- If user doesn't exist yet and `oidc_auto_provision` is enabled, they get created as regular admin (not super-admin)

### OIDC Identity Method

```php
// OIDC identity method: 'email' (legacy, backward compat) or 'issuer_sub' (recommended).
// 'email' — same email = same account across all IdPs (legacy behavior)
// 'issuer_sub' — each IdP is a separate identity space (secure, recommended)
$CONF['oidc_identity'] = 'issuer_sub';
```

- `email` — legacy behavior, same email = same account (backward compat)
- `issuer_sub` — secure, recommended, each IdP is its own identity space

### Per-Domain OIDC — Database

```sql
CREATE TABLE domain_oidc (
    domain VARCHAR(255) NOT NULL PRIMARY KEY REFERENCES domain(domain) ON DELETE CASCADE,
    issuer_url TEXT NOT NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret VARCHAR(255) NOT NULL,
    scopes VARCHAR(255) DEFAULT 'openid email profile',
    login_button_text VARCHAR(255) DEFAULT 'Login with SSO',
    auto_provision SMALLINT DEFAULT 0,
    mfa_policy VARCHAR(50) DEFAULT 'none',
    mfa_methods TEXT DEFAULT NULL,  -- comma-separated whitelist, NULL = use global
    mfa_blacklist TEXT DEFAULT NULL -- comma-separated blacklist, NULL = use global
);
```

- Managed through Domain Edit UI
- Only super-admins can configure
- Per-domain MFA policy override

### Admin Tracking

```sql
ALTER TABLE admin ADD COLUMN oidc_issuer TEXT;
ALTER TABLE admin ADD COLUMN oidc_sub VARCHAR(255);
```

- Records which IdP created the admin account
- NULL = local password user
- **Identity binding by issuer+sub** (stable, unique) instead of email

## Login Flow

```
User visits login page
    ↓
Sees "Login with SSO" button (global) and/or per-domain buttons
    ↓
Option A: Global IdP button → authenticate → user gets their existing permissions
Option B: Per-domain button (e.g., domain.com) → domain IdP → domain-admin
    ↓
Callback validates token
    ↓
Look up user by configured identity method:
    ├── 'issuer_sub' mode → look up by (oidc_issuer, oidc_sub)
    │   ├── Found → use that account
    │   └── Not found → auto-provision if enabled
    └── 'email' mode → look up by username (email)
        ├── Found → upgrade old account with issuer+sub
        └── Not found → auto-provision if enabled
    ↓
Check if domain config exists:
    ├── Domain IdP → add to domain_admins for that domain
    └── Global IdP → use permissions already assigned by super-admin
```

## Identity Binding: issuer + sub (instead of email)

**Problem with email binding:**
- Email can change in IdP → loses access
- Email can be reused (old employee → new employee) → inherits access
- Email is not globally unique across IdPs

**Solution: issuer + sub**
- `iss` (issuer): which IdP issued the token
- `sub` (subject): stable unique user ID within that issuer
- Never changes, even if email changes
- Globally unique per issuer

**Migration:**
1. Add `oidc_issuer` and `oidc_sub` columns to `admin` table
2. On first OIDC login, store `iss + sub` alongside username
3. Look up users by `iss + sub` (or by username in legacy `email` mode)
4. Email becomes display attribute only

## The Username Uniqueness Problem

**The issue:** If the same email is used at different IdPs, and we store it as the username, we have a PK collision.

| username | oidc_issuer | oidc_sub | Notes |
|----------|-------------|----------|-------|
| doneisgood@example.com | NULL | NULL | Local password user |
| doneisgood@example.com | keycloak.example.com/realms/OrgA | user-123 | OrgA OIDC user |
| doneisgood@example.com | keycloak.example.com/realms/OrgB | user-456 | OrgB OIDC user |

**Option A: Composite key `(username, oidc_issuer)`**
```sql
ALTER TABLE admin DROP CONSTRAINT admin_key;
ALTER TABLE admin ADD PRIMARY KEY (username, oidc_issuer);
```
- ✅ Clean per-domain separation
- ❌ **Breaks everything that expects unique username** — `Login.php`, `AdminHandler::init()`, `AdminHandler::store()`, etc.

**Option B: Linking table (minimal disruption)**
```sql
CREATE TABLE admin_oidc (
    id SERIAL PRIMARY KEY,
    admin_username varchar(255) NOT NULL REFERENCES admin(username),
    oidc_issuer text NOT NULL,
    oidc_sub varchar(255) NOT NULL,
    UNIQUE (oidc_issuer, oidc_sub)
);
```
- ✅ `admin` table unchanged — username stays unique PK
- ✅ Login flow: look up `admin_oidc` by issuer+sub → get `admin_username` → look up `admin`
- ❌ Same email at different IdP = different admin accounts = **duplicate usernames in the admin list**

**Option C: Same email = same account (simplest)**
- ✅ Username stays unique, no code breaks
- ❌ Same email at different IdP = **same account** (can't separate them)

**The core question for the team:** Same email at different IdP — do you want same account or different accounts? If different, you either break username uniqueness (Option A) or generate unique usernames (Option B).

## Auto-Provisioning

| Source | Behavior |
|--------|----------|
| Global IdP | If user exists, use their permissions. If not and `oidc_auto_provision` is enabled, create regular admin (not super-admin) |
| Domain IdP | Create admin + insert into `domain_admins` for that domain |
| Domain IdP + no auto_provision | Reject login, "Contact administrator" |

## UI Changes

### Domain List
- New "OIDC" column showing enabled/disabled status
- "Configure OIDC" link per domain

### Domain Edit Page
- New "OIDC Authentication" section (visible to super-admins)
- Fields: issuer URL, client ID, client secret, scopes, button text, auto-provision, MFA policy
- Enable/disable toggle

### Login Page
- If per-domain OIDC configured: show per-domain buttons (one per domain with OIDC configured)
- If only global OIDC: single button (current behavior)

## Migration

Existing global OIDC users:
1. Their `oidc_issuer` is set to the global issuer
2. Their `oidc_sub` is set from the token
3. They retain their existing permissions
4. No action required

## Open Questions

1. **Username uniqueness** — composite key vs linking table vs same-email-same-account (see above)
2. **Email-based domain detection** — what if email domain doesn't match any configured domain?
3. **Multiple IdPs per domain** — should we support fallback IdPs?
4. **Group/role mapping** — should IdP groups map to PostfixAdmin permissions?
5. **Discovery document** — validate `issuer` in `.well-known/openid-configuration` before saving?

## Scope

This is a follow-up to PR #1144. The global OIDC in that PR becomes the "global admin" path. This proposal adds the per-domain layer.

## Implementation Estimate (take with laughing salts)

| Component | Effort |
|-----------|--------|
| Database schema + migration | 1 day |
| Domain Edit UI | 1-2 days |
| Login flow changes | 1-2 days |
| Auto-provisioning logic | 1 day |
| Tests | 1-2 days |
| **Total** | **5-8 days** |
