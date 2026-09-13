document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('registration-form');
    if (!form) {
        return;
    }

    const loginInput = document.getElementById('login');
    const passwordInput = document.getElementById('password');
    const submitButton = document.getElementById('btn-reg');
    const loginError = document.getElementById('correct_login');
    const usernamePattern = /^[A-Za-z0-9._-]{3,50}$/;

    function validateLogin() {
        if (!loginInput) {
            return true;
        }

        const value = loginInput.value.trim();
        const valid = usernamePattern.test(value);
        if (loginError) {
            loginError.textContent = value === '' || valid
                ? ''
                : '3–50 символов: латинские буквы, цифры, точка, дефис или подчёркивание.';
        }
        loginInput.setAttribute('aria-invalid', value !== '' && !valid ? 'true' : 'false');
        return valid;
    }

    function validatePassword() {
        if (!passwordInput) {
            return true;
        }
        const valid = passwordInput.value.length >= 10;
        passwordInput.setAttribute('aria-invalid', passwordInput.value !== '' && !valid ? 'true' : 'false');
        return valid;
    }

    function syncButton() {
        if (!submitButton) {
            return;
        }
        submitButton.disabled = !form.checkValidity() || !validateLogin() || !validatePassword();
    }

    loginInput?.addEventListener('input', syncButton);
    passwordInput?.addEventListener('input', syncButton);
    form.addEventListener('input', syncButton);
    form.addEventListener('change', syncButton);
    form.addEventListener('submit', function (event) {
        if (!validateLogin() || !validatePassword() || !form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    syncButton();
});
