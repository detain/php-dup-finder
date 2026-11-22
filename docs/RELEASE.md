# Release Signing Process

phpdup's self-update verifies release artifacts against a **signed release
manifest** before installation. This document describes the integrity model
introduced by the once-S05 rebuild (php-dup-finder-once-005): every check
**fails closed** — a placeholder key, a missing manifest, or a tampered
artifact aborts the install with an explicit error instead of passing.

## Overview

Each GitHub release published by the workflow attaches six files:

| File | Purpose |
|------|---------|
| `phpdup.phar` | The self-contained PHAR binary |
| `phpdup.release.json` | **Signed release manifest**: tag-bound JSON pinning the artifact's SHA-256 |
| `phpdup.release.json.sig` | HMAC-SHA256 (hex) over the **manifest bytes** |
| `phpdup.phar.sha256` | Checksum sidecar for manual installs, in GNU coreutils `sha256sum --check` format — exactly `<hash>  phpdup.phar` (TWO spaces; text mode), so `sha256sum --check phpdup.phar.sha256` works verbatim from a directory holding both files. **Not trusted by `self:update`** (see below) |
| `phpdup.build.json` | Reproducible-build metadata (once-S11): resolved `sugarcraft/*` `dev-master#<sha>` refs, composer content-hash, PHP runtime, build instant |
| `phpdup.releasenotes.md` | Human-readable summary the `publish` job attaches as the GitHub release **body** |

The manifest shape (schema 1):

```json
{
    "schema": 1,
    "tag": "v1.2.3",
    "file": "phpdup.phar",
    "sha256": "<64-hex sha256 of phpdup.phar>"
}
```

## Current publication status (once-S12, 2026-11-22)

Verified against `api.github.com` the day this section was written:

- **No tag has been published through this workflow chain yet.** The only
  release, `v1.0.0`, predates the once-S05 signing rebuild and carries **no
  assets** — so the README's `releases/latest/download/phpdup.phar` (and
  `.sha256`) URLs currently return HTTP 404. They start working with the
  first `v*` tag pushed once the `HMAC_SECRET_KEY` secret is configured (the
  sign step refuses to run without it, so no gate-clean release — and no
  assets — exists until then). Note that until the `VERIFICATION_KEY`
  constant *also* carries the real key (owner action below), a published
  phar installs fine but later live `self:update` runs still refuse on the
  placeholder key.
- `phpdup self:update` against an asset-less release reports the missing
  `phpdup.phar` asset and exits 1 — honest refusal, not a client bug.
- **`composer require --dev detain/php-dup-finder` does not resolve** from a
  fresh stable project: the package's 16 `sugarcraft/*` dependencies are
  pinned to `dev-master#<sha>` (once-S11), and pinned dev-version VCS
  requirements are not resolvable transitively. Install from source until
  upstream tags land (README "Composer — not supported yet").

## How It Works

1. **Signing** (release workflow, `build-and-sign` job):
   - On a `v*` tag push, GitHub Actions builds the PHAR and writes
     `phpdup.phar.sha256` in the exact `sha256sum --check` format above.
   - Builds `phpdup.release.json` embedding the tag, artifact name, and the
     hash **re-parsed from that same sidecar file** — a sidecar line that
     deviates from the format aborts the job rather than pinning garbage.
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

## Transport and install-check truth (once-S91)

`self:update` reads the release API and writes binaries, so a lie can enter in
two places: the **reply** and the **swap**. Both are pinned at the transport
level — `tests/Unit/Cli/UpdateCommandTest.php` drives the real code against a
forked loopback HTTP server on an ephemeral port (hermetic, no network group,
no external dial).

- **The HTTP status is part of the reply.** A body is decoded as release data
  only for an HTTP 2xx. GitHub answers rate limits (403), bad credentials
  (401), an unpublished repository (404) and its own outages (5xx) with JSON
  *error* bodies; those used to flow into the release logic and surface as a
  misleading `Asset phpdup.phar not found in release unknown`. Each status now
  refuses with its own honest message. A `Retry-After` header (either form) is
  reported back as guidance — the command is one-shot and never re-dials on
  its own — and a 2xx reply with no usable JSON body (e.g. 204) says exactly
  that instead of blaming the network.
- **The metadata request does not follow redirects.** It is the one request
  that can carry `Authorization`, and a credential must never be replayed onto
  a host the caller did not choose; a 3xx surfaces as a refusal. Asset
  downloads *do* follow redirects (GitHub serves them through a CDN hop) and
  carry no credentials at all — see the token note below.
