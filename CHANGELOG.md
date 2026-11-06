# Changelog

## 2.1.0 — 2026-11-06

- Preserve the released --ref/-r API alongside --project-version.
- Fix Symfony command registration and Composer binary-proxy autoloading.
- Reject existing project paths and report Composer/Git failures accurately.
- Generate private environment files and fill blank application keys without replacing existing keys.
- Initialize Git without automatic staging or commits; do not run database migrations automatically.
- Refresh vulnerable Symfony dependencies and verify PHP 8.2–8.5 compatibility.
