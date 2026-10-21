// Ventana "Compartir": agregar personas por correo, cambiar su permiso y quitar su acceso;
// crear, copiar y desactivar el enlace público.
// Solo el dueño del archivo la usa (la API rechaza a cualquier otro usuario).

const PERMISOS = {
    lectura: 'Lectura',
    escritura: 'Escritura',
};

// alCerrar: se llama al cerrar la ventana (para actualizar la lista de archivos)
async function abrirCompartir(archivo, alCerrar) {
    const dueno = await sesion;
    const dialogo = crearDialogoCompartir();
    const form = dialogo.querySelector('form');
    const error = dialogo.querySelector('.error');
    const lista = dialogo.querySelector('.personas');
    const seccionEnlace = dialogo.querySelector('.enlace-publico');

    const rutaBase = `/archivos/${encodeURIComponent(archivo.id)}/compartidos`;
    const rutaEnlace = `/archivos/${encodeURIComponent(archivo.id)}/enlace`;

    dialogo.onclose = () => alCerrar?.();

    dialogo.querySelector('.compartir-nombre').textContent = archivo.nombre;
    form.reset();
    error.hidden = true;
    lista.innerHTML = '';

    function mostrarError(datos) {
        error.textContent = mensajeDeError(datos);
        error.hidden = false;
    }

    function dibujar(compartidos) {
        const filaDueno = `
            <li class="persona">
                <span class="avatar avatar-chico" aria-hidden="true">${escaparHtml(inicial(dueno.nombre))}</span>
                <span class="persona-datos">
                    <span class="persona-nombre">${escaparHtml(dueno.nombre)} (tú)</span>
                    <span class="persona-correo">${escaparHtml(dueno.correo)}</span>
                </span>
                <span class="persona-rol">Propietario</span>
            </li>
        `;

        const filas = compartidos.map((c) => `
            <li class="persona" data-id="${c.id}">
                <span class="avatar avatar-chico" aria-hidden="true">${escaparHtml(inicial(c.nombre))}</span>
                <span class="persona-datos">
                    <span class="persona-nombre">${escaparHtml(c.nombre)}</span>
                    <span class="persona-correo">${escaparHtml(c.correo)}</span>
                </span>
                <select class="persona-permiso" aria-label="Permiso de ${escaparHtml(c.nombre)}">
                    ${Object.entries(PERMISOS).map(([valor, texto]) =>
                        `<option value="${valor}" ${c.permiso === valor ? 'selected' : ''}>${texto}</option>`
                    ).join('')}
                </select>
                <button type="button" class="boton-icono persona-quitar" title="Quitar acceso" aria-label="Quitar acceso a ${escaparHtml(c.nombre)}">
                    ${icono(ICONOS.cerrar)}
                </button>
            </li>
        `);

        lista.innerHTML = filaDueno + filas.join('');
    }

    // Agregar persona
    form.onsubmit = async (e) => {
        e.preventDefault();
        error.hidden = true;

        const boton = form.querySelector('[type="submit"]');
        boton.disabled = true;

        const { ok, datos } = await api(rutaBase, {
            method: 'POST',
            body: { correo: form.correo.value, permiso: form.permiso.value },
        });

        boton.disabled = false;

        if (!ok) {
            mostrarError(datos);
            return;
        }

        form.correo.value = '';
        dibujar(datos.compartidos);
    };

    // Cambiar permiso
    lista.onchange = async (e) => {
        const persona = e.target.closest('.persona');
        if (!e.target.matches('.persona-permiso') || !persona) return;

        const { ok, datos } = await api(`${rutaBase}/${persona.dataset.id}`, {
            method: 'PATCH',
            body: { permiso: e.target.value },
        });

        ok ? dibujar(datos.compartidos) : mostrarError(datos);
    };

    // Quitar acceso
    lista.onclick = async (e) => {
        const boton = e.target.closest('.persona-quitar');
        if (!boton) return;

        const persona = boton.closest('.persona');
        const nombre = persona.querySelector('.persona-nombre').textContent;

        const { ok, datos } = await api(`${rutaBase}/${persona.dataset.id}`, { method: 'DELETE' });

        if (!ok) {
            mostrarError(datos);
            return;
        }

        dibujar(datos.compartidos);
        mostrarAviso(`Se quitó el acceso a ${nombre}.`);
    };

    // ---------------------------------------------------------------------
    // Enlace público
    // ---------------------------------------------------------------------

    function dibujarEnlace(enlace) {
        if (!enlace) {
            seccionEnlace.innerHTML = `
                <p class="enlace-estado">Desactivado. Solo tú y las personas agregadas pueden acceder.</p>
                <button type="button" class="boton boton-secundario boton-con-icono" data-enlace="crear">
                    ${icono(ICONOS.enlace)}<span>Crear enlace público</span>
                </button>
            `;
            return;
        }

        const url = `${location.origin}${API_URL}/publico/${enlace.token}`;

        seccionEnlace.innerHTML = `
            <p class="enlace-estado enlace-activo">
                ${icono(ICONOS.enlace)}
                Cualquier persona con el enlace puede ver el archivo, sin iniciar sesión.
            </p>
            <div class="enlace-fila">
                <input type="text" readonly aria-label="Enlace público">
                <button type="button" class="boton" data-enlace="copiar">Copiar</button>
            </div>
            <button type="button" class="boton-texto boton-texto-peligro" data-enlace="desactivar">Desactivar enlace</button>
        `;
        seccionEnlace.querySelector('input').value = url;
    }

    seccionEnlace.onclick = async (e) => {
        const boton = e.target.closest('[data-enlace]');
        if (!boton) return;

        error.hidden = true;

        switch (boton.dataset.enlace) {
            case 'crear': {
                const { ok, datos } = await api(rutaEnlace, { method: 'POST' });
                ok ? dibujarEnlace(datos.enlace) : mostrarError(datos);
                break;
            }

            case 'copiar': {
                const copiado = await copiarAlPortapapeles(seccionEnlace.querySelector('input'));
                mostrarAviso(copiado ? 'Enlace copiado.' : 'No se pudo copiar. El enlace quedó seleccionado: cópialo con Ctrl+C.');
                break;
            }

            case 'desactivar': {
                const { ok, datos } = await api(rutaEnlace, { method: 'DELETE' });
                if (!ok) {
                    mostrarError(datos);
                    break;
                }
                dibujarEnlace(null);
                mostrarAviso('El enlace público se desactivó. Ya nadie puede abrirlo.');
                break;
            }
        }
    };

    seccionEnlace.innerHTML = '';
    dialogo.showModal();

    const [personas, enlace] = await Promise.all([api(rutaBase), api(rutaEnlace)]);

    personas.ok ? dibujar(personas.datos.compartidos) : mostrarError(personas.datos);
    if (enlace.ok) dibujarEnlace(enlace.datos.enlace);

    form.correo.focus();
}

