-- ============================================================================
-- MBC - Email - Struktur
-- ============================================================================
-- Folders/Messages/Attachments/Tags + Views.
-- Konsolidiert aus den urspruenglichen Einzel-Migrationen (Reihenfolge erhalten).
-- ============================================================================


-- ============================================================================
-- MBC Email Module - Database Schema
-- ============================================================================
-- Version: 1.0.0
-- Erstellt: 2025-12-07
-- Beschreibung: IMAP-basiertes E-Mail-Management mit Datenbank-Synchronisierung
-- Phase: 1 (Read-Only IMAP Client)
-- ============================================================================

-- ============================================================================
-- Tabelle: mbc_email_folders
-- Beschreibung: IMAP-Ordner-Struktur (Inbox, Sent, Drafts, etc.)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_email_folders (
  -- Primary Key
  folders_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Basis-Daten
  name VARCHAR(255) NOT NULL COMMENT 'Interner Name (z.B. INBOX)',
  display_name VARCHAR(255) NOT NULL COMMENT 'Anzeigename (lokalisiert)',
  imap_path VARCHAR(500) NOT NULL COMMENT 'Vollständiger IMAP-Pfad',

  -- Ordner-Typ
  type ENUM('inbox', 'sent', 'drafts', 'trash', 'spam', 'archive', 'custom')
    NOT NULL DEFAULT 'custom' COMMENT 'Ordner-Typ',

  -- Hierarchie
  parent_id INT UNSIGNED DEFAULT NULL COMMENT 'Parent-Ordner-ID (NULL = Root)',

  -- User-Zuordnung
  user_id INT UNSIGNED NOT NULL COMMENT 'Besitzer des Ordners',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_email_folder_parent
    FOREIGN KEY (parent_id)
    REFERENCES mbc_email_folders(folders_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_email_folder_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Unique Constraint
  UNIQUE KEY uk_imap_path_user (imap_path, user_id),

  -- Indizes
  INDEX idx_folder_user_id (user_id),
  INDEX idx_folder_parent_id (parent_id),
  INDEX idx_folder_type (type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IMAP-Ordner-Struktur (Email-Modul)';


-- ============================================================================
-- Tabelle: mbc_email_messages
-- Beschreibung: Synchronisierte E-Mail-Nachrichten (ohne Attachment-Bodies)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_email_messages (
  -- Primary Key
  emails_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- IMAP-Identifikation
  uid INT UNSIGNED NOT NULL COMMENT 'IMAP UID (eindeutig pro Ordner)',
  message_id VARCHAR(255) NOT NULL COMMENT 'RFC 5322 Message-ID',

  -- Ordner-Zuordnung
  folder_id INT UNSIGNED NOT NULL COMMENT 'FK zu mbc_email_folders',

  -- Absender
  from_email VARCHAR(255) NOT NULL COMMENT 'Absender E-Mail-Adresse',
  from_name VARCHAR(255) DEFAULT NULL COMMENT 'Absender Name',

  -- Empfänger (JSON Arrays)
  to_json JSON NOT NULL COMMENT 'Array von {email, name}',
  cc_json JSON DEFAULT NULL COMMENT 'CC-Empfänger Array',
  bcc_json JSON DEFAULT NULL COMMENT 'BCC-Empfänger Array (nur gesendete E-Mails)',

  -- E-Mail-Inhalt
  subject VARCHAR(500) NOT NULL COMMENT 'Betreff',
  body_plain TEXT NOT NULL COMMENT 'Plain-Text-Body',
  body_html MEDIUMTEXT DEFAULT NULL COMMENT 'HTML-Body (optional)',

  -- E-Mail-Metadaten
  email_date DATETIME NOT NULL COMMENT 'Datum aus E-Mail-Header',
  is_read BOOLEAN DEFAULT FALSE COMMENT 'Gelesen-Status',
  is_flagged BOOLEAN DEFAULT FALSE COMMENT 'Markierungs-Status (Stern/Flag)',
  has_attachments BOOLEAN DEFAULT FALSE COMMENT 'Hat Anhänge',
  size_bytes INT UNSIGNED DEFAULT 0 COMMENT 'Größe in Bytes',

  -- User-Zuordnung
  user_id INT UNSIGNED NOT NULL COMMENT 'FK zu mbc_users',

  -- Timestamps
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_email_message_folder
    FOREIGN KEY (folder_id)
    REFERENCES mbc_email_folders(folders_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_email_message_user
    FOREIGN KEY (user_id)
    REFERENCES mbc_users(users_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Unique Constraint (UID eindeutig pro Ordner und User)
  UNIQUE KEY uk_uid_folder_user (uid, folder_id, user_id),

  -- Indizes
  INDEX idx_email_folder_id (folder_id),
  INDEX idx_email_user_id (user_id),
  INDEX idx_email_message_id (message_id),
  INDEX idx_email_is_read (is_read),
  INDEX idx_email_date (email_date),
  INDEX idx_email_from (from_email),
  INDEX idx_email_subject (subject(100))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='E-Mail-Nachrichten (Email-Modul)';


-- ============================================================================
-- Tabelle: mbc_email_attachments
-- Beschreibung: Attachment-Metadaten (ohne Datei-Inhalt, Phase 1)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_email_attachments (
  -- Primary Key
  attachments_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- E-Mail-Zuordnung
  email_id INT UNSIGNED NOT NULL COMMENT 'FK zu mbc_email_messages',

  -- Attachment-Metadaten
  filename VARCHAR(500) NOT NULL COMMENT 'Dateiname',
  mime_type VARCHAR(255) NOT NULL COMMENT 'MIME-Type',
  size_bytes INT UNSIGNED NOT NULL COMMENT 'Größe in Bytes',
  imap_part_id VARCHAR(50) NOT NULL COMMENT 'IMAP Part-ID für Download',

  -- Timestamp
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Constraints
  CONSTRAINT fk_email_attachment_email
    FOREIGN KEY (email_id)
    REFERENCES mbc_email_messages(emails_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_attachment_email_id (email_id),
  INDEX idx_attachment_filename (filename(100))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Attachment-Metadaten (Email-Modul)';


-- ============================================================================
-- Tabelle: mbc_email_tags
-- Beschreibung: Junction Table für E-Mail-Tags (N:M-Relation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mbc_email_tags (
  -- Composite Primary Key
  email_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,

  -- Timestamp
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Primary Key
  PRIMARY KEY (email_id, tag_id),

  -- Constraints
  CONSTRAINT fk_email_tag_email
    FOREIGN KEY (email_id)
    REFERENCES mbc_email_messages(emails_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT fk_email_tag_tag
    FOREIGN KEY (tag_id)
    REFERENCES mbc_tags(tags_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  -- Indizes
  INDEX idx_email_tag_id (tag_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='E-Mail-Tags Junction Table (Email-Modul)';


-- ============================================================================
-- Views für vereinfachte Queries
-- ============================================================================

-- View: Ordner mit Unread-Count und Total-Count
CREATE OR REPLACE VIEW vw_email_folders_with_counts AS
SELECT
  f.*,
  COUNT(CASE WHEN m.is_read = FALSE THEN 1 END) AS unread_count,
  COUNT(m.emails_id) AS total_count
FROM mbc_email_folders f
LEFT JOIN mbc_email_messages m ON f.folders_id = m.folder_id
GROUP BY f.folders_id;


-- View: E-Mails mit Tags (aggregiert)
CREATE OR REPLACE VIEW vw_email_messages_with_tags AS
SELECT
  m.emails_id,
  m.uid,
  m.message_id,
  m.folder_id,
  m.from_email,
  m.from_name,
  m.to_json,
  m.cc_json,
  m.bcc_json,
  m.subject,
  m.body_plain,
  m.body_html,
  m.email_date,
  m.is_read,
  m.is_flagged,
  m.has_attachments,
  m.size_bytes,
  m.user_id,
  m.created_at,
  m.updated_at,
  GROUP_CONCAT(
    JSON_OBJECT('id', t.tags_id, 'name', t.name)
    SEPARATOR ','
  ) AS tags_json
FROM mbc_email_messages m
LEFT JOIN mbc_email_tags et ON m.emails_id = et.email_id
LEFT JOIN mbc_tags t ON et.tag_id = t.tags_id
GROUP BY m.emails_id;


-- View: E-Mails mit Attachment-Count
CREATE OR REPLACE VIEW vw_email_messages_with_attachment_count AS
SELECT
  m.*,
  COUNT(a.attachments_id) AS attachment_count
FROM mbc_email_messages m
LEFT JOIN mbc_email_attachments a ON m.emails_id = a.email_id
GROUP BY m.emails_id;


-- ============================================================================
-- Schema-Version-Tracking
-- ============================================================================

INSERT INTO mbc_schema_versions (module, version, description)
VALUES ('email', '1.0.0', 'Initial schema - Phase 1: Read-Only IMAP Client')
ON DUPLICATE KEY UPDATE
  version = '1.0.0',
  applied_at = CURRENT_TIMESTAMP,
  description = 'Initial schema - Phase 1: Read-Only IMAP Client';


-- ============================================================================
-- Test-Daten (optional - kann auskommentiert werden)
-- ============================================================================

-- Beispiel: Standard-Ordner für User 1 (falls benötigt)
-- INSERT INTO mbc_email_folders (name, display_name, imap_path, type, parent_id, user_id) VALUES
--   ('INBOX', 'Posteingang', 'INBOX', 'inbox', NULL, 1),
--   ('Sent', 'Gesendet', 'INBOX.Sent', 'sent', NULL, 1),
--   ('Drafts', 'Entwürfe', 'INBOX.Drafts', 'drafts', NULL, 1),
--   ('Trash', 'Papierkorb', 'INBOX.Trash', 'trash', NULL, 1);


-- ============================================================================
-- Ende des Schemas
-- ============================================================================

