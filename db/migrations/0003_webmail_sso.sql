-- ============================================================================
--  STEP P-UI-4 — Webmail SSO one-time tokens (Roundcube auto-login, cPanel-style)
--  Panel token banata hai (POST /webmail/open), Roundcube plugin verify karta
--  hai (GET /internal/webmail-sso + shared secret), phir token used mark.
--  Idempotent (IF NOT EXISTS) so re-running safe.
-- ============================================================================
CREATE TABLE IF NOT EXISTS webmail_sso_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token       CHAR(64)        NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  mailbox     VARCHAR(255)    NOT NULL,
  used        TINYINT(1)      NOT NULL DEFAULT 0,
  expires_at  DATETIME        NOT NULL,
  created_at  TIMESTAMP       NULL,
  updated_at  TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webmail_sso_token (token),
  KEY idx_webmail_sso_user (user_id, used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='One-time Webmail (Roundcube) SSO tokens';
