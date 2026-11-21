# Release Signing Process

phpdup's self-update verifies release artifacts against a **signed release
manifest** before installation. This document describes the integrity model
introduced by the once-S05 rebuild (php-dup-finder-once-005): every check
**fails closed** — a placeholder key, a missing manifest, or a tampered
artifact aborts the install with an explicit error instead of passing.

## Overview

Each GitHub release includes four artifacts:

| File | Purpose |
|------|---------|
| `phpdup.phar` | The self-contained PHAR binary |
| `phpdup.release.json` | **Signed release manifest**: tag-bound JSON pinning the artifact's SHA-256 |
| `phpdup.release.json.sig` | HMAC-SHA256 (hex) over the **manifest bytes** |
| `phpdup.phar.sha256` | Informational checksum sidecar for manual `sha256sum` spot-checks — **not trusted by `self:update`** (see below) |

The manifest shape (schema 1):

```json
{
    "schema": 1,
    "tag": "v1.2.3",
    "file": "phpdup.phar",
    "sha256": "<64-hex sha256 of phpdup.phar>"
}
```

## How It Works

1. **Signing** (release workflow, `build-and-sign` job):
   - On a `v*` tag push, GitHub Actions builds the PHAR and computes its SHA-256.
   - Builds `phpdup.release.json` embedding the tag, artifact name, and that hash.
   - Computes `HMAC-SHA256(phpdup.release.json, HMAC_SECRET_KEY)` **with the raw
     secret string** — no base64 transform on either side — and writes
     `phpdup.release.json.sig`.
   - The `HMAC_SECRET_KEY` secret is attached **only to the sign step** (and the
     verify gate), never to the upload step. The sign step refuses to run when
     the secret is unset or empty — no silent empty-key signatures.

2. **Round-trip gate** (`roundtrip-verify` job — blocks publishing):
   - Verifies the staged artifacts through the real `UpdateCommand` code path
     (offline mode below): good artifact → exit 0; tampered copy → exit 1;
     unset (placeholder) key → exit 1. The `publish` job only runs when all
     three legs hold.

3. **Verification** (`phpdup self:update`):
   - Fetches the release metadata, then downloads `phpdup.phar`,
     `phpdup.release.json` and `phpdup.release.json.sig`.
   - Verifies the HMAC over the manifest bytes against the pinned verification
     key. A placeholder or empty key **refuses** (explicit error, exit 1).
   - Validates the manifest: supported `schema`, `file == "phpdup.phar"`, and
     `tag` equal to the tag in the release metadata response (replay binding).
   - Hashes the downloaded artifact and requires equality with the sha256
     pinned **inside the verified manifest**. The plain `phpdup.phar.sha256`
     sidecar is deliberately ignored: a checksum arriving in the same response
     as the artifact it describes provides no protection — that was the old
     fail-open fallback and it is gone.
   - Only then replaces the running phar atomically.

`--allow-unsigned` no longer exists. The only unchecked path is
**`--dev-allow-unsigned`**, confined to development rigs: it skips the manifest
legs entirely and prints a loud banner on both the output and **stderr**. Never
use it for a production install.

## Offline Verification Mode

The same code path can verify already-downloaded files without network and
without running from a phar — this is what the CI round-trip gate drives, and
it is also useful for manual audits:

```bash
php bin/phpdup self:update \
  --verify-artifact=phpdup.phar \
  --verify-manifest=phpdup.release.json \
  --verify-signature=phpdup.release.json.sig
# exit 0 = verified; exit 1 = tampered / malformed / placeholder key
```

Offline mode skips the tag-vs-release-metadata binding (there is no live
release to bind against); the signature, schema, artifact-name and sha256
checks all run.

## Setting Up HMAC_SECRET_KEY

> **Status (once-S05 era):** the `VERIFICATION_KEY` constant in
> `src/Cli/UpdateCommand.php` still ships the build placeholder, so every
> live verification **refuses by design** until the release manager performs
> the owner key action below. The **asymmetric (minisign/ed25519) migration —
> generating the real keypair and its secret custody — is an open OWNER
> action**; see "Security notes".

1. Go to **Settings → Secrets and variables → Actions** in the repository.
2. Add repository secret `HMAC_SECRET_KEY`. Value: a long random string.
   **Whatever string you store here is exactly what must go into
   `VERIFICATION_KEY` below — raw, identical bytes, no encoding either side.**
3. Replace the placeholder in `src/Cli/UpdateCommand.php`:
   ```php
   private const VERIFICATION_KEY = '<the exact HMAC_SECRET_KEY string>';
   ```
4. Tag a release; the round-trip gate proves sign↔verify agreement with the
   real key **before** `publish` can attach any assets.

For dev/CI experiments the verification key can also be supplied without a
rebuild via the environment override `PHPDUP_UPDATE_VERIFICATION_KEY`
(raw string, same form as the secret).

## Making a Signed Release

```bash
# 1. Ensure HMAC_SECRET_KEY is configured and VERIFICATION_KEY is replaced
# 2. Tag and push the release
git tag v1.2.3
git push origin v1.2.3
# GitHub Actions will then:
#   - build the PHAR + checksum + signed manifest (secret on the SIGN step)
#   - run the sign -> verify round-trip gate (good / tampered / placeholder legs)
#   - publish the GitHub release with all four assets only if the gate passed
```

## Key Rotation

1. Generate a new random secret (e.g. `openssl rand -hex 32`).
2. Update the `HMAC_SECRET_KEY` repository secret **and** the
   `VERIFICATION_KEY` constant in `src/Cli/UpdateCommand.php` to the identical
   raw string (a phar built with the old key can only verify new releases
   after it self-updates once more — rotate by releasing, not by editing).
3. Tag and push the new release; the round-trip gate blocks publication if
   the two sides drifted apart.

## Security Notes — honest limits of the current scheme

- **HMAC is symmetric.** The verification key *is* the signing secret. Once
  `VERIFICATION_KEY` carries the real key, that key is embedded in every
  shipped phar (uncompressed `src/` — strings-extractable). The check
  therefore defends against network/release-storage attackers and torn or
  replaced downloads, but **anyone who fully reads an installed phar can
  mint signatures that pass**. This is the residual flaw the finding names
  "symmetric-secret forging".
- **The asymmetric migration fixes exactly this**: a minisign/ed25519 keypair
  whose *public* half compiles into the phar while the private half stays in
  owner-held CI secret custody (plan shared-resource: `[OWNER: generate
  asymmetric keypair + secret custody]`). A phar-stub key-separation follow-up
  is tracked as XP-09 (once-S102 window). Until that lands, treat
  `self:update` integrity as **transport protection, not publisher
  authentication** — for a fresh install, download over the browser and
  compare the sha256 against a value obtained through a second channel.
- The placeholder now **fails closed**: before this rebuild a placeholder key
  plus an unset signing secret combined into releases that "verified" against
  nobody. Now a release whose signature does not verify against a real key
  cannot install, and an unsigned release is loud, opt-in, and dev-only.
- If the secret is compromised: rotate immediately (above), and note that
  installed copies still hold the old key — a forced offline upgrade is the
  only clean remediation until the asymmetric scheme lands.
