(() => {
    'use strict';

    const DEFAULT_LEASE_TTL_MS = 12000;
    const DEFAULT_RENEW_MS = 4000;

    function randomTabId() {
        if (globalThis.crypto?.randomUUID) {
            return globalThis.crypto.randomUUID();
        }
        return 'tab-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function safeStorage() {
        try {
            const storage = globalThis.localStorage;
            const probe = '__wspace_messenger_transport_probe__';
            storage.setItem(probe, '1');
            storage.removeItem(probe);
            return storage;
        } catch (_) {
            return null;
        }
    }

    class MessengerTabCoordinator {
        constructor(options = {}) {
            this.leaseKey = options.leaseKey || 'wspace:messenger-global-transport-owner';
            this.channelName = options.channelName || 'wspace:messenger-global-transport';
            this.leaseTtlMs = Math.max(4000, Number(options.leaseTtlMs || DEFAULT_LEASE_TTL_MS));
            this.renewMs = Math.max(1000, Math.min(
                this.leaseTtlMs - 1000,
                Number(options.renewMs || DEFAULT_RENEW_MS)
            ));
            this.tabId = options.tabId || randomTabId();
            this.storage = options.storage === undefined ? safeStorage() : options.storage;
            this.channelFactory = options.channelFactory || (
                typeof globalThis.BroadcastChannel === 'function'
                    ? (name) => new globalThis.BroadcastChannel(name)
                    : null
            );
            this.now = options.now || (() => Date.now());
            this.setTimer = options.setTimer || ((callback, delay) => globalThis.setTimeout(callback, delay));
            this.clearTimer = options.clearTimer || ((timer) => globalThis.clearTimeout(timer));
            this.isVisible = options.isVisible || (() => document.visibilityState === 'visible');
            this.onLeadershipChange = options.onLeadershipChange || (() => {});
            this.onMessage = options.onMessage || (() => {});
            this.eventWindow = options.eventWindow || globalThis.window;
            this.eventDocument = options.eventDocument || globalThis.document;

            this.started = false;
            this.leader = false;
            this.channel = null;
            this.renewTimer = null;
            this.electionTimer = null;

            this.handleStorage = (event) => {
                if (event?.key !== this.leaseKey) return;
                this.refresh();
            };
            this.handleVisibility = () => {
                if (this.isVisible()) {
                    this.refresh();
                    return;
                }
                this.release();
            };
            this.handlePageHide = () => this.release();
        }

        isLeader() {
            return this.leader;
        }

        start() {
            if (this.started) return;
            this.started = true;

            if (!this.storage || !this.channelFactory) {
                this.setLeader(true);
                return;
            }

            try {
                this.channel = this.channelFactory(this.channelName);
                this.channel.onmessage = (event) => {
                    if (!this.started || event?.data?.sender === this.tabId) return;
                    this.onMessage(event?.data?.payload);
                };
            } catch (_) {
                this.channel = null;
                this.setLeader(true);
                return;
            }

            this.eventWindow?.addEventListener?.('storage', this.handleStorage);
            this.eventWindow?.addEventListener?.('pagehide', this.handlePageHide);
            this.eventDocument?.addEventListener?.('visibilitychange', this.handleVisibility);
            this.refresh();
        }

        stop() {
            if (!this.started) return;
            this.release();
            this.started = false;
            this.cancelElection();

            this.eventWindow?.removeEventListener?.('storage', this.handleStorage);
            this.eventWindow?.removeEventListener?.('pagehide', this.handlePageHide);
            this.eventDocument?.removeEventListener?.('visibilitychange', this.handleVisibility);

            try {
                this.channel?.close?.();
            } catch (_) {
                // Закрытие уже закрытого BroadcastChannel безопасно игнорируется.
            }
            this.channel = null;
        }

        publish(payload) {
            if (!this.started || !this.channel) return;
            try {
                this.channel.postMessage({ sender: this.tabId, payload });
            } catch (_) {
                // Потеря межвкладочного сообщения не должна останавливать transport.
            }
        }

        refresh() {
            if (!this.started || !this.storage || !this.channel) return;
            if (!this.isVisible()) {
                this.release();
                return;
            }

            const now = this.now();
            const lease = this.readLease();
            if (lease && lease.owner !== this.tabId && lease.expiresAt > now) {
                this.setLeader(false);
                this.scheduleElection(lease.expiresAt - now + 50);
                return;
            }

            if (!this.writeLease(now + this.leaseTtlMs)) {
                this.setLeader(false);
                this.scheduleElection(1000);
                return;
            }

            const confirmed = this.readLease();
            if (!confirmed || confirmed.owner !== this.tabId) {
                this.setLeader(false);
                this.scheduleElection(1000);
                return;
            }

            this.setLeader(true);
            this.scheduleRenewal();
        }

        release() {
            this.cancelRenewal();

            if (this.storage) {
                const lease = this.readLease();
                if (lease?.owner === this.tabId) {
                    try {
                        this.storage.removeItem(this.leaseKey);
                    } catch (_) {
                        // Lease сам истечёт, если storage временно недоступен.
                    }
                }
            }

            this.setLeader(false);
        }

        readLease() {
            if (!this.storage) return null;
            try {
                const raw = this.storage.getItem(this.leaseKey);
                if (!raw) return null;
                const value = JSON.parse(raw);
                const owner = String(value?.owner || '');
                const expiresAt = Number(value?.expiresAt || 0);
                if (!owner || !Number.isFinite(expiresAt) || expiresAt <= 0) return null;
                return { owner, expiresAt };
            } catch (_) {
                return null;
            }
        }

        writeLease(expiresAt) {
            if (!this.storage) return false;
            try {
                this.storage.setItem(this.leaseKey, JSON.stringify({
                    owner: this.tabId,
                    expiresAt
                }));
                return true;
            } catch (_) {
                return false;
            }
        }

        setLeader(next) {
            const value = Boolean(next);
            if (this.leader === value) return;
            this.leader = value;
            this.onLeadershipChange(value);
        }

        scheduleRenewal() {
            this.cancelRenewal();
            if (!this.leader || !this.started) return;

            this.renewTimer = this.setTimer(() => {
                this.renewTimer = null;
                if (!this.started || !this.leader) return;
                if (!this.isVisible()) {
                    this.release();
                    return;
                }

                const lease = this.readLease();
                if (lease && lease.owner !== this.tabId && lease.expiresAt > this.now()) {
                    this.setLeader(false);
                    this.scheduleElection(lease.expiresAt - this.now() + 50);
                    return;
                }

                if (!this.writeLease(this.now() + this.leaseTtlMs)) {
                    this.setLeader(false);
                    this.scheduleElection(1000);
                    return;
                }

                this.scheduleRenewal();
            }, this.renewMs);
        }

        scheduleElection(delay) {
            this.cancelElection();
            if (!this.started || !this.isVisible()) return;
            this.electionTimer = this.setTimer(() => {
                this.electionTimer = null;
                this.refresh();
            }, Math.max(50, Math.min(this.leaseTtlMs, Number(delay || 50))));
        }

        cancelRenewal() {
            if (!this.renewTimer) return;
            this.clearTimer(this.renewTimer);
            this.renewTimer = null;
        }

        cancelElection() {
            if (!this.electionTimer) return;
            this.clearTimer(this.electionTimer);
            this.electionTimer = null;
        }
    }

    globalThis.wspaceMessengerTabCoordinator = {
        create(options = {}) {
            return new MessengerTabCoordinator(options);
        },
        MessengerTabCoordinator
    };
})();
