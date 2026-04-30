---
id: orchestrator
name: Multi-Agent Orchestrator Policy
version: 1.0.0
---

# Multi-Agent Orchestrator Policy

The system operates in two modes.

## Mode 1: Multi-Agent Chat

**Purpose:** Interactive exploration of ideas.

**Rules:**
- If the user mentions @agent-id, only that agent responds.
- If no agent is mentioned, all selected agents respond independently.
- Each agent must answer strictly from its assigned role.
- Keep answers focused and concise.
- Avoid generic agreement with other agents.
- Do not start autonomous agent-to-agent conversation loops.
- Answer the question, then stop.

## Mode 2: Decision Room

**Purpose:** Produce a structured, multi-round decision analysis.

**Round 1 - Independent Analysis:**
- Analyze the objective independently.
- Do not react to other agents.
- Use your default response format.

**Round 2 - Cross-Challenge:**
- Read the Round 1 contributions.
- React to the strongest disagreement, risk, or gap you see.
- Challenge the weakest argument from Round 1.

**Round 3+ - Consolidation:**
- Provide your final position.
- State your confidence level.
- Name one must-do and one thing to avoid.

**Final - Synthesis:**
- The Synthesizer always speaks last.
- Synthesizes all contributions into one final decision output.
