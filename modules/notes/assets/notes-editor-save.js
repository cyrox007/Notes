(() => {
    function boot() {
        const root = document.querySelector('[data-note-editor-013]');
        if (!(root instanceof HTMLElement)) return;

        const form = root.querySelector('form.note__edit');
        if (!(form instanceof HTMLFormElement)) return;

        const nameInput = form.querySelector('input[name="notename"]');
        const contentInput = form.querySelector('textarea[name="content"]');
        if (!(nameInput instanceof HTMLInputElement) || !(contentInput instanceof HTMLTextAreaElement)) return;

        const feedback = window.wspace?.feedback;
        const submit = form.querySelector('button[type="submit"]');
        let saving = false;

        async function parseJson(response) {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Не удалось сохранить заметку');
            }
            return data;
        }

        function setBusy(value) {
            saving = value;
            form.toggleAttribute('aria-busy', value);
            if (submit instanceof HTMLButtonElement) submit.disabled = value;
        }

        function applyServerState(note, submitted) {
            if (!note || typeof note !== 'object') return;

            const name = typeof note.notename === 'string' ? note.notename : submitted.title;
            const updatedAt = typeof note.updated_note === 'string' ? note.updated_note : '';
            const heading = root.querySelector('.note-editor-013__title-group h1');
            const meta = root.querySelectorAll('.note-editor-013__meta span');

            if (nameInput.value === submitted.title) nameInput.value = name;
            if (name && heading) heading.textContent = name;
            if (updatedAt && meta.length >= 3) meta[2].textContent = `Изменено: ${updatedAt}`;
        }

        function submittedState() {
            return {
                title: nameInput.value,
                content: contentInput.value,
            };
        }

        async function saveNote() {
            if (saving) return;

            const submitted = submittedState();
            const formData = new FormData(form);
            setBusy(true);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const result = await parseJson(response);
                const note = result.note && typeof result.note === 'object' ? result.note : {};
                const saved = {
                    title: typeof note.notename === 'string' ? note.notename : submitted.title,
                    content: submitted.content,
                };
                const hasNewerChanges = nameInput.value !== submitted.title || contentInput.value !== submitted.content;

                applyServerState(note, submitted);
                form.dispatchEvent(new CustomEvent('notes:server-saved', {
                    detail: { note, saved },
                }));
                feedback?.toast?.(
                    hasNewerChanges
                        ? 'Отправленная версия сохранена. Новые изменения ещё не сохранены.'
                        : 'Заметка сохранена',
                    'success'
                );
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Не удалось сохранить заметку';
                form.dispatchEvent(new CustomEvent('notes:server-save-failed', {
                    detail: { message },
                }));
                feedback?.inline?.(form, message, 'error');
                feedback?.toast?.(message, 'error');
            } finally {
                setBusy(false);
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (saving) return;
            void saveNote();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
