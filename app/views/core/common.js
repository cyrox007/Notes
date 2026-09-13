{literal}
const socketTicket = "{/literal}{$socket_ticket|default:''|escape:'javascript'}{literal}";
const socketUrl = "{/literal}{$socket_url|default:''|escape:'javascript'}{literal}";

wspace.socketConfig = {
    ticket: socketTicket,
    url: socketUrl
};

(function bootstrapSecurity() {
    const unsafeMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
    const template = document.getElementById('csrf-token-template');
    const tokenInput = template && template.content
        ? template.content.querySelector('input[name="csrf_token"]')
        : null;
    const csrfToken = tokenInput ? tokenInput.value : '';

    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    wspace.security = {
        csrfToken,
        getCSRFToken() {
            return csrfToken;
        }
    };

    if (typeof window.fetch === 'function') {
        const nativeFetch = window.fetch.bind(window);
        window.fetch = function securedFetch(input, init = {}) {
            const requestMethod = String(
                init.method || (typeof Request !== 'undefined' && input instanceof Request ? input.method : 'GET')
            ).toUpperCase();
            const requestUrl = typeof Request !== 'undefined' && input instanceof Request ? input.url : String(input);

            if (csrfToken && unsafeMethods.has(requestMethod) && isSameOrigin(requestUrl)) {
                const headers = new Headers(
                    init.headers || (typeof Request !== 'undefined' && input instanceof Request ? input.headers : undefined)
                );
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', csrfToken);
                }
                init = Object.assign({}, init, { headers });
            }

            return nativeFetch(input, init);
        };
    }

    if (typeof XMLHttpRequest !== 'undefined') {
        const nativeOpen = XMLHttpRequest.prototype.open;
        const nativeSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function securedOpen(method, url, ...args) {
            this.__wspaceMethod = String(method || 'GET').toUpperCase();
            this.__wspaceUrl = String(url || '');
            return nativeOpen.call(this, method, url, ...args);
        };

        XMLHttpRequest.prototype.send = function securedSend(body) {
            if (
                csrfToken
                && unsafeMethods.has(this.__wspaceMethod || 'GET')
                && isSameOrigin(this.__wspaceUrl || window.location.href)
            ) {
                this.setRequestHeader('X-CSRF-Token', csrfToken);
            }
            return nativeSend.call(this, body);
        };
    }

    document.addEventListener('submit', function addCsrfToForm(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const method = String(form.method || 'GET').toUpperCase();
        if (!csrfToken || !unsafeMethods.has(method) || !isSameOrigin(form.action || window.location.href)) {
            return;
        }

        if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = csrfToken;
            form.appendChild(input);
        }
    }, true);
})();

document.addEventListener('DOMContentLoaded', function () {
    const sidebarControl = document.getElementById('sidebarControl');
    const sidebar = document.querySelector('.sidebar');
    const content = document.querySelector('.wrapper__content');

    if (sidebarControl && sidebar && content) {
        sidebarControl.addEventListener('click', (event) => {
            event.preventDefault();
            content.classList.toggle('sidebar--active');
            sidebar.classList.toggle('active');
        });
    }
});

{/literal}
