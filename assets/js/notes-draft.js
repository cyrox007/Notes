(function bootstrapNoteDraft() {
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('form.note__edit');
        if (!(form instanceof HTMLFormElement)) return;

        const title = form.querySelector('input[name="notename"]');
        const content = form.querySelector('textarea[name="content"]');
        if (!(title instanceof HTMLInputElement) || !(content instanceof HTMLTextAreaElement)) return;

        const feedback = window.wspace?.feedback;
        const storageKey = `workspace.note.draft:${window.location.pathname}`;
        const initial = { title: title.value, content: content.value };
        let dirty = false;
        let saveTimer = null;

        const status = document.createElement('div');
        status.className = 'note-draft-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        const submit = form.querySelector('.note__submit');
        (submit || form).appendChild(status);

        function currentDraft() {
            return { title: title.value, content: content.value, savedAt: Date.now() };
        }

        function setStatus(message, isDirty) {
            status.textContent = message;
            status.classList.toggle('is-dirty', Boolean(isDirty));
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
            if (dirty) saveTimer = window.setTimeout(writeDraft, 700);
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
                try { localStorage.removeItem(storageKey); } catch (_) {}
                return;
            }
            title.value = draftTitle;
            content.value = draftContent;
            dirty = true;
            setStatus('Локальный черновик восстановлен — сохраните заметку', true);
            feedback?.toast?.('Локальный черновик восстановлен', 'success');
        }

        title.addEventListener('input', scheduleSave);
        content.addEventListener('input', scheduleSave);
        form.addEventListener('submit', () => {
            dirty = false;
            window.clearTimeout(saveTimer);
            try { localStorage.removeItem(storageKey); } catch (_) {}
            setStatus('Сохраняем…', false);
        });
        window.addEventListener('beforeunload', (event) => {
            if (!dirty) return;
            writeDraft();
            event.preventDefault();
            event.returnValue = '';
        });

        setStatus('Все изменения сохранены на сервере', false);
        restoreDraftIfNeeded();
    });
})();
