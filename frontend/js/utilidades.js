// Funciones y constantes que usan varias páginas.

const ICONOS = {
    carpeta: '<path class="relleno" d="M3 6a2 2 0 0 1 2-2h4.2a2 2 0 0 1 1.4.6L12 6h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    archivo: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    nuevaCarpeta: '<path d="M3 6a2 2 0 0 1 2-2h4.2a2 2 0 0 1 1.4.6L12 6h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13h6"/>',
    opciones: '<circle class="relleno" cx="12" cy="5" r="1.6"/><circle class="relleno" cx="12" cy="12" r="1.6"/><circle class="relleno" cx="12" cy="19" r="1.6"/>',
    renombrar: '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    separador: '<path d="M9 6l6 6-6 6"/>',
    subir: '<path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    descargar: '<path d="M12 4v12M7 11l5 5 5-5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
    cerrar: '<path d="M6 6l12 12M18 6L6 18"/>',
    listo: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    error: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
    papelera: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    restaurar: '<path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.6"/><path d="M4 4v4.6h4.6"/>',
    compartir: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M19 8v6M16 11h6"/>',
    enlace: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
    personas: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2A5 5 0 0 1 21.5 19"/>',
};

// Categoría de un archivo según su extensión o su tipo MIME (para el color de su icono)
const CATEGORIAS_POR_EXTENSION = {
    pdf: 'pdf',
    csv: 'hoja', xls: 'hoja', xlsx: 'hoja', ods: 'hoja',
    doc: 'documento', docx: 'documento', odt: 'documento', txt: 'documento', md: 'documento',
    ppt: 'presentacion', pptx: 'presentacion', odp: 'presentacion',
    zip: 'comprimido', rar: 'comprimido', '7z': 'comprimido', gz: 'comprimido', tar: 'comprimido',
};

function categoriaArchivo(tipoMime, nombre = '') {
    const extension = nombre.includes('.') ? nombre.split('.').pop().toLowerCase() : '';
    if (CATEGORIAS_POR_EXTENSION[extension]) return CATEGORIAS_POR_EXTENSION[extension];

    const tipo = tipoMime || '';

    if (tipo === 'application/pdf') return 'pdf';
    if (tipo.startsWith('image/')) return 'imagen';
    if (tipo.startsWith('video/') || tipo.startsWith('audio/')) return 'multimedia';
    if (/zip|rar|7z|tar|gzip/.test(tipo)) return 'comprimido';
    if (/sheet|excel|csv/.test(tipo)) return 'hoja';
    // Antes que "documento": los .pptx también contienen "officedocument"
    if (/presentation|powerpoint/.test(tipo)) return 'presentacion';
    if (/word|document|text\//.test(tipo)) return 'documento';

    return 'otro';
}

function icono(trazos) {
    return `<svg viewBox="0 0 24 24" aria-hidden="true">${trazos}</svg>`;
}

// Para insertar texto del usuario dentro de HTML sin que se interprete
function escaparHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto ?? '';
    return div.innerHTML;
}

