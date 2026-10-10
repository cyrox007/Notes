{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        const composer = document.querySelector('.messenger-composer');
        if (!app || !composer || !app.el?.input) return;

        const composerTools = composer.querySelector('.messenger-composer__tools') || composer;
        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        const attachButton = document.getElementById('message-attach-button');
        const sendButton = document.getElementById('message-send-button');
        const uploadStatus = document.getElementById('messenger-upload-status');
        const uploadText = document.getElementById('messenger-upload-text');
        const uploadProgress = document.getElementById('messenger-upload-progress');
        const uploadPercent = document.getElementById('messenger-upload-percent');

        const micButton = document.createElement('button');
        micButton.type = 'button';
        micButton.id = 'message-voice-button';
        micButton.className = 'messenger-icon-button messenger-voice-button';
        micButton.title = 'Записать голосовое сообщение';
        micButton.setAttribute('aria-label', micButton.title);
        const micIcon = document.createElement('i');
        micIcon.className = 'fa fa-microphone';
        micIcon.setAttribute('aria-hidden', 'true');
        micButton.append(micIcon);
        composerTools.append(micButton);

        const recorderBar = document.createElement('div');
        recorderBar.className = 'messenger-voice-recorder';
        recorderBar.hidden = true;

        const recordingDot = document.createElement('span');
        recordingDot.className = 'messenger-voice-recorder__dot';
        recordingDot.setAttribute('aria-hidden', 'true');
        const recordingTime = document.createElement('strong');
        recordingTime.className = 'messenger-voice-recorder__time';
        recordingTime.textContent = '0:00';
        const recordingLabel = document.createElement('span');
        recordingLabel.className = 'messenger-voice-recorder__label';
        recordingLabel.textContent = 'Запись голосового сообщения';
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'messenger-icon-button messenger-voice-recorder__cancel';
        cancelButton.title = 'Отменить запись';
        cancelButton.setAttribute('aria-label', cancelButton.title);
        cancelButton.innerHTML = '<i class="fa fa-trash-o" aria-hidden="true"></i>';
        const finishButton = document.createElement('button');
        finishButton.type = 'button';
        finishButton.className = 'messenger-send-button messenger-voice-recorder__send';
        finishButton.title = 'Отправить голосовое сообщение';
        finishButton.setAttribute('aria-label', finishButton.title);
        finishButton.innerHTML = '<i class="fa fa-paper-plane" aria-hidden="true"></i>';
        recorderBar.append(recordingDot, recordingTime, recordingLabel, cancelButton, finishButton);
        composer.before(recorderBar);

        const supportsRecording = Boolean(
            navigator.mediaDevices?.getUserMedia
            && window.MediaRecorder
        );
        if (!supportsRecording) {
            const insecureContext = window.isSecureContext === false;
            const unavailableMessage = insecureContext
                ? 'Для записи голосовых сообщений откройте Workspace по HTTPS.'
                : 'Этот браузер не поддерживает запись голосовых сообщений.';
            micButton.classList.add('messenger-voice-button--unavailable');
            micButton.setAttribute('aria-disabled', 'true');
            micButton.title = unavailableMessage;
            micButton.setAttribute('aria-label', unavailableMessage);
            micButton.addEventListener('click', () => app.showToast(unavailableMessage));
            return;
        }

        const MAX_DURATION_SECONDS = 300;
        const mimeCandidates = [
            { mime: 'audio/webm;codecs=opus', extension: 'webm' },
            { mime: 'audio/ogg;codecs=opus', extension: 'ogg' },
            { mime: 'audio/mp4', extension: 'm4a' },
            { mime: 'audio/webm', extension: 'webm' },
        ];

        let recorder = null;
        let stream = null;
        let chunks = [];
        let startedAt = 0;
        let timerId = null;
        let initialDialogUid = null;
        let sendAfterStop = false;
        let voiceUploading = false;
        let voiceStarting = false;
        let activeAudio = null;
        let pendingVoice = null;
        let recoveryUrl = null;
        const recovery = document.createElement('div');
        recovery.className = 'messenger-voice-recovery';
        recovery.hidden = true;
        const retry = document.createElement('button');
        retry.type = 'button'; retry.textContent = 'Повторить отправку голосового';
        const saveRecording = document.createElement('a');
        saveRecording.textContent = 'Скачать запись'; saveRecording.download = 'voice.webm';
        const dismiss = document.createElement('button');
        dismiss.type = 'button'; dismiss.textContent = 'Удалить неотправленную запись';
        recovery.append(retry, saveRecording, dismiss);
        recorderBar.after(recovery);
        const clearRecovery = () => {
            pendingVoice = null; recovery.hidden = true;
            if (recoveryUrl) URL.revokeObjectURL(recoveryUrl);
            recoveryUrl = null;
        };
        retry.addEventListener('click', () => {
            if (!pendingVoice || voiceUploading) return;
            const p = pendingVoice;
            void submitRecording(p.blob, p.mime, p.extension, p.dialogUid, p.replyToUid);
        });
        dismiss.addEventListener('click', () => {
            if (!voiceUploading && window.confirm('Удалить неотправленную голосовую запись?')) clearRecovery();
        });

        const setActivity = (activity, active, dialogUid = initialDialogUid || app.currentDialog?.uid || '') => {
            if (!dialogUid || typeof app.setLocalActivity !== 'function') return false;
            return app.setLocalActivity(activity, active, dialogUid);
        };

        const formatDuration = (seconds) => {
            const value = Math.max(0, Math.floor(Number(seconds) || 0));
            const minutes = Math.floor(value / 60);
            return `${minutes}:${String(value % 60).padStart(2, '0')}`;
        };

        const selectedMime = () => {
            for (const candidate of mimeCandidates) {
                if (MediaRecorder.isTypeSupported(candidate.mime)) return candidate;
            }
            return null;
        };

        const extensionForMime = (mime) => {
            const value = String(mime || '').toLowerCase();
            if (value.includes('ogg')) return 'ogg';
            if (value.includes('mp4')) return 'm4a';
            if (value.includes('wav')) return 'wav';
            if (value.includes('webm')) return 'webm';
            return '';
        };

        const isRecording = () => recorder && recorder.state !== 'inactive';

        const setUploadUi = (active, text = '', percent = 0) => {
            voiceUploading = active;
            micButton.disabled = active;
            if (attachButton) attachButton.disabled = active;
            if (sendButton) sendButton.disabled = active;
            if (!uploadStatus || !uploadProgress) return;
            uploadStatus.hidden = !active;
            if (uploadText) uploadText.textContent = text;
            uploadProgress.value = Math.max(0, Math.min(100, Number(percent || 0)));
            if (uploadPercent) uploadPercent.textContent = active ? `${Math.round(uploadProgress.value)}%` : '';
        };

        const setRecordingUi = (active) => {
            recorderBar.hidden = !active;
            composer.hidden = active;
            app.root.dataset.voiceRecording = active ? 'true' : 'false';
            if (!active) recordingTime.textContent = '0:00';
        };

        const stopTracks = () => {
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
            }
            stream = null;
        };

        const stopTimer = () => {
            if (timerId !== null) window.clearInterval(timerId);
            timerId = null;
        };

        const resetRecorderState = () => {
            const dialogUid = initialDialogUid;
            stopTimer();
            stopTracks();
            recorder = null;
            chunks = [];
            startedAt = 0;
            if (dialogUid) setActivity('recording_voice', false, dialogUid);
            initialDialogUid = null;
            sendAfterStop = false;
            setRecordingUi(false);
        };

        const uploadVoice = (file, dialogUid) => new Promise((resolve, reject) => {
            const form = new FormData();
            form.append('dialog_uid', dialogUid);
            form.append('voice', file, file.name);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', appPath('/messenger/voice-upload'), true);
            xhr.responseType = 'json';
            xhr.timeout = 90000;
            xhr.addEventListener('timeout', () => reject(new Error('Превышено время загрузки голосового сообщения')));
            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                setUploadUi(true, 'Загрузка голосового сообщения…', (event.loaded / event.total) * 100);
            });
            xhr.addEventListener('load', () => {
                const data = xhr.response || {};
                if (xhr.status >= 200 && xhr.status < 300 && data.success && data.attachment) {
                    resolve(data.attachment);
                    return;
                }
                reject(new Error(data.message || `Ошибка загрузки (${xhr.status})`));
            });
            xhr.addEventListener('error', () => reject(new Error('Сетевая ошибка при загрузке голосового сообщения')));
            xhr.addEventListener('abort', () => reject(new Error('Загрузка голосового сообщения отменена')));
            xhr.send(form);
        });

        const submitRecording = async (blob, mime, extension, dialogUid, replyToUid) => {
            if (!blob || blob.size <= 0) {
                app.showToast('Запись получилась пустой');
                return;
            }

            const previousAttachment = pendingVoice?.blob === blob ? pendingVoice.attachment : null;
            pendingVoice = { blob, mime, extension, dialogUid, replyToUid, attachment: previousAttachment };
            const file = new File(
                [blob],
                `voice-${Date.now()}.${extension}`,
                { type: mime || blob.type || 'audio/webm' }
            );
            setUploadUi(true, 'Подготовка голосового сообщения…', 0);
            setActivity('uploading_voice', true, dialogUid);

            try {
                const attachment = pendingVoice.attachment || await uploadVoice(file, dialogUid);
                pendingVoice.attachment = attachment;
                if ((attachment.media_kind || '') !== 'voice') {
                    throw new Error('Сервер не распознал голосовое сообщение');
                }
                const controller = new AbortController();
                const timeout = window.setTimeout(() => controller.abort(), 30000);
                try {
                    const response = await fetch(appPath(`/messenger/recorded/${encodeURIComponent(attachment.uid)}/send`), {
                        method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams({ reply_to_uid: replyToUid || '' }), signal: controller.signal,
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось подтвердить отправку');
                } finally { window.clearTimeout(timeout); }
                clearRecovery();
                if (app.currentDialog?.uid === dialogUid) app.sendEvent('MessangerSocket:load', { dialog_uid: dialogUid });
                app.sendEvent('MessangerSocket:get_dialogs', {});
                if (replyToUid) app.clearComposeContext();
                app.stopTyping();
                app.showToast('Голосовое сообщение отправлено');
            } catch (error) {
                console.error(error);
                if (recoveryUrl) URL.revokeObjectURL(recoveryUrl);
                recoveryUrl = URL.createObjectURL(blob);
                saveRecording.href = recoveryUrl; saveRecording.download = `voice-${Date.now()}.${extension}`;
                recovery.hidden = false;
                app.showToast(`${error?.message || 'Не удалось отправить голосовое сообщение'}. Запись сохранена: повторите отправку или скачайте её.`);
            } finally {
                setActivity('uploading_voice', false, dialogUid);
                setUploadUi(false);
            }
        };

        const finishRecording = (send) => {
            if (!isRecording()) return;
            sendAfterStop = Boolean(send);
            recorder.stop();
        };

        const startRecording = async () => {
            if (voiceUploading || voiceStarting || isRecording()) return;
            if (pendingVoice) { app.showToast('Отправьте или удалите предыдущую неотправленную запись'); return; }
            if (app.root.dataset.videoRecording === 'true' || app.root.dataset.callActive === 'true') {
                app.showToast('Завершите текущую видеозапись или звонок');
                return;
            }
            if (!app.currentDialog?.uid) {
                app.showToast('Сначала выберите диалог');
                return;
            }
            if (app.editing) {
                app.showToast('Сначала завершите редактирование сообщения');
                return;
            }
            if ((app.el.input.value || '').trim() !== '') {
                app.showToast('Сначала отправьте или очистите набранный текст');
                return;
            }
            if (uploadStatus && !uploadStatus.hidden) {
                app.showToast('Дождитесь завершения текущей загрузки');
                return;
            }

            const selected = selectedMime();
            const targetDialog = app.currentDialog.uid;
            voiceStarting = true;
            app.root.dataset.voiceRecording = 'true';
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                    video: false,
                });

                if (app.currentDialog?.uid !== targetDialog) {
                    resetRecorderState();
                    app.showToast('Диалог изменился. Начните запись заново.');
                    return;
                }
                recorder = selected
                    ? new MediaRecorder(stream, { mimeType: selected.mime, audioBitsPerSecond: 64000 })
                    : new MediaRecorder(stream, { audioBitsPerSecond: 64000 });
                const actualMime = recorder.mimeType || selected?.mime || '';
                const extension = extensionForMime(actualMime) || selected?.extension;
                if (!extension) {
                    resetRecorderState();
                    app.showToast('Браузер использует неподдерживаемый формат записи');
                    return;
                }

                initialDialogUid = app.currentDialog.uid;
                setActivity('recording_voice', true, initialDialogUid);
                const replyToUid = app.replyTo?.uid || null;
                chunks = [];
                sendAfterStop = false;

                recorder.addEventListener('dataavailable', (event) => {
                    if (event.data?.size > 0) chunks.push(event.data);
                });
                recorder.addEventListener('error', (event) => {
                    console.error('Voice MediaRecorder error', event.error || event);
                    app.showToast('Ошибка записи с микрофона');
                    finishRecording(false);
                });
                recorder.addEventListener('stop', () => {
                    const shouldSend = sendAfterStop;
                    const dialogUid = initialDialogUid;
                    const blob = shouldSend
                        ? new Blob(chunks, { type: actualMime || chunks[0]?.type || 'audio/webm' })
                        : null;
                    resetRecorderState();
                    if (shouldSend && dialogUid && blob) {
                        void submitRecording(blob, actualMime || blob.type, extension, dialogUid, replyToUid);
                    }
                }, { once: true });

                recorder.start(1000);
                startedAt = Date.now();
                setRecordingUi(true);
                recordingTime.textContent = '0:00';
                timerId = window.setInterval(() => {
                    const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                    recordingTime.textContent = formatDuration(elapsed);
                    if (elapsed >= MAX_DURATION_SECONDS) {
                        app.showToast('Достигнут лимит записи 5 минут');
                        finishRecording(true);
                    }
                }, 250);
            } catch (error) {
                console.error(error);
                resetRecorderState();
                if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
                    app.showToast('Разрешите доступ к микрофону в браузере');
                } else if (error?.name === 'NotFoundError') {
                    app.showToast('Микрофон не найден');
                } else {
                    app.showToast('Не удалось начать запись с микрофона');
                }
            } finally {
                voiceStarting = false;
                if (!isRecording()) app.root.dataset.voiceRecording = 'false';
            }
        };

        micButton.addEventListener('click', () => void startRecording());
        cancelButton.addEventListener('click', () => finishRecording(false));
        finishButton.addEventListener('click', () => finishRecording(true));

        app.el.chatActive?.addEventListener('drop', (event) => {
            if (!isRecording() && !voiceUploading) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        app.el.chatActive?.addEventListener('dragover', (event) => {
            if (!isRecording() && !voiceUploading) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        app.el.input.addEventListener('paste', (event) => {
            if (!isRecording() && !voiceUploading) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        const originalOpenDialog = app.openDialog.bind(app);
        app.openDialog = (uid) => {
            if (isRecording()) finishRecording(false);
            return originalOpenDialog(uid);
        };

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            if (message?.message_type !== 'voice') return row;

            const nativeAudio = row.querySelector('.messenger-media--voice audio');
            if (!nativeAudio) return row;
            row.classList.add('messenger-message--voice');
            nativeAudio.classList.add('messenger-voice-native-audio');
            nativeAudio.hidden = true;
            nativeAudio.setAttribute('aria-hidden', 'true');
            nativeAudio.tabIndex = -1;

            const player = document.createElement('div');
            player.className = 'messenger-voice-player';

            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'messenger-voice-player__play';
            play.setAttribute('aria-label', 'Воспроизвести голосовое сообщение');
            const playIcon = document.createElement('i');
            playIcon.className = 'fa fa-play';
            playIcon.setAttribute('aria-hidden', 'true');
            play.append(playIcon);

            const body = document.createElement('div');
            body.className = 'messenger-voice-player__body';

            const waveform = document.createElement('div');
            waveform.className = 'messenger-voice-player__waveform';
            const bars = [];
            const seed = String(message.uid || message.id || 'voice');
            for (let index = 0; index < 34; index += 1) {
                const bar = document.createElement('span');
                const code = seed.charCodeAt(index % seed.length) || 17;
                const height = 24 + ((code * (index + 7)) % 68);
                bar.style.setProperty('--voice-bar-height', `${height}%`);
                waveform.append(bar);
                bars.push(bar);
            }

            const progress = document.createElement('input');
            progress.type = 'range';
            progress.min = '0';
            progress.max = '1000';
            progress.value = '0';
            progress.className = 'messenger-voice-player__progress';
            progress.setAttribute('aria-label', 'Позиция голосового сообщения');
            waveform.append(progress);

            const timing = document.createElement('div');
            timing.className = 'messenger-voice-player__timing';
            const currentTime = document.createElement('span');
            currentTime.className = 'messenger-voice-player__time messenger-voice-player__time--current';
            currentTime.textContent = '0:00';
            const durationTime = document.createElement('span');
            durationTime.className = 'messenger-voice-player__time messenger-voice-player__time--duration';
            durationTime.textContent = '0:00';
            timing.append(currentTime, durationTime);
            body.append(waveform, timing);

            const speed = document.createElement('button');
            speed.type = 'button';
            speed.className = 'messenger-voice-player__speed';
            speed.textContent = '1×';
            speed.setAttribute('aria-label', 'Скорость воспроизведения 1×');

            const updateTime = () => {
                const duration = Number.isFinite(nativeAudio.duration) ? nativeAudio.duration : 0;
                const current = Number.isFinite(nativeAudio.currentTime) ? nativeAudio.currentTime : 0;
                const ratio = duration > 0 ? Math.max(0, Math.min(1, current / duration)) : 0;
                progress.value = String(Math.round(ratio * 1000));
                currentTime.textContent = formatDuration(current);
                durationTime.textContent = formatDuration(duration);
                const playedBars = Math.round(ratio * bars.length);
                bars.forEach((bar, index) => bar.classList.toggle('is-played', index < playedBars));
            };

            play.addEventListener('click', async () => {
                try {
                    if (nativeAudio.paused) {
                        if (activeAudio && activeAudio !== nativeAudio) activeAudio.pause();
                        activeAudio = nativeAudio;
                        await nativeAudio.play();
                    } else {
                        nativeAudio.pause();
                    }
                } catch (error) {
                    console.error(error);
                    app.showToast('Не удалось воспроизвести голосовое сообщение');
                }
            });
            nativeAudio.addEventListener('play', () => {
                playIcon.className = 'fa fa-pause';
                play.setAttribute('aria-label', 'Поставить голосовое сообщение на паузу');
            });
            nativeAudio.addEventListener('pause', () => {
                playIcon.className = 'fa fa-play';
                play.setAttribute('aria-label', 'Воспроизвести голосовое сообщение');
            });
            nativeAudio.addEventListener('loadedmetadata', updateTime);
            nativeAudio.addEventListener('durationchange', updateTime);
            nativeAudio.addEventListener('timeupdate', updateTime);
            nativeAudio.addEventListener('ended', () => {
                nativeAudio.currentTime = 0;
                updateTime();
            });
            progress.addEventListener('input', () => {
                if (!Number.isFinite(nativeAudio.duration) || nativeAudio.duration <= 0) return;
                nativeAudio.currentTime = (Number(progress.value) / 1000) * nativeAudio.duration;
            });
            speed.addEventListener('click', () => {
                const rates = [1, 1.5, 2];
                const current = rates.indexOf(nativeAudio.playbackRate);
                const next = rates[(current + 1) % rates.length];
                nativeAudio.playbackRate = next;
                speed.textContent = `${next}×`;
                speed.setAttribute('aria-label', `Скорость воспроизведения ${next}×`);
            });

            player.append(play, body, speed);
            nativeAudio.before(player);
            return row;
        };
    });
})();
{/literal}
