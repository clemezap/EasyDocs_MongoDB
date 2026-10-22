// Metadatos del archivo: propiedades opcionales (autor, fecha de publicación, etiquetas...).
// Cada archivo tiene las suyas; se guardan en el subdocumento "metadatos" del archivo.
//   - crearEditorMetadatos(): filas nombre / tipo / valor (lo usan el toast y la ventana)
//   - agregarAlToastMetadatos(): toast que aparece al subir archivos
//   - abrirPropiedades(): ventana para ver, agregar, editar y eliminar después de subir

const TIPOS_METADATO = {
    texto: 'Texto',
    numero: 'Número',
    fecha: 'Fecha',
    booleano: 'Sí / No',
    lista: 'Lista',
};

// Nombres sugeridos y el tipo que se elige al escribirlos
const SUGERENCIAS_METADATOS = {
    'Autor': 'texto',
    'Fecha de publicación': 'fecha',
    'Serie': 'texto',
    'Clasificación': 'texto',
    'Etiquetas': 'lista',
    'Idioma': 'texto',
    'Editorial': 'texto',
    'Páginas': 'numero',
    'Año': 'numero',
    'Artista': 'texto',
    'Álbum': 'texto',
    'Lugar': 'texto',
    'Cámara': 'texto',
    'Leído': 'booleano',
};

// ---------------------------------------------------------------------------
// Editor de filas
// ---------------------------------------------------------------------------

// Devuelve { cargar(lista), agregar(), leer(), claves(), vacio() }.
// leer() -> { metadatos: [{clave, tipo, valor}], error } (error = texto o null)
function crearEditorMetadatos(contenedor) {
    asegurarSugerenciasMetadatos();

    contenedor.innerHTML = '<ul class="metadatos-filas"></ul>';
    const filas = contenedor.querySelector('ul');

    function campoValor(tipo, valor) {
        switch (tipo) {
            case 'numero':
                return `<input type="number" step="any" class="metadato-valor" aria-label="Valor" value="${escaparHtml(valor ?? '')}">`;
            case 'fecha':
                return `<input type="date" class="metadato-valor" aria-label="Valor" value="${escaparHtml(valor ?? '')}">`;
            case 'booleano':
                return `
                    <select class="metadato-valor" aria-label="Valor">
                        <option value="si" ${valor !== false ? 'selected' : ''}>Sí</option>
                        <option value="no" ${valor === false ? 'selected' : ''}>No</option>
                    </select>`;
            case 'lista':
                return `<input type="text" class="metadato-valor" aria-label="Valor" placeholder="Separadas por comas" value="${escaparHtml(Array.isArray(valor) ? valor.join(', ') : valor ?? '')}">`;
            default:
                return `<input type="text" class="metadato-valor" aria-label="Valor" maxlength="1000" placeholder="Valor" value="${escaparHtml(valor ?? '')}">`;
        }
    }

    function agregar({ clave = '', tipo = 'texto', valor } = {}) {
        const li = document.createElement('li');
        li.className = 'metadato';
        li.innerHTML = `
            <input type="text" class="metadato-clave" list="sugerencias-metadatos" maxlength="64"
                   placeholder="Nombre (ej. Autor)" aria-label="Nombre de la propiedad" value="${escaparHtml(clave)}">
            <select class="metadato-tipo" aria-label="Tipo">
                ${Object.entries(TIPOS_METADATO).map(([valorTipo, texto]) =>
                    `<option value="${valorTipo}" ${valorTipo === tipo ? 'selected' : ''}>${texto}</option>`
                ).join('')}
            </select>
            ${campoValor(tipo, valor)}
            <button type="button" class="boton-icono metadato-quitar" title="Quitar" aria-label="Quitar propiedad">${icono(ICONOS.cerrar)}</button>
        `;
        filas.append(li);
        return li;
    }

    function cambiarTipo(fila, tipo) {
        const anterior = fila.querySelector('.metadato-valor');
        // El texto escrito se conserva si el nuevo campo lo acepta
        const texto = anterior.tagName === 'INPUT' ? anterior.value : '';
        anterior.outerHTML = campoValor(tipo, tipo === 'booleano' ? undefined : texto);
    }

    filas.addEventListener('change', (e) => {
        if (e.target.matches('.metadato-tipo')) {
            cambiarTipo(e.target.closest('.metadato'), e.target.value);
        }
    });

    // Al elegir un nombre sugerido se pone su tipo (si aún no se escribió un valor)
    filas.addEventListener('input', (e) => {
        if (!e.target.matches('.metadato-clave')) return;

        const fila = e.target.closest('.metadato');
        const tipo = SUGERENCIAS_METADATOS[e.target.value.trim()];
        const selector = fila.querySelector('.metadato-tipo');
        const valor = fila.querySelector('.metadato-valor');

        if (tipo && tipo !== selector.value && (valor.tagName === 'SELECT' || valor.value === '')) {
            selector.value = tipo;
            cambiarTipo(fila, tipo);
        }
    });

    filas.addEventListener('click', (e) => {
        const quitar = e.target.closest('.metadato-quitar');
        if (quitar) quitar.closest('.metadato').remove();
    });

    function leer() {
        const metadatos = [];
        const vistas = new Set();

        for (const fila of filas.querySelectorAll('.metadato')) {
            const clave = fila.querySelector('.metadato-clave').value.trim();
            const tipo = fila.querySelector('.metadato-tipo').value;
            const campo = fila.querySelector('.metadato-valor');
            const texto = campo.value.trim();

            // Filas que no se llenaron se ignoran
            if (clave === '' && (texto === '' || tipo === 'booleano')) continue;

            if (clave === '') return { error: `Ponle nombre a la propiedad con valor "${texto}".` };
            if (/[.$]/.test(clave)) return { error: `El nombre "${clave}" no puede llevar punto (.) ni signo de pesos ($).` };
            if (vistas.has(clave)) return { error: `La propiedad "${clave}" está repetida.` };
            if (tipo !== 'booleano' && texto === '') return { error: `Escribe un valor para "${clave}".` };

            vistas.add(clave);

            let valor = texto;
            if (tipo === 'booleano') valor = campo.value === 'si';
            if (tipo === 'lista') valor = texto.split(',').map((e) => e.trim()).filter(Boolean);

            metadatos.push({ clave, tipo, valor });
        }

        return { metadatos, error: null };
    }

    return {
        cargar(lista) {
            filas.innerHTML = '';
            lista.forEach(agregar);
        },
        agregar() {
            agregar().querySelector('.metadato-clave').focus();
        },
        leer,
        vacio: () => filas.children.length === 0,
    };
}

