---
name: wp-trac-auth
description: >-
  Authenticate to WordPress Trac by capturing the browser session cookie so
  the other wp-trac skills can fetch filtered data. Use when a Trac request
  reports "auth required", when the cookie is missing or expired, or when the
  user asks to log in to / authenticate with Trac. Walks the user through
  copying the Cookie: header and persists it securely.
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-auth/scripts/auth.php:*)
argument-hint: ""
---

Set up or refresh WordPress Trac authentication.

Trac filters some unauthenticated requests, so the wp-trac skills need the
user's browser session cookie. This skill checks the current cookie, guides the
user through capturing a fresh one when needed, saves it securely, and confirms
it works.

## Workflow

### 1. Check current status

Run:

!`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-auth/scripts/auth.php"` status

- **exit 0 (valid)**: already authenticated. Tell the user they're good to go.
  If a Trac command triggered this, re-run that command now.
- **exit 3 (missing/empty)** or **exit 4 (expired)**: continue to step 2.

### 2. Guide the user to copy the Cookie header

Tell the user to do the following, in their browser:

1. Log in to <https://core.trac.wordpress.org/>.
2. Open devtools → **Network** tab.
3. Reload, then click any request to `core.trac.wordpress.org`.
4. In the request headers, find the **`Cookie:`** request header and copy its
   full value. When logged in it includes the WordPress.org SSO cookies
   `wporg_logged_in` and `wporg_sec` (this Trac authenticates via those, not a
   `trac_auth` cookie).
5. Paste that value back here.

### 3. Save it

Pipe the pasted value to the script via **STDIN** — never pass it as a command
argument (that would leak the session token into the process list and shell
history). Use a quoted heredoc so the shell does no interpolation:

```bash
"${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-auth/scripts/auth.php" save <<'COOKIE'
<paste the cookie value here>
COOKIE
```

- **exit 0**: saved and verified. If a Trac command triggered this, re-run it now.
- **exit 2**: the value didn't look like a logged-in Trac cookie (no
  `wporg_logged_in` / `wporg_sec` / `trac_auth`). Make sure the user is logged
  in at core.trac.wordpress.org, then re-copy the full `Cookie:` header.
- **exit 5**: saved but still not authenticated — the cookie is likely expired.
  Ask the user to log in again, re-copy a fresh header, and retry.

## Security

- **Never print the pasted cookie value back to the user or into any other
  output.** It is a live session token. The script only ever reports derived
  facts (path, length, whether a login cookie is present, valid/expired).
- The cookie is saved with restrictive permissions (file `0600`, dir `0700`) at
  the path resolved from `$TRAC_COOKIE_FILE` → `$XDG_CONFIG_HOME/wp-trac/cookie`
  → `~/.config/wp-trac/cookie`.
- The cookie is host-scoped to Trac; the scripts deliberately never send it to
  other origins.
- Cookies expire with the browser session, so this flow recurs — just re-run
  `/wp-trac-auth` whenever requests start failing.
