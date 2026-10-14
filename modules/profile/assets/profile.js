window.wspace.page.register('modules/profile/assets/profile.js', (pageScope) => {
pageScope.onInit( () => {
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

// Read-only published content. Render server values as text, never as HTML.
pageScope.listen(document, 'click', async (event) => {
    const trigger = event.target.closest('[data-public-view]');
    if (!trigger) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'profile-public-view';
    dialog.setAttribute('aria-labelledby', 'public-view-title');
    const header = document.createElement('div');header.className = 'profile-public-view__header';
    const title = document.createElement('h2');title.id = 'public-view-title';title.textContent = 'Загрузка…';
    const close = document.createElement('button');close.type = 'button';close.className = 'profile-public__file-open';close.textContent = 'Закрыть';
    header.append(title, close);
    const body = document.createElement('div');body.className = 'profile-public-view__body';body.setAttribute('aria-busy', 'true');body.textContent = 'Загружаем опубликованный материал…';
    dialog.append(header, body);pageScope.append(dialog);
    const controller = new AbortController();
    const dismiss = () => window.wspace.modalMotion.closeDialog(dialog);
    close.addEventListener('click', dismiss);
    dialog.addEventListener('cancel', e => {e.preventDefault();dismiss();});
    dialog.addEventListener('click', e => {if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dismiss();}});
    dialog.addEventListener('close', () => {controller.abort();dialog.remove();trigger.focus();});
    window.wspace.modalMotion.openDialog(dialog);
    try {
        const response = await pageScope.fetch(trigger.dataset.publicView, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:controller.signal});
        if(response.redirected) throw new Error('Сессия завершена. Войдите в систему заново.');
        const data = await response.json();
        if(!response.ok) throw new Error(data.error || 'Материал недоступен');
        title.textContent = data.title || 'Без названия';body.replaceChildren();
        const meta = document.createElement('p');meta.className = 'profile-public-view__meta';meta.textContent = ['Автор: @'+data.owner, data.status, data.priority, data.due_date ? 'Срок: '+data.due_date : null].filter(Boolean).join(' · ');
        const notice = document.createElement('p');notice.className = 'profile-public-view__meta';notice.textContent = 'Только просмотр. Владелец может снять публикацию в любой момент.';
        const content = document.createElement('div');content.className = 'profile-public-view__text';content.textContent = data.content || 'Текст отсутствует';body.append(meta, notice, content);
        for(const item of data.subtasks || []) {const row=document.createElement('p');row.textContent=(Number(item.is_completed)?'✓ ':'○ ')+item.title;body.append(row);}
        if(data.attachments?.length){const heading=document.createElement('h3');heading.textContent='Вложения заметки';body.append(heading);for(const file of data.attachments){const url=new URL(file.url,location.href);if(url.origin!==location.origin)continue;const link=document.createElement('a');link.className='profile-public__file-open';link.href=url.href;link.download=file.file_name;link.textContent='Скачать: '+file.file_name;body.append(link);}}
    } catch(error) {if(error.name!=='AbortError'){title.textContent='Не удалось открыть';body.textContent=error.message || 'Попробуйте ещё раз';}}
    finally {body.removeAttribute('aria-busy');}
});
});
