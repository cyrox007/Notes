{literal}
document.addEventListener('DOMContentLoaded', () => {
    const btnEditProfile = document.querySelector('.profile__edit_user-info');
    const cardInfo = document.querySelector('.profile__card-info--data');
    const cardEdit = document.querySelector('.profile__card-info--edit');
    const closeEdit = document.getElementById('close');

    const showEdit = () => {
        if (!btnEditProfile || !cardInfo || !cardEdit) return;
        btnEditProfile.classList.add('invisible-btn');
        btnEditProfile.disabled = true;
        cardInfo.classList.add('hidden');
        cardInfo.classList.remove('visible');
        cardEdit.classList.add('visible');
    };

    const hideEdit = () => {
        if (!btnEditProfile || !cardInfo || !cardEdit) return;
        btnEditProfile.classList.remove('invisible-btn');
        btnEditProfile.disabled = false;
        cardInfo.classList.remove('hidden');
        cardInfo.classList.add('visible');
        cardEdit.classList.remove('visible');
    };

    btnEditProfile?.addEventListener('click', (event) => {
        event.preventDefault();
        showEdit();
    });

    closeEdit?.addEventListener('click', (event) => {
        event.preventDefault();
        hideEdit();
    });

    const fieldNewPassword = document.getElementById('new-password');
    const fieldRepeatPassword = document.getElementById('repeat-new-password');
    const errorRepeatMsg = document.getElementById('error-repeat');
    const changePasswordBtn = document.getElementById('change-password-btn');

    const validatePasswordConfirmation = () => {
        if (!fieldNewPassword || !fieldRepeatPassword || !errorRepeatMsg || !changePasswordBtn) return;
        const hasValues = fieldNewPassword.value.length > 0 && fieldRepeatPassword.value.length > 0;
        const matches = hasValues && fieldNewPassword.value === fieldRepeatPassword.value;
        errorRepeatMsg.innerText = hasValues && !matches ? 'Пароли не совпадают' : '';
        changePasswordBtn.disabled = !matches;
    };

    fieldNewPassword?.addEventListener('input', validatePasswordConfirmation);
    fieldRepeatPassword?.addEventListener('input', validatePasswordConfirmation);
    validatePasswordConfirmation();
});
{/literal}