-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db_patches/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/abuse_filter (
  af_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  af_pattern BLOB NOT NULL,
  af_user BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  af_user_text VARBINARY(255) DEFAULT '' NOT NULL,
  af_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  af_timestamp BINARY(14) NOT NULL,
  af_enabled TINYINT(1) DEFAULT 1 NOT NULL,
  af_comments BLOB DEFAULT NULL,
  af_public_comments TINYBLOB DEFAULT NULL,
  af_hidden TINYINT(1) DEFAULT 0 NOT NULL,
  af_hit_count BIGINT DEFAULT 0 NOT NULL,
  af_throttled TINYINT(1) DEFAULT 0 NOT NULL,
  af_deleted TINYINT(1) DEFAULT 0 NOT NULL,
  af_actions VARCHAR(255) DEFAULT '' NOT NULL,
  af_global TINYINT(1) DEFAULT 0 NOT NULL,
  af_group VARBINARY(64) DEFAULT 'default' NOT NULL,
  INDEX af_user (af_user),
  INDEX af_actor (af_actor),
  INDEX af_group_enabled (af_group, af_enabled, af_id),
  PRIMARY KEY(af_id)
) /*$wgDBTableOptions*/;


CREATE TABLE /*_*/abuse_filter_action (
  afa_filter BIGINT UNSIGNED NOT NULL,
  afa_consequence VARCHAR(255) NOT NULL,
  afa_parameters TINYBLOB NOT NULL,
  INDEX afa_consequence (afa_consequence),
  PRIMARY KEY(afa_filter, afa_consequence)
) /*$wgDBTableOptions*/;


CREATE TABLE /*_*/abuse_filter_log (
  afl_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  afl_global TINYINT(1) NOT NULL,
  afl_filter_id BIGINT UNSIGNED NOT NULL,
  afl_user BIGINT UNSIGNED NOT NULL,
  afl_user_text VARBINARY(255) NOT NULL,
  afl_ip VARCHAR(255) NOT NULL,
  afl_action VARBINARY(255) NOT NULL,
  afl_actions VARBINARY(255) NOT NULL,
  afl_var_dump BLOB NOT NULL,
  afl_timestamp BINARY(14) NOT NULL,
  afl_namespace INT NOT NULL,
  afl_title VARBINARY(255) NOT NULL,
  afl_wiki VARBINARY(64) DEFAULT NULL,
  afl_deleted TINYINT(1) DEFAULT 0 NOT NULL,
  afl_patrolled_by INT UNSIGNED DEFAULT 0 NOT NULL,
  afl_rev_id INT UNSIGNED DEFAULT NULL,
  INDEX afl_filter_timestamp_full (
    afl_global, afl_filter_id, afl_timestamp
  ),
  INDEX afl_user_timestamp (
    afl_user, afl_user_text, afl_timestamp
  ),
  INDEX afl_timestamp (afl_timestamp),
  INDEX afl_page_timestamp (
    afl_namespace, afl_title, afl_timestamp
  ),
  INDEX afl_ip_timestamp (afl_ip, afl_timestamp),
  INDEX afl_rev_id (afl_rev_id),
  INDEX afl_wiki_timestamp (afl_wiki, afl_timestamp),
  PRIMARY KEY(afl_id)
) /*$wgDBTableOptions*/;


CREATE TABLE /*_*/abuse_filter_history (
  afh_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  afh_filter BIGINT UNSIGNED NOT NULL,
  afh_user BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  afh_user_text VARBINARY(255) DEFAULT '' NOT NULL,
  afh_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  afh_timestamp BINARY(14) NOT NULL,
  afh_pattern BLOB NOT NULL,
  afh_comments BLOB NOT NULL,
  afh_flags TINYBLOB NOT NULL,
  afh_public_comments TINYBLOB DEFAULT NULL,
  afh_actions BLOB DEFAULT NULL,
  afh_deleted TINYINT(1) DEFAULT 0 NOT NULL,
  afh_changed_fields VARCHAR(255) DEFAULT '' NOT NULL,
  afh_group VARBINARY(64) DEFAULT NULL,
  INDEX afh_filter (afh_filter),
  INDEX afh_user (afh_user),
  INDEX afh_user_text (afh_user_text),
  INDEX afh_actor (afh_actor),
  INDEX afh_timestamp (afh_timestamp),
  PRIMARY KEY(afh_id)
) /*$wgDBTableOptions*/;
