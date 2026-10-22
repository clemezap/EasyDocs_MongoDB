// =============================================================================
// EasyDocs — esquema de MongoDB (reemplaza a squema.sql)
//
// Crea las colecciones con su validador ($jsonSchema) y sus índices.
// Se puede ejecutar varias veces: si la colección ya existe, solo actualiza
// el validador (collMod) y createIndex no duplica índices.
//
// Uso (desde backend/):
//   mongosh "$MONGODB_URI" --file database/esquema.mongodb.js
//
// La base se toma de MONGODB_DATABASE (por defecto "easydocs").
// =============================================================================

const nombreBase = process.env.MONGODB_DATABASE || 'easydocs';
const base = db.getSiblingDB(nombreBase);

// MySQL usaba utf8mb4_unicode_ci: "Tareas" y "tareas" eran el mismo nombre y el
// orden no distinguía mayúsculas. MongoDB distingue por defecto, así que cada
// colección se crea con esta intercalación (strength 2 = ignora mayúsculas, no acentos).
// Ojo: la intercalación solo se puede fijar al crear la colección, collMod no la cambia.
const intercalacion = { locale: 'es', strength: 2 };

/**
 * Crea la colección con su validador, o lo actualiza si ya existe.
 */
function coleccion(nombre, esquema) {
    const opciones = {
        validator: { $jsonSchema: esquema },
        validationLevel: 'strict',
        validationAction: 'error',
    };

    if (base.getCollectionNames().includes(nombre)) {
        base.runCommand({ collMod: nombre, ...opciones });
    } else {
        base.createCollection(nombre, { ...opciones, collation: intercalacion });
    }

    return base.getCollection(nombre);
}

// Equivalente al CHECK de MySQL:
//   (papelera = 0 AND fecha_papelera IS NULL) OR (papelera = 1 AND fecha_papelera IS NOT NULL)
const regla_papelera = {
    oneOf: [
        { properties: { papelera: { enum: [false] }, fecha_papelera: { bsonType: 'null' } } },
        { properties: { papelera: { enum: [true] }, fecha_papelera: { bsonType: 'date' } } },
    ],
};

// carpeta_padre: null = raíz, si no el _id (UUIDv7) de una carpeta
const carpeta_padre = { bsonType: ['null', 'string'] };

// -----------------------------------------------------------------------------
// usuarios
// -----------------------------------------------------------------------------
const usuarios = coleccion('usuarios', {
    bsonType: 'object',
    required: ['_id', 'nombre', 'correo', 'password', 'creado_en'],
    properties: {
        _id: { bsonType: 'objectId' },
        nombre: { bsonType: 'string', minLength: 1, maxLength: 150 },
        correo: { bsonType: 'string', minLength: 3, maxLength: 255 },   // siempre en minúsculas
        password: { bsonType: 'string', minLength: 1 },                 // hash bcrypt, nunca texto plano
        creado_en: { bsonType: 'date' },
    },
});

usuarios.createIndex({ correo: 1 }, { unique: true, name: 'uq_usuarios_correo' });

// -----------------------------------------------------------------------------
// carpetas (jerarquía por referencia al padre; se recorre con $graphLookup)
// -----------------------------------------------------------------------------
const carpetas = coleccion('carpetas', {
    bsonType: 'object',
    required: ['_id', 'usuario_id', 'carpeta_padre', 'nombre', 'papelera', 'fecha_papelera', 'creado_en', 'actualizado_en'],
    properties: {
        _id: { bsonType: 'string' },                                    // UUIDv7
        usuario_id: { bsonType: 'objectId' },                           // dueño
        carpeta_padre,
        nombre: { bsonType: 'string', minLength: 1, maxLength: 255 },
        papelera: { bsonType: 'bool' },
        fecha_papelera: { bsonType: ['null', 'date'] },
        creado_en: { bsonType: 'date' },
        actualizado_en: { bsonType: 'date' },
    },
    ...regla_papelera,
});

carpetas.createIndex({ usuario_id: 1, carpeta_padre: 1, papelera: 1 }, { name: 'idx_carpetas_listado' });
carpetas.createIndex({ carpeta_padre: 1 }, { name: 'idx_carpetas_padre' });

// No puede haber dos carpetas activas con el mismo nombre en el mismo lugar.
// En MySQL solo se validaba en código; aquí lo garantiza la base (índice parcial:
// las carpetas en la papelera no cuentan).
carpetas.createIndex(
    { usuario_id: 1, carpeta_padre: 1, nombre: 1 },
    { unique: true, partialFilterExpression: { papelera: false }, name: 'uq_carpetas_nombre_activo' }
);

