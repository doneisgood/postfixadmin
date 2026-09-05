# Per-Domain LDAP — Design Proposal

**Status:** Draft for discussion
**Author:** @doneisgood (FrogPond.Cloud)
**Related:** PR #1144 (global OIDC support), Per-Domain OIDC Design

## Problem

LLDAP provides a single LDAP directory for the homelab. This works for simple deployments but doesn't support:

1. **Per-domain LDAP** — Domain A uses LLDAP, Domain B uses a different LDAP server
2. **Multi-tenant hosting** — one PostfixAdmin instance serving multiple organizations with their own directories
3. **Domain-scoped auto-provisioning** — LDAP users should get domain-admin rights, not super-admin

## Goals

| Goal | Description |
|------|-------------|
| Global LDAP | Config-file LDAP lets any admin log in (permissions assigned by super-admin) |
| Domain LDAP | Database-configured per-domain LDAP grants domain-scoped permissions |
| Migration path | Existing global OIDC users are not broken |
| UI-managed | Super-admin configures per-domain LDAP through the web UI |

## Authentication Methods

PostfixAdmin supports multiple authentication methods that can be combined:

| Method | Status | Configured Via | Notes |
|--------|--------|----------------|-------|
| **Local Password** | Built-in | Always available | Default admin method, always enabled |
| **OIDC** | Implemented | `$CONF['additional_auth'] = ['oidc']` | Per-domain or global IdP (Keycloak, Google, etc.) |
| **LDAP** | Planned | `$CONF['additional_auth'][] = 'ldap'` | Per-domain or global LDAP (LLDAP, Active Directory, etc.) |
| **SAML** | Planned | `$CONF['additional_auth'][] = 'saml'` | Not yet implemented; aspirational |

The `additional_auth` array is designed for extensibility. Any combination can be enabled:
```php
// Examples:
$CONF['additional_auth'] = ['oidc'];                    // OIDC only
$CONF['additional_auth'] = ['ldap'];                    // LDAP only
$CONF['additional_auth'] = ['oidc', 'ldap'];             // OIDC + LDAP
$CONF['additional_auth'] = ['oidc', 'ldap', 'saml'];    // All methods
$CONF['additional_auth'] = [];                          // Local password only (default)
```

Local password auth is **always available** regardless of `additional_auth` — it cannot be disabled.

## Configuration

### Global LDAP — Config File

```php
// config.local.php — global LDAP for any admin
$CONF['additional_auth'] = ['ldap'];

$CONF['ldap'] = [
    'host'         => 'lldap.frogpond.cloud',
    'port'         => 3890,
    'base_dn'      => 'ou=people,dc=frogpond,dc=cloud',
    'bind_dn'      => 'cn=readonly,ou=people,dc=frogpond,dc=cloud',
    'bind_password' => '...',
    'user_filter'  => '(&(uid=%s)(objectClass=person))',
    'login_button_text' => 'Login with LDAP',
];
```

- Stays in `config.local.php` (not UI-managed)
- Users authenticating via this LDAP get permissions that the super-admin already assigned them
- If user doesn't exist yet and `ldap_auto_provision` is enabled, they get created as regular admin (not super-admin)

### Per-Domain LDAP — Database

```sql
CREATE TABLE domain_ldap (
    domain VARCHAR(255) NOT NULL PRIMARY KEY REFERENCES domain(domain) ON DELETE CASCADE,
    host VARCHAR(255) NOT NULL,
    port INTEGER NOT NULL DEFAULT 389,
    encryption VARCHAR(10) DEFAULT 'none',  -- 'none', 'tls', 'ssl'
    base_dn VARCHAR(255) NOT NULL,
    bind_dn VARCHAR(255) NOT NULL,
    bind_password VARCHAR(255) NOT NULL,
    user_filter VARCHAR(255) DEFAULT '(uid=%s)',
    login_button_text VARCHAR(255) DEFAULT 'Login with LDAP',
    auto_provision SMALLINT DEFAULT 0
);
```

