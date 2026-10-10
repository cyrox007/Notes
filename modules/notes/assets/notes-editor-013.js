(() => {
    function boot() {
        const root = document.querySelector('[data-note-editor-013]');
        if (!root) return;

        const feedback = window.wspace?.feedback;
        const uploadUrl = root.dataset.uploadUrl || '';
        const shareUrl = root.dataset.shareUrl || '';
        const unshareUrl = root.dataset.unshareUrl || '';

        async function parseJson(response) {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error(data.error || 'Ошибка запроса');
            return data;
        }

        function toast(message, type = 'info') {
            if (feedback?.toast) feedback.toast(message, type);
        }

        async function confirmAction(message, options = {}) {
            if (feedback?.confirm) return feedback.confirm(message, options);
            return window.confirm(message);
        }

        const uploadForm = root.querySelector('#uploadForm');
        const fileDrop = root.querySelector('[data-note-file-drop]');
        const fileInput = uploadForm?.querySelector('input[type="file"]');
        const uploadStatus = fileDrop?.querySelector('[role="status"]');
        let uploadingFile = false;
        const isFileDrag = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
        fileDrop?.addEventListener('dragover', (event) => {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = uploadingFile ? 'none' : 'copy';
            fileDrop.classList.toggle('is-file-dragover', !uploadingFile);
        });
        fileDrop?.addEventListener('dragleave', (event) => {
            if (event.relatedTarget && fileDrop.contains(event.relatedTarget)) return;
            const bounds = fileDrop.getBoundingClientRect();
            if (!event.relatedTarget && event.clientX > bounds.left && event.clientX < bounds.right && event.clientY > bounds.top && event.clientY < bounds.bottom) return;
            fileDrop.classList.remove('is-file-dragover');
        });
        fileDrop?.addEventListener('drop', (event) => {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            fileDrop.classList.remove('is-file-dragover');
            if (uploadingFile || !fileInput) return;
            const files = event.dataTransfer.files;
            if (files.length !== 1 || !files[0].size) {
                uploadStatus.textContent = 'Перетащите один непустой файл. Папки не поддерживаются.';
                return;
            }
            fileInput.files = files;
            uploadForm.requestSubmit();
        });
        window.addEventListener('dragend', () => fileDrop?.classList.remove('is-file-dragover'));
        uploadForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (uploadingFile) return;
            const submit = uploadForm.querySelector('button[type="submit"]');
            try {
                uploadingFile = true;
                if (uploadStatus) uploadStatus.textContent = 'Загружаем файл…';
                fileDrop?.setAttribute('aria-busy', 'true');
                if (submit) submit.disabled = true;
                await parseJson(await fetch(uploadUrl, { method: 'POST', body: new FormData(uploadForm) }));
                toast('Файл добавлен к заметке', 'success');
                window.location.reload();
            } catch (error) {
                if (uploadStatus) uploadStatus.textContent = error.message;
                feedback?.inline?.(uploadForm, error.message, 'error');
            } finally {
                uploadingFile = false;
                fileDrop?.removeAttribute('aria-busy');
                if (submit) submit.disabled = false;
            }
        });

        root.querySelectorAll('.delete-attachment').forEach((button) => {
            button.addEventListener('click', async () => {
                const approved = await confirmAction('Удалить это вложение?', {
                    title: 'Удалить вложение', confirmText: 'Удалить', danger: true
                });
                if (!approved) return;
                try {
                    button.disabled = true;
                    await parseJson(await fetch(button.dataset.deleteUrl, { method: 'POST' }));
                    button.closest('.attachment-item')?.remove();
                    toast('Вложение удалено', 'success');
                } catch (error) {
                    toast(error.message, 'error');
                    button.disabled = false;
                }
            });
        });

        const shareForm = root.querySelector('#shareForm');
        shareForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submit = shareForm.querySelector('button[type="submit"]');
            try {
                if (submit) submit.disabled = true;
                const result = await parseJson(await fetch(shareUrl, { method: 'POST', body: new FormData(shareForm) }));
                if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(result.share_url).catch(() => {});
                toast('Ссылка для просмотра создана', 'success');
                window.alert('Ссылка создана: ' + result.share_url);
                window.location.reload();
            } catch (error) {
                feedback?.inline?.(shareForm, error.message, 'error');
                if (submit) submit.disabled = false;
            }
        });

        root.querySelector('#copyShareUrl')?.addEventListener('click', async () => {
            const input = root.querySelector('#shareUrl');
            if (!(input instanceof HTMLInputElement)) return;
            try {
                if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(input.value);
                else {
                    input.select();
                    document.execCommand('copy');
                }
                toast('Ссылка скопирована', 'success');
            } catch (_) {
                toast('Не удалось скопировать ссылку', 'error');
            }
        });

        root.querySelector('#unshareNote')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            if (!window.confirm('Деактивировать публичную ссылку?')) return;
            try {
                button.disabled = true;
                await parseJson(await fetch(unshareUrl, { method: 'POST' }));
                toast('Публичная ссылка отключена', 'success');
                window.location.reload();
            } catch (error) {
                toast(error.message, 'error');
                button.disabled = false;
            }
        });

        root.querySelectorAll('.attachment-item--voice audio').forEach(setupAudioPlayer);
        setupVoiceRecorder(root, uploadUrl, parseJson, feedback);
    }

    function setupAudioPlayer(audio) {
        if (audio._notePlayer) return audio._notePlayer;
        audio.controls = false;
        const player = document.createElement('div');player.className = 'note-audio-player';
        const play = document.createElement('button');play.type = 'button';play.textContent = '▶';play.setAttribute('aria-label', 'Воспроизвести запись');
        const seek = document.createElement('input');seek.type = 'range';seek.min = '0';seek.max = '100';seek.value = '0';seek.setAttribute('aria-label', 'Позиция записи');
        const time = document.createElement('span');time.textContent = '0:00';
        const update = () => {const seconds = Math.floor(audio.currentTime || 0);time.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;seek.disabled = !Number.isFinite(audio.duration) || audio.duration <= 0;seek.value = seek.disabled ? 0 : audio.currentTime / audio.duration * 100;};
        play.addEventListener('click', async () => {if (!audio.paused) audio.pause();else try {await audio.play();}catch (_) {time.textContent = 'Запись недоступна';}});
        for (const event of ['play','pause','ended']) audio.addEventListener(event, () => {play.textContent = audio.paused ? '▶' : 'Ⅱ';play.setAttribute('aria-label', audio.paused ? 'Воспроизвести запись' : 'Приостановить запись');});
        for (const event of ['timeupdate','loadedmetadata','emptied']) audio.addEventListener(event, update);
        seek.addEventListener('input', () => {if (Number.isFinite(audio.duration)) audio.currentTime = Number(seek.value) / 100 * audio.duration;});
        player.append(play,seek,time);audio.after(player);audio.style.display = 'none';
        const sync = () => {player.hidden = audio.hidden;};
        new MutationObserver(sync).observe(audio,{attributes:true,attributeFilter:['hidden']});sync();update();audio._notePlayer = player;return player;
    }

    function setupVoiceRecorder(root, uploadUrl, parseJson, feedback) {
        const open = root.querySelector('#recordVoiceBtn');
        const recorderPanel = root.querySelector('#voiceRecorder');
        const start = root.querySelector('#startRecord');
        const stop = root.querySelector('#stopRecord');
        const discard = root.querySelector('#discardRecord');
        const send = root.querySelector('#sendVoice');
        const preview = root.querySelector('#voicePreview');
        const timer = root.querySelector('#recordingTimer');
        const status = root.querySelector('#recordingStatus');
        const pulse = root.querySelector('.note-voice-recorder__pulse');
        if (!open || !recorderPanel || !start || !stop || !discard || !send || !preview || !timer || !status) return;

        setupAudioPlayer(preview);
        const meter = document.createElement('canvas');meter.width = 240;meter.height = 40;meter.className = 'note-voice-meter';meter.hidden = true;meter.setAttribute('aria-label','Уровень сигнала микрофона');status.parentElement.append(meter);
        let meterContext = null, meterSource = null, meterFrame = 0;
        function stopMeter() {cancelAnimationFrame(meterFrame);meterFrame = 0;try {meterSource?.disconnect();}catch (_) {}meterSource = null;meterContext?.close().catch(()=>{});meterContext = null;meter.hidden = true;}
        function startMeter() {
            try {
                const Audio = window.AudioContext || window.webkitAudioContext;if (!Audio) return;
                meterContext = new Audio();meterSource = meterContext.createMediaStreamSource(stream);
                const analyser = meterContext.createAnalyser();analyser.fftSize = 256;meterSource.connect(analyser);
                meterContext.resume().catch(()=>{});meter.hidden = false;
                const samples = new Uint8Array(analyser.fftSize), frequencies = new Uint8Array(analyser.frequencyBinCount), ctx = meter.getContext('2d');let lastSound = performance.now();
                const draw = () => {analyser.getByteTimeDomainData(samples);analyser.getByteFrequencyData(frequencies);let power = 0;for (const sample of samples) power += Math.pow((sample - 128) / 128, 2);if (Math.sqrt(power / samples.length) > .008) lastSound = performance.now();status.textContent = performance.now() - lastSound < 1500 ? 'Идёт запись · микрофон принимает звук' : 'Идёт запись · звук не обнаружен';ctx.clearRect(0,0,240,40);ctx.fillStyle = getComputedStyle(meter).color;for(let i=0;i<24;i++){const height = Math.max(2,frequencies[i*3] / 255 * 36);ctx.fillRect(i*10,(40-height)/2,6,height);}meterFrame = requestAnimationFrame(draw);};draw();
            } catch (_) {stopMeter();}
        }
        let recorder = null;
        let stream = null;
        let chunks = [];
        let blob = null;
        let startedAt = 0;
        let elapsedMs = 0;
        let ticker = 0;
        let objectUrl = '';

        const mediaAvailable = Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
        if (!mediaAvailable) {
            open.disabled = true;
            open.title = 'Запись с микрофона не поддерживается этим браузером';
            status.textContent = 'Запись с микрофона недоступна в этом браузере.';
            return;
        }

        function formatDuration(ms) {
            const total = Math.max(0, Math.floor(ms / 1000));
            const minutes = Math.floor(total / 60).toString().padStart(2, '0');
            const seconds = (total % 60).toString().padStart(2, '0');
            return `${minutes}:${seconds}`;
        }

        function updateTimer() {
            const current = recorder?.state === 'recording' ? Date.now() - startedAt : elapsedMs;
            timer.textContent = formatDuration(current);
        }

        function stopTracks() {
            stopMeter();
            stream?.getTracks?.().forEach((track) => track.stop());
            stream = null;
        }

        function clearTicker() {
            if (ticker) window.clearInterval(ticker);
            ticker = 0;
        }

        function revokePreview() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = '';
            preview.removeAttribute('src');
            preview.load?.();
        }

        function reset({ close = false } = {}) {
            if (recorder?.state && recorder.state !== 'inactive') {
                try { recorder.stop(); } catch (_) {}
            }
            clearTicker();
            stopTracks();
            revokePreview();
            recorder = null;
            chunks = [];
            blob = null;
            startedAt = 0;
            elapsedMs = 0;
            timer.textContent = '00:00';
            status.textContent = 'Нажмите «Начать запись», когда будете готовы.';
            start.disabled = false;
            stop.disabled = true;
            send.disabled = true;
            preview.hidden = true;
            pulse?.classList.remove('is-recording');
            if (close) recorderPanel.hidden = true;
        }

        open.addEventListener('click', () => {
            recorderPanel.hidden = false;
            recorderPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            start.focus();
        });

        start.addEventListener('click', async () => {
            try {
                reset();
                stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                const preferred = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm']
                    .find((type) => window.MediaRecorder.isTypeSupported?.(type));
                recorder = preferred ? new MediaRecorder(stream, { mimeType: preferred }) : new MediaRecorder(stream);
                chunks = [];
                recorder.addEventListener('dataavailable', (event) => { if (event.data?.size) chunks.push(event.data); });
                const activeRecorder = recorder;
                recorder.addEventListener('stop', () => {
                    if (recorder !== activeRecorder) return;
                    clearTicker();
                    elapsedMs = Math.max(elapsedMs, Date.now() - startedAt);
                    blob = new Blob(chunks, { type: recorder.mimeType || chunks[0]?.type || 'audio/webm' });
                    objectUrl = URL.createObjectURL(blob);
                    preview.src = objectUrl;
                    preview.hidden = false;
                    send.disabled = blob.size === 0;
                    stop.disabled = true;
                    start.disabled = false;
                    status.textContent = blob.size ? 'Запись готова. Прослушайте её перед сохранением.' : 'Запись пустая — попробуйте ещё раз.';
                    pulse?.classList.remove('is-recording');
                    stopTracks();
                    updateTimer();
                }, { once: true });
                recorder.start(500);
                startMeter();
                startedAt = Date.now();
                elapsedMs = 0;
                start.disabled = true;
                stop.disabled = false;
                send.disabled = true;
                preview.hidden = true;
                status.textContent = 'Идёт запись…';
                pulse?.classList.add('is-recording');
                ticker = window.setInterval(updateTimer, 250);
                updateTimer();
            } catch (error) {
                reset();
                status.textContent = 'Не удалось получить доступ к микрофону.';
                feedback?.toast?.(`Микрофон недоступен: ${error.message}`, 'error');
            }
        });

        stop.addEventListener('click', () => {
            if (!recorder || recorder.state === 'inactive') return;
            elapsedMs = Date.now() - startedAt;
            recorder.stop();
        });

        discard.addEventListener('click', () => reset({ close: true }));

        send.addEventListener('click', async () => {
            if (!blob?.size) return;
            const mime = (blob.type || 'audio/webm').split(';')[0];
            const extension = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : mime.includes('wav') ? 'wav' : 'webm';
            const file = new File([blob], `voice-${Date.now()}.${extension}`, { type: mime });
            const form = new FormData();
            form.append('attachment', file);
            form.append('is_voice', 'true');
            form.append('duration', String(Math.max(1, Math.round(elapsedMs / 1000))));
            try {
                send.disabled = true;
                status.textContent = 'Сохраняем голосовую заметку…';
                await parseJson(await fetch(uploadUrl, { method: 'POST', body: form }));
                feedback?.toast?.('Голосовая заметка сохранена', 'success');
                window.location.reload();
            } catch (error) {
                send.disabled = false;
                status.textContent = 'Не удалось сохранить запись. Она остаётся в предпросмотре.';
                feedback?.toast?.(error.message, 'error');
            }
        });

        window.addEventListener('pagehide', () => {stopTracks();preview.pause();});
        reset({ close: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
