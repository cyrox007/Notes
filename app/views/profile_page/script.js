{literal}
document.addEventListener('DOMContentLoaded', () => {
    const editButtons = Array.from(document.querySelectorAll('.profile__edit_user-info, [data-profile-edit]'));
    const cardInfo = document.querySelector('.profile__card-info--data');
    const cardEdit = document.querySelector('.profile__card-info--edit');
    const closeEdit = document.getElementById('close');

    if (cardEdit && !cardEdit.id) {
        cardEdit.id = 'profile-account-settings';
    }

    const setEditButtonsState = (expanded) => {
        editButtons.forEach((button) => {
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            button.classList.toggle('invisible-btn', expanded);
            button.disabled = expanded;
        });
    };

    const showEdit = () => {
        if (!cardInfo || !cardEdit) return;
        setEditButtonsState(true);
        cardInfo.classList.add('hidden');
        cardInfo.classList.remove('visible');
        cardEdit.classList.add('visible');
        closeEdit?.focus({ preventScroll: true });
        cardEdit.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const hideEdit = () => {
        if (!cardInfo || !cardEdit) return;
        setEditButtonsState(false);
        cardInfo.classList.remove('hidden');
        cardInfo.classList.add('visible');
        cardEdit.classList.remove('visible');
        editButtons[0]?.focus({ preventScroll: true });
    };

    editButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            showEdit();
        });
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