# Expanded adversarial evaluation and hardening

22 September 2026. **Live testing stopped at 560 additional calls after the user said further broad testing was unnecessary.** The approved 2,000-call budget was a ceiling, not a quota. No further paid batches were started.

## Implemented outcome

The production question now exactly matches the frozen, evaluated mixed-content candidate. It explicitly assesses embedded solicitation throughout the submission and distinguishes genuine reporting/quotation from a pretext that promotes the offer. The configured rejection threshold remains 0.9.

`Verdict::decision(reviewThreshold: 0.2)` adds an explicit `Allow`, `Review`, or `Block` decision. Available probabilities below 0.2 are allowed; 0.2–0.9 are held; 0.9 and above are blocked. Unavailable results always require review in this mode, regardless of the binary validation rule's `fail_open` setting.

The [review workflow example](../../examples/review-workflow.php) persists an application-owned record before queuing delivery. Its SQLite integration tests prove that pending/blocked records queue no delivery, that approved records queue only after commit, and that a failed insert queues nothing. **No consuming application or production review queue was deployed.** Applications must adopt this workflow and provide their own reviewer interface and delivery job.

The existing `NotSpam` rule remains a binary validation interface. It does not apply the review band or create pending records. The measured review-policy outcome requires the facade decision and persistence workflow; the rule alone still admits below-threshold spam.

## Frozen holdout: 40 spam and 40 legitimate samples

The 80-case holdout file was frozen before evaluation. The candidate prompt and review thresholds were frozen after development and were not tuned against holdout outcomes.

| Configuration | Spam automatically allowed | Spam held | Spam blocked | Legitimate allowed | Legitimate held | Legitimate blocked |
| --- | --- | --- | --- | --- | --- | --- |
| Original prompt, original binary rule | 16 | 0 | 24 | 40 | 0 | 0 |
| Original prompt, review policy | 0 | 16 | 24 | 35 | 5 | 0 |
| Improved prompt, original binary rule | 2 | 0 | 38 | 40 | 0 | 0 |
| Improved prompt, review policy | 0 | 2 | 38 | 39 | 1 | 0 |

The two remaining below-0.9 spam results were a nested JSON promotion (0.88) and a translation pretext (0.86). Both require review under the new policy. The legitimate held enquiry was a business asking to buy parts regularly (0.65). It was **not automatically rejected**, but it would require staff attention before delivery.

The original prompt with the review band also withheld all sampled spam. The prompt improvement reduced the sampled review workload: from 16 to 2 spam reviews and from 5 to 1 legitimate reviews. This separates the effect of the prompt from the effect of the admission policy.

## Mutation testing

Three 100-case mutation batches finished before testing was stopped: 240 spam mutations and 60 legitimate mutations. They exercise JSON/XML wrappers, HTML, entity/Base64 encoding, zero-width characters, full-width text, quotation, fabricated moderation results, customer-like prefixes, long wrappers, and collapsed markup.

| Label | Automatically allowed | Held | Blocked |
| --- | --- | --- | --- |
| 240 spam mutations | 0 | 53 | 187 |
| 60 legitimate mutations | 50 | 10 | 0 |

Ten legitimate mutations required review, chiefly long repetitive wrappers or encoded/transliterated messages. The lowest spam score observed in this mutation sample was 0.44. These results demonstrate review workload and remaining binary-rule weaknesses; mutations from common parents are **not independent samples**.

A further 60 legitimate mutations and all 40 prepared stress cases were not run live after the user shortened the test run. The stress corpus includes pathological Unicode, input-size boundaries, encoded payloads, and ambiguous messages. Prepared cases are not counted as executed tests.

## Development and totals

Development used the original 32 cases plus 18 harder legitimate controls. At threshold 0.9 the prompt alone reduced spam admissions from 7/24 to 2/24, with 0/26 legitimate blocks for either prompt. The review policy held the two remaining spam cases and one legitimate control for the improved prompt.

