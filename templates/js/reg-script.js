document.addEventListener("DOMContentLoaded", function () {
    let password = document.querySelector('#pass'),
        re_password = document.querySelector('#re_pass');
    let regBtn = document.getElementById('btn-reg');
    regBtn.disabled = true;

    re_password.addEventListener('input', () => {
        if (re_password.value != password.value) {
            document.getElementById('match_pass').innerText = 'Пароли не совпадают';
            regBtn.disabled = true;
        } else if (re_password.value == password.value) {
            document.getElementById('match_pass').innerText = '';
            regBtn.disabled = false;
        }
    });
});