// Página Papelera: restaurar, eliminar definitivamente y vaciar.

(async function () {
    const usuario = await sesion;
    if (!usuario) return;

    const lista = document.getElementById('lista-elementos');
    const listaVacia = document.getElementById('lista-vacia');
    const botonVaciar = document.getElementById('btn-vaciar');

    let elementos = [];

    async function cargar() {
        const { ok, datos } = await api('/papelera');

        if (!ok) {
            lista.innerHTML = '';
            listaVacia.textContent = mensajeDeError(datos);
            listaVacia.hidden = false;
            return;
        }

        // Lo eliminado más recientemente primero
        elementos = [
            ...datos.carpetas.map((c) => ({ ...c, tipo: 'carpeta' })),
            ...datos.archivos.map((a) => ({ ...a, tipo: 'archivo' })),
        ].sort((a, b) => b.fecha_papelera.localeCompare(a.fecha_papelera));

        lista.innerHTML = elementos.map((el) => filaElemento(el, { fecha: 'fecha_papelera' })).join('');

        listaVacia.textContent = 'La papelera está vacía.';
        listaVacia.hidden = elementos.length > 0;
        botonVaciar.hidden = elementos.length === 0;
    }

    prepararLista({
        lista,
        menu: document.getElementById('menu-opciones'),
        buscar: (id) => elementos.find((el) => el.id === id),
        alElegir: (accion, elemento) => {
            if (accion === 'restaurar') restaurar(elemento);
            if (accion === 'eliminar') eliminar(elemento);
        },
    });

    function rutaDe(elemento) {
        return `/papelera/${elemento.tipo === 'carpeta' ? 'carpetas' : 'archivos'}/${encodeURIComponent(elemento.id)}`;
    }

    async function restaurar(elemento) {
        const { ok, datos } = await api(rutaDe(elemento) + '/restaurar', { method: 'POST' });

        mostrarAviso(ok ? datos.mensaje : mensajeDeError(datos));
        if (ok) cargar();
    }

    async function eliminar(elemento) {
        const aceptado = await confirmar({
            titulo: '¿Eliminar definitivamente?',
            mensaje: elemento.tipo === 'carpeta'
                ? `Se eliminará "${elemento.nombre}" y todo su contenido. Esta acción no se puede deshacer.`
                : `Se eliminará "${elemento.nombre}". Esta acción no se puede deshacer.`,
            boton: 'Eliminar definitivamente',
        });

        if (!aceptado) return;

        const { ok, datos } = await api(rutaDe(elemento), { method: 'DELETE' });

        mostrarAviso(ok ? datos.mensaje : mensajeDeError(datos));
        if (ok) cargar();
    }

    botonVaciar.addEventListener('click', async () => {
        const aceptado = await confirmar({
            titulo: '¿Vaciar la papelera?',
            mensaje: 'Todos los elementos de la papelera se eliminarán definitivamente. Esta acción no se puede deshacer.',
            boton: 'Vaciar papelera',
        });

        if (!aceptado) return;

        const { ok, datos } = await api('/papelera', { method: 'DELETE' });

        mostrarAviso(ok ? datos.mensaje : mensajeDeError(datos));
        if (ok) cargar();
    });

    cargar();
})();
