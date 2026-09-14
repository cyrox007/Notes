(function bootstrapWorkspaceFeedback() {
    window.wspace = window.wspace || {};

    let toastRegion = null;
    let dialogRoot = null;

    function ensureToastRegion() {
        if (toastRegion) return toastRegion;
        toastRegion = document.createElement('div');
        toastRegion.className = 'wspace-toast-region';
        toastRegion.setAttribute('aria-live', 'polite');
        toastRegion.setAttribute('aria-atomic', 'false');
        document.body.appendChild(toastRegion);
        return toastRegion;
    }

    function toast(message, type = 'info', timeout = 4500) {
        const region = ensureToastRegion();
        const node = document.createElement('div');
        node.className = `wspace-toast wspace-toast--${type}`;
        node.setAttribute('role', type === 'error' ? 'alert' : 'status');
        node.textContent = String(message || '');
        region.appendChild(node);
        requestAnimationFrame(() => node.classList.add('is-visible'));
        const dismiss = () => {
            node.classList.remove('is-visible');
            window.setTimeout(() => node.remove(), 180);
        };
        node.addEventListener('click', dismiss, { once: true });
        if (timeout > 0) window.setTimeout(dismiss, timeout);
        return node;
    }

    function ensureDialogRoot() {
        if (dialogRoot) return dialogRoot;
        dialogRoot = document.createElement('div');
        dialogRoot.className = 'wspace-dialog-backdrop';
        dialogRoot.hidden = true;
        document.body.appendChild(dialogRoot);
        return dialogRoot;
    }

    function ask({ title, message, input = false, initialValue = '', confirmText = 'Подтвердить', cancelText = 'Отмена', danger = false }) {
        return new Promise((resolve) => {
            const root = ensureDialogRoot();
            root.replaceChildren();
            root.hidden = false;
            document.body.classList.add('wspace-dialog-open');

            const dialog = document.createElement('div');
            dialog.className = 'wspace-dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');

            const heading = document.createElement('h2');
            heading.className = 'wspace-dialog__title';
            heading.textContent = title || 'Подтверждение';
            dialog.appendChild(heading);

            const text = document.createElement('p');
            text.className = 'wspace-dialog__message';
            text.textContent = String(message || '');
            dialog.appendChild(text);

            let field = null;
            if (input) {
                field = document.createElement('input');
                field.type = 'text';
                field.className = 'wspace-dialog__input';
                field.value = initialValue;
                field.autocomplete = 'off';
                dialog.appendChild(field);
            }

            const actions = document.createElement('div');
            actions.className = 'wspace-dialog__actions';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'wspace-dialog__button wspace-dialog__button--secondary';
            cancel.textContent = cancelText;
            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = `wspace-dialog__button ${danger ? 'wspace-dialog__button--danger' : 'wspace-dialog__button--primary'}`;
            confirmButton.textContent = confirmText;
            actions.append(cancel, confirmButton);
            dialog.appendChild(actions);
            root.appendChild(dialog);

            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                root.hidden = true;
                root.replaceChildren();
                document.body.classList.remove('wspace-dialog-open');
                document.removeEventListener('keydown', onKeydown, true);
                resolve(value);
            };
            const onKeydown = (event) => {
                if (event.key === 'Escape') finish(input ? null : false);
                if (event.key === 'Enter' && document.activeElement !== cancel) {
                    event.preventDefault();
                    finish(input ? field.value : true);
                }
            };

            cancel.addEventListener('click', () => finish(input ? null : false));
            confirmButton.addEventListener('click', () => finish(input ? field.value : true));
            root.addEventListener('mousedown', (event) => {
                if (event.target === root) finish(input ? null : false);
            }, { once: true });
            document.addEventListener('keydown', onKeydown, true);

            window.setTimeout(() => (field || confirmButton).focus(), 0);
            if (field) field.select();
        });
    }

    function inline(target, message, type = 'error') {
        const host = typeof target === 'string' ? document.querySelector(target) : target;
        if (!host) return null;
        let node = host.querySelector(':scope > .wspace-inline-feedback');
        if (!node) {
            node = document.createElement('div');
            node.className = 'wspace-inline-feedback';
            host.prepend(node);
        }
        node.className = `wspace-inline-feedback wspace-inline-feedback--${type}`;
        node.setAttribute('role', type === 'error' ? 'alert' : 'status');
        node.textContent = String(message || '');
        return node;
    }

    wspace.feedback = {
        toast,
        inline,
        confirm(message, options = {}) {
            return ask({
                title: options.title || 'Подтверждение',
                message,
                confirmText: options.confirmText || 'Подтвердить',
                cancelText: options.cancelText || 'Отмена',
                danger: Boolean(options.danger),
            });
        },
        prompt(message, initialValue = '', options = {}) {
            return ask({
                title: options.title || 'Введите значение',
                message,
                input: true,
                initialValue,
                confirmText: options.confirmText || 'Сохранить',
                cancelText: options.cancelText || 'Отмена',
            });
        }
    };
})();
