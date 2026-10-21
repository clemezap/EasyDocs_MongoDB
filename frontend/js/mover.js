// Ventana "Mover": explorar las carpetas propias y mover un archivo o una carpeta a la elegida.
// Usa la misma API que la página Principal (/carpetas/contenido) para navegar.

// elemento: { id, nombre, tipo: 'carpeta' | 'archivo' }.
// origen: id de la carpeta donde está ahora (null = raíz).
// Devuelve una promesa con { destino, nombreDestino } si se movió, o null si se canceló.
function abrirMover(elemento, origen) {
    const dialogo = crearDialogoMover();
    const ruta = dialogo.querySelector('.ruta');
    const lista = dialogo.querySelector('.mover-carpetas');
    const error = dialogo.querySelector('.error');
    const botonMover = dialogo.querySelector('[value="mover"]');

    // Carpeta que se está viendo en la ventana (null = raíz)
    let actual = origen;
    let nombreActual = 'Principal';

    dialogo.querySelector('.mover-nombre').textContent = elemento.nombre;

    async function ir(carpetaId) {
        error.hidden = true;

        const consulta = carpetaId ? '?carpeta=' + encodeURIComponent(carpetaId) : '';
        const { ok, datos } = await api('/carpetas/contenido' + consulta);

        if (!ok) {
            error.textContent = mensajeDeError(datos);
            error.hidden = false;
            return;
        }

        actual = carpetaId || null;

        const partes = [{ id: null, nombre: 'Principal' }, ...datos.ruta];
        nombreActual = partes[partes.length - 1].nombre;

        ruta.innerHTML = partes.map((parte, i) => {
            const separador = i > 0 ? icono(ICONOS.separador) : '';
            return i === partes.length - 1
                ? `${separador}<span class="ruta-actual">${escaparHtml(parte.nombre)}</span>`
                : `${separador}<button type="button" class="ruta-enlace" data-id="${parte.id ?? ''}">${escaparHtml(parte.nombre)}</button>`;
        }).join('');

        lista.innerHTML = datos.carpetas.length === 0
            ? '<li class="mover-vacio">No hay subcarpetas aquí.</li>'
            : datos.carpetas.map((c) => {
                // Una carpeta no se puede mover dentro de sí misma: se muestra desactivada
                // (y así tampoco se puede entrar a sus subcarpetas).
                const esLaMisma = elemento.tipo === 'carpeta' && c.id === elemento.id;

                return `
                    <li>
                        <button type="button" class="mover-carpeta" data-id="${c.id}" ${esLaMisma ? 'disabled title="No puedes mover una carpeta dentro de sí misma"' : ''}>
                            <span class="icono-carpeta">${icono(ICONOS.carpeta)}</span>
                            <span class="nombre-texto">${escaparHtml(c.nombre)}</span>
                            ${esLaMisma ? '' : icono(ICONOS.separador)}
                        </button>
                    </li>
                `;
            }).join('');

        // No tiene sentido mover a la carpeta donde ya está
        const yaEstaAqui = actual === (origen || null);
        botonMover.disabled = yaEstaAqui;
        botonMover.textContent = yaEstaAqui ? 'Ya está aquí' : 'Mover aquí';
    }

    ruta.onclick = (e) => {
        const enlace = e.target.closest('.ruta-enlace');
        if (enlace) ir(enlace.dataset.id);
    };

    lista.onclick = (e) => {
        const carpeta = e.target.closest('.mover-carpeta');
        if (carpeta) ir(carpeta.dataset.id);
    };

    return new Promise((resolve) => {
        dialogo.onclick = async (e) => {
            const boton = e.target.closest('[value]');
            if (!boton) return;

            if (boton.value === 'cancelar') {
                dialogo.close();
                return;
            }

            botonMover.disabled = true;

            const tipo = elemento.tipo === 'carpeta' ? 'carpetas' : 'archivos';
            const { ok, datos } = await api(`/${tipo}/${encodeURIComponent(elemento.id)}/mover`, {
                method: 'PATCH',
                body: { carpeta_padre: actual },
            });

            if (!ok) {
                botonMover.disabled = false;
                error.textContent = mensajeDeError(datos);
                error.hidden = false;
                return;
            }

            dialogo.onclose = null;
            dialogo.close();
            resolve({ destino: actual, nombreDestino: nombreActual });
        };

        // Escape o Cancelar
        dialogo.onclose = () => resolve(null);

        dialogo.showModal();
        ir(origen);
    });
}

function crearDialogoMover() {
    let dialogo = document.getElementById('dialogo-mover');
    if (dialogo) return dialogo;

    dialogo = document.createElement('dialog');
    dialogo.id = 'dialogo-mover';
    dialogo.className = 'dialogo dialogo-mover';
    dialogo.innerHTML = `
        <h2>Mover "<span class="mover-nombre"></span>"</h2>

        <nav class="ruta ruta-mover" aria-label="Ubicación"></nav>

        <ul class="mover-carpetas"></ul>

        <p class="error" hidden></p>

        <div class="dialogo-botones">
            <button type="button" class="boton boton-secundario" value="cancelar">Cancelar</button>
            <button type="button" class="boton" value="mover">Mover aquí</button>
        </div>
    `;
    document.body.append(dialogo);

    return dialogo;
}