- Managed through Domain Edit UI
- Only super-admins can configure
- Per-domain LDAP server override

## Login Flow

```
User visits login page
    ↓
Sees "Login with LDAP" button (global) and/or per-domain buttons
    ↓
Option A: Global LDAP button → authenticate → user gets their existing permissions
Option B: Per-domain button (e.g., domain.com) → domain LDAP → domain-admin
    ↓
Determine LDAP server:
    ├── Per-domain LDAP configured → use domain_ldap table
    └── Global LDAP configured → use $CONF['ldap']
    ↓
Bind to LDAP with service account (bind_dn)
    ↓
Search for user by user_filter (substitute username)
    ↓
If user found → attempt ldap_bind() with user's DN + entered password
    ↓
If success:
    ├── User exists in admin table → use that account
    └── User doesn't exist → auto-provision if enabled
    ↓
Check if domain config exists:
    ├── Domain LDAP → add to domain_admins for that domain
    └── Global LDAP → use permissions already assigned by super-admin
```

## Implementation Details

### PHP LDAP Extension

Requires `ext-ldap` (standard PHP extension, usually installed). Uses:
- `ldap_connect()` — connect to server
- `ldap_bind()` — authenticate (service account or user)
- `ldap_search()` — find user entry
- `ldap_get_entries()` — extract user attributes

### Username Uniqueness

LDAP is typically ONE directory per domain, so username uniqueness is simpler than OIDC:

| LDAP User | admin.username | Notes |
|-----------|----------------|-------|
| `doneisgood` (uid from LLDAP) | `doneisgood` | Maps directly to admin.username |
| `kathy` (uid from LLDAP) | `kathy` | Maps directly |

**Rule:** LDAP `uid` attribute maps to `admin.username`. Since `uid` is unique within an LDAP directory, and each domain has its own directory, there's no collision risk.

**For global LDAP:** Same principle — `uid` is unique in the directory.

**For mixed OIDC + LDAP:** OIDC stores email as username, LDAP stores `uid`. If they differ, they're different accounts. No conflict.

### Auto-Provisioning

| Source | Behavior |
|--------|----------|
| Global LDAP | If user exists, use their permissions. If not and `ldap_auto_provision` is enabled, create regular admin |
| Domain LDAP | Create admin + insert into `domain_admins` for that domain |
| Domain LDAP + no auto_provision | Reject login, "Contact administrator" |

### Encryption Options

| Encryption | Port | Notes |
|------------|------|-------|
| `none` | 389 | Plain text (internal networks) |
| `tls` | 389 | STARTTLS upgrade |
| `ssl` | 636 | LDAPS |

## UI Changes

### Domain List
- New "LDAP" column showing enabled/disabled status
- "Configure LDAP" link per domain

### Domain Edit Page
- New "LDAP Authentication" section (visible to super-admins)
- Fields: host, port, encryption, base DN, bind DN, bind password, user filter, button text, auto-provision
- Enable/disable toggle

### Login Page
- If per-domain LDAP configured: show per-domain buttons
- If only global LDAP: single button

## Migration

Existing global OIDC users are unaffected. LDAP is additive — it doesn't replace or conflict with OIDC.

## Open Questions

1. **Username mapping** — should we use `uid`, `mail`, or `cn` as the LDAP attribute that maps to `admin.username`?
2. **Group/role mapping** — should LDAP groups map to PostfixAdmin permissions (domain-admin vs super-admin)?
3. **Multiple LDAP servers per domain** — fallback LDAP servers?
4. **LDAPS certificate validation** — trust self-signed certs?

## Scope

This is a follow-up to the Per-Domain OIDC design. It adds LDAP as a second authentication method alongside OIDC. Both can be enabled simultaneously.

## Implementation Estimate

| Component | Effort |
|-----------|--------|
| Database schema + migration | 1 day |
| Domain Edit UI | 1 day |
| Login flow changes | 1-2 days |
| Auto-provisioning logic | 1 day |
| Tests | 1-2 days |
| **Total** | **5-8 days** |
