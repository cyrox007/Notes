window.wspace.page.register('modules/notes/assets/notes-draft.js', (pageScope) => {
(function bootstrapNoteDraft() {
    pageScope.onInit( () => {
        const form = document.querySelector('form.note__edit');
        if (!(form instanceof HTMLFormElement)) return;

        const title = form.querySelector('input[name="notename"]');
        const content = form.querySelector('textarea[name="content"]');
        if (!(title instanceof HTMLInputElement) || !(content instanceof HTMLTextAreaElement)) return;

        const feedback = window.wspace?.feedback;
        const storageKey = `workspace.note.draft:${window.location.pathname}`;
        let initial = { title: title.value, content: content.value };
        let dirty = false;
        let saveTimer = null;

        const status = document.createElement('div');
        status.className = 'note-draft-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        const submit = form.querySelector('.note__submit');
        const saveRow = form.querySelector('.note-editor-013__save-row');
        if (saveRow && submit) {
            submit.insertAdjacentElement('afterend', status);
        } else {
            (submit || form).appendChild(status);
        }

        function currentDraft() {
            return { title: title.value, content: content.value, savedAt: Date.now() };
        }

        function setStatus(message, isDirty) {
            status.textContent = message;
            status.classList.toggle('is-dirty', Boolean(isDirty));
        }

        function removeDraft() {
            try { localStorage.removeItem(storageKey); } catch (_) {}
        }

        function writeDraft() {
            try {
                localStorage.setItem(storageKey, JSON.stringify(currentDraft()));
                setStatus('Черновик сохранён локально', dirty);
            } catch (_) {
                setStatus('Локальное автосохранение недоступно', dirty);
            }
        }

        function scheduleSave() {
            dirty = title.value !== initial.title || content.value !== initial.content;
            setStatus(dirty ? 'Есть несохранённые изменения' : 'Все изменения сохранены на сервере', dirty);
            window.clearTimeout(saveTimer);
            if (dirty) saveTimer = pageScope.setTimeout(writeDraft, 700);
        }

        async function restoreDraftIfNeeded() {
            let draft = null;
            try {
                draft = JSON.parse(localStorage.getItem(storageKey) || 'null');
            } catch (_) {
                draft = null;
            }
            if (!draft || typeof draft !== 'object') return;
            const draftTitle = String(draft.title ?? '');
            const draftContent = String(draft.content ?? '');
            if (draftTitle === initial.title && draftContent === initial.content) return;

            const restore = feedback?.confirm
                ? await feedback.confirm('Найден локальный черновик с несохранёнными изменениями. Восстановить его?', {
                    title: 'Восстановить черновик',
                    confirmText: 'Восстановить'
                })
                : window.confirm('Найден локальный черновик. Восстановить его?');
            if (!restore) {
                removeDraft();
                return;
            }
            title.value = draftTitle;
            content.value = draftContent;
            dirty = true;
            setStatus('Локальный черновик восстановлен — сохраните заметку', true);
            feedback?.toast?.('Локальный черновик восстановлен', 'success');
        }

        function savedStateFromEvent(event) {
            const detail = event instanceof CustomEvent && event.detail && typeof event.detail === 'object'
                ? event.detail
                : {};
            const saved = detail.saved && typeof detail.saved === 'object' ? detail.saved : {};
            return {
                title: typeof saved.title === 'string' ? saved.title : title.value,
                content: typeof saved.content === 'string' ? saved.content : content.value,
            };
        }

        function markServerSaved(event) {
            initial = savedStateFromEvent(event);
            dirty = title.value !== initial.title || content.value !== initial.content;
            window.clearTimeout(saveTimer);

            if (dirty) {
                writeDraft();
                return;
            }

            removeDraft();
            setStatus('Все изменения сохранены на сервере', false);
        }

        function markServerSaveFailed() {
            dirty = title.value !== initial.title || content.value !== initial.content;
            window.clearTimeout(saveTimer);
            if (dirty) {
                writeDraft();
                return;
            }
            setStatus('Не удалось подтвердить сохранение на сервере', true);
        }

        title.addEventListener('input', scheduleSave);
        content.addEventListener('input', scheduleSave);
        form.addEventListener('submit', (event) => {
            window.clearTimeout(saveTimer);
            setStatus('Сохраняем…', dirty);
            if (event.defaultPrevented) return;

            dirty = false;
            removeDraft();
        });
        form.addEventListener('notes:server-saved', markServerSaved);
        form.addEventListener('notes:server-save-failed', markServerSaveFailed);
        pageScope.listen(window, 'beforeunload', (event) => {
            if (!dirty) return;
            writeDraft();
            event.preventDefault();
            event.returnValue = '';
        });

        setStatus('Все изменения сохранены на сервере', false);
        restoreDraftIfNeeded();
    });
})();
});
