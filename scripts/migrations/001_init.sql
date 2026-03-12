CREATE DATABASE IF NOT EXISTS servicos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE servicos_db;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    telefone VARCHAR(20) NULL,
    senha_hash VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profissionais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NULL,
    nome_completo VARCHAR(150) NULL,
    foto_perfil VARCHAR(255) NULL,
    telefone VARCHAR(20) NULL,
    whatsapp VARCHAR(20) NULL,
    instagram VARCHAR(120) NULL,
    site_url VARCHAR(255) NULL,
    facebook VARCHAR(255) NULL,
    youtube VARCHAR(255) NULL,
    email VARCHAR(100) NULL,
    bio TEXT NULL,
    servicos JSON NULL,
    fotos_trabalho JSON NULL,
    fotos_trabalhos TEXT NULL,
    online TINYINT(1) NOT NULL DEFAULT 1,
    bairro VARCHAR(80) NULL,
    cidade VARCHAR(80) NULL,
    tags VARCHAR(255) NULL,
    descricao TEXT NULL,
    desde YEAR NULL,
    nota DECIMAL(2,1) NOT NULL DEFAULT 0.0,
    total_avaliacoes INT NOT NULL DEFAULT 0,
    public_id CHAR(32) NULL UNIQUE,
    user_id INT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_token (token_hash),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedbacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT NOT NULL,
    client_name VARCHAR(80) NULL,
    rating TINYINT NOT NULL,
    comment TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    fingerprint CHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_feedback_profissional (profissional_id),
    CONSTRAINT fk_feedback_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