// <datalist> con los nombres sugeridos (uno por página)
function asegurarSugerenciasMetadatos() {
    if (document.getElementById('sugerencias-metadatos')) return;

    const lista = document.createElement('datalist');
    lista.id = 'sugerencias-metadatos';
    lista.innerHTML = Object.keys(SUGERENCIAS_METADATOS)
        .map((nombre) => `<option value="${escaparHtml(nombre)}"></option>`)
        .join('');
    document.body.append(lista);
}

// Valor guardado -> texto para mostrar (modo solo lectura)
function textoMetadato({ tipo, valor }) {
    if (tipo === 'booleano') return valor ? 'Sí' : 'No';
    if (tipo === 'lista') return valor.join(', ');
    if (tipo === 'fecha') {
        return new Date(valor + 'T00:00:00Z').toLocaleDateString('es-MX', {
            day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
        });
    }
    return String(valor);
}

function guardarMetadatos(archivoId, metadatos, eliminar = []) {
    return api(`/archivos/${encodeURIComponent(archivoId)}/metadatos`, {
        method: 'PATCH',
        body: { metadatos, eliminar },
    });
}

// ---------------------------------------------------------------------------
// Toast al subir archivos
// ---------------------------------------------------------------------------

// Aparece en cuanto empieza una subida; no la detiene. Si se guarda antes de que
// termine, espera a que acabe y aplica las propiedades a todos los archivos subidos.
//   cantidad: archivos de esta subida
//   subida:   promesa con los ids de los archivos que sí se subieron
//   alGuardar: se llama al guardar (para recargar la lista)
// Si el toast ya está abierto, la nueva subida se suma a la anterior.
const toastMetadatos = { subidas: [], cantidad: 0, editor: null, alGuardar: null };