// -----------------------------------------------------------------------------
// archivos (con compartidos y enlaces públicos incrustados)
// -----------------------------------------------------------------------------
const archivos = coleccion('archivos', {
    bsonType: 'object',
    required: [
        '_id', 'usuario_id', 'carpeta_padre', 'nombre', 'ruta', 'tipo_mime', 'tamano',
        'papelera', 'fecha_papelera', 'creado_en', 'actualizado_en', 'metadatos', 'compartidos', 'enlaces_publicos',
    ],
    properties: {
        _id: { bsonType: 'string' },                                    // UUIDv7
        usuario_id: { bsonType: 'objectId' },                           // dueño
        carpeta_padre,
        nombre: { bsonType: 'string', minLength: 1, maxLength: 255 },   // nombre original que ve el usuario
        ruta: { bsonType: 'string', minLength: 1, maxLength: 512 },     // antes s3_key; ej. usuarios/<id>/<uuid>.pdf
        tipo_mime: { bsonType: 'string', maxLength: 150 },
        tamano: { bsonType: ['int', 'long'], minimum: 0 },              // en bytes
        papelera: { bsonType: 'bool' },
        fecha_papelera: { bsonType: ['null', 'date'] },
        creado_en: { bsonType: 'date' },
        actualizado_en: { bsonType: 'date' },

        // Propiedades opcionales que escribe el usuario: cada archivo tiene las suyas
        // (un libro: autor, serie; una foto: cámara, lugar; una canción: artista, álbum).
        // Los nombres de los campos son libres; solo se limita el tipo de los valores
        // a texto, número, fecha, sí/no o lista (etiquetas), sin objetos anidados.
        // Que los nombres no traigan "$" ni "." se valida en Laravel.
        metadatos: {
            bsonType: 'object',
            maxProperties: 50,
            additionalProperties: {
                bsonType: ['string', 'int', 'long', 'double', 'decimal', 'bool', 'date', 'array'],
                items: { bsonType: 'string' },                          // solo aplica a las listas
            },
        },

        // Antes tabla archivos_compartidos (muchos a muchos archivos <-> usuarios).
        // nombre y correo se copian del usuario para no hacer $lookup al listar.
        compartidos: {
            bsonType: 'array',
            items: {
                bsonType: 'object',
                required: ['id', 'usuario_id', 'nombre', 'correo', 'permiso', 'creado_en'],
                properties: {
                    id: { bsonType: 'string' },                         // UUIDv7, lo usa la URL para cambiar/quitar
                    usuario_id: { bsonType: 'objectId' },               // con quién se comparte
                    nombre: { bsonType: 'string' },
                    correo: { bsonType: 'string' },
                    permiso: { enum: ['lectura', 'escritura'] },
                    creado_en: { bsonType: 'date' },
                },
            },
        },

        // Antes tabla enlaces_publicos. Desactivar no borra el elemento: queda el historial.
        enlaces_publicos: {
            bsonType: 'array',
            items: {
                bsonType: 'object',
                required: ['id', 'token', 'activo', 'creado_en', 'revocado_en'],
                properties: {
                    id: { bsonType: 'string' },
                    token: { bsonType: 'string', pattern: '^[a-f0-9]{64}$' },   // bin2hex(random_bytes(32))
                    activo: { bsonType: 'bool' },
                    creado_en: { bsonType: 'date' },
                    revocado_en: { bsonType: ['null', 'date'] },
                },
                // Equivalente al CHECK chk_enlaces_revocado
                oneOf: [
                    { properties: { activo: { enum: [true] }, revocado_en: { bsonType: 'null' } } },
                    { properties: { activo: { enum: [false] }, revocado_en: { bsonType: 'date' } } },
                ],
            },
        },
    },
    ...regla_papelera,
});

archivos.createIndex({ usuario_id: 1, carpeta_padre: 1, papelera: 1 }, { name: 'idx_archivos_listado' });
archivos.createIndex({ carpeta_padre: 1 }, { name: 'idx_archivos_padre' });
archivos.createIndex({ ruta: 1 }, { unique: true, name: 'uq_archivos_ruta' });

// "Compartidos conmigo" y la consulta de permisos buscan por el usuario dentro del arreglo
archivos.createIndex({ 'compartidos.usuario_id': 1 }, { name: 'idx_archivos_compartidos' });

// Token único entre todos los archivos. Es parcial para que los archivos sin
// enlaces (arreglo vacío) no choquen entre sí en el índice único.
archivos.createIndex(
    { 'enlaces_publicos.token': 1 },
    {
        unique: true,
        partialFilterExpression: { 'enlaces_publicos.token': { $exists: true } },
        name: 'uq_archivos_enlace_token',
    }
);

print(`Esquema de EasyDocs listo en la base "${nombreBase}".`);
