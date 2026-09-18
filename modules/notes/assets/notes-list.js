document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('.switch-btn');
    const personal = document.getElementById('personal');
    const allUsers = document.getElementById('all-user');
    const authorColumn = document.querySelector('.notes__list_head--author');
    if (!toggle || !personal || !allUsers) return;

    toggle.addEventListener('click', () => {
        const enabled = !toggle.classList.contains('switch-on');
        toggle.classList.toggle('switch-on', enabled);
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        personal.classList.toggle('visible', !enabled);
        allUsers.classList.toggle('visible', enabled);
        if (authorColumn) authorColumn.hidden = !enabled;
    });
});