function agregarAlToastMetadatos(cantidad, subida, alGuardar) {
    const toast = crearToastMetadatos();

    if (toast.hidden) {
        toastMetadatos.subidas = [];
        toastMetadatos.cantidad = 0;
        toastMetadatos.editor.cargar([]);
        toastMetadatos.editor.agregar();
        toast.querySelector('.error').hidden = true;
        toast.querySelector('[data-toast="guardar"]').disabled = false;
    }

    toastMetadatos.subidas.push(subida);
    toastMetadatos.cantidad += cantidad;
    toastMetadatos.alGuardar = alGuardar;

    toast.querySelector('.toast-metadatos-ayuda').textContent = toastMetadatos.cantidad === 1
        ? 'Se agregarán al archivo que estás subiendo. Puedes cambiarlas después en "Propiedades".'
        : `Se agregarán a los ${toastMetadatos.cantidad} archivos que estás subiendo. Puedes cambiarlas después en "Propiedades".`;

    toast.hidden = false;
}

async function guardarToastMetadatos(toast) {
    const error = toast.querySelector('.error');
    const boton = toast.querySelector('[data-toast="guardar"]');
    const { metadatos, error: problema } = toastMetadatos.editor.leer();

    error.hidden = true;

    if (problema) {
        error.textContent = problema;
        error.hidden = false;
        return;
    }

    if (metadatos.length === 0) {
        toast.hidden = true;
        return;
    }

    boton.disabled = true;
    boton.textContent = 'Esperando la subida…';

    const ids = (await Promise.all(toastMetadatos.subidas)).flat();

    boton.textContent = 'Guardando…';

    const respuestas = await Promise.all(ids.map((id) => guardarMetadatos(id, metadatos)));
    const fallida = respuestas.find((r) => !r.ok);

    boton.textContent = 'Guardar';
    boton.disabled = false;

    if (fallida) {
        error.textContent = mensajeDeError(fallida.datos);
        error.hidden = false;
        return;
    }

    toast.hidden = true;
    toastMetadatos.alGuardar?.();

    if (ids.length > 0) {
        mostrarAviso(ids.length === 1
            ? 'Propiedades guardadas.'
            : `Propiedades guardadas en ${ids.length} archivos.`);
    }
}

function crearToastMetadatos() {
    let toast = document.getElementById('toast-metadatos');
    if (toast) return toast;

    toast = document.createElement('section');
    toast.id = 'toast-metadatos';
    toast.className = 'toast-metadatos';
    toast.setAttribute('aria-labelledby', 'toast-metadatos-titulo');
    toast.hidden = true;
    toast.innerHTML = `
        <header>
            <h2 id="toast-metadatos-titulo">${icono(ICONOS.etiqueta)} Agregar propiedades <span>(opcional)</span></h2>
            <button type="button" class="boton-icono" data-toast="omitir" aria-label="Cerrar">${icono(ICONOS.cerrar)}</button>
        </header>
        <div class="toast-metadatos-cuerpo">
            <p class="toast-metadatos-ayuda"></p>
            <div class="toast-metadatos-editor"></div>
            <button type="button" class="boton-texto" data-toast="agregar">+ Agregar propiedad</button>
            <p class="error" hidden></p>
        </div>
        <footer class="dialogo-botones">
            <button type="button" class="boton boton-secundario" data-toast="omitir">Omitir</button>
            <button type="button" class="boton" data-toast="guardar">Guardar</button>
        </footer>
    `;

    toastMetadatos.editor = crearEditorMetadatos(toast.querySelector('.toast-metadatos-editor'));

    toast.addEventListener('click', (e) => {
        const accion = e.target.closest('[data-toast]')?.dataset.toast;

        if (accion === 'agregar') toastMetadatos.editor.agregar();
        if (accion === 'omitir') toast.hidden = true;
        if (accion === 'guardar') guardarToastMetadatos(toast);
    });

    document.body.append(toast);
    return toast;
}

// ---------------------------------------------------------------------------
// Ventana "Propiedades" (después de subir)
// ---------------------------------------------------------------------------

