-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
-- SPDX-License-Identifier: AGPL-3.0-or-later
--
-- MVP DEVELOPMENT SCHEMA, OPT-IN ONLY.
-- Apply only to a fresh, isolated database with synthetic test records.
-- No migration or change to existing municipal databases is authorized.
--
-- This stores REFERENCES and reconciler metadata only, NEVER document bytes.
CREATE SCHEMA IF NOT EXISTS ecidade_nextcloud;

CREATE SEQUENCE ecidade_nextcloud.legacy_id_seq
    AS bigint
    MINVALUE 3000000000
    MAXVALUE 4294967295
    START WITH 3000000000
    NO CYCLE;

CREATE TABLE ecidade_nextcloud.documents (
    legacy_id oid PRIMARY KEY,
    installation varchar(64) NOT NULL,
    tenant varchar(64) NOT NULL,
    business_key varchar(120) NOT NULL,
    document_key char(64) NOT NULL UNIQUE,
    current_revision integer NOT NULL DEFAULT 0,
    pending_revision integer DEFAULT NULL,
    deleted_at timestamptz DEFAULT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT valid_revisions CHECK (
        current_revision >= 0 AND
        (pending_revision IS NULL OR pending_revision = current_revision + 1)
    )
);
CREATE INDEX documents_scope ON ecidade_nextcloud.documents (installation, tenant, legacy_id);
-- Reserve the same logical reference exactly once for active documents.
CREATE UNIQUE INDEX unique_active_business_document ON ecidade_nextcloud.documents
    (installation, tenant, business_key) WHERE deleted_at IS NULL;

CREATE TABLE ecidade_nextcloud.operations (
    id bigserial PRIMARY KEY,
    legacy_id oid NOT NULL REFERENCES ecidade_nextcloud.documents(legacy_id),
    operation_key char(64) NOT NULL UNIQUE,
    operation_type varchar(8) NOT NULL
        CHECK (operation_type IN ('write', 'delete')),
    revision integer NOT NULL CHECK (revision >= 0),
    status varchar(16) NOT NULL
        CHECK (status IN ('staged', 'processing', 'finalized', 'retained', 'dead')),
    checksum char(64) DEFAULT NULL,
    byte_count bigint DEFAULT NULL CHECK (byte_count IS NULL OR byte_count >= 0),
    remote_file_id bigint DEFAULT NULL,
    attempts integer NOT NULL DEFAULT 0,
    claimed_at timestamptz DEFAULT NULL,
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    error_code varchar(48) DEFAULT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz DEFAULT NULL,
    CONSTRAINT valid_op_fields CHECK (
        (operation_type = 'write' AND checksum IS NOT NULL
         AND byte_count IS NOT NULL AND remote_file_id IS NOT NULL)
        OR (operation_type = 'delete' AND checksum IS NULL)
    ),
    CONSTRAINT unique_document_revision UNIQUE (legacy_id, revision)
);
CREATE INDEX pending_reconcile ON ecidade_nextcloud.operations
    (status, next_attempt_at, claimed_at)
    WHERE operation_type = 'write';
COMMENT ON TABLE ecidade_nextcloud.operations IS
    'Metadata-only outbox for Nextcloud immutable document versions; no file bytes';
