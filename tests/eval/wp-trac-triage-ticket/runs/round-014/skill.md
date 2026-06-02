---
name: wp-trac-triage-ticket
description: >-
  Classify a single WordPress Trac ticket into a recommended action
  (ALREADY-FIXED, BACKPORT-PENDING, PR-RESOLVES, PR-STALE, NEEDS-INFO,
  NEEDS-DISCUSSION, NOT-DEFECT, READY-TO-FIX). Use before dispatching a
  worker to a ticket — lets the orchestrator skip non-fixable tickets
  without paying the cost of a worktree and envlite init.
argument-hint: <ticket-number>
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)
---

If no ticket number was provided, stop and report — this skill requires one.

# wp-trac-triage-ticket

Decide what should happen to a Trac ticket based on its current state. No worktree, no envlite, no git verification — just inspection of the data returned by `wp-trac-ticket`.

## Fetch the ticket

Call the script directly (default mode returns metadata, description, attachments, changesets, comments, and PRs in one call):

```
${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php <ticket-number>
```

Do not invoke the `wp-trac-ticket` skill recursively — call the script directly so the output stays in your context.

## Decision tree

Evaluate in order. Stop at the first match. **Follow the priority order literally** — do not override on "spirit" grounds. If a higher-priority branch matches, emit that classification even when a lower-priority one feels more semantically informative. The order is the contract.

### 1. ALREADY-FIXED

Pattern: `status=closed`, `resolution=fixed`, at least one entry under `## Changesets`.

Action: skip; cite changeset.

### 2. CLOSED-NON-FIXED

Pattern: `status=closed` AND `resolution` in {`worksforme`, `invalid`, `duplicate`, `wontfix`}.

Action: skip permanently — the ticket is closed for a reason that isn't a fix. For `duplicate`, the closing comment usually names the canonical ticket; cite it in `notes`.

### 3. BACKPORT-PENDING

Pattern, all of:
- `status=reopened`.
- A comment matches "Reopening … to request backport[ing] [<changeset>] to the <X.Y> branch" or a close variant.
- The referenced changeset appears in `## Changesets`.
- No substantive activity *after* the backport-request comment. "Substantive" = new repro, new attached patch, regression report, or a tester reporting the backport doesn't apply. Bookkeeping (milestone changes, owner changes, version bumps) and tester comments confirming the existing fix works don't count — even when formatted as full test reports with environment blocks, screenshots, or before/after artifacts. Judge by the conclusion, not the formality.

Action: skip — backports are committer-only.

### 4. NOT-DEFECT

Pattern: ticket `Type:` is not `defect (bug)` (e.g. `enhancement`, `task`, `feature request`).

Action: skip — out of scope for defect-fixing flow. The orchestrator dispatches workers to fix defects only, so non-defects short-circuit before any PR-state checks.

### 5. PR-RESOLVES

Pattern: at least one PR under `## Pull Requests` where all of:
- `state: open`.
- `CI: success` (or no CI signal — treat absent as neutral, not failing).
- `Updated:` within the last 30 days.
- **No `CHANGES_REQUESTED` reviewer state on the `Reviews:` line.** A `CHANGES_REQUESTED` state from any reviewer disqualifies the PR — fall through to PR-STALE (if old/red) or NEEDS-DISCUSSION (if active disagreement). `APPROVED` and `COMMENTED` states do not disqualify.
- PR title/scope plausibly matches the ticket (judgment call — see Confidence below).

Action: recommend reviewing the PR before dispatching a worker; cite PR URL.

### 6. PR-STALE

Pattern: open PRs exist under `## Pull Requests`, but ALL of them are either:
- Last updated more than 30 days ago, or
- CI status is `failure` with no comments addressing it in the last 30 days.

Action: recommend reviving the PR rather than starting fresh.

### 7. NEEDS-DISCUSSION

Pattern: any of:
- `Keywords:` or `Focuses:` include `needs-design-feedback`, `needs-dev-feedback`, `dev-feedback`, or `needs-unit-tests`.
- Recent comments (last 30 days) show active disagreement on approach (multiple authors proposing different fixes, contested triage decisions).
- An open PR has a `CHANGES_REQUESTED` reviewer state (disqualified PR-RESOLVES at rule 5) AND recent comments show multi-author disagreement or unresolved review feedback. If no active disagreement, prefer PR-STALE.

Action: skip — judgment required.

### 8. NEEDS-INFO

Pattern: description lacks reproduction steps AND recent comments ask for repro, environment details, or clarification.

Action: skip — wait for reporter to respond.

### 9. READY-TO-FIX

Default. None of the above matched.

Action: dispatch worker (orchestrator decides priority via scoring).

## Confidence

- `high` — load-bearing signals are all explicit and unambiguous.
- `medium` — classification is correct but at least one signal required interpretation (e.g. "is this PR's scope a match for the ticket?").
- `low` — close call; could plausibly be a different classification.

**PR-RESOLVES scope-match rule.** When emitting PR-RESOLVES, confidence is `high` only if at least one explicit scope-match signal is present:
- PR title verbatim matches the ticket summary, OR
- PR title or body references the ticket number (e.g. `#NNNNN` or `Trac NNNNN`), OR
- A recent commenter (ticket owner, reporter, or reviewer) has confirmed the PR addresses this ticket.

If none of these is present and the match relies on the judge inferring topical overlap from PR title alone, downgrade to `medium`. This is the honest signal that the load-bearing decision is judgment-based.

## Output

Emit the result as the LAST thing in your response, **as plain text — NOT inside a markdown code fence**. The `=== TRIAGE RESULT ===` and closing `===` are the parseability sentinels; wrapping them in ```` ``` ```` breaks downstream extraction. No prose after the closing `===`.

```
=== TRIAGE RESULT ===
ticket:         #<N>
classification: <ALREADY-FIXED | CLOSED-NON-FIXED | BACKPORT-PENDING | PR-RESOLVES | PR-STALE | NOT-DEFECT | NEEDS-DISCUSSION | NEEDS-INFO | READY-TO-FIX>
confidence:     <high | medium | low>
evidence:       <one line citing the load-bearing facts from the ticket>
recommendation: <one line: what should the orchestrator do?>
notes:          <one line | n/a>
===
```

(The fenced block above is for reference only. Your actual emission must be unfenced.)
