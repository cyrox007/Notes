document.addEventListener("DOMContentLoaded", function () {
    let password = document.querySelector('#pass'),
        re_password = document.querySelector('#re_pass');
    let regBtn = document.getElementById('btn-reg');
    let loginInput = document.getElementById('login');
    
    regBtn.disabled = true;
    function disabledBtn(status) {
        regBtn.disabled = status;
    }

    loginInput.addEventListener('input', () => {
        console.log('dfsdf');
        if (loginInput.value.match(/^[0-9a-zA-Z]+$/) || loginInput.value != '') {
            document.getElementById('correct_login').innerText = "";
            disabledBtn(false);
        } else {
            document.getElementById('correct_login').innerText = "Можно вводить только латинские буквы и цифры";
            disabledBtn(true);
        }
        
    });

    password.addEventListener('change', () => {
        if (password.value == '') {
            disabledBtn(true);
        } else {
            disabledBtn(false);
        }
    });

    re_password.addEventListener('input', () => {
        if (re_password.value != password.value || re_password.value == '') {
            document.getElementById('match_pass').innerText = 'Пароли не совпадают';
            disabledBtn(true);
        } else if (re_password.value == password.value) {
            document.getElementById('match_pass').innerText = '';
            disabledBtn(false);
        }
    });
});