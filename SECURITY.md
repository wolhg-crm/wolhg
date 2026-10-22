# Security Policy

## Supported versions

Security fixes are applied on the default branch (`main`).

## Reporting a vulnerability

Please **do not** open a public GitHub/GitLab issue for vulnerabilities that
could expose personal data of church members.

Email the maintainers privately (or use the private security contact of the
repository host). Include:

- affected version / commit;
- steps to reproduce;
- impact (auth bypass, data leak, RCE, etc.).

We aim to acknowledge reports within a few days and to ship a fix before any
public disclosure.

## Operator responsibilities

This CRM stores personal data (names, phones, pastoral notes). Each deploying
church is the **data controller** under applicable law (including 152-FZ in the
Russian Federation). See [docs/personal-data-rf.md](docs/personal-data-rf.md).

Before production:

1. Replace `security.encryption_key` in `config/local.php`.
2. Change every default seed password (`password`).
3. Never commit `config/local.php`, `.env`, FTP/SFTP credentials, or API tokens.
4. Keep `vendor/` and uploads writable only as required; block PHP execution in
   `public/uploads/` (shipped `.htaccess` helpers).

## Secrets in git history

If a secret was ever committed, **rotate it immediately** on the provider
(hosting FTP, OAuth, bot tokens, VAPID, DB). Removing the file from the tree is
not enough while old commits remain reachable.