// alCerrar: se llama si se guardaron cambios (para recargar la lista)
async function abrirPropiedades(archivo, alCerrar) {
    const dialogo = crearDialogoPropiedades();
    const cuerpo = dialogo.querySelector('.propiedades-cuerpo');
    const error = dialogo.querySelector('.error');
    const botones = dialogo.querySelector('.dialogo-botones');
    const ruta = `/archivos/${encodeURIComponent(archivo.id)}/metadatos`;

    dialogo.querySelector('.propiedades-nombre').textContent = archivo.nombre;
    cuerpo.innerHTML = '<p class="propiedades-nota">Cargando…</p>';
    botones.innerHTML = '';
    error.hidden = true;
    dialogo.showModal();

    const { ok, datos } = await api(ruta);

    if (!ok) {
        cuerpo.innerHTML = '';
        error.textContent = mensajeDeError(datos);
        error.hidden = false;
        botones.innerHTML = '<button type="button" class="boton" value="cerrar">Cerrar</button>';
        return;
    }

    if (!datos.editable) {
        dibujarSoloLectura(cuerpo, datos.metadatos);
        botones.innerHTML = '<button type="button" class="boton" value="cerrar">Cerrar</button>';
        return;
    }

    // Las que estaban y ya no están al guardar se eliminan ($unset)
    const originales = datos.metadatos.map((m) => m.clave);

    cuerpo.innerHTML = `
        <div class="propiedades-editor"></div>
        <p class="propiedades-nota propiedades-vacio" hidden>Este archivo aún no tiene propiedades.</p>
        <button type="button" class="boton-texto" data-propiedades="agregar">+ Agregar propiedad</button>
    `;
    botones.innerHTML = `
        <button type="button" class="boton boton-secundario" value="cerrar">Cancelar</button>
        <button type="button" class="boton" data-propiedades="guardar">Guardar</button>
    `;

    const editor = crearEditorMetadatos(cuerpo.querySelector('.propiedades-editor'));
    const vacio = cuerpo.querySelector('.propiedades-vacio');

    editor.cargar(datos.metadatos);
    vacio.hidden = !editor.vacio();

    dialogo.onclick = async (e) => {
        const accion = e.target.closest('[data-propiedades]')?.dataset.propiedades;

        if (accion === 'agregar') {
            editor.agregar();
            vacio.hidden = true;
        }

        if (accion !== 'guardar') return;

        const { metadatos, error: problema } = editor.leer();

        if (problema) {
            error.textContent = problema;
            error.hidden = false;
            return;
        }

        const quedan = metadatos.map((m) => m.clave);
        const eliminar = originales.filter((clave) => !quedan.includes(clave));
        const boton = e.target.closest('button');

        boton.disabled = true;
        const respuesta = await guardarMetadatos(archivo.id, metadatos, eliminar);
        boton.disabled = false;

        if (!respuesta.ok) {
            error.textContent = mensajeDeError(respuesta.datos);
            error.hidden = false;
            return;
        }

        dialogo.close();
        mostrarAviso('Propiedades guardadas.');
        alCerrar?.();
    };
}

function dibujarSoloLectura(cuerpo, metadatos) {
    if (metadatos.length === 0) {
        cuerpo.innerHTML = '<p class="propiedades-nota">Este archivo no tiene propiedades.</p>';
        return;
    }

    cuerpo.innerHTML = `
        <dl class="propiedades-lista">
            ${metadatos.map((m) => `
                <dt>${escaparHtml(m.clave)}</dt>
                <dd>${escaparHtml(textoMetadato(m))}</dd>
            `).join('')}
        </dl>
        <p class="propiedades-nota">Solo el propietario o quien tiene permiso de escritura puede cambiarlas.</p>
    `;
}

function crearDialogoPropiedades() {
    let dialogo = document.getElementById('dialogo-propiedades');
    if (dialogo) return dialogo;

    dialogo = document.createElement('dialog');
    dialogo.id = 'dialogo-propiedades';
    dialogo.className = 'dialogo dialogo-propiedades';
    dialogo.innerHTML = `
        <h2>Propiedades de "<span class="propiedades-nombre"></span>"</h2>
        <div class="propiedades-cuerpo"></div>
        <p class="error" hidden></p>
        <div class="dialogo-botones"></div>
    `;

    dialogo.querySelector('.dialogo-botones').addEventListener('click', (e) => {
        if (e.target.closest('[value="cerrar"]')) dialogo.close();
    });

    document.body.append(dialogo);
    return dialogo;
}
