# MunAI — Harmonia's Operational Deployment (PHP)

This is the PHP that actually runs MunAI at harmonism.io: the Telegram bot, the website
companion API, the RAG layer, the memory layer, the classifier, the crisis detector, the
membership resolver, the outreach engine, and the async queue that drives them.

It is published so that a practitioner talking to MunAI can read what they are talking to.
That is the whole reason it is here.

## What this is not

**It is not a reference implementation.** The tradition-neutral reference is the Python SDIP
harness at [`../../components/sdip/`](../../components/sdip/). If you want to build a
doctrinally-aligned companion for your own tradition, start there — it is designed to be
adopted. This directory is not.

**It is not supported, and it will not be made generic.** The code is welded to Harmonia's
specific stack: Hostinger LiteSpeed, Supabase auth, SQLite over a shared filesystem, an
async job queue with a cron watchdog, a ten-language deployment chain, Lightning settlement.
Lifting it into your own environment is a porting exercise, not a configuration exercise.
No issues will be triaged against it and no support is implied.

**It is not runnable as-is.** Credentials live outside the tree, in files this mirror does
not and will never contain. See `telegram-companion/telegram-config.template.php` for the
shape of what is missing.

## How this mirror is produced

By `scripts/publish-munai-mirror.sh` in the Harmonia website repository, which copies an
allowlist of source files and rewrites deployment identifiers and private addresses on the
way out, then refuses to write anything if a sanitization check fails. Production source is
never modified by the publication step.

Two consequences worth stating plainly, because a reader should not have to infer them:

- **The mirror is sanitized, not raw.** Hosting account, server host and SSH port appear as
  `SERVER_USER`, `SERVER_HOST` and `SSH_PORT`. Three private addresses in the comp allowlist
  are replaced with `example.com` placeholders, and the allowlist block itself is published
  in its config-sourced form.
- **Some files are excluded by name.** `circle-grant-announcement.php` is a one-shot script
  whose hand-written messages name two private individuals. Databases, logs and caches are
  never staged.

Everything else is byte-identical to what is deployed.

## What is not here and never will be

Practitioner data. Conversation history, Harmonic Profiles, per-practitioner memory, linked
accounts, wheel scores. That material belongs to the practitioner. Publishing the code that
handles it is transparency; publishing the data itself would be a betrayal of the same
sovereignty doctrine that motivates publishing the code.

Credentials, likewise.

## Licence

Public domain, CC0-1.0, like everything else in this repository. See
[`../../NOTICE.md`](../../NOTICE.md) for why there is no licence.
