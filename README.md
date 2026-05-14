# Decision Arena

> **Ne plus demander une réponse à une IA, mais observer une décision émerger d’un système.**

**Decision Arena** est un outil **local-first** de *Decision Intelligence* : plusieurs agents (personas Markdown) débattent, confrontent leurs positions et produisent une synthèse **auditable** — votes, graphe d’interactions, guardrails, exports.

| | |
|---|---|
| **Stack** | PHP 8+ · Vanilla JS (ES modules) · SQLite · sans framework · sans npm |
| **Cible** | Fondateurs, EM, lead devs, PM/PO, équipes produit/tech — pas uniquement un usage “boardroom enterprise” abstrait |
| **Statut** | **Alpha** — bugs possibles, API et UX encore évolutifs |

**Pitch court :** “Ne plus demander une réponse à une IA, mais observer une décision émerger d’un système.”

Pour une version plus courte et partageable : **[README_marketing.md](README_marketing.md)**.  
Notes de version alpha : **[release_notes_alpha.md](release_notes_alpha.md)**.

---

## Sommaire

1. [Pourquoi Decision Arena ?](#pourquoi-decision-arena)
2. [Fonctionnalités clés](#fonctionnalités-clés)
3. [Cas d’usage](#cas-dusage)
4. [Installation](#installation)
5. [Démarrage rapide](#démarrage-rapide)
6. [Providers LLM](#providers-llm)
7. [Modes d’analyse](#modes-danalyse)
8. [Architecture](#architecture)
9. [Philosophie produit](#philosophie-produit)
10. [Limitations](#limitations)
11. [Roadmap courte](#roadmap-courte)
12. [Contribution](#contribution)
13. [Licence](#licence)

---

## Pourquoi Decision Arena ?

Une réponse unique d’un LLM masque souvent :

- un **faux consensus** interne au modèle ;
- des **hallucinations** présentées avec la même confiance que du solide ;
- une **absence de contradiction** structurée — tout est lissé dans une prose plausible ;
- des **biais mono-agent** (confirmation, sur-généralisation).

Decision Arena ne promet pas “la bonne réponse”. Il propose :

- un **débat structuré** entre rôles explicites (PM, architecte, critique, synthèse, etc.) ;
- des **mécanismes de désaccord** là où c’est pertinent pour votre mode ;
- une **synthèse traçable** : Decision Brief, graphe, votes, reliability / guardrails ;
- une approche **Decision Intelligence** : vous observez *comment* une décision se forme dans le système, pas seulement le paragraphe final.

---

## Fonctionnalités clés

- **Débats multi-agents** — plusieurs personas, tours, mentions ciblées en chat réactif.
- **Decision Room** — analyse multi-tour, synthèse, suivis, garde-fous décisionnels.
- **Founder Sprint** — preset clarification + challenge orienté produit / wedge / validation.
- **CEO Challenge** — preset angle stratégie, moat, risques, priorités implicites.
- **Quick Decision** — boucle courte (usage “décision rapide”), verdict et brief associés.
- **Stress Test** — pression systématique sur la robustesse d’une décision ou d’un plan.
- **Jury / Comité** — vote et seuil de consensus configurables.
- **Confrontation** — Blue Team vs Red Team avec synthèse optionnelle.
- **Decision Brief** — sortie résumée et actionnable liée au run.
- **Validation Logic** — critères de succès et *kill criteria* contre les métriques vanity.
- **Fiabilité & guardrails** — qualité de contexte, faux consensus, scores, avertissements persistés.
- **Exports** — formats enrichis pour archiver et partager (avec options de redaction selon contexte).
- **Providers locaux et cloud** — Ollama, LM Studio, API compatible OpenAI ; routage multi-provider.
- **Personas en Markdown** — définitions versionnables, âmes / dynamiques de décision selon configuration.
- **Local-first** — vos données et votre stack restent chez vous ; pas de dépendance à un SaaS fermé pour fonctionner.

---

## Cas d’usage

Exemples réalistes (libellés en anglais comme souvent en produit) :

| Question | Mode souvent pertinent |
|---|---|
| « Should we ship this feature? » | Quick Decision, Decision Room |
| « Should we pivot? » | Stress Test, Jury, Decision Room |
| « Is this startup idea actually viable? » | **Founder Sprint**, Stress Test |
| « Should we refactor now? » | Stress Test, Confrontation |
| « Should we stay open-source? » | CEO Challenge, Jury |

**Founder Sprint (exemple)** — Idée large → *Founder Interrogation* (pain, ICP, hypothèse critique, wedge) → panel qui réduit le scope et exige des signaux de validation mesurables → Decision Brief + Validation Logic.

**CEO Challenge (exemple)** — Même matière avec angle **stratégie / moat / canal / what NOT to build**, pour éviter une roadmap “tout pour tout le monde”.

---

## Installation

### Prérequis

- **PHP 8.0+** avec `pdo_sqlite`, `curl`, `json`
- **Apache** avec `mod_rewrite` (AMPPS, XAMPP, WampServer), ou serveur PHP intégré pour un essai rapide
- **[Ollama](https://ollama.ai/)** (recommandé en local) ou **[LM Studio](https://lmstudio.ai/)**, ou toute API compatible schéma OpenAI

Pas de Docker obligatoire — déploiement volontairement simple.

### Option A — AMPPS / XAMPP / WampServer

1. Installez votre stack Apache + PHP habituelle.
2. Copiez le dépôt sous le docroot, par ex. `...\www\decision-room-ai\`.
3. Démarrez Apache ; ouvrez **`http://localhost/decision-room-ai/frontend/index.html`** (ajustez le chemin si besoin).

### Option B — Serveur PHP intégré (dev)

```bash
php -S localhost:8000 -t backend/public
```

Ouvrez ensuite `frontend/index.html` dans le navigateur.  
L’URL API par défaut du frontend est documentée dans `frontend/src/services/apiClient.js` (`API_BASE`) — alignez-la sur votre hôte/port si nécessaire.

La base SQLite est créée / migrée côté backend (`backend/storage/database/`).

---

## Démarrage rapide

1. Ouvrez l’application dans le navigateur.
2. **Administration → Providers** : créez au moins un provider (ex. Ollama `http://localhost:11434`).
3. Utilisez **Fetch models** pour renseigner les modèles disponibles.
4. Au **tableau de bord**, **Configurer une analyse** : choisissez un mode, un template ou un *scenario pack*, puis lancez.

---

## Providers LLM

### Ollama (exemple)

```
Type      : ollama
Base URL  : http://localhost:11434
Model     : (modèle installé localement, ex. qwen2.5:14b)
```

### LM Studio (exemple)

```
Type      : lmstudio
Base URL  : http://localhost:1234
Model     : modèle chargé dans LM Studio
```

### API compatible OpenAI

Configurez `openai-compatible` avec base URL, clé et modèle.  
Le routage (priorité, fallback, *load balancing*) est réglable dans l’admin — voir l’UI **Providers**.

**BYOK (clefs dans le navigateur)** — section dédiée dans l’admin pour mémoriser localement des profils OpenAI / Anthropic / Mistral / OpenRouter (`localStorage`) ; les appels sensibles passent via le backend PHP, pas directement depuis le navigateur vers le cloud.

---

## Modes d’analyse

| Mode | Rôle |
|---|---|
| **Chat** | Exploration libre, mentions d’agents (`@pm`). |
| **Decision Room** | Délibération structurée multi-tour + brief + fiabilité avancée. |
| **Quick Decision** | Arbitrage rapide avec synthèse et verdict. |
| **Stress Test** | Stresser la décision / le plan sous angle adversarial. |
| **Confrontation** | Blue vs Red, configuration des équipes et des tours CF. |
| **Jury** | Vote final avec seuil de consensus. |

Presets **Fast Decision** (Decision Room accéléré), **Launch Assistant**, templates et *scenario packs* orientent le paramétrage sans changer l’architecture.

Référence détaillée sur les panneaux (*Deliberation Intelligence*, evidence, risk, etc.) : explorez l’UI après un run — les écrans varient selon le mode et les données persistées.

---

## Architecture

Vue volontairement plate :

- **`frontend/`** — Application **Vanilla JS** découpée par *features* (`frontend/src/features/`), i18n embarquée (`frontend/i18n.js`), pas de bundler imposé.
- **`backend/public/`** — Front controller PHP (routes API).
- **`backend/src/Controllers/`** — HTTP : sessions, runs par mode, admin, exports…
- **`backend/src/Domain/Orchestration/`** — **Runners** (*DecisionRoomRunner*, *QuickDecisionRunner*, *JuryRunner*, *ConfrontationRunner*, *StressTestRunner*, chat réactif…) ; construction de prompts (`PromptBuilder`, etc.).
- **`backend/src/Domain/`** — Logique métier : fiabilité, vote, evidence, personas draft, learning…
- **`backend/src/Infrastructure/Persistence/`** — SQLite, repositories, migrations.
- **`backend/storage/personas/`** — Fichiers personas Markdown + helpers associés.

Pas de “meta-framework” : PHP et JS restent lisibles ligne à ligne.

---

## Decision Memory (navigation & retrieval)

Decision Arena inclut une couche **Decision Memory** orientée **décisions structurées** (pas de chat “mémoire implicite”).

### Navigation primaire (source de vérité)

Le parcours reste:

**Strategic Context → Decision Room / Chain → Decision Memory**

La navigation prime sur toute recherche.

### Recherche déterministe (production) — SQLite FTS5 scoped

- **But**: accélérer la recherche dans des **mémoires structurées** (résumé, risques, next steps…), **pas** du RAG, **pas** une “mémoire conversationnelle”.
- **Index**: table virtuelle `decision_memory_fts` (FTS5, optionnel).
- **Fallback**: si FTS5 indisponible, la recherche retombe en **LIKE**.
- **API**: `GET /api/decision-memories/search` (scopes `context_id` / `room_id`, filtres, ordering déterministe)
- **Safety**:
  - jamais de raw chat / provider output / prompts / reasoning blobs
  - exclusion par défaut des mémoires invalidées/archivées/stale (override expert explicite)

### Similarité sémantique (expérimental) — discovery only (Expert-only)

Optionnel et **secondaire** : aide à découvrir des décisions **similaires** (patterns de risques, hypothèses, pivots), sans remplacer la recherche FTS.

- **Feature flag**: `SEMANTIC_MEMORY_ENABLED=false` par défaut.
- **Stockage**: `decision_memory_embeddings` (SQLite, vecteurs JSON).
- **Provider** (ce lot): `DeterministicFakeEmbeddingProvider` uniquement (pas d’appels externes).
- **API**: `GET /api/decision-memories/similar` (params `memory_id` ou `q`, scopes + filtres).
- **Garanties**:
  - discovery only: **aucune** injection automatique dans les prompts
  - **aucun** auto-link, aucune modification lifecycle/current_state
  - warnings UI obligatoires: “Similarity does not imply correctness.” + “These are prior decision records, not verified facts.”

### Scripts utiles

- Rebuild FTS: `php backend/tools/rebuild_decision_memory_fts.php`
- Rebuild embeddings (expérimental): `php backend/tools/rebuild_decision_memory_embeddings.php`

---

## Philosophie produit

- **Local-first** — données et exécution sous votre contrôle ; pas de dépendance narrative à un cloud opaque.
- **Auditabilité** — graphe, votes, messages, exports : vous pouvez revoir *qui* a plaidé *quoi*.
- **Anti-boîte-noire** — pas de promesse d’oracle ; exposition des limites (contexte, faux consensus, qualité de débat).
- **Contradiction > consensus artificiel** — le désaccord structuré est une fonctionnalité, pas un bug.
- **Pas de framework bloat** — la complexité reste dans le problème (décision), pas dans la stack UI.

---

## Limitations

- **Alpha** — comportements inattendus, régressions possibles.
- **Qualité des modèles** — tout le pipeline est sensible au modèle choisi (respect des formats, profondeur du débat).
- **Prompts** — utiles et itérés, mais jamais “terminés” ; mauvais réglages produisent des sorties faibles.
- **Pas un substitut d’équipe** — pas de responsabilité légale, métier ou humaine ; outil d’aide à penser et à documenter.
- **Parsing** — lorsque les modèles mélangent formats (JSON, Markdown, prose), des garde-fous parsent ou isolent le contenu ; des edge cases restent possibles.

---

## Roadmap courte

Objectifs réalistes, non contractuels :

- Renforcer la **fiabilité perçue** et la calibration par mode.
- Améliorer **replay / comparaison** de sessions.
- **Analytics** locales légères (sans plateforme SaaS).
- **RAG / contexte documentaire** raisonnablement simple si le besoin se confirme.
- Poursuivre la maturation **evidence** et post-mortem dans le cycle décisionnel.

Pas de feuille de route “science-fiction” : pas d’AGI, pas de promesse de remplacer vos décideurs.

---

## Contribution

Les contributions utiles, même modestes, sont les bienvenues :

- **Retours utilisateurs** — usages réels, friction UX, idées de presets.
- **Issues / bugs** — reproduction minimale, environnement (OS, PHP, modèle).
- **Prompts & personas** — proposez des variantes testées sur vos cas.
- **Documentation** — corrections de chemins, exemples, clarifications.
- **Simplification UX** — sans explosion de dépendances ni nouvelle couche abstraite générique.

Ton attendu : technique, direct, vérifiable — éviter le jargon marketing creux.

Pour la **licence commerciale** ou une utilisation hors cadre personnel / éducatif : **mehdy.driouech@dawp-engineering.com**.

---

## Licence

**Decision Arena Restricted License v1.0** (voir le texte complet ci-dessous). En résumé : usage personnel et éducatif autorisé ; usage commercial, redistribution publique et fork sans accord écrit interdits.

Pour toute licence commercielle :

👉 contact : mehdy.driouech@dawp-engineering.com

---

# Decision Arena Restricted License v1.0

Copyright (c) 2026 Mehdy Driouech

---

## 1. Grant of License

Permission is hereby granted to any individual to:

- Use the Software for personal, non-commercial purposes
- Study and modify the source code for personal or educational use
- Run the Software locally for experimentation

---

## 2. Prohibited Uses

The following uses are strictly prohibited without explicit written permission from the Licensor:

### 2.1 Commercial Use

You may NOT:

- Sell the Software or any derivative work
- Offer the Software as a paid service (SaaS, API, platform, etc.)
- Use the Software within a commercial organization or for business purposes
- Integrate the Software into a product or service that generates revenue

---

### 2.2 Redistribution

You may NOT:

- Redistribute the Software in modified or unmodified form
- Publish forks publicly (GitHub, GitLab, etc.)
- Rebrand, white-label, or sublicense the Software

---

### 2.3 Competitive Use

You may NOT:

- Use the Software to build, train, or improve a competing product
- Replicate core concepts, architecture, or features for commercial purposes

---

## 3. Modifications

You may modify the Software for personal use only.

All modifications remain subject to this license.

---

## 4. Commercial Licensing

Commercial usage requires a separate written agreement with the Licensor.

Contact: mehdy.driouech@dawp-engineering.com

---

## 5. Ownership

The Software remains the exclusive property of the Licensor.

This license does not grant any ownership rights.

---

## 6. Termination

This license is automatically terminated if you violate any of its terms.

Upon termination, you must:

- Stop using the Software
- Delete all copies

---

## 7. Disclaimer

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.

---

## 8. Acceptance

By using this Software, you agree to all terms of this license.

