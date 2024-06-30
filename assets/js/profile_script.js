{literal}
document.addEventListener('DOMContentLoaded', () => {
    let btnEditProfile = document.querySelector('.profile__edit_user-info');
    let cardInfo = document.querySelector('.profile__card-info--data');
    let cardEdit = document.querySelector('.profile__card-info--edit');

    btnEditProfile.addEventListener('click', (e) => {
        e.preventDefault();
        btnEditProfile.classList.toggle('invisible-btn');
        btnEditProfile.disabled = true;
        cardInfo.classList.toggle('visible');
        cardEdit.classList.toggle('visible');
    });

    let closeEdit = document.getElementById('close');

    closeEdit.addEventListener('click', (e)=>{
        e.preventDefault();
        btnEditProfile.classList.toggle('invisible-btn');
        btnEditProfile.disabled = false;
        cardInfo.classList.toggle('visible');
        cardEdit.classList.toggle('visible');
    });

    let fieldNewPassword = document.getElementById('new-password');
    let fieldRepeatPassword = document.getElementById('repeat-new-password');
    let errorRepeatMsg = document.getElementById('error-repeat');

    let changePasswordBtn = document.getElementById('change-password-btn');
    changePasswordBtn.disabled = true;

    fieldRepeatPassword.addEventListener('input', () => {
        if (fieldNewPassword.value != fieldRepeatPassword.value) {
            errorRepeatMsg.innerText = "Пароли не совпадают";
            changePasswordBtn.disabled = true;
        } else {
            errorRepeatMsg.innerText = "";
            changePasswordBtn.disabled = false;
        }
    });
});
{/literal}