-- ============================================================================
--  AlphaCP — Migration 0002 (Step 2B-1): panel core — users, RBAC, login guard
--  Field definitions follow docs/02-database-schema.sql (the frozen spec).
--  Applied by installer/panel-install.sh (tracked in schema_migrations).
-- ============================================================================

-- STEP S2 -- Panel users (admin, reseller, customer login, support staff)
CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username          VARCHAR(64)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password          VARCHAR(255) NOT NULL,
  display_name      VARCHAR(120) NULL,
  phone             VARCHAR(32)  NULL,
  role_id           BIGINT UNSIGNED NOT NULL,
  reseller_id       BIGINT UNSIGNED NULL,
  status            ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  twofa_enabled     TINYINT(1) NOT NULL DEFAULT 0,
  twofa_secret      VARBINARY(255) NULL,
  twofa_recovery    JSON NULL,
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
CREATE TABLE IF NOT EXISTS roles (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(50)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  level        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  description  VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL,
  updated_at   TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RBAC roles';

-- STEP S2 -- Permission registry (module.action, e.g. accounts.create)
CREATE TABLE IF NOT EXISTS permissions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(100) NOT NULL,
  module        VARCHAR(50)  NOT NULL,
  description   VARCHAR(255) NULL,
  is_privileged TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm_slug (slug),
  KEY idx_perm_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Permission registry';

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Role→permission map';

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  effect        ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  PRIMARY KEY (user_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user permission overrides';

-- STEP S2 -- Login attempts (cPHulk-style throttling; raw failures, IP kept)
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45) NOT NULL,
  username    VARCHAR(64) NOT NULL,
  successful  TINYINT(1) NOT NULL DEFAULT 0,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_ip (ip, created_at),
  KEY idx_login_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Login attempts for brute-force throttling';

-- ============================================================================
--  Seed: roles, core permissions, role→permission map
--  Idempotent (INSERT IGNORE) so re-running the installer is safe.
-- ============================================================================

INSERT IGNORE INTO roles (slug, name, level, is_system, description) VALUES
  ('superadmin', 'Super Admin',   10, 1, 'Full control incl. server + billing API'),
  ('admin',      'Administrator', 20, 1, 'Full server management'),
  ('reseller',   'Reseller',      50, 1, 'Manages own customers'),
  ('support',    'Support Staff', 70, 1, 'Read + limited actions'),
  ('user',       'Customer',      90, 1, 'Own account only (cPanel-equivalent)');

INSERT IGNORE INTO permissions (slug, module, description, is_privileged) VALUES
  ('panel.login',        'panel',    'Log in to the panel',                   0),
  ('panel.users.manage', 'panel',    'Create/edit panel users',               0),
  ('panel.audit.view',   'panel',    'View audit trail',                      0),
  ('server.view',        'server',   'View server health/services',           0),
  ('server.services',    'server',   'Control services (restart/stop)',       1),
  ('accounts.list',      'accounts', 'List hosting accounts',                 0),
  ('accounts.create',    'accounts', 'Create hosting accounts',               1),
  ('accounts.suspend',   'accounts', 'Suspend/unsuspend accounts',            1),
  ('accounts.terminate', 'accounts', 'Terminate accounts',                    1),
  ('packages.manage',    'packages', 'Manage hosting packages/limits',        1),
  ('dns.manage',         'dns',      'Manage DNS zones',                      1),
  ('mail.manage',        'mail',     'Manage email accounts',                 1),
  ('db.manage',          'db',       'Manage customer databases',             1),
  ('files.manage',       'files',    'File manager operations',               1),
  ('api.tokens',         'api',      'Create/manage API tokens (billing)',    0);

-- superadmin + admin get everything by default; other roles get scoped sets.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ('superadmin', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN ('panel.login','server.view','accounts.list','accounts.create','accounts.suspend',
                'packages.manage','dns.manage','mail.manage','db.manage','files.manage','api.tokens')
WHERE r.slug = 'reseller';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN ('panel.login','server.view','panel.audit.view','accounts.list')
WHERE r.slug = 'support';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN ('panel.login')
WHERE r.slug = 'user';
