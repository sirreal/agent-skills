---
name: wp-trac-ticket
description: >-
  Look up a specific WordPress Trac ticket by its number to see details (status,
  component, type, milestone, description) or discussion/comments. Accepts #62345,
  62345, or https://core.trac.wordpress.org/ticket/62345. Use whenever the user
  references a specific ticket number or wants to read a particular ticket's
  comments/discussion. Do NOT use wp-trac-search for single ticket lookups.
allowed-tools:
  - Bash(${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php:*)
argument-hint: "[--discussion | --prs] <ticket-number>"
---

Look up WordPress Trac ticket information.

If no ticket number was provided, ask the user which ticket they want to look up.

## Input formats

Pass the numeric ticket number to the script (e.g. `30000`).

## Choosing a mode

Three modes are available. Pick one based on what the user wants.

| User says | Mode |
|-----------|------|
| "look up ticket 30000" | basic (no flag) |
| "what's the status of ticket 12345" | basic (no flag) |
| "what are people saying on ticket 30000" | `--discussion` |
| "show me the comments on #62345" | `--discussion` |
| "summarize the discussion on ticket 50000" | `--discussion` |
| "which comments matter on ticket 30000" | `--discussion` + relevance scoring |
| "is this still a real bug on #16191" | `--discussion` + relevance scoring |
| "rank the comments on ticket 50000 by relevance" | `--discussion` + relevance scoring |
| "show me the PRs for ticket 64776" | `--prs` |
| "what pull requests are open for #62345" | `--prs` |
| "any code submitted for ticket 50000?" | `--prs` |

Modes are exclusive. If both `--discussion` and `--prs` are passed, the last one on the command line wins.

## Commands

- Basic info: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` <ticket-number>
- With comments: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --discussion <ticket-number>
- With PRs: !`echo "${CLAUDE_PLUGIN_ROOT}/skills/wp-trac-ticket/scripts/ticket.php"` --prs <ticket-number>

## Output

Basic mode returns: id, component, summary, type, status, milestone, and description.

Discussion mode returns: a list of comments with author and content for each. Attachment and `prbot` (PR-mirror bot) entries are filtered out — use `--prs` for PR data.

PRs mode returns: a list of associated GitHub pull requests sorted with open first then closed, each by most-recent update. For each PR: number, title, state, author, URL, created/updated/closed dates, additions/deletions, whether it touches tests, CI status (when present), and review status.

## Relevance scoring

When the user asks which comments matter, whether a bug is still real, or wants the discussion ranked by relevance, produce the standard `--discussion` output AND a scoring table afterward. Skip for plain "summarize" or "show comments" requests.

**Anchor:** Score each comment against the **ticket description**. If a later comment supersedes the description (stale example, wrong scope), score on the corrected understanding. Adapt naturally for feature requests (read "root cause" as "core design constraint"). Score on substance, not authorship.

**Bands:**

| Band | Meaning |
|------|---------|
| 0.9–1.0 | Landed/maintainer-confirmed resolution OR root-cause naming the mechanism OR methodical testing on current release with explicit resolution claim. |
| 0.7–0.8 | Direct engagement. **0.80** patch+tests, confirmed negative repro on current release with method, scope-narrowing with new evidence, or design-rationale for a landed change. **0.75** repro attempt with new data, proposed fix with stated mechanism, or concrete gap-demonstration. **0.70** concrete rebuttal, substantive technical engagement without resolution, or concrete cross-ref with rationale. |
| 0.5–0.6 | Adjacent: passing cross-reference, retest-ask without performing one, close-suggestion summarizing others, speculative scope-expansion. |
| 0.1–0.4 | Low signal — forward-tracking, milestone bumps with rationale (~0.3–0.4); pure metadata events: keyword-only changes, description edits, slackbot links with no content, bare merge-confirmations (~0.1–0.2). |
| 0.0 | Off-topic, spam. |

Cite the sub-anchor in rationale for any 0.7–0.8 score (e.g., "patch+tests = 0.80", "proposed fix with mechanism = 0.75").

**Output format:** After the discussion section, append:

```
## Relevance scoring

| # | Author | Score | Rationale |
|---|--------|-------|-----------|
| 1 | author | 0.NN | one-line rationale tied to the description |
| ... |
```

Rationales: one line, anchored to the description.
