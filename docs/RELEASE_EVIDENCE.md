# 1.0 release evidence

Workspace Organizer 1.0 keeps release evidence reproducible and machine-readable. The automated release-evidence gate complements the existing module lifecycle, HTTPS/WSS, installer, updater, rollback, security, retention and production health workflows.

## Cross-browser and mobile evidence

The automated browser matrix authenticates a real user and verifies the core workspace shell plus Notes, Tasks, Files and Profile in:

- Chromium desktop, 1366x768;
- Firefox desktop, 1366x768;
- WebKit desktop, 1366x768;
- Chromium mobile, 390x844 touch/mobile context;
- WebKit mobile, 412x915 touch/mobile context.

Each scenario must:

- complete the actual login flow;
- render the main workspace content;
- load Notes, Tasks, Files and Profile with HTTP 200;
- produce no JavaScript page errors;
- keep the document within the configured viewport without horizontal document overflow.

Mobile scenarios additionally prove the real sidebar lifecycle: initially closed, open through the menu control, visible backdrop, and close through Escape with correct aria-expanded state.

The existing Browser HTTPS and WSS E2E workflow remains the release evidence for authenticated Messenger realtime behavior, reconnect, messages and transient activity presence. This matrix does not duplicate that WSS test across three browser engines.

## Load and soak evidence

After the browser matrix exports an authenticated PHP session cookie, the same release-candidate process is exercised through authenticated GET requests against:

- workspace home;
- Notes;
- Tasks;
- Files;
- Profile.

Default CI thresholds:

- fixed load: 600 requests with concurrency 12;
- soak: 45 seconds with concurrency 4;
- allowed HTTP/auth/transport errors: 0;
- maximum p95 latency: 2500 ms for both phases;
- minimum fixed-load throughput: 5 requests/second;
- minimum soak throughput: 3 requests/second.

These are release-regression thresholds for the GitHub runner, not a production capacity claim. Production sizing still depends on CPU, database, storage, reverse proxy, network and real workload characteristics. Increasing the thresholds to hide a regression is not acceptable release evidence; a changed threshold requires an explicit reviewed rationale.

## Evidence artifacts

The workflow uploads machine-readable artifacts for the exact commit:

- cross-browser/mobile JSON;
- load/soak JSON;
- post-run healthcheck JSON.

CI also requires a clean PHP runtime log: fatal, uncaught and parse errors fail the evidence job.

## Release acceptance

Automated evidence does not fabricate human beta evidence. Before the final merge to master and v1.0.0 tag, the release owner must additionally record the release-candidate acceptance decision and confirm:

1. no open P0/P1 data-loss defects;
2. no open P0/P1 security defects;
3. no unresolved release-blocking regression from beta/RC testing;
4. the exact release head has green release evidence and Stable release gate;
5. the production backup/restore, upgrade and rollback evidence required by the release runbook is current.

If the product has not been exposed to a representative human beta cohort, record that explicitly instead of claiming beta coverage that did not occur.
