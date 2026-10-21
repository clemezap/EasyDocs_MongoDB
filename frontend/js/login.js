(async function () {
    if (await obtenerUsuario()) {
        window.location.href = '/index.html';
        return;
    }

    const form = document.getElementById('form-login');
    const error = document.getElementById('error');
    const boton = form.querySelector('button');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        error.hidden = true;
        boton.disabled = true;

        const { ok, datos } = await api('/login', {
            method: 'POST',
            body: {
                correo: form.correo.value,
                password: form.password.value,
            },
        });

        if (ok) {
            window.location.href = '/index.html';
            return;
        }

        error.textContent = mensajeDeError(datos);
        error.hidden = false;
        boton.disabled = false;
    });
})();
