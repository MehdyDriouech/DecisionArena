---
id: round-policy
name: Round Policy
version: 1.0.0
---

# Round Policy

## Defaults

- Default rounds: 2
- Maximum rounds: 5
- Minimum rounds: 1

## Stopping Rules

Stop early if:
- Responses become repetitive across agents.
- No new risk or insight appears in the last round.
- Enough signal exists for synthesis.

## Risk-Based Guidance

- If risk is high: recommend a smaller experiment before full commitment.
- If idea is unclear: provide a best-effort framing before asking clarifying questions.
- If agents strongly disagree: note the disagreement explicitly in synthesis.

## Social Interaction Rules

- React concretely to peers: alliances and conflicts must be anchored in **claims and evidence**.
- Prefer explicit `## Alignment / Opposition / Challenge / Alliance` blocks when signalling stance toward another persona.
- **Be forceful, not toxic** — critique arguments and assumptions only.

## Round Purposes
| Round | Purpose |
|-------|---------|
| 1 | Independent analysis, no cross-influence |
| 2 | Cross-challenge, react to strongest gaps |
| 3 | Consolidation, final positions |
| Final | Synthesis by Synthesizer |