// navigator.clipboard solo existe con HTTPS (o localhost) y puede rechazar la copia.
// Si no funciona se usa el método antiguo: seleccionar el texto y copiarlo.
// Devuelve true si se copió.
async function copiarAlPortapapeles(input) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(input.value);
            return true;
        } catch {
            // se intenta con el método antiguo
        }
    }

    input.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    }
}

function inicial(nombre) {
    return (nombre || '?').trim().charAt(0).toUpperCase();
}

function crearDialogoCompartir() {
    let dialogo = document.getElementById('dialogo-compartir');
    if (dialogo) return dialogo;

    dialogo = document.createElement('dialog');
    dialogo.id = 'dialogo-compartir';
    dialogo.className = 'dialogo dialogo-compartir';
    dialogo.innerHTML = `
        <h2>Compartir "<span class="compartir-nombre"></span>"</h2>

        <form class="compartir-form" novalidate>
            <input type="email" name="correo" placeholder="Correo electrónico" autocomplete="off" required aria-label="Correo de la persona">
            <select name="permiso" aria-label="Permiso">
                ${Object.entries(PERMISOS).map(([valor, texto]) => `<option value="${valor}">${texto}</option>`).join('')}
            </select>
            <button type="submit" class="boton">Agregar</button>
        </form>

        <p class="compartir-ayuda">Lectura: puede descargar el archivo. Escritura: también puede cambiarle el nombre.</p>
        <p class="error" hidden></p>

        <h3>Personas con acceso</h3>
        <ul class="personas"></ul>

        <h3>Enlace público</h3>
        <div class="enlace-publico"></div>

        <div class="dialogo-botones">
            <button type="button" class="boton" value="listo">Listo</button>
        </div>
    `;

    dialogo.querySelector('[value="listo"]').addEventListener('click', () => dialogo.close());
    document.body.append(dialogo);

    return dialogo;
}
