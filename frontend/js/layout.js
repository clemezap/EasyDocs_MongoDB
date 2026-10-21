// Estructura común de las páginas con sesión: verifica la sesión y dibuja la barra lateral.

const SECCIONES = [
    {
        id: 'principal',
        titulo: 'Principal',
        ruta: '/index.html',
        icono: '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
    },
    {
        id: 'compartidos',
        titulo: 'Compartidos conmigo',
        ruta: '/paginas/compartidos.html',
        icono: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2A5 5 0 0 1 21.5 19"/>',
    },
    {
        id: 'papelera',
        titulo: 'Papelera',
        ruta: '/paginas/papelera.html',
        icono: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    },
];

function dibujarBarraLateral(usuario, paginaActual) {
    const barra = document.getElementById('barra-lateral');

    barra.innerHTML = `
        <a href="/index.html" class="marca">
            ${icono('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>')}
            <span>EasyDocs</span>
        </a>

        <div class="perfil">
            <div class="avatar" aria-hidden="true"></div>
            <div class="perfil-datos">
                <p class="perfil-nombre"></p>
                <p class="perfil-correo"></p>
            </div>
        </div>

        <nav class="menu">
            ${SECCIONES.map((s) => `
                <a href="${s.ruta}" class="menu-enlace" ${s.id === paginaActual ? 'aria-current="page"' : ''}>
                    ${icono(s.icono)}
                    <span>${s.titulo}</span>
                </a>
            `).join('')}
        </nav>

        <button type="button" class="boton-logout" id="btn-logout" title="Cerrar sesión" aria-label="Cerrar sesión">
            ${icono('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>')}
        </button>
    `;

    // textContent para no interpretar HTML que venga en los datos del usuario
    barra.querySelector('.avatar').textContent = usuario.nombre.trim().charAt(0).toUpperCase();
    barra.querySelector('.perfil-nombre').textContent = usuario.nombre;
    barra.querySelector('.perfil-correo').textContent = usuario.correo;

    document.getElementById('btn-logout').addEventListener('click', async () => {
        await api('/logout', { method: 'POST' });
        window.location.href = '/paginas/login.html';
    });
}

// Promesa con el usuario actual; los scripts de cada página pueden hacer: const usuario = await sesion;
const sesion = (async function () {
    const usuario = await obtenerUsuario();

    if (!usuario) {
        window.location.href = '/paginas/login.html';
        return null;
    }

    const app = document.querySelector('.app');
    dibujarBarraLateral(usuario, app.dataset.pagina);
    app.hidden = false;

    return usuario;
})();
