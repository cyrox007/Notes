(function bootstrapModalMotion() {
    'use strict';
    const wspace = window.wspace = window.wspace || {};
    const closing = new WeakMap();
    const dialogs = new WeakSet();

    function cancel(element) {
        const pending = closing.get(element);
        if (!pending) return;
        closing.delete(element);
        window.clearTimeout(pending.timer);
        pending.animations.forEach(animation => animation.cancel());
        element.inert = pending.inert;
        element.classList.remove('is-motion-closing');
    }

    function open(element, reveal = () => { element.hidden = false; }) {
        if (!element) return;
        cancel(element);
        reveal();
    }

    function close(element, cleanup = () => { element.hidden = true; }) {
        if (!element || closing.has(element)) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || typeof element.animate !== 'function') {
            cleanup();
            return;
        }
        const pending = { animations: [], timer: null, inert: element.inert };
        closing.set(element, pending);
        element.inert = true;
        const finish = () => {
            if (closing.get(element) !== pending) return;
            closing.delete(element);
            window.clearTimeout(pending.timer);
            cleanup();
            element.inert = pending.inert;
            pending.animations.forEach(animation => animation.cancel());
            element.classList.remove('is-motion-closing');
        };
        const opacity = getComputedStyle(element).opacity;
        element.classList.add('is-motion-closing');
        pending.animations.push(element.animate([{ opacity }, { opacity: 0 }], {
            duration: 180, easing: 'ease-in', fill: 'forwards'
        }));
        const panel = element.querySelector('.calendar-editor__panel, .modal-content, .file-manager__modal-content, .wspace-dialog');
        const surface = panel || (element instanceof HTMLDialogElement ? element : null);
        if (surface) {
            pending.animations.push(surface.animate([
                { transform: getComputedStyle(surface).transform },
                { transform: element.classList.contains('calendar-editor') ? 'translateX(24px)' : 'translateY(8px) scale(.985)' }
            ], { duration: 180, easing: 'ease-in', fill: 'forwards' }));
        }
        pending.animations[0].finished.then(finish).catch(() => {});
        pending.timer = window.setTimeout(finish, 240);
    }

    function openDialog(dialog) {
        if (!dialog) return;
        if (!dialogs.has(dialog)) {
            dialogs.add(dialog);
            dialog.addEventListener('cancel', event => {
                if (event.defaultPrevented) return;
                event.preventDefault();
                closeDialog(dialog);
            });
            dialog.addEventListener('submit', event => {
                const form = event.target;
                if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.getAttribute('method') !== 'dialog') return;
                event.preventDefault();
                closeDialog(dialog, event.submitter?.value);
            });
        }
        open(dialog, () => { if (!dialog.open) dialog.showModal(); });
    }

    function closeDialog(dialog, value) {
        if (!dialog?.open) return;
        close(dialog, () => {
            if (!dialog.open) return;
            if (value === undefined) dialog.close();
            else dialog.close(value);
        });
    }

    wspace.modalMotion = { open, close, openDialog, closeDialog };
})();
