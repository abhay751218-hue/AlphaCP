-- ============================================================================
--  AlphaCP — Migration 0001 (Step 2A): core tables
--  Applied by installer/step2-install.sh into database `alphacp`.
--  Field definitions follow docs/02-database-schema.sql (the frozen spec).
--  Later migrations add users/accounts/mail/... as their steps arrive.
-- ============================================================================

-- STEP S2 -- Servers (central panel + managed nodes)
CREATE TABLE IF NOT EXISTS servers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(100) NOT NULL,
  hostname           VARCHAR(190) NOT NULL,
  role               ENUM('central','node') NOT NULL DEFAULT 'central',
  public_ip          VARCHAR(45) NULL,
  private_ip         VARCHAR(45) NULL,
  os                 VARCHAR(100) NULL,
  arch               ENUM('x86_64','aarch64') NULL,
  panel_version      VARCHAR(20) NULL,
  status             ENUM('active','maintenance','offline') NOT NULL DEFAULT 'active',
  fingerprint        VARCHAR(128) NULL,
  agent_token_hash   VARCHAR(255) NULL,
  specs              JSON NULL,
  license_id         BIGINT UNSIGNED NULL,
  last_heartbeat_at  TIMESTAMP NULL,
  created_at         TIMESTAMP NULL,
  updated_at         TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_servers_hostname (hostname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Central panel + managed nodes';

-- STEP S2 -- Namespaced key-value settings
CREATE TABLE IF NOT EXISTS settings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       ENUM('global','server','user','account') NOT NULL DEFAULT 'global',
  scope_id    BIGINT UNSIGNED NULL,
  key_name    VARCHAR(100) NOT NULL,
  value       JSON NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings (scope, scope_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Namespaced key-value settings';

-- STEP S2 -- System events / notifications feed
CREATE TABLE IF NOT EXISTS system_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(60) NOT NULL,
  severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  title       VARCHAR(190) NOT NULL,
  body        TEXT NULL,
  target_type VARCHAR(50) NULL,
  target_id   BIGINT UNSIGNED NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  meta        JSON NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_type (type, created_at),
  KEY idx_events_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Events shown in notification bell + email alerts';

-- STEP S2 -- Privileged task queue (agent work)
CREATE TABLE IF NOT EXISTS tasks (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  server_id      BIGINT UNSIGNED NOT NULL,
  type           VARCHAR(80) NOT NULL,
  safety         ENUM('readonly','mutating','destructive') NOT NULL,
  payload        JSON NOT NULL,
  priority       TINYINT UNSIGNED NOT NULL DEFAULT 100,
  account_id     BIGINT UNSIGNED NULL,
  requested_by   BIGINT UNSIGNED NULL,
  requested_src  VARCHAR(40) NOT NULL DEFAULT 'cli',
  status         ENUM('queued','running','success','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  result         JSON NULL,
  error          TEXT NULL,
  claimed_at     TIMESTAMP NULL,
  started_at     TIMESTAMP NULL,
  finished_at    TIMESTAMP NULL,
  duration_ms    INT UNSIGNED NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_task_pick (server_id, status, priority, id),
  KEY idx_task_acct (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Privileged task queue (agent work)';

-- STEP S2 -- Live task logs (streamed to UI progress)
CREATE TABLE IF NOT EXISTS task_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id    BIGINT UNSIGNED NOT NULL,
  level      ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  line       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tlog_task (task_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP S2 -- Audit trail (immutable, append-only)
CREATE TABLE IF NOT EXISTS audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type     ENUM('user','api','system','agent') NOT NULL,
  actor_id       BIGINT UNSIGNED NULL,
  actor_ip       VARCHAR(45) NULL,
  action         VARCHAR(100) NOT NULL,
  target_type    VARCHAR(50) NULL,
  target_id      BIGINT UNSIGNED NULL,
  severity       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  meta           JSON NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_actor (actor_type, actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Immutable audit trail';

-- STEP S1/S2 -- Update history (panel self-updates)
CREATE TABLE IF NOT EXISTS updates_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_version VARCHAR(20) NULL,
  to_version   VARCHAR(20) NOT NULL,
  channel      ENUM('stable','beta') NOT NULL DEFAULT 'stable',
  status       ENUM('started','success','failed','rolled_back') NOT NULL DEFAULT 'started',
  triggered_by BIGINT UNSIGNED NULL,
  log_path     VARCHAR(255) NULL,
  started_at   TIMESTAMP NULL,
  finished_at  TIMESTAMP NULL,
  created_at   TIMESTAMP NULL,
  updated_at   TIMESTAMP NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Panel update/rollback history';

-- Schema version marker (migrations runner checks this)
CREATE TABLE IF NOT EXISTS schema_migrations (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version    VARCHAR(40) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
