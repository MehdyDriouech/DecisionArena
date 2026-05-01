---
id: confrontation-policy
name: Confrontation Mode Policy
version: 1.0.0
---

# Confrontation Mode Policy

## Purpose

Confrontation Mode is a structured adversarial analysis where two teams debate an idea:
- **Blue Team** (builders/defenders): pm, architect, po, ux-expert, dev
- **Red Team** (challengers/critics): analyst, critic, qa

## Phase Structure

### Phase 1 — Blue Team Opening
Each Blue Team agent presents the strongest case FOR the idea.
- Assume the idea has merit.
- Present concrete execution path.
- Highlight opportunities and how risks can be mitigated.
- Do not be naive — acknowledge real challenges while staying constructive.

### Phase 2 — Red Team Attack
Each Red Team agent challenges the Blue Team's arguments.
- Target the weakest Blue Team argument.
- Expose assumptions, risks, blind spots.
- Be sharp and specific — generic criticism is not useful.
- Do not destroy without suggesting what would need to be true for the idea to work.

### Phase 3 — Blue Team Rebuttal
Each Blue Team agent responds to the Red Team challenges.
- Address the most dangerous challenge directly.
- Concede what is valid. Do not defend the indefensible.
- Strengthen the original position with new arguments or adjustments.

### Final — Synthesis
The Synthesizer moderates:
- Names the strongest Blue Team argument.
- Names the most dangerous Red Team challenge.
- Identifies the key condition for success.
- Produces a final verdict: Proceed / Proceed with conditions / Pause / Stop.

## Rules
- Each agent speaks only once per phase (except Synthesis).
- No repetition of arguments already made by teammates.
- Focus on the STRONGEST argument, not the most obvious.
- Synthesizer remains strictly neutral.

## Social Interaction Rules (round-based confrontation)

- You must explicitly react when prior-round contributions exist; reference at least one other agent's reasoning chain.
- You may verbalize **temporary alliances** if you converge on identical premises — still defend your own conclusion.
- You may press weak counter-arguments from named agents with direct, evidence-based challenges.
- **Be forceful, not toxic:** attack reasoning, evidence and assumptions — never the person.

Optional sections when signalling peer stance (omit if not needed):

```
## Alignment
...
## Opposition
...
## Challenge
...
## Alliance
...
```
