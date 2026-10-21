// Página "Compartidos conmigo": archivos de otros usuarios a los que tengo acceso.
// Con permiso de lectura solo se descargan; con escritura también se pueden renombrar.

(async function () {
    const usuario = await sesion;
    if (!usuario) return;

    const lista = document.getElementById('lista-elementos');
    const listaVacia = document.getElementById('lista-vacia');

    let elementos = [];

    async function cargar() {
        const { ok, datos } = await api('/compartidos');

        if (!ok) {
            lista.innerHTML = '';
            listaVacia.textContent = mensajeDeError(datos);
            listaVacia.hidden = false;
            return;
        }

        elementos = datos.archivos.map((a) => ({ ...a, tipo: 'archivo' }));

        lista.innerHTML = elementos.map((el) => filaElemento(el, {
            columnas: [
                `<span title="${escaparHtml(el.propietario_correo)}">${escaparHtml(el.propietario)}</span>`,
                formatearFecha(el.compartido_en),
            ],
        })).join('');

        listaVacia.textContent = 'Nadie ha compartido archivos contigo.';
        listaVacia.hidden = elementos.length > 0;
    }

    prepararLista({
        lista,
        menu: document.getElementById('menu-opciones'),
        buscar: (id) => elementos.find((el) => el.id === id),
        opcionVisible: (accion, el) => accion !== 'renombrar' || el.permiso === 'escritura',
        alElegir: async (accion, elemento) => {
            if (accion === 'descargar') descargarArchivo(elemento.id);
            if (accion === 'renombrar' && await renombrarElemento(elemento)) cargar();
        },
    });

    cargar();
})();