- **A download counts as complete only when the received bytes agree with the
  declared `Content-Length`,** and a `206` / `Content-Range` reply is refused
  outright: this client never sends a `Range` request, so a range reply
  describes a slice, not the artifact, and a length that matches the *range*
  is a lie the old leg would have accepted as a finished download.
- **The previous phar is kept until the new one proves itself.** The swap
  renames the running phar to a `.backup.*` sibling, renames the downloaded
  artifact into place, then runs a post-install check **at the final path** —
  present, readable, non-empty, and on a verified install still hashing to the
  signed manifest's pin. Only then is the backup deleted. If the check fails,
  the backup is restored automatically and exit 1 carries the reason; if the
  restore itself fails, the manual recovery command is printed. The older
  order deleted the good binary on the strength of a rename alone, so
  "rollback is automatic" was true for only half the failure shapes
  (php-dup-finder-once-114). On a `--dev-allow-unsigned` install there is no
  signed pin to re-check, so the post-install check is deliberately weaker
  (readable and non-empty) — as loud as the mode that allows it.

### Tokens and private repositories (once-S91: documented, deliberately absent)

- `GITHUB_TOKEN` (environment only — never a CLI option) is sent as a bearer
  token on the `api.github.com` metadata request, and is the one request the
  client refuses to redirect. Refusals name the variable, never its contents;
  no output path prints a token value.
- **Release asset downloads carry no authentication, so `self:update` does not
  support private releases.** That is documented-absent behavior, not an
  oversight: asset URLs redirect to a CDN host this client will not hand a
  credential to, and there is no `--token` flag to imply otherwise. For a
  private release, download with an authenticated client (`gh release
  download`) and check the bytes locally with the offline `--verify-*` mode
  above.

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
#   - build the PHAR + sha256sum-format checksum sidecar + signed manifest (secret on the SIGN step)
#   - run the sign -> verify round-trip gate (good / tampered / placeholder legs)
#   - publish the GitHub release with all six assets only if the gate passed
```

## Key Rotation

1. Generate a new random secret (e.g. `openssl rand -hex 32`).
2. Update the `HMAC_SECRET_KEY` repository secret **and** the
   `VERIFICATION_KEY` constant in `src/Cli/UpdateCommand.php` to the identical
   raw string. Clients still holding key K1 can never verify K2-signed bytes —
   publish the first K2-compiled release while `HMAC_SECRET_KEY` still holds
   K1 (the transition release), then switch the secret; installs predating the
   transition release must be re-installed once out-of-band (rotate by
   releasing, not by editing).
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

## Target selection truth (once-S99)

`self:update` reads the **stable-only** `/releases/latest` endpoint, and the
target policy now matches that truth instead of pretending otherwise:

- **`--pre` is removed** (owner ruling: remove, not wire a listing
  endpoint). It was dead code — the endpoint never lists pre-releases, so
  nothing was ever "also considered" — and the old skip-arm printed
  `To install anyway: phpdup self:update --pre`, a promise no flag could
  keep. Nothing in the shipped output ever suggests the flag again.
- **A pre-release-shaped reply is refused (exit 1).** If the stable-only
  endpoint ever answers with a `prerelease: true` shape (a proxy or mirror
  rewriting it), that is an upstream anomaly: the client fails closed and
  names the tag, offering no way through.
- **Downgrades are refused with exit 2** — the project-wide "the request
  itself is invalid" code (docs/CLI.md: 2 = invalid config), distinct from
  a runtime failure (1). `self:update` only moves forward, and the refusal
  echoes BOTH tags honestly: the release target and the running version.
  To install an older build deliberately, fetch it out of band
  (`gh release download v1.2.3`) and verify the bytes with the offline
  `--verify-*` mode above — the same documented path as any targeted
  install.
- **An equal target exits 0** as "already up to date": no transfer, no
  swap, no residue.
- **Unparseable versions never fabricate a verdict.** The gate compares
  `version_compare` cores only when both sides parse (`v` prefix optional,
  pre-release suffix honoured: `1.0.0-RC1` sorts before `1.0.0`). Plain
  `version_compare('unknown', …)` returns `-1`, so feeding the
  tag-missing placeholder straight in would call every release-less reply
  a phantom downgrade; instead it stays uncomparable and the
  signature/manifest/hash legs decide, as always.

The gate runs **before** asset resolution and before the dry-run report, so
`--dry-run` previews the refusal instead of bypassing it. All of it is
pinned through the real `execute()` body in
`tests/Unit/Cli/UpdateCommandTest.php` (injected-fake-transport matrix plus
a forked-loopback real-transport leg).
