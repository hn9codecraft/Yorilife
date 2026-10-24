-- Roles for role-based access control. Permissions per role live in
-- config/permissions.php (keyed by slug) until module permissions are approved.
-- These four roles are the proposed initial set (pending owner approval).

CREATE TABLE roles (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY roles_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (slug, name, description) VALUES
    ('super_admin', 'Super Admin', 'Full access, including managing administrators.'),
    ('admin', 'Admin', 'Manages users below Admin level.'),
    ('sales_manager', 'Sales Manager', 'Sales management access (module permissions to be defined).'),
    ('sales_user', 'Sales User', 'Sales user access (module permissions to be defined).');
