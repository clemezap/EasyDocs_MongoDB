SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- carpetas
-- -----------------------------------------------------------------------------
CREATE TABLE carpetas (
    id              CHAR(36)     NOT NULL,
    usuario_id      BIGINT       NOT NULL,
    carpeta_padre   CHAR(36)     NULL,          -- NULL = raíz
    nombre          VARCHAR(255) NOT NULL,
    papelera        TINYINT(1)   NOT NULL DEFAULT 0,
    fecha_papelera  DATETIME     NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_carpetas_listado (usuario_id, carpeta_padre, papelera),
    INDEX idx_carpetas_padre (carpeta_padre),

    CONSTRAINT fk_carpetas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_carpetas_padre
        FOREIGN KEY (carpeta_padre) REFERENCES carpetas (id)
        ON DELETE CASCADE,

    CONSTRAINT chk_carpetas_papelera CHECK (
        (papelera = 0 AND fecha_papelera IS NULL) OR
        (papelera = 1 AND fecha_papelera IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- archivos
-- -----------------------------------------------------------------------------
CREATE TABLE archivos (
    id              CHAR(36)        NOT NULL,
    usuario_id      BIGINT          NOT NULL,   -- dueño
    carpeta_padre   CHAR(36)        NULL,       -- NULL = raíz
    nombre          VARCHAR(255)    NOT NULL,   -- nombre original que ve el usuario
    s3_key          VARCHAR(512)    NOT NULL,   -- ej. usuarios/7/<uuid>.pdf
    tipo_mime       VARCHAR(150)    NOT NULL,
    tamano          BIGINT UNSIGNED NOT NULL,   -- en bytes
    papelera        TINYINT(1)      NOT NULL DEFAULT 0,
    fecha_papelera  DATETIME        NULL,
    creado_en       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_archivos_s3_key (s3_key),
    INDEX idx_archivos_listado (usuario_id, carpeta_padre, papelera),
    INDEX idx_archivos_padre (carpeta_padre),

    CONSTRAINT fk_archivos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_archivos_carpeta
        FOREIGN KEY (carpeta_padre) REFERENCES carpetas (id)
        ON DELETE CASCADE,

    CONSTRAINT chk_archivos_papelera CHECK (
        (papelera = 0 AND fecha_papelera IS NULL) OR
        (papelera = 1 AND fecha_papelera IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- archivos_compartidos (muchos a muchos: archivos <-> usuarios)
-- -----------------------------------------------------------------------------
CREATE TABLE archivos_compartidos (
    id          CHAR(36)                       NOT NULL,
    archivo_id  CHAR(36)                       NOT NULL,
    usuario_id  BIGINT                         NOT NULL,   -- usuario con quien se comparte
    permiso     ENUM('lectura', 'escritura')   NOT NULL DEFAULT 'lectura',
    creado_en   DATETIME                       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_compartido (archivo_id, usuario_id),   -- no compartir dos veces con la misma persona
    INDEX idx_compartidos_usuario (usuario_id),

    CONSTRAINT fk_compartidos_archivo
        FOREIGN KEY (archivo_id) REFERENCES archivos (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_compartidos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- enlaces_publicos
-- -----------------------------------------------------------------------------
CREATE TABLE enlaces_publicos (
    id           CHAR(36)   NOT NULL,
    archivo_id   CHAR(36)   NOT NULL,
    token        CHAR(64)   NOT NULL,   -- bin2hex(random_bytes(32)); va en la URL pública
    activo       TINYINT(1) NOT NULL DEFAULT 1,
    creado_en    DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revocado_en  DATETIME   NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_enlaces_token (token),
    INDEX idx_enlaces_archivo (archivo_id, activo),

    CONSTRAINT fk_enlaces_archivo
        FOREIGN KEY (archivo_id) REFERENCES archivos (id)
        ON DELETE CASCADE,

    CONSTRAINT chk_enlaces_revocado CHECK (
        (activo = 1 AND revocado_en IS NULL) OR
        (activo = 0 AND revocado_en IS NOT NULL)
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
