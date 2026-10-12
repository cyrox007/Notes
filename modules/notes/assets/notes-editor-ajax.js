(() => {
    function boot() {
        const root = document.querySelector('[data-note-editor-013]');
        if (!root) return;

        const feedback = window.wspace?.feedback;
        const busyForms = new WeakSet();
        const busyButtons = new WeakSet();

        async function parseJson(response) {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Ошибка запроса');
            }
            return data;
        }

        function toast(message, type = 'info') {
            feedback?.toast?.(message, type);
        }

        async function confirmAction(message, options = {}) {
            if (feedback?.confirm) return feedback.confirm(message, options);
            return window.confirm(message);
        }

        async function refreshSection(selector) {
            const response = await fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error('Не удалось обновить данные редактора');
            }

            const html = await response.text();
            const documentCopy = new DOMParser().parseFromString(html, 'text/html');
            const current = root.querySelector(selector);
            const fresh = documentCopy.querySelector(selector);
            if (!current || !fresh) {
                throw new Error('Сервер вернул неполные данные редактора');
            }

            current.innerHTML = fresh.innerHTML;
            return current;
        }

        function formatDuration(seconds) {
            const value = Math.max(0, Math.floor(Number(seconds) || 0));
            return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
        }

        function enhanceVoiceAudio(audio) {
            if (!(audio instanceof HTMLAudioElement)) return;
            if (audio.nextElementSibling?.classList.contains('note-audio-player')) return;

            audio.controls = false;
            const player = document.createElement('div');
            player.className = 'note-audio-player';

            const play = document.createElement('button');
            play.type = 'button';
            play.textContent = '▶';
            play.setAttribute('aria-label', 'Воспроизвести запись');

            const seek = document.createElement('input');
            seek.type = 'range';
            seek.min = '0';
            seek.max = '100';
            seek.value = '0';
            seek.setAttribute('aria-label', 'Позиция записи');

            const time = document.createElement('span');
            time.textContent = '0:00';

            const update = () => {
                time.textContent = formatDuration(audio.currentTime);
                seek.disabled = !Number.isFinite(audio.duration) || audio.duration <= 0;
                seek.value = seek.disabled ? '0' : String(audio.currentTime / audio.duration * 100);
            };

            play.addEventListener('click', async () => {
                if (!audio.paused) {
                    audio.pause();
                    return;
                }
                try {
                    await audio.play();
                } catch (_) {
                    time.textContent = 'Запись недоступна';
                }
            });

            ['play', 'pause', 'ended'].forEach((eventName) => {
                audio.addEventListener(eventName, () => {
                    play.textContent = audio.paused ? '▶' : 'Ⅱ';
                    play.setAttribute('aria-label', audio.paused ? 'Воспроизвести запись' : 'Приостановить запись');
                });
            });
            ['timeupdate', 'loadedmetadata', 'emptied'].forEach((eventName) => audio.addEventListener(eventName, update));
            seek.addEventListener('input', () => {
                if (Number.isFinite(audio.duration)) {
                    audio.currentTime = Number(seek.value) / 100 * audio.duration;
                }
            });

            player.append(play, seek, time);
            audio.after(player);
            audio.style.display = 'none';
            update();
        }

        function enhanceVoiceList() {
            root.querySelectorAll('.attachment-item--voice audio').forEach(enhanceVoiceAudio);
        }

        async function submitUpload(form) {
            if (busyForms.has(form)) return;
            const submit = form.querySelector('button[type="submit"]');
            const drop = root.querySelector('[data-note-file-drop]');
            const status = drop?.querySelector('[role="status"]');

            busyForms.add(form);
            if (submit) submit.disabled = true;
            drop?.setAttribute('aria-busy', 'true');
            if (status) status.textContent = 'Загружаем файл…';

            try {
                await parseJson(await fetch(root.dataset.uploadUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(form),
                }));
                await refreshSection('#attachmentsList');
                form.reset();
                if (status) status.textContent = 'Файл добавлен.';
                toast('Файл добавлен к заметке', 'success');
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Не удалось загрузить файл';
                if (status) status.textContent = message;
                feedback?.inline?.(form, message, 'error');
            } finally {
                busyForms.delete(form);
                if (submit) submit.disabled = false;
                drop?.removeAttribute('aria-busy');
            }
        }

        async function submitShare(form) {
            if (busyForms.has(form)) return;
            const submit = form.querySelector('button[type="submit"]');
            busyForms.add(form);
            if (submit) submit.disabled = true;

            try {
                const result = await parseJson(await fetch(root.dataset.shareUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(form),
                }));
                await refreshSection('.note-editor-013__share-box');
                if (result.share_url && navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(result.share_url).catch(() => {});
                }
                toast('Ссылка для просмотра создана', 'success');
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Не удалось создать ссылку';
                feedback?.inline?.(form, message, 'error');
                if (submit) submit.disabled = false;
            } finally {
                busyForms.delete(form);
            }
        }

        async function unshare(button) {
            if (busyButtons.has(button)) return;
            const approved = await confirmAction('Деактивировать публичную ссылку?', {
                title: 'Отключить публичную ссылку',
                confirmText: 'Отключить',
                danger: true,
            });
            if (!approved) return;

            busyButtons.add(button);
            button.disabled = true;
            try {
                await parseJson(await fetch(root.dataset.unshareUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                }));
                await refreshSection('.note-editor-013__share-box');
                toast('Публичная ссылка отключена', 'success');
            } catch (error) {
                button.disabled = false;
                toast(error instanceof Error ? error.message : 'Не удалось отключить ссылку', 'error');
            } finally {
                busyButtons.delete(button);
            }
        }

        async function copyShareUrl() {
            const input = root.querySelector('#shareUrl');
            if (!(input instanceof HTMLInputElement)) return;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(input.value);
                } else {
                    input.select();
                    document.execCommand('copy');
                }
                toast('Ссылка скопирована', 'success');
            } catch (_) {
                toast('Не удалось скопировать ссылку', 'error');
            }
        }

        async function deleteAttachment(button) {
            if (busyButtons.has(button)) return;
            const approved = await confirmAction('Удалить это вложение?', {
                title: 'Удалить вложение',
                confirmText: 'Удалить',
                danger: true,
            });
            if (!approved) return;

            const item = button.closest('.attachment-item');
            const selector = item?.classList.contains('attachment-item--voice')
                ? '.note-editor-013__voice-list'
                : '#attachmentsList';

            busyButtons.add(button);
            button.disabled = true;
            try {
                await parseJson(await fetch(button.dataset.deleteUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                }));
                await refreshSection(selector);
                if (selector === '.note-editor-013__voice-list') enhanceVoiceList();
                toast('Вложение удалено', 'success');
            } catch (error) {
                button.disabled = false;
                toast(error instanceof Error ? error.message : 'Не удалось удалить вложение', 'error');
            } finally {
                busyButtons.delete(button);
            }
        }

        function durationFromRecorder(preview) {
            if (Number.isFinite(preview.duration) && preview.duration > 0) {
                return Math.max(1, Math.round(preview.duration));
            }
            const timer = root.querySelector('#recordingTimer')?.textContent?.trim() || '';
            const match = timer.match(/^(\d+):(\d{2})$/);
            if (!match) return 1;
            return Math.max(1, Number(match[1]) * 60 + Number(match[2]));
        }

        async function sendVoice(button) {
            if (busyButtons.has(button)) return;
            const preview = root.querySelector('#voicePreview');
            const status = root.querySelector('#recordingStatus');
            if (!(preview instanceof HTMLAudioElement) || !preview.src) {
                toast('Сначала запишите голосовую заметку', 'error');
                return;
            }

            busyButtons.add(button);
            button.disabled = true;
            if (status) status.textContent = 'Сохраняем голосовую заметку…';

            try {
                const blob = await (await fetch(preview.currentSrc || preview.src)).blob();
                if (!blob.size) throw new Error('Запись пустая');

                const mime = (blob.type || 'audio/webm').split(';')[0];
                const extension = mime.includes('ogg') ? 'ogg'
                    : mime.includes('mp4') ? 'm4a'
                        : mime.includes('wav') ? 'wav'
                            : 'webm';
                const file = new File([blob], `voice-${Date.now()}.${extension}`, { type: mime });
                const form = new FormData();
                form.append('attachment', file);
                form.append('is_voice', 'true');
                form.append('duration', String(durationFromRecorder(preview)));

                await parseJson(await fetch(root.dataset.uploadUrl || '', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: form,
                }));
                await refreshSection('.note-editor-013__voice-list');
                enhanceVoiceList();
                root.querySelector('#discardRecord')?.click();
                toast('Голосовая заметка сохранена', 'success');
            } catch (error) {
                button.disabled = false;
                if (status) status.textContent = 'Не удалось сохранить запись. Она остаётся в предпросмотре.';
                toast(error instanceof Error ? error.message : 'Не удалось сохранить голосовую заметку', 'error');
            } finally {
                busyButtons.delete(button);
            }
        }

        root.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.id !== 'uploadForm' && form.id !== 'shareForm') return;

            event.preventDefault();
            event.stopImmediatePropagation();
            if (form.id === 'uploadForm') void submitUpload(form);
            else void submitShare(form);
        }, true);

        root.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            const deleteButton = target.closest('.delete-attachment');
            const unshareButton = target.closest('#unshareNote');
            const copyButton = target.closest('#copyShareUrl');
            const voiceButton = target.closest('#sendVoice');
            const handled = deleteButton || unshareButton || copyButton || voiceButton;
            if (!handled) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            if (deleteButton instanceof HTMLButtonElement) void deleteAttachment(deleteButton);
            else if (unshareButton instanceof HTMLButtonElement) void unshare(unshareButton);
            else if (copyButton instanceof HTMLButtonElement) void copyShareUrl();
            else if (voiceButton instanceof HTMLButtonElement) void sendVoice(voiceButton);
        }, true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
