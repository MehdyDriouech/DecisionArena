<?php
declare(strict_types=1);

/**
 * Golden synthesis fixtures for the Runtime Reliability Matrix.
 *
 * These are deterministic model-output samples. They represent the shapes the
 * runtime must survive: strong Markdown, terse prose, broken JSON-ish output,
 * and incomplete/weak answers. They are not provider prompts and not product
 * copy; they are parser/outcome regression inputs.
 */
return [
    'provider_matrix' => [
        'ollama' => [
            ['model' => 'llama3.1:8b', 'profile' => 'small local / concise'],
            ['model' => 'qwen2.5:14b', 'profile' => 'local / structured'],
            ['model' => 'mistral:7b', 'profile' => 'local / weak JSON tolerance'],
        ],
        'lmstudio' => [
            ['model' => 'local-model', 'profile' => 'LM Studio default'],
            ['model' => 'qwen2.5-7b-instruct', 'profile' => 'small / verbose drift'],
        ],
        'openai-compatible' => [
            ['model' => 'gpt-4o-mini', 'profile' => 'small commercial / structured'],
            ['model' => 'gpt-4.1', 'profile' => 'large commercial / reasoning'],
            ['model' => 'openrouter/auto', 'profile' => 'router variability'],
        ],
    ],
    'cases' => [
        [
            'id' => 'founder-good-markdown',
            'playbook_id' => 'founder-sprint',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Decision
Proceed with a narrow founder-led validation sprint.

## Confidence
High.

## Why
- The wedge is specific: compliance leads at Series A fintechs with a manual evidence-collection pain.
- The ICP is reachable this week through warm founder channels.

## Risks
- The pain may be annoying but not budget-worthy.
- The wedge may collapse into generic compliance tooling.

## Recommended next actions
- Run five concierge interviews with compliance leads.
- Ask each prospect to rank urgency and name the current workaround.

## Validation logic
Success signal: three prospects ask for a follow-up or share budget owner details.
Validation threshold: 3/5 qualified prospects show urgency.
Failure signal: prospects describe the issue as low-priority admin work.
Kill criteria: kill or pivot if fewer than two prospects accept the pain framing.

## Wedge critique
The wedge is credible only if "evidence collection before audits" stays narrow.

## ICP challenge
Start with fintech compliance leads, not all regulated companies.

## Next experiment
Five-prospect concierge interview sprint with a manual evidence packet mock.
TXT,
        ],
        [
            'id' => 'founder-weak-prose',
            'playbook_id' => 'founder-sprint',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'This sounds promising but the segment is too broad. I would test first. The main risk is weak urgency. Next step: talk to a few target customers and see if they care. Confidence medium.',
        ],
        [
            'id' => 'founder-broken-json',
            'playbook_id' => 'founder-sprint',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => <<<'TXT'
```json
{
 "decision": "GO",
 "confidence": "HIGH",
 "recommended_next_actions": ["Run 5 ICP calls", "Create a concierge mock"],
 "validation_logic": {"success_signal": "3 prospects request follow-up", "kill_criteria": "No urgent pain after 5 calls",},
 "outcomes": {
   "validation_signal": "Prospects volunteer urgency and budget owner",
   "kill_criteria": "No one accepts the problem framing",
   "next_experiment": "Concierge interview sprint"
 }
}
```
TXT,
        ],
        [
            'id' => 'founder-incomplete',
            'playbook_id' => 'founder-sprint',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'Looks good. I would probably proceed.',
        ],

        [
            'id' => 'ceo-good-markdown',
            'playbook_id' => 'ceo-challenge',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Verdict
Proceed with constraints.

## Confidence
Medium.

## Why
- The strategy is coherent, but the distribution bet is still under-proven.
- The leadership decision should narrow scope before funding a full build.

## Strategic assumptions
- Buyers will pay for workflow consolidation rather than another analytics layer.
- Sales can reach economic buyers within one quarter.

## Blind spots
- Integration complexity may be underestimated.
- The buyer's switching cost is not yet quantified.

## Execution risks
- Enterprise onboarding could consume the roadmap.
- Founder-led sales may not translate into a repeatable motion.

## Tradeoff analysis
Choosing speed sacrifices proof of repeatability; choosing research delays the window.

## Leadership decision memo
Approve a constrained pilot budget, require three signed design partners, and revisit scale funding after implementation proof.

## Recommended next actions
- Secure three design partners with written success criteria.
- Build only the integration path needed for those partners.
TXT,
        ],
        [
            'id' => 'ceo-weak-prose',
            'playbook_id' => 'ceo-challenge',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'The CEO answer is to be careful. There are some risks around execution and positioning. I would narrow the plan and review tradeoffs before committing. Confidence is low to medium.',
        ],
        [
            'id' => 'ceo-broken-json',
            'playbook_id' => 'ceo-challenge',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => '{"decision":"ITERATE","confidence":"MEDIUM","why":["Distribution is not proven"],"risks":["Execution risk"],"recommended_next_actions":["Constrain the pilot"],"outcomes":{"blind_spots":"Switching cost unknown","execution_risks":"Integration effort could dominate","tradeoff_analysis":"Speed vs proof","leadership_decision_memo":"Approve only a constrained pilot",},}',
        ],
        [
            'id' => 'ceo-incomplete',
            'playbook_id' => 'ceo-challenge',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'Needs leadership judgment. There are tradeoffs.',
        ],

        [
            'id' => 'stress-good-markdown',
            'playbook_id' => 'stress-test',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Decision
Validate first.

## Confidence
Medium.

## Core hypothesis
Teams will change their weekly planning process if the system reduces coordination time by 30%.

## Failure scenarios
- Managers ignore the tool because it adds another planning surface.
- Data quality is too poor to trust automated recommendations.

## Weakest assumptions
- Teams will invite the tool into existing rituals.
- The integrations provide enough context without manual cleanup.

## Evidence gaps
- No proof that managers would replace the current spreadsheet workflow.
- No proof that recommendations are trusted after one use.

## Pivot kill signals
Kill if three pilot teams refuse to use it after onboarding. Pivot if they only value reporting, not decision support.

## Recommended next actions
- Run a two-week pilot with three teams and measure actual meeting-time reduction.

## Validation logic
Success signal: two teams independently use the recommendation before planning.
Kill criteria: no team uses the output without facilitator prompting.
TXT,
        ],
        [
            'id' => 'stress-weak-prose',
            'playbook_id' => 'stress-test',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'Recommendation: test first before committing. Confidence is medium. The biggest risk is that enterprise buyers will not change procurement behavior. Next step: simulate the rollout with three accounts. Failure scenario: legal review blocks the pilot. Weakest assumption: buyer urgency.',
        ],
        [
            'id' => 'stress-broken-json',
            'playbook_id' => 'stress-test',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => '{"decision":"ITERATE","confidence":"MEDIUM","risks":["workflow adoption"],"recommended_next_actions":["pilot with 3 teams"],"outcomes":{"failure_scenarios":"managers ignore it","weakest_assumptions":"behavior change","evidence_gaps":"no usage proof","pivot_kill_signals":"kill if no team uses it",}',
        ],
        [
            'id' => 'stress-incomplete',
            'playbook_id' => 'stress-test',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'There are risks. More evidence needed.',
        ],

        [
            'id' => 'jury-good-markdown',
            'playbook_id' => 'jury',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Verdict
Proceed with option B.

## Confidence
High.

## Decision options
- Option A: build the full workflow.
- Option B: ship the narrow approval assistant.
- Option C: delay until more research.

## Evaluation criteria
Time to value, reversibility, implementation cost, and evidence strength.

## Pros cons by perspective
Product favors B for focus. Engineering favors B for scope control. Sales favors A but lacks evidence.

## Final recommendation
Choose Option B and explicitly reject the full workflow for this cycle.

## Confidence level
High, because B is reversible and tests the riskiest assumption.

## Recommended next actions
- Create a one-sprint B prototype.
- Define the adoption threshold before launch.

## Validation logic
Success signal: five active users complete approvals unaided.
Kill criteria: users still request the full workflow before using the assistant.
TXT,
        ],
        [
            'id' => 'jury-weak-prose',
            'playbook_id' => 'jury',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'I recommend option B. It seems like the best compromise. There are tradeoffs and some risk. Confidence medium. Next action: prototype it.',
        ],
        [
            'id' => 'jury-broken-json',
            'playbook_id' => 'jury',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => '{"decision":"GO","confidence":"HIGH","recommended_next_actions":["prototype option B"],"outcomes":{"decision_options":"A full, B narrow, C delay","evaluation_criteria":"cost, reversibility, value","final_recommendation":"Option B","confidence_level":"high",},}',
        ],
        [
            'id' => 'jury-incomplete',
            'playbook_id' => 'jury',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'The recommendation is probably B.',
        ],

        [
            'id' => 'confrontation-good-markdown',
            'playbook_id' => 'confrontation',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Decision
Proceed with constraints.

## Confidence
Medium.

## Position A
Ship now: speed matters and the MVP can expose real demand.

## Position B
Delay: the risk is shipping an underdefined workflow that confuses early users.

## Conflict points
- Speed versus clarity.
- Learning from usage versus protecting trust.

## Strongest arguments
The strongest pro argument is reversibility. The strongest contra argument is trust erosion if the first workflow fails.

## Synthesis or decision path
Ship only the reversible slice and hold back irreversible automation until usage data supports it.

## Recommended next actions
- Launch to ten controlled users.
- Block irreversible automation until activation data clears the threshold.

## Validation logic
Success signal: controlled users complete the workflow twice.
Kill criteria: users need manual explanation after onboarding.
TXT,
        ],
        [
            'id' => 'confrontation-weak-prose',
            'playbook_id' => 'confrontation',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'The two positions disagree about speed versus risk. I would choose a middle path and test a smaller version. Confidence medium.',
        ],
        [
            'id' => 'confrontation-broken-json',
            'playbook_id' => 'confrontation',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => '{"decision":"GO","confidence":"MEDIUM","recommended_next_actions":["controlled launch"],"outcomes":{"position_a":"ship now","position_b":"delay for clarity","conflict_points":"speed vs trust","strongest_arguments":"reversibility vs trust erosion","synthesis_or_decision_path":"ship reversible slice",},}',
        ],
        [
            'id' => 'confrontation-incomplete',
            'playbook_id' => 'confrontation',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'Both sides have a point. The answer is somewhere in between.',
        ],

        [
            'id' => 'quick-good-markdown',
            'playbook_id' => 'quick-decision',
            'shape' => 'markdown',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-markdown',
            'content' => <<<'TXT'
## Decision
Proceed with the smaller launch.

## Confidence
High.

## Decision framing
Choose whether to launch the invite-only cohort this week or wait for the full onboarding flow.

## Key constraint
The launch window is 48 hours and engineering capacity is fixed.

## Best available option
Launch the invite-only cohort with manual onboarding.

## Main risk
Support load could spike if instructions are unclear.

## Immediate next action
Send the invite to the first cohort today and assign one owner to support triage.

## Validation logic
Success signal: 20 qualified signups and fewer than five support escalations.
Kill criteria: stop expansion if fewer than five qualified signups arrive.
TXT,
        ],
        [
            'id' => 'quick-weak-prose',
            'playbook_id' => 'quick-decision',
            'shape' => 'prose',
            'quality' => 'weak',
            'provider' => 'fixture',
            'model' => 'golden-prose-weak',
            'content' => 'Proceed with the smaller launch. Main constraint: the deadline. Main risk: support load. Immediate next action: send the invite today. Confidence high.',
        ],
        [
            'id' => 'quick-broken-json',
            'playbook_id' => 'quick-decision',
            'shape' => 'broken_json',
            'quality' => 'good',
            'provider' => 'fixture',
            'model' => 'golden-broken-json',
            'content' => '{"decision":"GO","confidence":"HIGH","recommended_next_actions":["send invite today"],"validation_logic":{"success_signal":"20 signups","kill_criteria":"fewer than five signups",},"outcomes":{"immediate_next_action":"send invite today","key_constraint":"48 hours","best_available_option":"smaller launch","main_risk":"support load",},}',
        ],
        [
            'id' => 'quick-incomplete',
            'playbook_id' => 'quick-decision',
            'shape' => 'incomplete',
            'quality' => 'incomplete',
            'provider' => 'fixture',
            'model' => 'golden-incomplete',
            'content' => 'Do it.',
        ],
    ],
];
