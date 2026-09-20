{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        const composer = document.querySelector('.messenger-composer');
        if (!app || !composer || !app.el?.input) return;

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
        composer.insertBefore(micButton, sendButton || null);

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
            micButton.hidden = true;
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
        let activeAudio = null;

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
            xhr.open('POST', '/messenger/voice-upload', true);
            xhr.responseType = 'json';
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

            const file = new File(
                [blob],
                `voice-${Date.now()}.${extension}`,
                { type: mime || blob.type || 'audio/webm' }
            );
            setUploadUi(true, 'Подготовка голосового сообщения…', 0);
            setActivity('uploading_voice', true, dialogUid);

            try {
                const attachment = await uploadVoice(file, dialogUid);
                if ((attachment.media_kind || '') !== 'voice') {
                    throw new Error('Сервер не распознал голосовое сообщение');
                }
                if (app.currentDialog?.uid !== dialogUid) {
                    throw new Error('Диалог изменился до отправки записи');
                }
                const sent = app.sendEvent('MediaSocket:send', {
                    attachment_uid: attachment.uid,
                    caption: '',
                    reply_to_uid: replyToUid || null,
                });
                if (!sent) {
                    throw new Error('Запись загружена, но WebSocket сейчас недоступен');
                }
                if (replyToUid) app.clearComposeContext();
                app.stopTyping();
                app.showToast('Голосовое сообщение отправляется');
            } catch (error) {
                console.error(error);
                app.showToast(error?.message || 'Не удалось отправить голосовое сообщение');
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
            if (voiceUploading || isRecording()) return;
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
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                    video: false,
                });

                recorder = selected
                    ? new MediaRecorder(stream, { mimeType: selected.mime, audioBitsPerSecond: 64000 })
                    : new MediaRecorder(stream, { audioBitsPerSecond: 64000 });
                const actualMime = recorder.mimeType || selected?.mime || '';
                const extension = selected?.extension || extensionForMime(actualMime);
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
            nativeAudio.classList.add('messenger-voice-native-audio');

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
            const progress = document.createElement('input');
            progress.type = 'range';
            progress.min = '0';
            progress.max = '1000';
            progress.value = '0';
            progress.className = 'messenger-voice-player__progress';
            progress.setAttribute('aria-label', 'Позиция голосового сообщения');
            const time = document.createElement('span');
            time.className = 'messenger-voice-player__time';
            time.textContent = '0:00';
            body.append(progress, time);

            const speed = document.createElement('button');
            speed.type = 'button';
            speed.className = 'messenger-voice-player__speed';
            speed.textContent = '1×';
            speed.setAttribute('aria-label', 'Скорость воспроизведения 1×');

            const updateTime = () => {
                const duration = Number.isFinite(nativeAudio.duration) ? nativeAudio.duration : 0;
                const current = Number.isFinite(nativeAudio.currentTime) ? nativeAudio.currentTime : 0;
                progress.value = duration > 0 ? String(Math.round((current / duration) * 1000)) : '0';
                time.textContent = duration > 0
                    ? `${formatDuration(current)} / ${formatDuration(duration)}`
                    : formatDuration(current);
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
