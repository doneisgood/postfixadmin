<?php

use PHPUnit\Framework\TestCase;

class DomainOidcTest extends TestCase
{
    public function setUp(): void
    {
        db_execute("CREATE TABLE IF NOT EXISTS domain_oidc (
            domain VARCHAR(255) NOT NULL PRIMARY KEY,
            issuer_url TEXT NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            client_secret VARCHAR(255) NOT NULL,
            scopes VARCHAR(255) DEFAULT 'openid email profile',
            login_button_text VARCHAR(255) DEFAULT 'Login with SSO',
            auto_provision SMALLINT DEFAULT 0,
            mfa_policy VARCHAR(50) DEFAULT 'none'
        )");
        db_execute("CREATE TABLE IF NOT EXISTS domain (
            domain VARCHAR(255) NOT NULL PRIMARY KEY,
            description VARCHAR(255) NOT NULL DEFAULT '',
            aliases INTEGER NOT NULL DEFAULT 0,
            mailboxes INTEGER NOT NULL DEFAULT 0,
            maxquota INTEGER NOT NULL DEFAULT 0,
            quota INTEGER NOT NULL DEFAULT 0,
            transport VARCHAR(255) NOT NULL DEFAULT '',
            backupmx BOOLEAN NOT NULL DEFAULT false,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            modified DATETIME DEFAULT CURRENT_TIMESTAMP,
            active BOOLEAN NOT NULL DEFAULT true
        )");
        db_execute("DELETE FROM domain_oidc");
        db_execute("DELETE FROM domain WHERE domain LIKE 'test-%'");
    }

    public function tearDown(): void
    {
        db_execute("DELETE FROM domain_oidc");
        db_execute("DELETE FROM domain WHERE domain LIKE 'test-%'");
    }

    public function testInsertDomainOidcConfig(): void
    {
        $domain = 'test-oidc-1.com';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test domain', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret) VALUES (?, ?, ?, ?)",
            [$domain, 'https://keycloak.example.com/realms/test', 'test-client', 'test-secret']);
        $result = db_query_one("SELECT * FROM domain_oidc WHERE domain = ?", [$domain]);
        $this->assertNotEmpty($result);
        $this->assertEquals($domain, $result['domain']);
        $this->assertEquals('https://keycloak.example.com/realms/test', $result['issuer_url']);
    }

    public function testUpdateDomainOidcConfig(): void
    {
        $domain = 'test-oidc-2.com';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret) VALUES (?, ?, ?, ?)",
            [$domain, 'https://old.example.com', 'old-client', 'old-secret']);
        db_execute("UPDATE domain_oidc SET issuer_url = ?, client_id = ?, client_secret = ? WHERE domain = ?",
            ['https://new.example.com', 'new-client', 'new-secret', $domain]);
        $result = db_query_one("SELECT * FROM domain_oidc WHERE domain = ?", [$domain]);
        $this->assertEquals('https://new.example.com', $result['issuer_url']);
    }

    public function testDeleteDomainOidcConfig(): void
    {
        $domain = 'test-oidc-3.com';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret) VALUES (?, ?, ?, ?)",
            [$domain, 'https://keycloak.example.com', 'client', 'secret']);
        db_execute("DELETE FROM domain_oidc WHERE domain = ?", [$domain]);
        $result = db_query_one("SELECT * FROM domain_oidc WHERE domain = ?", [$domain]);
        $this->assertEmpty($result);
    }

    public function testAutoProvisionFlag(): void
    {
        $domain = 'test-oidc-5.com';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret, auto_provision) VALUES (?, ?, ?, ?, ?)",
            [$domain, 'https://keycloak.example.com', 'client', 'secret', 1]);
        $result = db_query_one("SELECT auto_provision FROM domain_oidc WHERE domain = ?", [$domain]);
        $this->assertEquals(1, $result['auto_provision']);
    }

    public function testMfaPolicyPerDomain(): void
    {
        $domain = 'test-oidc-6.com';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret, mfa_policy) VALUES (?, ?, ?, ?, ?)",
            [$domain, 'https://keycloak.example.com', 'client', 'secret', 'mfa_or_totp']);
        $result = db_query_one("SELECT mfa_policy FROM domain_oidc WHERE domain = ?", [$domain]);
        $this->assertEquals('mfa_or_totp', $result['mfa_policy']);
    }

    public function testGetOidcConfigByIssuer(): void
    {
        $domain = 'test-oidc-7.com';
        $issuer = 'https://keycloak.example.com/realms/special';
        db_execute("INSERT INTO domain (domain, description, transport) VALUES (?, ?, ?)", [$domain, 'Test', '']);
        db_execute("INSERT INTO domain_oidc (domain, issuer_url, client_id, client_secret) VALUES (?, ?, ?, ?)",
            [$domain, $issuer, 'client', 'secret']);
        $result = db_query_one("SELECT * FROM domain_oidc WHERE issuer_url = ?", [$issuer]);
        $this->assertNotEmpty($result);
        $this->assertEquals($domain, $result['domain']);
    }
}
