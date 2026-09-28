-- ============================================================================
--  AlphaCP — Panel Database Schema (DESIGN SPEC)
--  Version: 1.0 (Step 0 blueprint)          Date: 2026-09-28
--  Status: DESIGN DOCUMENT — execution ke waqt ye Laravel migrations me
--          convert hoga (source of truth = migrations). Ye file reference hai.
--
--  Conventions:
--   • InnoDB, utf8mb4, snake_case, plural table names
--   • Every table: id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     created_at / updated_at TIMESTAMP NULL
--   • Soft deletes (deleted_at) only on: users, accounts, packages, domains
--   • FKs ON DELETE: RESTRICT by default (CASCADE only for pure child rows)
--   • **STEP tag** = roadmap step jisme ye table banti hai
--   • cPanel-compatible limit keys packages me flat columns ke roop me (QUOTA, MAXPOP...)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- SECTION 1: CORE (Step 2) — users, roles, permissions, audit, settings
-- ============================================================================

-- STEP S2 -- Panel users (admin, reseller, customer login, support staff)
CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username          VARCHAR(64)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password          VARCHAR(255) NOT NULL,
  display_name      VARCHAR(120) NULL,
  phone             VARCHAR(32)  NULL,
  role_id           BIGINT UNSIGNED NOT NULL,
  reseller_id       BIGINT UNSIGNED NULL,          -- if this user belongs to a reseller
  status            ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  twofa_enabled     TINYINT(1) NOT NULL DEFAULT 0,
  twofa_secret      VARBINARY(255) NULL,           -- encrypted
  twofa_recovery    JSON NULL,                     -- hashed recovery codes
  locale            VARCHAR(8)  NOT NULL DEFAULT 'en',
  timezone          VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  last_login_at     TIMESTAMP NULL,
  last_login_ip     VARCHAR(45) NULL,
  failed_logins     INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until      TIMESTAMP NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  deleted_at        TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_reseller (reseller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Panel logins (all personas)';

-- STEP S2 -- Roles: superadmin, admin, reseller, support, user
CREATE TABLE roles (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(50)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  level        SMALLINT UNSIGNED NOT NULL DEFAULT 100,  -- 10=superadmin .. 90=user
  is_system    TINYINT(1) NOT NULL DEFAULT 0,           -- system roles can't be deleted
  description  VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RBAC roles';

-- STEP S2 -- Permission registry (module.action, e.g. accounts.create)
CREATE TABLE permissions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(100) NOT NULL,      -- 'accounts.create'
  module       VARCHAR(50)  NOT NULL,      -- 'accounts'
  description  VARCHAR(255) NULL,
  is_privileged TINYINT(1) NOT NULL DEFAULT 0,  -- true => maps to root agent tasks
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_perm_slug (slug), KEY idx_perm_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Permission registry';

CREATE TABLE role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Role→permission map';

CREATE TABLE user_permissions (             -- per-user override (allow/deny)
  user_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  effect        ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  PRIMARY KEY (user_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user permission overrides';

-- STEP S2 -- API tokens (WHM API 1 + native REST), per-function permissions
CREATE TABLE api_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  token_hash    VARCHAR(255) NOT NULL,           -- sha256 of token; raw shown once
  type          ENUM('whm','uapi','native') NOT NULL DEFAULT 'whm',
  permissions   JSON NOT NULL,                   -- ["create-acct","suspend-acct", ...]
  ip_allowlist  JSON NULL,
  expires_at    TIMESTAMP NULL,
  last_used_at  TIMESTAMP NULL,
  last_used_ip  VARCHAR(45) NULL,
  revoked_at    TIMESTAMP NULL,
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_token_hash (token_hash), KEY idx_token_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Billing/API tokens with scoped permissions';

-- STEP S2 -- Audit trail (immutable, append-only)
CREATE TABLE audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type     ENUM('user','api','system','agent') NOT NULL,
  actor_id       BIGINT UNSIGNED NULL,
  actor_ip       VARCHAR(45) NULL,
  action         VARCHAR(100) NOT NULL,          -- 'account.suspend'
  target_type    VARCHAR(50) NULL,               -- 'account'
  target_id      BIGINT UNSIGNED NULL,
  severity       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  meta           JSON NULL,                      -- before/after, params (redacted)
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_actor (actor_type, actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Immutable audit trail';

-- STEP S2 -- Brute-force protection (cPHulk-style)
CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username    VARCHAR(64) NULL,
  ip          VARCHAR(45) NOT NULL,
  service     ENUM('panel','webmail','ftp','smtp','ssh') NOT NULL DEFAULT 'panel',
  success     TINYINT(1) NOT NULL DEFAULT 0,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_attempts_ip (ip, created_at), KEY idx_attempts_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Login attempts for brute-force engine';

CREATE TABLE ip_block_rules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope          ENUM('server','account') NOT NULL DEFAULT 'server',
  account_id     BIGINT UNSIGNED NULL,
  ip             VARCHAR(45) NOT NULL,
  reason         VARCHAR(255) NULL,
  auto           TINYINT(1) NOT NULL DEFAULT 0,
  blocked_until  TIMESTAMP NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_ipblock_ip (ip), KEY idx_ipblock_scope (scope, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP blocklist (manual + auto)';

-- STEP S2 -- Settings (global / server / user / account scope)
CREATE TABLE settings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       ENUM('global','server','user','account') NOT NULL DEFAULT 'global',
  scope_id    BIGINT UNSIGNED NULL,
  key_name    VARCHAR(100) NOT NULL,
  value       JSON NULL,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_settings (scope, scope_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Namespaced key-value settings';

-- STEP S2 -- System events / notifications feed
CREATE TABLE system_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(60) NOT NULL,              -- 'disk.threshold','service.down'
  severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  title       VARCHAR(190) NOT NULL,
  body        TEXT NULL,
  target_type VARCHAR(50) NULL, target_id BIGINT UNSIGNED NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  meta        JSON NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_events_type (type, created_at), KEY idx_events_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Events shown in notification bell + email alerts';

-- STEP S2 -- Password history (policy: no reuse of last N)
CREATE TABLE password_history (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  password   VARCHAR(255) NOT NULL,              -- hashed
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_pwhist_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S2 -- Webhooks (outbound: billing/native integrations)
CREATE TABLE webhook_endpoints (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  secret      VARCHAR(255) NOT NULL,             -- HMAC signing secret (encrypted)
  events      JSON NOT NULL,                     -- ["account.created","account.suspended",...]
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE webhook_deliveries (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  endpoint_id   BIGINT UNSIGNED NOT NULL,
  event         VARCHAR(60) NOT NULL,
  payload       JSON NOT NULL,
  response_code SMALLINT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  next_retry_at TIMESTAMP NULL,
  delivered_at  TIMESTAMP NULL,
  created_at    TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_wh_del (endpoint_id, status), KEY idx_wh_retry (next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 2: SERVERS / NODES / IPs (Step 2 core, Step 15 multi-server)
-- ============================================================================

-- STEP S2/S15 -- Servers: central (panel) + nodes
CREATE TABLE servers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(100) NOT NULL,
  hostname           VARCHAR(190) NOT NULL,
  role               ENUM('central','node') NOT NULL DEFAULT 'central',
  public_ip          VARCHAR(45) NULL,
  private_ip         VARCHAR(45) NULL,
  os                 VARCHAR(100) NULL,          -- 'Ubuntu 24.04'
  arch               ENUM('x86_64','aarch64') NULL,
  panel_version      VARCHAR(20) NULL,
  status             ENUM('active','maintenance','offline') NOT NULL DEFAULT 'active',
  fingerprint        VARCHAR(128) NULL,          -- license binding hash
  agent_token_hash   VARCHAR(255) NULL,          -- node auth
  specs              JSON NULL,                  -- cpu, ram_mb, disk_gb
  license_id         BIGINT UNSIGNED NULL,       -- fk to licenses (Section 11)
  last_heartbeat_at  TIMESTAMP NULL,
  created_at         TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_servers_hostname (hostname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Central panel + managed nodes';

-- STEP S2 -- Service status cache (apache, mysql, exim, dovecot, bind, ftp, redis)
CREATE TABLE server_services (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id     BIGINT UNSIGNED NOT NULL,
  service       VARCHAR(40) NOT NULL,
  status        ENUM('running','stopped','failed','unknown') NOT NULL DEFAULT 'unknown',
  version       VARCHAR(40) NULL,
  last_check_at TIMESTAMP NULL,
  meta          JSON NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_svc (server_id, service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S11 -- Server metrics time-series (sampled every minute by agent)
CREATE TABLE server_metrics (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id      BIGINT UNSIGNED NOT NULL,
  captured_at    TIMESTAMP NOT NULL,
  load1          DECIMAL(6,2) NULL,
  cpu_pct        DECIMAL(5,2) NULL,
  mem_used_mb    INT UNSIGNED NULL,
  mem_total_mb   INT UNSIGNED NULL,
  disk_used_gb   DECIMAL(8,2) NULL,
  disk_total_gb  DECIMAL(8,2) NULL,
  net_in_kbps    INT UNSIGNED NULL,
  net_out_kbps   INT UNSIGNED NULL,
  PRIMARY KEY (id), KEY idx_metrics_server_time (server_id, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S2 -- IP pool
CREATE TABLE server_ips (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id    BIGINT UNSIGNED NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  ipv6         VARCHAR(45) NULL,
  netmask      VARCHAR(45) NULL,
  type         ENUM('main','shared','dedicated') NOT NULL DEFAULT 'shared',
  status       ENUM('free','assigned','reserved') NOT NULL DEFAULT 'free',
  account_id   BIGINT UNSIGNED NULL,             -- if dedicated to an account
  notes        VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_ip (ip), KEY idx_ip_server (server_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 3: PACKAGES & LIMITS (Step 4)
-- ============================================================================

-- STEP S4 -- Feature lists (which UI tools a package can see)
CREATE TABLE feature_lists (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  features    JSON NOT NULL,                     -- {"email":true,"ftp":false,...}
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S4 -- Hosting packages (cPanel-compatible limit columns; -1 = unlimited)
CREATE TABLE packages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id        BIGINT UNSIGNED NULL,          -- NULL = admin-owned, else reseller
  name            VARCHAR(100) NOT NULL,         -- unique per owner
  description     VARCHAR(255) NULL,
  feature_list_id BIGINT UNSIGNED NULL,
  -- cPanel-compatible limit keys -------------------------------------------
  QUOTA              INT NOT NULL DEFAULT -1,    -- disk MB
  BWLIMIT            INT NOT NULL DEFAULT -1,    -- bandwidth MB/month
  MAXPOP             INT NOT NULL DEFAULT -1, MAXFWD INT NOT NULL DEFAULT -1,
  MAXRESP            INT NOT NULL DEFAULT -1, MAXPASS INT NOT NULL DEFAULT -1,
  MAXLST             INT NOT NULL DEFAULT -1, MAXFTP INT NOT NULL DEFAULT -1,
  MAXSQL             INT NOT NULL DEFAULT -1, MAXSUB INT NOT NULL DEFAULT -1,
  MAXPARK            INT NOT NULL DEFAULT -1, MAXADDON INT NOT NULL DEFAULT -1,
  MAXCRON            INT NOT NULL DEFAULT -1, MAXINODE INT NOT NULL DEFAULT -1,
  MAILBOXQUOTA       INT NOT NULL DEFAULT -1, DBQUOTA INT NOT NULL DEFAULT -1,
  MAXEMAILPERHOUR    INT NOT NULL DEFAULT 300, MAXMSGSIZE INT NOT NULL DEFAULT 50,
  HASSHELL           TINYINT(1) NOT NULL DEFAULT 0,
  DEDICATEDIP        TINYINT(1) NOT NULL DEFAULT 0,
  CPULIMIT           INT NOT NULL DEFAULT -1,    -- percent
  RAMLIMIT           INT NOT NULL DEFAULT -1,    -- MB
  IOLIMIT            INT NOT NULL DEFAULT -1,    -- MB/s
  NPROCLIMIT         INT NOT NULL DEFAULT -1, EPLIMIT INT NOT NULL DEFAULT -1,
  -- -------------------------------------------------------------------------
  is_default      TINYINT(1) NOT NULL DEFAULT 0,
  status          ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_pkg_owner (owner_id), KEY idx_pkg_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Hosting plans (cPanel-compatible limits)';

-- STEP S15 -- Reseller allocation ledgers (how many accounts of which package)
CREATE TABLE reseller_allocations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id  BIGINT UNSIGNED NOT NULL,
  package_id   BIGINT UNSIGNED NOT NULL,
  qty_total    INT NOT NULL DEFAULT 0,
  qty_used     INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_alloc (reseller_id, package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 4: ACCOUNTS (Step 3) — the heart of the panel
-- ============================================================================

-- STEP S3 -- Hosting accounts (Linux user = account)
CREATE TABLE accounts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id         BIGINT UNSIGNED NOT NULL,
  package_id        BIGINT UNSIGNED NOT NULL,
  reseller_id       BIGINT UNSIGNED NULL,        -- billing/hierarchy
  owner_user_id     BIGINT UNSIGNED NULL,        -- panel login of the customer
  username          VARCHAR(32) NOT NULL,        -- linux username (unique per server)
  main_domain       VARCHAR(190) NOT NULL,
  contact_email     VARCHAR(190) NOT NULL,
  ip_id             BIGINT UNSIGNED NULL,        -- dedicated IP if any
  home_path         VARCHAR(255) NOT NULL,       -- /home/<username>
  status            ENUM('pending','active','suspended','terminated') NOT NULL DEFAULT 'pending',
  suspend_reason    VARCHAR(255) NULL,
  suspended_at      TIMESTAMP NULL,
  suspend_outgoing_mail TINYINT(1) NOT NULL DEFAULT 0,   -- WHM API: suspend_outgoing_email
  terminated_at     TIMESTAMP NULL,
  setup_completed_at TIMESTAMP NULL,
  -- cached usage (updated by usage.sync; also in usage tables) --
  disk_used_mb      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bw_used_mb        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  inodes_used       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_usage_sync_at TIMESTAMP NULL,
  meta              JSON NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acct_user_server (server_id, username),
  UNIQUE KEY uq_acct_domain (main_domain),
  KEY idx_acct_status (status), KEY idx_acct_reseller (reseller_id),
  KEY idx_acct_package (package_id), KEY idx_acct_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Hosting accounts (one per linux user)';

-- STEP S3 -- Lifecycle events per account (create/suspend/unsuspend/terminate/modify)
CREATE TABLE account_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  BIGINT UNSIGNED NOT NULL,
  event       VARCHAR(60) NOT NULL,
  message     VARCHAR(500) NULL,
  meta        JSON NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_acctev (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S3 -- Which panel users can access which account (owner + additional)
CREATE TABLE account_users (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('owner','additional') NOT NULL DEFAULT 'owner',
  created_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_acct_user (account_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S11 -- Daily usage snapshots (billing + graphs + quota alerts)
CREATE TABLE account_usage_daily (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id   BIGINT UNSIGNED NOT NULL,
  date         DATE NOT NULL,
  disk_mb      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bw_mb        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  inodes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  emails_sent  INT UNSIGNED NOT NULL DEFAULT 0,
  cpu_avg_pct  DECIMAL(5,2) NULL,
  mem_peak_mb  INT UNSIGNED NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_usage_day (account_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S11 -- Per-domain bandwidth (WHM API showbw source)
CREATE TABLE bandwidth_daily (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  domain_id  BIGINT UNSIGNED NULL,
  date       DATE NOT NULL,
  bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uq_bw (account_id, domain_id, date), KEY idx_bw_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S10 -- Account transfer / migration jobs (in/out, cPanel import)
CREATE TABLE account_transfers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  BIGINT UNSIGNED NULL,
  direction   ENUM('import','export') NOT NULL,
  source      VARCHAR(255) NULL,                 -- cPanel backup path / remote
  status      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  log_path    VARCHAR(255) NULL,
  started_at  TIMESTAMP NULL, finished_at TIMESTAMP NULL,
  created_at  TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_transfer_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 5: DOMAINS & DNS (Step 5 + Step 9)
-- ============================================================================

-- STEP S5 -- Domains of an account (main / addon / parked / subdomain)
CREATE TABLE domains (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id      BIGINT UNSIGNED NOT NULL,
  domain          VARCHAR(190) NOT NULL,
  type            ENUM('main','addon','parked','subdomain') NOT NULL DEFAULT 'main',
  parent_domain_id BIGINT UNSIGNED NULL,         -- for subdomains
  doc_root        VARCHAR(255) NOT NULL,
  php_version     VARCHAR(10) NULL,              -- '8.3'
  php_ini         JSON NULL,                     -- per-domain overrides
  ssl_cert_id     BIGINT UNSIGNED NULL,
  https_forced    TINYINT(1) NOT NULL DEFAULT 0,
  status          ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_domain (domain), KEY idx_domain_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S5 -- URL redirects
CREATE TABLE redirects (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_id    BIGINT UNSIGNED NOT NULL,
  source_path  VARCHAR(255) NOT NULL DEFAULT '/',
  target_url   VARCHAR(500) NOT NULL,
  type         ENUM('301','302') NOT NULL DEFAULT '301',
  wildcard     TINYINT(1) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_redirect_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S9 -- DNS zones (BIND)
CREATE TABLE dns_zones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain        VARCHAR(190) NOT NULL,           -- zone origin (no trailing dot)
  account_id    BIGINT UNSIGNED NULL,
  server_id     BIGINT UNSIGNED NULL,
  type          ENUM('master','slave') NOT NULL DEFAULT 'master',
  template_id   BIGINT UNSIGNED NULL,
  serial        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  refresh_ttl   INT NOT NULL DEFAULT 3600,
  retry_ttl     INT NOT NULL DEFAULT 600,
  expire_ttl    INT NOT NULL DEFAULT 604800,
  minimum_ttl   INT NOT NULL DEFAULT 86400,
  default_ttl   INT NOT NULL DEFAULT 14400,
  status        ENUM('active','deleted') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_zone (domain), KEY idx_zone_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S9 -- DNS records
CREATE TABLE dns_records (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  zone_id    BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(190) NOT NULL,              -- '@' or 'www'
  type       ENUM('A','AAAA','CNAME','MX','TXT','SRV','CAA','NS','PTR','SOA','SPF','DKIM','DMARC','ALIAS')
             NOT NULL,
  content    TEXT NOT NULL,
  ttl        INT NOT NULL DEFAULT 14400,
  priority   INT NULL, weight INT NULL, port INT NULL, flag INT NULL, tag VARCHAR(20) NULL,
  is_auto    TINYINT(1) NOT NULL DEFAULT 0,      -- managed by panel (e.g. DKIM)
  comment    VARCHAR(190) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_rec_zone (zone_id, type), KEY idx_rec_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S9 -- Zone templates for new accounts
CREATE TABLE dns_zone_templates (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  records      JSON NOT NULL,                    -- [{name,type,content,ttl}...]
  is_default   TINYINT(1) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S9 -- DNS cluster membership
CREATE TABLE dns_cluster_members (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id  BIGINT UNSIGNED NOT NULL,
  role       ENUM('standalone','master','slave') NOT NULL DEFAULT 'standalone',
  status     ENUM('ok','error') NOT NULL DEFAULT 'ok',
  last_sync_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_dns_member (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 6: EMAIL (Step 7)
-- ============================================================================

-- STEP S7 -- Mail domain settings (per hosted domain)
CREATE TABLE mail_domains (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id          BIGINT UNSIGNED NOT NULL,
  domain              VARCHAR(190) NOT NULL,
  dkim_selector       VARCHAR(40) NOT NULL DEFAULT 'default',
  dkim_private_path   VARCHAR(255) NULL,
  spf_status          ENUM('missing','ok','warn') NOT NULL DEFAULT 'missing',
  dkim_status         ENUM('missing','ok','warn') NOT NULL DEFAULT 'missing',
  dmarc_status        ENUM('missing','ok','warn') NOT NULL DEFAULT 'missing',
  ptr_status          ENUM('missing','ok','warn') NOT NULL DEFAULT 'missing',
  deliverability_score TINYINT UNSIGNED NULL,    -- 0-100
  catch_all_target    VARCHAR(190) NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_maildomain (domain), KEY idx_maildom_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S7 -- Mailboxes (Dovecot)
CREATE TABLE mailboxes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mail_domain_id BIGINT UNSIGNED NOT NULL,
  local_part     VARCHAR(64) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  password       VARCHAR(255) NOT NULL,          -- dovecot-compatible hash
  quota_mb       INT NOT NULL DEFAULT 1024,
  used_mb        INT UNSIGNED NOT NULL DEFAULT 0,
  status         ENUM('active','suspended') NOT NULL DEFAULT 'active',
  suspended_outgoing TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at  TIMESTAMP NULL,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_mailbox (email), KEY idx_mailbox_domain (mail_domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mail_forwarders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mail_domain_id BIGINT UNSIGNED NOT NULL,
  source         VARCHAR(190) NOT NULL,          -- local part or full address
  destination    VARCHAR(500) NOT NULL,
  keep_copy      TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_fwd_domain (mail_domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mail_autoresponders (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mailbox_id  BIGINT UNSIGNED NOT NULL,
  subject     VARCHAR(190) NULL,
  body        TEXT NULL,
  is_html     TINYINT(1) NOT NULL DEFAULT 0,
  start_at    TIMESTAMP NULL, end_at TIMESTAMP NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_autoresp (mailbox_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S7 -- Sieve filters (per mailbox) + global (mailbox_id NULL)
CREATE TABLE mail_filters (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mail_domain_id BIGINT UNSIGNED NOT NULL,
  mailbox_id     BIGINT UNSIGNED NULL,           -- NULL = domain-wide/global
  name           VARCHAR(100) NOT NULL,
  rules          JSON NOT NULL,                  -- [{field,op,value}]
  actions        JSON NOT NULL,                  -- [{type:'move'|'delete'|'forward',...}]
  priority       SMALLINT NOT NULL DEFAULT 100,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_filter_domain (mail_domain_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spam_settings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope            ENUM('account','mailbox') NOT NULL,
  scope_id         BIGINT UNSIGNED NOT NULL,
  required_score   DECIMAL(4,1) NOT NULL DEFAULT 5.0,
  auto_delete_score DECIMAL(4,1) NULL,
  whitelist        JSON NULL,
  blacklist        JSON NULL,
  created_at       TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_spam (scope, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mailing_lists (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mail_domain_id   BIGINT UNSIGNED NOT NULL,
  name             VARCHAR(100) NOT NULL,
  admin_password   VARCHAR(255) NULL,
  moderators       JSON NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_mlist (mail_domain_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mailing_list_members (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id   BIGINT UNSIGNED NOT NULL,
  email     VARCHAR(190) NOT NULL,
  name      VARCHAR(120) NULL,
  status    ENUM('active','pending','unsubscribed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_mlist_member (list_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 7: DATABASES (Step 8)
-- ============================================================================

-- STEP S8 -- Customer databases
CREATE TABLE mysql_databases (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    BIGINT UNSIGNED NOT NULL,
  server_id     BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(64) NOT NULL,            -- e.g. user_wp1
  size_mb       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  size_limit_mb INT NOT NULL DEFAULT -1,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_dbname (name), KEY idx_db_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mysql_users (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  username   VARCHAR(64) NOT NULL,
  password   VARCHAR(255) NOT NULL,              -- encrypted store for display/reapply
  host       VARCHAR(64) NOT NULL DEFAULT 'localhost',
  status     ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_dbuser (username, host), KEY idx_dbuser_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mysql_privileges (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mysql_user_id    BIGINT UNSIGNED NOT NULL,
  database_id      BIGINT UNSIGNED NOT NULL,
  privileges       JSON NOT NULL,                -- ["SELECT","INSERT",...] or ["ALL"]
  PRIMARY KEY (id), UNIQUE KEY uq_priv (mysql_user_id, database_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE remote_mysql_hosts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  host       VARCHAR(190) NOT NULL,              -- IP or hostname or %
  created_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_remote_host (account_id, host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 8: FILES / FTP / CRON / SSH / GIT (Step 6)
-- ============================================================================

-- STEP S6 -- FTP accounts
CREATE TABLE ftp_accounts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  username   VARCHAR(64) NOT NULL,
  password   VARCHAR(255) NOT NULL,
  home_dir   VARCHAR(255) NOT NULL,
  quota_mb   INT NOT NULL DEFAULT -1,
  status     ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_ftpuser (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S6 -- Cron jobs (crontab per account)
CREATE TABLE cron_jobs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    BIGINT UNSIGNED NOT NULL,
  schedule      VARCHAR(100) NOT NULL,           -- '*/5 * * * *'
  command       TEXT NOT NULL,
  output_email  VARCHAR(190) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at   TIMESTAMP NULL,
  last_status   ENUM('ok','error') NULL,
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_cron_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S6 -- SSH keys (authorized_keys managed per account)
CREATE TABLE ssh_keys (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  public_key  TEXT NOT NULL,
  fingerprint VARCHAR(128) NULL,
  created_at  TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_sshkey_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S6 -- Git deployments
CREATE TABLE git_deployments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id     BIGINT UNSIGNED NOT NULL,
  domain_id      BIGINT UNSIGNED NULL,
  repo_url       VARCHAR(500) NOT NULL,
  branch         VARCHAR(100) NOT NULL DEFAULT 'main',
  path           VARCHAR(255) NOT NULL,
  auto_deploy    TINYINT(1) NOT NULL DEFAULT 0,
  deploy_key_id  BIGINT UNSIGNED NULL,
  last_deploy_at TIMESTAMP NULL,
  status         ENUM('idle','deploying','failed') NOT NULL DEFAULT 'idle',
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_git_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S6 -- Trash (file manager safety net)
CREATE TABLE file_trash (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    BIGINT UNSIGNED NOT NULL,
  original_path VARCHAR(500) NOT NULL,
  trash_path    VARCHAR(500) NOT NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deleted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_trash_acct (account_id), KEY idx_trash_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 9: BACKUP (Step 10)
-- ============================================================================

-- STEP S10 -- Backup schedules (per account / reseller / server-wide)
CREATE TABLE backup_schedules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope          ENUM('account','reseller','server') NOT NULL,
  scope_id       BIGINT UNSIGNED NULL,
  frequency      ENUM('daily','weekly','monthly','custom') NOT NULL DEFAULT 'daily',
  time_of_day    TIME NOT NULL DEFAULT '02:00:00',
  retention_days INT NOT NULL DEFAULT 7,
  include        JSON NOT NULL,                  -- {"files":true,"db":true,"mail":true,"dns":true}
  destinations   JSON NULL,                      -- [{"type":"s3","bucket":"..."},{"type":"ftp"}]
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_bksch_scope (scope, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backups (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id  BIGINT UNSIGNED NULL,
  account_id   BIGINT UNSIGNED NULL,
  type         ENUM('full','files','database','mail','dns') NOT NULL DEFAULT 'full',
  path         VARCHAR(500) NULL,
  destination  VARCHAR(255) NULL,                -- local|s3|ftp|sftp
  size_mb      BIGINT UNSIGNED NULL,
  checksum     VARCHAR(128) NULL,                -- sha256
  status       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  started_at   TIMESTAMP NULL, finished_at TIMESTAMP NULL,
  expires_at   TIMESTAMP NULL,
  log_path     VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_bk_account (account_id, created_at), KEY idx_bk_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backup_restores (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  backup_id    BIGINT UNSIGNED NOT NULL,
  account_id   BIGINT UNSIGNED NOT NULL,
  items        JSON NOT NULL,                    -- what was restored
  status       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  performed_by BIGINT UNSIGNED NULL,
  log_path     VARCHAR(255) NULL,
  started_at   TIMESTAMP NULL, finished_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_br_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 10: SSL / APPS / SECURITY (Steps 5, 13, 14)
-- ============================================================================

-- STEP S5 -- SSL certificates
CREATE TABLE ssl_certificates (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_id    BIGINT UNSIGNED NOT NULL,
  provider     ENUM('letsencrypt','custom','self') NOT NULL DEFAULT 'letsencrypt',
  common_name  VARCHAR(190) NOT NULL,
  sans         JSON NULL,
  cert_path    VARCHAR(255) NULL, key_path VARCHAR(255) NULL,
  issued_at    TIMESTAMP NULL, expires_at TIMESTAMP NULL,
  auto_renew   TINYINT(1) NOT NULL DEFAULT 1,
  status       ENUM('pending','active','expired','failed') NOT NULL DEFAULT 'pending',
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_ssl_domain (domain_id), KEY idx_ssl_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S5 -- ACME order log
CREATE TABLE ssl_orders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_id BIGINT UNSIGNED NOT NULL,
  type           ENUM('issue','renew','revoke') NOT NULL,
  challenge      ENUM('http-01','dns-01') NOT NULL DEFAULT 'http-01',
  status         ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  log            TEXT NULL,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_sslorder_cert (certificate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S14 -- One-click app catalog
CREATE TABLE app_catalog (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(60) NOT NULL,           -- 'wordpress'
  name           VARCHAR(100) NOT NULL,
  category       VARCHAR(60) NULL,
  versions       JSON NULL,                      -- available versions
  source_url     VARCHAR(500) NULL,
  install_handler VARCHAR(100) NULL,             -- agent handler name
  requirements   JSON NULL,                      -- php ext, db, etc
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_app_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE app_installations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id   BIGINT UNSIGNED NOT NULL,
  domain_id    BIGINT UNSIGNED NOT NULL,
  app_id       BIGINT UNSIGNED NOT NULL,
  version      VARCHAR(30) NULL,
  path         VARCHAR(255) NOT NULL,
  database_id  BIGINT UNSIGNED NULL,
  admin_email  VARCHAR(190) NULL,
  auto_update  TINYINT(1) NOT NULL DEFAULT 0,
  status       ENUM('installing','active','failed','removed') NOT NULL DEFAULT 'installing',
  installed_at TIMESTAMP NULL,
  meta         JSON NULL,
  PRIMARY KEY (id), KEY idx_appinst_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S14 -- WordPress toolkit metadata
CREATE TABLE wordpress_sites (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  installation_id       BIGINT UNSIGNED NOT NULL,
  wp_version            VARCHAR(20) NULL,
  core_update_available TINYINT(1) NOT NULL DEFAULT 0,
  plugin_count          INT UNSIGNED NULL,
  theme_count           INT UNSIGNED NULL,
  vulnerabilities       JSON NULL,
  last_scan_at          TIMESTAMP NULL,
  staging_of_id         BIGINT UNSIGNED NULL,    -- if this is a staging copy
  status                ENUM('ok','warning','critical') NOT NULL DEFAULT 'ok',
  created_at            TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_wp_install (installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S13 -- Malware scans + threats
CREATE TABLE malware_scans (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    BIGINT UNSIGNED NULL,
  server_id     BIGINT UNSIGNED NULL,
  type          ENUM('manual','scheduled') NOT NULL DEFAULT 'manual',
  status        ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  files_scanned BIGINT UNSIGNED NOT NULL DEFAULT 0,
  threats_found INT UNSIGNED NOT NULL DEFAULT 0,
  started_at    TIMESTAMP NULL, finished_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_scan_acct (account_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE malware_threats (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scan_id         BIGINT UNSIGNED NOT NULL,
  account_id      BIGINT UNSIGNED NULL,
  file_path       VARCHAR(500) NOT NULL,
  signature       VARCHAR(190) NULL,
  severity        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status          ENUM('detected','quarantined','cleaned','ignored') NOT NULL DEFAULT 'detected',
  quarantine_path VARCHAR(500) NULL,
  detected_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_threat_scan (scan_id), KEY idx_threat_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S13 -- ModSecurity per-account rules/toggles
CREATE TABLE modsecurity_rules (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  BIGINT UNSIGNED NULL,              -- NULL = server-wide
  domain_id   BIGINT UNSIGNED NULL,
  rule_id     VARCHAR(40) NULL,
  description VARCHAR(255) NULL,
  action      ENUM('enable','disable') NOT NULL DEFAULT 'enable',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_modsec_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 11: LICENSING (Step 2 client + Step 15 license server)
-- ============================================================================

-- STEP S2 -- Local license state (panel side; one active row per server)
CREATE TABLE licenses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id         BIGINT UNSIGNED NOT NULL,
  license_key       VARCHAR(64) NOT NULL,
  license_uid       VARCHAR(64) NULL,            -- server-issued id
  tier              VARCHAR(40) NULL,            -- trial|starter|business|unlimited|oem
  features          JSON NULL,                   -- feature flags from payload
  max_accounts      INT NOT NULL DEFAULT 0,
  fingerprint       VARCHAR(128) NULL,
  payload           JSON NULL,                   -- full signed payload (as received)
  signature         VARCHAR(512) NULL,           -- Ed25519 signature (base64)
  status            ENUM('trial','active','grace','expired','revoked','invalid') NOT NULL DEFAULT 'trial',
  activated_at      TIMESTAMP NULL,
  expires_at        TIMESTAMP NULL,
  grace_until       TIMESTAMP NULL,
  last_heartbeat_at TIMESTAMP NULL,
  created_at        TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_license_server (server_id), KEY idx_license_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cached license state (signed payload verified)';

-- STEP S2 -- License event log (audit + debugging)
CREATE TABLE license_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id BIGINT UNSIGNED NULL,
  event      ENUM('activate','heartbeat','expired','grace_enter','revoked','invalid','renewed') NOT NULL,
  request    JSON NULL,
  response   JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_lic_ev (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S15 -- License server side tables (license-server/ app uses these)
-- NOTE: separate database. Kept here for the full picture.
CREATE TABLE ls_customers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(150) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  company    VARCHAR(150) NULL,
  country    VARCHAR(60) NULL,
  status     ENUM('active','suspended','fraud') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_ls_cust_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ls_licenses (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id   BIGINT UNSIGNED NOT NULL,
  license_key   VARCHAR(64) NOT NULL,
  license_uid   VARCHAR(64) NOT NULL,
  product       VARCHAR(40) NOT NULL DEFAULT 'alphacp',
  tier          VARCHAR(40) NOT NULL,
  max_accounts  INT NOT NULL DEFAULT 0,
  features      JSON NULL,
  max_servers   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  issued_at     TIMESTAMP NULL,
  expires_at    TIMESTAMP NULL,
  status        ENUM('trial','active','suspended','expired','revoked') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_ls_key (license_key), UNIQUE KEY uq_ls_uid (license_uid),
  KEY idx_ls_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ls_activations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id   BIGINT UNSIGNED NOT NULL,
  fingerprint  VARCHAR(128) NOT NULL,
  hostname     VARCHAR(190) NULL,
  ip           VARCHAR(45) NULL,
  panel_version VARCHAR(20) NULL,
  activated_at TIMESTAMP NULL,
  last_seen_at TIMESTAMP NULL,
  status       ENUM('active','released','blocked') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id), UNIQUE KEY uq_ls_act (license_id, fingerprint), KEY idx_ls_act_fp (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ls_heartbeats (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id  BIGINT UNSIGNED NOT NULL,
  fingerprint VARCHAR(128) NOT NULL,
  accounts_count INT UNSIGNED NULL,
  panel_version VARCHAR(20) NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_ls_hb (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 12: TASK AGENT + UPDATES + HEALTH (Steps 1-2 infra)
-- ============================================================================

-- STEP S2 -- THE privileged task queue (executed by paneld)
CREATE TABLE tasks (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id      BIGINT UNSIGNED NOT NULL,
  type           VARCHAR(80) NOT NULL,           -- 'account.create'
  safety         ENUM('readonly','mutating','destructive') NOT NULL,
  payload        JSON NOT NULL,
  priority       TINYINT UNSIGNED NOT NULL DEFAULT 100,  -- lower = sooner
  account_id     BIGINT UNSIGNED NULL,
  requested_by   BIGINT UNSIGNED NULL,           -- user id (or NULL = system)
  status         ENUM('queued','running','success','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  result         JSON NULL,
  error          TEXT NULL,
  claimed_at     TIMESTAMP NULL,
  started_at     TIMESTAMP NULL,
  finished_at    TIMESTAMP NULL,
  created_at     TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_task_pick (server_id, status, priority, id),
  KEY idx_task_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Privileged task queue (agent work)';

-- STEP S2 -- Live task logs (streamed to UI progress)
CREATE TABLE task_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id    BIGINT UNSIGNED NOT NULL,
  level      ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  line       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_tlog_task (task_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S1 -- Update history (panel self-updates)
CREATE TABLE updates_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_version VARCHAR(20) NULL, to_version VARCHAR(20) NOT NULL,
  channel      ENUM('stable','beta') NOT NULL DEFAULT 'stable',
  status       ENUM('started','success','failed','rolled_back') NOT NULL DEFAULT 'started',
  triggered_by BIGINT UNSIGNED NULL,
  log_path     VARCHAR(255) NULL,
  started_at   TIMESTAMP NULL, finished_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_upd_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S11 -- Health checks history
CREATE TABLE health_checks (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id  BIGINT UNSIGNED NOT NULL,
  check_name VARCHAR(80) NOT NULL,               -- 'disk_root','service_mysql','mail_queue'
  status     ENUM('ok','warning','critical') NOT NULL,
  value      VARCHAR(190) NULL,
  threshold  VARCHAR(190) NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_health (server_id, check_name, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S12 -- API request log (billing integration debugging + token usage)
CREATE TABLE api_request_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id     BIGINT UNSIGNED NULL,
  user_id      BIGINT UNSIGNED NULL,
  api          ENUM('whm','uapi','native') NOT NULL,
  function_name VARCHAR(100) NOT NULL,
  ip           VARCHAR(45) NULL,
  http_status  SMALLINT NULL,
  duration_ms  INT UNSIGNED NULL,
  request      JSON NULL,                        -- redacted
  response     JSON NULL,                        -- redacted
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_apilog_fn (function_name, created_at), KEY idx_apilog_token (token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 13: RESELLER (Step 15)
-- ============================================================================

CREATE TABLE reseller_profiles (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  parent_reseller_id BIGINT UNSIGNED NULL,
  company_name       VARCHAR(150) NULL,
  brand_name         VARCHAR(100) NULL,
  logo_path          VARCHAR(255) NULL,
  primary_color      VARCHAR(9) NULL,
  support_email      VARCHAR(190) NULL,
  whitelabel_domain  VARCHAR(190) NULL,
  max_accounts       INT NOT NULL DEFAULT 0,
  max_disk_mb        BIGINT NOT NULL DEFAULT 0,
  allowed_packages   JSON NULL,
  status             ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_reseller_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  APPENDIX: cPanel limit → column map (for WHM API 1 compatibility)
--   QUOTA→QUOTA · BWLIMIT→BWLIMIT · MAXPOP→MAXPOP · MAXFWD→MAXFWD
--   MAXLST→MAXLST · MAXSQL→MAXSQL · MAXSUB→MAXSUB · MAXPARK→MAXPARK
--   MAXADDON→MAXADDON · MAXFTP→MAXFTP · MAXCRON→MAXCRON · HASSHELL→HASSHELL
--  Accounting: -1 ya 0 = unlimited (module docs me exact semantics).
--
--  TOTAL: 55 tables (v1 design). Har table ka STEP tag batata hai kaunsa
--  roadmap step usko banayega. Migration order = STEP order.
-- ============================================================================
