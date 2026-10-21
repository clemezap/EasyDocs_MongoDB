(async function () {
    if (await obtenerUsuario()) {
        window.location.href = '/index.html';
        return;
    }

    const form = document.getElementById('form-registro');
    const error = document.getElementById('error');
    const boton = form.querySelector('button');

    function mostrarError(mensaje) {
        error.textContent = mensaje;
        error.hidden = false;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        error.hidden = true;

        if (form.password.value !== form.password_confirmation.value) {
            mostrarError('Las contraseñas no coinciden.');
            return;
        }

        boton.disabled = true;

        const { ok, datos } = await api('/registro', {
            method: 'POST',
            body: {
                nombre: form.nombre.value,
                correo: form.correo.value,
                password: form.password.value,
                password_confirmation: form.password_confirmation.value,
            },
        });

        if (ok) {
            window.location.href = '/index.html';
            return;
        }

        mostrarError(mensajeDeError(datos));
        boton.disabled = false;
    });
})();
