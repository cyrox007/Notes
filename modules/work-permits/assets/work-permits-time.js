// Все формы нарядов работают со временем объекта, а не с настройкой браузера.
// Одинаковые календарные показания во время осеннего перевода часов различаются по UTC.
(() => {
    const formatters = new Map();
    const formatter = zone => {
        if (!formatters.has(zone)) {
            formatters.set(zone, new Intl.DateTimeFormat('en-GB', {
                timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
            }));
        }
        return formatters.get(zone);
    };
    const parts = (instant, zone) => Object.fromEntries(formatter(zone)
        .formatToParts(new Date(instant)).filter(part => part.type !== 'literal')
        .map(part => [part.type, part.value]));
    const localText = (instant, zone) => {
        const p = parts(instant, zone);
        return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute;
    };
    const offsetAt = (instant, zone) => {
        const p = parts(instant, zone);
        const wall = Date.UTC(Number(p.year), Number(p.month) - 1, Number(p.day),
            Number(p.hour), Number(p.minute), Number(p.second));
        return wall - instant;
    };
    const offsetLabel = offset => {
        const minutes = Math.round(offset / 60000);
        const abs = Math.abs(minutes);
        return 'UTC' + (minutes >= 0 ? '+' : '-')
            + String(Math.floor(abs / 60)).padStart(2, '0') + ':'
            + String(abs % 60).padStart(2, '0');
    };
    const zoneFor = document => {
        const zone = document?.lifecycle?.issuance?.time_zone
            || document?.organization_time_zone || 'UTC';
        try {
            formatter(zone);
        } catch {
            throw new Error('Неизвестный часовой пояс организации. Обновите справочник.');
        }
        return zone;
    };
    const candidates = (value, zone) => {
        if (!/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(value || '')) {
            throw new Error('Укажите календарную дату и время объекта');
        }
        const nominal = Date.parse(value + ':00Z');
        if (!Number.isFinite(nominal) || new Date(nominal).toISOString().slice(0, 16) !== value) {
            throw new Error('Некорректная календарная дата или время');
        }
        const offsets = new Set();
        for (let hours = -36; hours <= 36; hours += 6) {
            const sample = nominal + hours * 3600000;
            offsets.add(offsetAt(sample, zone));
        }
        return [...new Set([...offsets].map(offset => nominal - offset))]
            .filter(instant => localText(instant, zone) === value)
            .sort((first, second) => first - second);
    };
    const choose = (input, moments, zone) => {
        const parent = input.closest('label');
        if (!parent) throw new Error('Невозможно уточнить время объекта');
        let select = parent.querySelector('[data-wp-time-choice]');
        if (!select) {
            select = document.createElement('select');
            select.dataset.wpTimeChoice = input.name;
            select.setAttribute('aria-label', 'Уточнить час при переводе часов');
            select.required = true;
            input.after(select);
            input.addEventListener('input', () => select.remove(), { once: true });
        }
        if (select.dataset.localValue !== input.value || select.dataset.zone !== zone) {
            select.replaceChildren(new Option('Выберите нужный час при переводе часов', ''));
            moments.forEach((instant, index) => {
                const label = (index === 0 ? 'Первый' : 'Второй') + ' раз — '
                    + offsetLabel(offsetAt(instant, zone));
                select.add(new Option(label, String(instant)));
            });
            select.dataset.localValue = input.value;
            select.dataset.zone = zone;
        }
        return select;
    };
    const toUtc = (input, document) => {
        const zone = zoneFor(document);
        const moments = candidates(input.value, zone);
        if (!moments.length) {
            throw new Error('Указанное время не существует в ' + zone
                + ' из-за перевода часов. Выберите другое время.');
        }
        let moment = moments[0];
        if (moments.length > 1) {
            const selection = choose(input, moments, zone);
            if (!selection.value) {
                throw new Error('В ' + zone + ' этот час повторяется. Уточните первый или второй час.');
            }
            moment = Number(selection.value);
            if (!moments.includes(moment)) {
                throw new Error('Выбранное время устарело. Уточните час повторно.');
            }
        } else {
            input.closest('label')?.querySelector('[data-wp-time-choice]')?.remove();
        }
        return new Date(moment).toISOString().slice(0, 19).replace('T', ' ');
    };
    const display = (utc, document) => {
        if (!utc) return '';
        const zone = zoneFor(document);
        const instant = Date.parse(String(utc).replace(' ', 'T') + 'Z');
        if (!Number.isFinite(instant)) return String(utc);
        return localText(instant, zone).replace('T', ' ')
            + ' (' + zone + ', ' + offsetLabel(offsetAt(instant, zone)) + ')';
    };
    const context = (panel, document) => {
        const zone = zoneFor(document);
        let hint = panel.querySelector('[data-wp-time-context]');
        if (!hint) {
            hint = document.createElement('p');
            hint.dataset.wpTimeContext = '';
            panel.querySelector('h2')?.after(hint);
        }
        hint.textContent = 'Время объекта: ' + zone + '. Вводите часы и минуты по местному '
            + 'времени организации, независимо от часового пояса устройства.'
            + (document.lifecycle?.issuance ? ' Пояс зафиксирован при выдаче.' : '');
        panel.querySelectorAll('[data-wp-time-choice]').forEach(node => node.remove());
    };
    window.WorkPermitTime = { zoneFor, candidates, toUtc, display, context };
})();
