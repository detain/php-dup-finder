# Release Signing Process

phpdup uses **HMAC-SHA256** signatures to verify release artifacts before installation. This protects against transmission corruption and ensures authenticity of releases.

## Overview

Each GitHub release includes three artifacts:

| File | Purpose |
|------|---------|
| `phpdup.phar` | The self-contained PHAR binary |
| `phpdup.phar.sha256` | SHA-256 checksum of the phar |
| `phpdup.phar.sig` | HMAC-SHA256 signature of the phar |

## How It Works

1. **Signing** (done by maintainer):
   - When a version tag is pushed, GitHub Actions builds the PHAR
   - Computes `HMAC-SHA256(phpdup.phar, HMAC_SECRET_KEY)`
   - Attaches `phpdup.phar.sig` as a release asset

2. **Verification** (done by `phpdup self:update`):
   - Downloads `phpdup.phar.sig` and `phpdup.phar`
   - Computes `HMAC-SHA256(phpdup.phar, VERIFICATION_KEY)`
   - Compares against the downloaded signature
   - Only installs if signature is valid

## Setting Up HMAC_SECRET_KEY

The `HMAC_SECRET_KEY` must be set as a GitHub Actions repository secret:

1. Go to **Settings → Secrets and variables → Actions** in your repository
2. Click **New repository secret**
3. Name: `HMAC_SECRET_KEY`
4. Value: Generate a secure random key (base64-encoded recommended)
   ```bash
   # Generate a 256-bit key, base64-encoded
   openssl rand -base64 32
   ```

## Making a Signed Release

```bash
# 1. Ensure HMAC_SECRET_KEY is set in GitHub repo secrets
# 2. Tag and push the release
git tag v1.2.3
git push origin v1.2.3
# GitHub Actions will automatically:
#   - Build the PHAR
#   - Generate phpdup.phar.sha256
#   - Generate phpdup.phar.sig
#   - Create the GitHub release with all assets
```

## Key Rotation

To rotate the verification key:

1. Generate a new key:
   ```bash
   openssl rand -base64 32
   ```

2. Update `HMAC_SECRET_KEY` in GitHub Actions secrets

3. Update `VERIFICATION_KEY` constant in `src/Cli/UpdateCommand.php`:
   ```php
   private const VERIFICATION_KEY = 'YOUR_NEW_BASE64_ENCODED_KEY';
   ```

4. Tag and push a new release

## Security Notes

- The HMAC key provides authenticity AND integrity verification
- Anyone with access to `HMAC_SECRET_KEY` can sign releases
- The `VERIFICATION_KEY` is embedded in the codebase (not secret)
- If a key is compromised, rotate immediately and release a new version
