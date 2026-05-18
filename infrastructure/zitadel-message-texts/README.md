# Zitadel Message Text Customizations — LiturgicalCalendar Org

This directory holds the per-language, per-template overrides for the
notification emails Zitadel sends to **Liturgical Calendar** users on the
umbrella `auth.catholicdigitalcommons.org` instance.

Each `{locale}/{template}.json` file maps 1:1 to a Zitadel Management API
endpoint of the form:

```text
PUT {ZITADEL_URL}/management/v1/text/message/{template}/{locale}
```

The `scripts/setup-zitadel-message-texts.sh` runner walks this tree and
PUTs each file. See "Running the setup" below.

## Why these exist

Zitadel ships [translated defaults for 22 languages](#supported-languages)
covering subject lines, button labels, greetings, and body text. Those
defaults are perfectly serviceable but generic — they say nothing about
Liturgical Calendar or the Catholic Digital Commons Foundation, and the
"From" line is umbrella-wide (`Catholic Digital Commons Foundation
<noreply@catholicdigitalcommons.org>`) for every property hosted on the
shared identity stack. Customizing the **body** is how we add LitCal-
specific context (project name, footer, expectations) so the email
that lands in a recipient's inbox reads like it came from the
Liturgical Calendar project, not a generic auth provider.

Scope: the PUTs are made authenticated as a machine user inside the
`LiturgicalCalendar` Org, so the overrides only apply to that org. Other
umbrella properties (cdcf-website, BibleGet, ...) keep their own overrides
or fall back to Zitadel defaults.

## Directory layout

```text
infrastructure/zitadel-message-texts/
├── README.md                         # this file
└── en/                               # English templates (canonical seed)
    ├── domain-claimed.json
    ├── init.json
    ├── invite-user.json
    ├── password-change.json
    ├── password-reset.json
    ├── passwordless-registration.json
    ├── verify-email.json
    └── verify-email-otp.json
```

To add a new language, copy `en/` to `{locale}/` and translate the
strings in each JSON file. Locales without an override directory fall
back to Zitadel's built-in default for that language.

## Template types

The eight templates currently scaffolded are the email-based ones. SMS
templates (`verify-phone`, `verify-sms-otp`) are intentionally **not**
included — Liturgical Calendar doesn't use SMS verification, and the
SMS template fields differ structurally (no subject/button/footer).
Add them later if SMS gets enabled.

| Template                    | When sent                                              |
|-----------------------------|--------------------------------------------------------|
| `init`                      | New user created (server-side), needs to set password  |
| `invite-user`               | Existing org member invites a new user                 |
| `verify-email`              | Email address confirmation (link-based)                |
| `verify-email-otp`          | Email address confirmation (one-time-code based)       |
| `password-reset`            | User requests password reset                           |
| `password-change`           | Confirmation after password successfully changed       |
| `passwordless-registration` | User starts passkey enrollment                         |
| `domain-claimed`            | User's email domain claimed by another org             |

## Field schema

Each JSON file accepts the following keys. All are optional — any key
you omit falls back to Zitadel's default for that locale/template.

| Key          | Typical use                                   |
|--------------|-----------------------------------------------|
| `title`      | H1 inside the email body                      |
| `preHeader`  | Inbox preview snippet (shown next to subject) |
| `subject`    | Email subject line                            |
| `greeting`   | First line of the body (see variables below)  |
| `text`       | Main body copy                                |
| `buttonText` | Label on the call-to-action button            |
| `footerText` | Closing line below the button                 |

## Template variables

Strings can interpolate Zitadel context variables via Go template
syntax. Commonly available:

- `{{.PreferredLoginName}}` — user's preferred login name (`user@org.tld`)
- `{{.FirstName}}` / `{{.LastName}}` / `{{.DisplayName}}` — name fields
- `{{.UserName}}` — the loginname portion
- `{{.OTP}}` — one-time code (only in `verify-email-otp`)

See the [Zitadel docs](https://zitadel.com/docs/guides/manage/customize/texts)
for the full per-template variable list. Using a variable that doesn't
exist for a given template renders empty.

## Supported languages

Zitadel 2.x ships built-in defaults for these 22 locales. Any locale
not listed here will be rejected by the API.

```text
ar  bg  cs  de  en  es  fr  hu
id  it  ja  ko  mk  nl  pl  pt
ro  ru  sv  tr  uk  zh
```

**Notes for LitCal:**

- `la` (Latin) is **not** in Zitadel's defaults — ICU has no Latin
  locale. Latin-preferring users fall back to English at the message-
  text layer regardless of any `Accept-Language: la` they send.
- `sk` (Slovak) and `vi` (Vietnamese) — supported by LitCal but **not**
  by Zitadel. Users with these as preferred locales will receive
  English messages.

## Running the setup

The runner is `scripts/setup-zitadel-message-texts.sh` (repo root).

**Required environment:**

```bash
export ZITADEL_URL=https://auth.catholicdigitalcommons.org
export ZITADEL_PAT=<PAT for a machine user inside LiturgicalCalendar Org>
```

The PAT must belong to a machine user that holds at least the
`ORG_OWNER` or `ORG_SETTINGS_MANAGER` role inside the `LiturgicalCalendar`
Org. Create one in the umbrella Zitadel console under
`LiturgicalCalendar` Org → Users → Service Users → New → assign role
→ Personal Access Tokens → Generate.

**Default run** (push every locale, every template):

```bash
./scripts/setup-zitadel-message-texts.sh
```

**Dry run** (print what would be PUT, no network calls):

```bash
./scripts/setup-zitadel-message-texts.sh --dry-run
```

**Scope to a single locale and/or template** (iteration):

```bash
./scripts/setup-zitadel-message-texts.sh --locale=en
./scripts/setup-zitadel-message-texts.sh --template=verify-email
./scripts/setup-zitadel-message-texts.sh --locale=en --template=verify-email
```

The runner is idempotent — PUTting the same body twice is a no-op on
the Zitadel side.

## Reverting a template

To restore Zitadel's default for a given `{template, locale}` pair,
either delete the file from this directory and re-run the script (no:
the script only PUTs, never DELETEs) **or** issue the reset endpoint
manually:

```bash
curl -X POST \
  -H "Authorization: Bearer $ZITADEL_PAT" \
  "$ZITADEL_URL/management/v1/text/message/{template}/{locale}/_reset"
```