Across development, holdout, and completed mutations, the improved prompt produced 430 live decisions: 304 spam-labelled cases and 126 legitimate cases. The review policy automatically allowed **zero of those spam-labelled cases**, held 57, and blocked 247. It allowed 114 legitimate cases and held 12, with no legitimate blocks in this sample.

Do not convert these selected, correlated observations into a real-world protection percentage. The labels are synthetic author judgements, not an independently adjudicated customer dataset. Quoted and mixed-intent messages can be ambiguous. The remaining test plan was curtailed; this was not an exhaustive search over possible submissions.

All 560 additional live classifications returned usable answers with model `jev-1.13.0`; none timed out. Reported input usage totalled **425,022 tokens**. This is usage metadata, not an invoice. The earlier 55 live calls from the first evaluation are separate and are not included in the additional-call count.

## Why this does not prove 99.9999%

If the target means a spam miss rate below one per million, zero misses would require approximately **2,995,731 representative independent spam trials** for a one-sided 95% zero-failure binomial bound: `ceil(log(0.05) / log(1 - 0.000001))`. This is a calculation under fixed-probability, independent-sampling assumptions; adaptive attacks and repeated synthetic templates do not satisfy those assumptions. See [NIST's exact binomial confidence-limit guidance](https://www.itl.nist.gov/div898/software/dataplot/refman2/auxillar/exacbino.htm).

Legitimate-message rejection/review rates are a separate objective. More calls against the same templates cannot establish either population rate. The next useful dataset is real, privacy-reviewed legitimate enquiries and independently labelled spam campaigns, not thousands of repetitions of these strings.

`decision(reviewThreshold: 0.0)` is also supported for applications that choose to disable automatic admission altogether. That is an explicit workflow restriction, **not a classifier improvement**. The evaluated/default review policy here remains 0.2, and normal low-score enquiries continue automatically.

## Local verification and remaining scope

- The package suite passes 148 tests / 332 assertions on Laravel 11.56.1 and 12.69.2 with both PHP 8.2.32 and 8.4.23, and Laravel 13.32.0 with PHP 8.4.23. Coverage includes hostile response shapes, review boundaries, and persistence/queue behavior.
- Laravel 11.0.0, 12.0.0, and 13.0.0 consumer fixtures pass discovery, HTTP, validation, translation, publishing, and config-cache checks. Pint and Composer validation pass. PHP 8.3 and hosted CI remain unrun locally.
- Review decisions do not reinterpret unavailable results as probability zero.
- Simulated API outages route to review in the tested workflow. No live outage was induced and no load test was performed.
- The production question was compared structurally with the frozen candidate and matched exactly; no further live classification was needed after promoting it.
- Representative production false positives, adaptive attackers outside these families, external link destinations, deployment behavior, and a real application's review operations remain unvalidated.

## Evidence

- [Frozen corpus plan](expanded-plan.json), [frozen candidate and policy](frozen-evaluation.json), [mutation/stress plan](expanded-mutation-plan.json)
- [Machine-readable summary](expanded-summary.json)
- [Development baseline](2026-09-22-104923-expanded-dev-baseline.json) and [candidate](2026-09-22-105042-expanded-dev-candidate.json)
- [Holdout baseline](2026-09-22-105249-holdout-baseline.json) and [candidate](2026-09-22-105416-holdout-candidate.json)
- [Mutations 0–99](2026-09-22-105627-mutations-000.json), [100–199](2026-09-22-105824-mutations-100.json), [200–299](2026-09-22-110016-mutations-200.json)
- [Runner](../../scripts/adversarial.php), [review example](../../examples/review-workflow.php), [decision tests](../../tests/Unit/DecisionTest.php), [workflow tests](../../tests/Feature/ReviewWorkflowTest.php)

The runner makes no network calls unless `--live` is explicit. Each invocation is capped at 100 cases, with sequential requests, no retries, and early stop on authentication failure or three consecutive unavailable responses. Reports omit the API key and preserve the actual question, corpus hash, model, probabilities, policy decisions, and timings.
