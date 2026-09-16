# Remote signed update delivery

Workspace Organizer 1.0 can discover and stage a signed update from a vendor-controlled HTTPS feed without giving the network layer any authority to mutate the live installation.

The boundary is deliberately split:

```text
remote feed
  -> signed manifest + detached signature
  -> compatibility verification
  -> exact package download from signed filename/size/SHA-256
  -> local ZIP structural audit
  -> immutable external staging
  -> STOP
```

Maintenance entry, backup, release-candidate extraction, live apply and rollback remain separate transaction steps.

## Configuration

Recommended production `.env` settings:

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Supported channels are `alpha`, `beta` and `stable`.

Remote delivery additionally requires the PHP `openssl` extension. The rest of the application can continue to run when this optional network capability is unavailable; `bin/update_remote.php` fails closed instead of weakening TLS verification.

`UPDATE_FEED_URL` must be a public HTTPS URL. The built-in transport deliberately rejects:

- plain HTTP;
- embedded URL credentials;
- non-443 ports;
- literal IP addresses;
- DNS results in private/reserved/special-use ranges;
- redirects;
- transfer-encoded/chunked responses;
- compressed HTTP response bodies;
- missing or ambiguous `Content-Length`;
- raw ASCII control characters or spaces in the URL/request target;
- TLS certificates/peer names that do not verify.

DNS is resolved before connecting and the validated public address is pinned for the TLS socket while certificate verification still uses the original DNS host name. This prevents a checked public name from being silently re-resolved to a private address for the actual connection.

## Feed contract

The feed is intentionally small and **not itself a trust root**:

```json
{
  "schema": 1,
  "product": "workspace-organizer",
  "channel": "stable",
  "manifest": "workspace-organizer-v1.0.0.update.json",
  "signature": "workspace-organizer-v1.0.0.update.sig"
}
```

`manifest` and `signature` must be simple file names in the same HTTPS directory as the feed. Paths, absolute URLs and traversal are rejected.

The feed may be modified by an untrusted intermediary without granting update authority. The updater only trusts a manifest whose exact bytes pass the configured Ed25519 update-key verification. The verified manifest then controls:

- product/version/version code;
- channel;
- source commit;
- minimum source version;
- minimum PHP version;
- **package filename**;
- **package byte size**;
- **package SHA-256**.

The package URL is derived from that signed package filename in the same feed directory. An unsigned `package` field in the feed is ignored.

## Check without package download

```bash
php bin/update_remote.php --check-only
```

or explicitly:

```bash
php bin/update_remote.php \
  --feed-url=https://updates.example.com/workspace-organizer/stable/feed.json \
  --channel=stable \
  --check-only --json
```

The command downloads only the feed, manifest and detached signature. It verifies the signature, authenticates the signed metadata and classifies the result without fetching package bytes:

- `update_available` — newer signed update is compatible;
- `up_to_date` — signed feed points to the installed `VERSION_CODE`;
- `ahead_of_feed` — this installation is newer than the signed feed;
- `update_incompatible` — a newer signed update exists but fails source-version or PHP compatibility policy.

All four read-only states return normally with `package_downloaded=false` and `live_files_changed=false`. The command does **not** enter maintenance, create a transaction or modify live files.

This is the intended primitive for a future administrator update UI.

## Download and immutable stage

```bash
php bin/update_remote.php --json
```

Optional explicit staging root:

```bash
php bin/update_remote.php \
  --stage-root=/var/lib/notes/update-staging \
  --json
```

The action path is stricter than read-only check: same-version, downgrade, source-floor and PHP incompatibility are rejected before package download or stage creation.

The package path is not accepted from the feed. After manifest verification the updater derives the package URL from the signed filename, then requires the HTTP `Content-Length` to equal the signed size and streams exactly that many bytes into a private external temporary directory while calculating SHA-256.

A remote package larger than 512 MiB is rejected before download even if a signed manifest requests it. This is an additional network-ingress resource limit, not a replacement for the signed size check.

After download the existing local updater contracts run again:

1. `UpdatePackageStager::verifyPackage()` validates signed filename, size, SHA-256 and ZIP magic;
2. `UpdateArchiveInspector` performs the non-extracting ZIP structural/safety audit;
3. `UpdatePackageStager::stage()` copies the exact manifest/signature/package into the normal immutable external stage and verifies the package again after copy;
4. temporary network download bytes are removed.

The resulting stage is therefore interchangeable with a manually supplied stage from `bin/update.php`. Downstream backup/candidate/apply commands do not need to know whether the verified package originally arrived through local media or the remote feed.

## Concurrency

Remote package delivery uses a non-blocking lock under the external staging root. Concurrent remote downloads cannot write through the same ingress path at the same time. The existing immutable staging lock still serializes final stage publication.

## Trust and failure behavior

Remote delivery fails closed when:

- no trusted update public key is configured;
- TLS, URL framing or DNS policy cannot be verified;
- the feed shape/product/channel is invalid;
- manifest/signature names are unsafe;
- signature verification fails;
- signed manifest channel differs from configured channel;
- package size exceeds the remote ingress ceiling;
- package transport size/hash differs from the signed manifest;
- ZIP structural audit fails;
- staging root is inside the application tree.

Additionally, the **stage action** fails closed when the signed target is not newer or is incompatible with the current source/runtime. Read-only `--check-only` reports those valid signed states as data instead of treating them as network errors.

No failure in this layer should require rollback because this layer never crosses the live mutation boundary.

## Operational publishing rule

Publish a channel directory as immutable release assets plus one small mutable feed pointer. A typical directory is:

```text
/stable/feed.json
/stable/workspace-organizer-v1.0.0.update.json
/stable/workspace-organizer-v1.0.0.update.sig
/stable/workspace-organizer-v1.0.0.zip
```

The feed can move to a newer signed manifest, but previously published signed manifest/signature/package triples should remain byte-identical for reproducibility and incident investigation.

Do not host the production update-signing private key on the update web server. The server only distributes already signed public artifacts.