// MySQL devuelve "2026-09-30 15:13:47" en UTC
function formatearFecha(valor) {
    if (!valor) return '—';

    const fecha = new Date(valor.replace(' ', 'T') + 'Z');

    return fecha.toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatearTamano(bytes) {
    if (bytes === null || bytes === undefined) return '—';

    const unidades = ['B', 'KB', 'MB', 'GB'];
    let valor = Number(bytes);
    let i = 0;

    while (valor >= 1024 && i < unidades.length - 1) {
        valor /= 1024;
        i++;
    }

    return `${i === 0 ? valor : valor.toFixed(1)} ${unidades[i]}`;
}

// ---------------------------------------------------------------------------
// Lista de carpetas y archivos (Principal, Papelera, ...)
// ---------------------------------------------------------------------------

// HTML de una fila. "fecha" es la columna de fecha a mostrar (ej. actualizado_en).
// Solo las carpetas con carpetaAbrible = true tienen el nombre como botón.
// "columnas" reemplaza las dos columnas de la derecha (HTML ya escapado).
function filaElemento(el, { fecha, carpetaAbrible = false, columnas }) {
    const [columna1, columna2] = columnas ?? [
        formatearFecha(el[fecha]),
        el.tipo === 'carpeta' ? '—' : formatearTamano(el.tamano),
    ];

    const nombre = escaparHtml(el.nombre);
    const categoria = el.tipo === 'archivo' ? 'tipo-' + categoriaArchivo(el.tipo_mime, el.nombre) : '';

    return `
        <li class="fila" data-id="${el.id}" data-tipo="${el.tipo}">
            <span class="nombre-elemento">
                <span class="icono-${el.tipo} ${categoria}">${icono(ICONOS[el.tipo])}</span>
                ${el.tipo === 'carpeta' && carpetaAbrible
                    ? `<button type="button" class="abrir-elemento" title="${nombre}">${nombre}</button>`
                    : `<span class="nombre-texto" title="${nombre}">${nombre}</span>`}
                ${marcasAcceso(el)}
            </span>
            <span class="col-extra">${columna1}</span>
            <span class="col-extra">${columna2}</span>
            <button type="button" class="boton-icono btn-opciones" aria-label="Opciones de ${nombre}" aria-haspopup="menu">${icono(ICONOS.opciones)}</button>
        </li>
    `;
}

// Iconos junto al nombre: compartido con personas y/o con enlace público activo.
// MySQL devuelve EXISTS como "0"/"1" (texto), por eso se convierte a número.
function marcasAcceso(el) {
    let marcas = '';

    if (Number(el.compartido) > 0) {
        marcas += `<span class="marca-acceso" title="Compartido con otras personas">${icono(ICONOS.personas)}</span>`;
    }
    if (Number(el.enlace_publico) > 0) {
        marcas += `<span class="marca-acceso" title="Tiene enlace público activo">${icono(ICONOS.enlace)}</span>`;
    }

    return marcas;
}

// Conecta los eventos de una lista: abrir carpetas y menú ⋮.
//   buscar(id)            -> el elemento con ese id
//   alAbrirCarpeta(id)    -> opcional; clic en el nombre o doble clic en la fila
//   alElegir(accion, el)  -> opción elegida del menú (data-accion)
//   opcionVisible(accion, el) -> opcional; para ocultar opciones según el elemento
// Las opciones del menú con data-tipos="carpeta archivo" solo aparecen en esos tipos.
function prepararLista({ lista, menu, buscar, alAbrirCarpeta, alElegir, opcionVisible = () => true }) {
    let elementoMenu = null;

    function abrirMenu(fila, boton) {
        elementoMenu = buscar(fila.dataset.id);

        menu.querySelectorAll('[data-tipos]').forEach((opcion) => {
            opcion.hidden = !opcion.dataset.tipos.split(' ').includes(elementoMenu.tipo)
                || !opcionVisible(opcion.dataset.accion, elementoMenu);
        });

        const caja = boton.getBoundingClientRect();
        menu.hidden = false;

        const izquierda = Math.min(caja.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8);
        menu.style.left = Math.max(8, izquierda) + window.scrollX + 'px';
        menu.style.top = caja.bottom + 4 + window.scrollY + 'px';
    }

    function cerrarMenu() {
        menu.hidden = true;
    }

    lista.addEventListener('click', (e) => {
        const fila = e.target.closest('.fila');
        if (!fila) return;

        if (e.target.closest('.abrir-elemento') && alAbrirCarpeta) {
            alAbrirCarpeta(fila.dataset.id);
        } else if (e.target.closest('.btn-opciones')) {
            abrirMenu(fila, e.target.closest('.btn-opciones'));
        }
    });

    // Doble clic en la fila de una carpeta también la abre (como en Drive)
    lista.addEventListener('dblclick', (e) => {
        const fila = e.target.closest('.fila');
        if (alAbrirCarpeta && fila && fila.dataset.tipo === 'carpeta' && !e.target.closest('button')) {
            alAbrirCarpeta(fila.dataset.id);
        }
    });

    document.addEventListener('click', (e) => {
        if (!menu.hidden && !e.target.closest('.menu-opciones, .btn-opciones')) cerrarMenu();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarMenu();
    });

    menu.addEventListener('click', (e) => {
        const opcion = e.target.closest('[data-accion]');
        if (!opcion) return;

        cerrarMenu();
        alElegir(opcion.dataset.accion, elementoMenu);
    });
}

// ---------------------------------------------------------------------------
// Aviso temporal abajo al centro, con una acción opcional (ej. "Deshacer")
// ---------------------------------------------------------------------------

let temporizadorAviso = null;

function mostrarAviso(mensaje, { textoAccion, alAccionar } = {}) {
    let aviso = document.getElementById('aviso');

    if (!aviso) {
        aviso = document.createElement('div');
        aviso.id = 'aviso';
        aviso.className = 'aviso';
        aviso.setAttribute('role', 'status');
        document.body.append(aviso);
    }

    aviso.innerHTML = `<span></span>${textoAccion ? '<button type="button"></button>' : ''}`;
    aviso.querySelector('span').textContent = mensaje;

    if (textoAccion) {
        const boton = aviso.querySelector('button');
        boton.textContent = textoAccion;
        boton.addEventListener('click', () => {
            aviso.hidden = true;
            alAccionar();
        });
    }

    aviso.hidden = false;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(() => { aviso.hidden = true; }, 6000);
}

// ---------------------------------------------------------------------------
// Ventana de confirmación. Devuelve una promesa con true (aceptar) o false.
// ---------------------------------------------------------------------------

function confirmar({ titulo, mensaje, boton = 'Aceptar' }) {
    let dialogo = document.getElementById('dialogo-confirmar');

    if (!dialogo) {
        dialogo = document.createElement('dialog');
        dialogo.id = 'dialogo-confirmar';
        dialogo.className = 'dialogo';
        dialogo.innerHTML = `
            <h2></h2>
            <p class="dialogo-mensaje"></p>
            <div class="dialogo-botones">
                <button type="button" class="boton boton-secundario" value="no">Cancelar</button>
                <button type="button" class="boton boton-peligro" value="si"></button>
            </div>
        `;
        document.body.append(dialogo);
    }

    dialogo.querySelector('h2').textContent = titulo;
    dialogo.querySelector('.dialogo-mensaje').textContent = mensaje;
    dialogo.querySelector('[value="si"]').textContent = boton;

    return new Promise((resolve) => {
        function responder(respuesta) {
            dialogo.removeEventListener('click', alHacerClic);
            dialogo.removeEventListener('close', alCerrar);
            if (dialogo.open) dialogo.close();
            resolve(respuesta);
        }

        function alHacerClic(e) {
            const boton = e.target.closest('button');
            if (boton) responder(boton.value === 'si');
        }

        // Escape o cerrar de otra forma = cancelar
        function alCerrar() {
            responder(false);
        }

        dialogo.addEventListener('click', alHacerClic);
        dialogo.addEventListener('close', alCerrar);
        dialogo.showModal();
        dialogo.querySelector('[value="no"]').focus();
    });
}

// ---------------------------------------------------------------------------
// Ventana para escribir un texto (nombre de carpeta, renombrar, ...).
//   enviar(texto) -> promesa con { ok, datos } (la respuesta de api())
// Si la API responde con error, se muestra en la misma ventana.
// Devuelve una promesa con true si se guardó o false si se canceló.
// ---------------------------------------------------------------------------

function pedirTexto({ titulo, boton = 'Guardar', valor = '', finSeleccion, enviar }) {
    let dialogo = document.getElementById('dialogo-texto');

    if (!dialogo) {
        dialogo = document.createElement('dialog');
        dialogo.id = 'dialogo-texto';
        dialogo.className = 'dialogo';
        dialogo.innerHTML = `
            <form novalidate>
                <h2 id="dialogo-texto-titulo"></h2>
                <input type="text" name="texto" maxlength="255" autocomplete="off" required aria-labelledby="dialogo-texto-titulo">
                <p class="error" hidden></p>
                <div class="dialogo-botones">
                    <button type="button" class="boton boton-secundario" value="cancelar">Cancelar</button>
                    <button type="submit" class="boton"></button>
                </div>
            </form>
        `;
        document.body.append(dialogo);
    }

    const form = dialogo.querySelector('form');
    const campo = form.texto;
    const error = form.querySelector('.error');
    const aceptar = form.querySelector('[type="submit"]');

    dialogo.querySelector('h2').textContent = titulo;
    aceptar.textContent = boton;
    aceptar.disabled = false;
    error.hidden = true;
    campo.value = valor;

    return new Promise((resolve) => {
        function terminar(resultado) {
            form.removeEventListener('submit', alEnviar);
            dialogo.removeEventListener('click', alHacerClic);
            dialogo.removeEventListener('close', alCerrar);
            if (dialogo.open) dialogo.close();
            resolve(resultado);
        }

        async function alEnviar(e) {
            e.preventDefault();

            const texto = campo.value.trim();
            if (!texto) {
                error.textContent = 'El nombre es obligatorio.';
                error.hidden = false;
                return;
            }

            aceptar.disabled = true;
            const { ok, datos } = await enviar(texto);
            aceptar.disabled = false;

            if (ok) {
                terminar(true);
            } else {
                error.textContent = mensajeDeError(datos);
                error.hidden = false;
            }
        }

        function alHacerClic(e) {
            if (e.target.closest('[value="cancelar"]')) terminar(false);
        }

        function alCerrar() {
            terminar(false);
        }

        form.addEventListener('submit', alEnviar);
        dialogo.addEventListener('click', alHacerClic);
        dialogo.addEventListener('close', alCerrar);

        dialogo.showModal();
        campo.setSelectionRange(0, finSeleccion ?? valor.length);
    });
}

// Renombrar una carpeta o un archivo. En archivos se selecciona el nombre sin la
// extensión, como en Drive. Devuelve true si se cambió.
function renombrarElemento(elemento) {
    const ruta = elemento.tipo === 'carpeta' ? '/carpetas/' : '/archivos/';
    const punto = elemento.nombre.lastIndexOf('.');

    return pedirTexto({
        titulo: 'Cambiar nombre',
        boton: 'Guardar',
        valor: elemento.nombre,
        finSeleccion: elemento.tipo === 'archivo' && punto > 0 ? punto : undefined,
        enviar: (nombre) => api(ruta + encodeURIComponent(elemento.id), {
            method: 'PATCH',
            body: { nombre },
        }),
    });
}

// Los archivos no se visualizan, solo se descargan. La descarga pasa por la API
// (que valida la sesión), así que no se genera ningún enlace público.
function descargarArchivo(id) {
    window.location.href = API_URL + '/archivos/' + encodeURIComponent(id) + '/descargar';
}
