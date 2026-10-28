
CREATE TABLE roles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id SMALLINT UNSIGNED NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role_id, is_active),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE categories (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(30) NOT NULL UNIQUE,
    requester_id BIGINT UNSIGNED NOT NULL,
    assigned_agent_id BIGINT UNSIGNED NULL,
    category_id SMALLINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM(
        'open',
        'in_progress',
        'pending',
        'resolved',
        'closed'
    ) NOT NULL DEFAULT 'open',
    priority ENUM('low', 'medium', 'high', 'urgent')
        NOT NULL DEFAULT 'medium',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_tickets_requester (requester_id, created_at),
    KEY idx_tickets_agent_status (assigned_agent_id, status),
    KEY idx_tickets_category (category_id),
    KEY idx_tickets_status_priority (status, priority),
    CONSTRAINT fk_tickets_requester
        FOREIGN KEY (requester_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_agent
        FOREIGN KEY (assigned_agent_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_internal BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_comments_ticket_created (ticket_id, created_at),
    KEY idx_comments_author (author_id),
    CONSTRAINT fk_comments_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_comments_author
        FOREIGN KEY (author_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE ticket_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_history_ticket_created (ticket_id, created_at),
    CONSTRAINT fk_history_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_history_actor
        FOREIGN KEY (actor_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_actor_created (actor_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO roles (name, description) VALUES
('Solicitante', 'Crea y consulta sus propios tickets'),
('Agente de soporte', 'Atiende los tickets que tiene asignados'),
('Supervisor', 'Supervisa tickets y asignaciones'),
('Administrador', 'Administra usuarios y configuración'),
('Auditor de solo lectura', 'Consulta información autorizada sin modificarla');
