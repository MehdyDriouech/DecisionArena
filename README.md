# Decision Arena

> **"Ne plus demander une réponse à une IA, mais observer une décision émerger d'un système."**

Decision Arena est un outil **local** de *Decision Intelligence* basé sur des **agents IA**. Plusieurs personas spécialisés (PM, Architecte, Critique, Juriste…) débattent, s'affrontent et votent — vous obtenez une décision argumentée, traçable et auditable, pas une réponse propre et consensuelle.

**Stack :** PHP 8+ · Vanilla JS (ES modules) · SQLite · Markdown — sans framework, sans bundler, sans dépendance externe.

---

## Sommaire

- [Pourquoi Decision Arena ?](#pourquoi-decision-arena-)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Démarrage](#démarrage)
- [Configuration des providers LLM](#configuration-des-providers-llm)
- [Modes d'analyse](#modes-danalyse)
- [Decision Reliability Engine](#decision-reliability-engine)
- [Deliberation Intelligence](#deliberation-intelligence)
- [Fonctionnalités transverses](#fonctionnalités-transverses)
- [Concepts & terminologie](#concepts--terminologie)
- [Architecture](#architecture)
- [Sécurité & limites](#sécurité--limites)
- [Guide de contribution](#guide-de-contribution)
- [Licence](#licence)

---

## Pourquoi Decision Arena ?

La plupart des décisions produit, business ou techniques souffrent des mêmes problèmes :

- **Biais** (confirmation, hiérarchie, intuition non challengée)
- **Pas de contradiction** — un LLM classique produit UNE réponse lissée
- **Peu traçables** — impossible d'auditer le raisonnement a posteriori

Decision Arena répond à ça :

| Ce que vous n'avez pas ailleurs | Ce que Decision Arena apporte |
|---|---|
| Multi-agents contradictoires | ✅ Débat structuré entre personas |
| Votes pondérés | ✅ Décision collective avec seuil configurable |
| Audit du raisonnement | ✅ Graph, heatmap, replay |
| Traçabilité | ✅ Logs, snapshots, exports (avec mode redacted) |
| Rejouabilité | ✅ Rerun avec variations, comparaison de sessions |
| Qualité décisionnelle | ✅ Guardrails, score 0–100, brief décisionnel, timeline, biais, Devil's Advocate, post-mortem |

---

## Prérequis

- **PHP 8.0+** avec les extensions : `pdo_sqlite`, `curl`, `json`
- **Apache** avec `mod_rewrite` activé (AMPPS, XAMPP ou WampServer)
- **Un provider LLM** local ou API-compatible :
  - [Ollama](https://ollama.ai/) (recommandé)
  - [LM Studio](https://lmstudio.ai/)
  - Toute API compatible OpenAI

---

## Installation

### Option A — AMPPS / XAMPP / WampServer (recommandée)

1. Installez l'un de ces packages web locaux :
   - **AMPPS** : https://www.ampps.com/
   - **XAMPP** : https://www.apachefriends.org/
   - **WampServer** : https://www.wampserver.com/

2. Copiez le projet dans le répertoire web :

   | Package | Chemin cible |
   |---|---|
   | AMPPS | `…\www\decision-room-ai\` |
   | XAMPP | `…\htdocs\decision-room-ai\` |
   | WampServer | `…\www\decision-room-ai\` |

3. Installez un provider LLM (voir [Configuration des providers LLM](#configuration-des-providers-llm))

4. Démarrez Apache, puis ouvrez :
   ```
   http://localhost/decision-room-ai/frontend/index.html
   ```

### Option B — Serveur PHP intégré (développement)

```bash
php -S localhost:8000 -t backend/public
```

Puis ouvrez `frontend/index.html` dans votre navigateur.

---

## Démarrage

1. Ouvrez l'application dans votre navigateur
2. Allez dans **Administration → Providers** et ajoutez votre provider LLM
3. Cliquez **Fetch models** pour auto-découvrir les modèles disponibles
4. Créez votre première session via **Nouvelle session**

---

## Configuration des providers LLM

### Ollama (recommandé en local)

```
ID        : local-ollama
Type      : ollama
Base URL  : http://localhost:11434
Model     : qwen2.5:14b  (ou tout autre modèle installé)
```

Télécharger un modèle : `ollama pull qwen2.5:14b`

### LM Studio

```
ID        : local-lmstudio
Type      : lmstudio
Base URL  : http://localhost:1234
Model     : (nom du modèle chargé dans LM Studio)
```

### OpenAI / API compatible

```
Type      : openai-compatible
Base URL  : https://api.openai.com
API Key   : votre clé
Model     : gpt-4o
```

### Routage LLM

Le backend supporte plusieurs stratégies de sélection du provider, configurables dans **Administration → Providers** :

| Mode | Comportement |
|---|---|
| `single-primary` | Utilise toujours le provider principal désigné |
| `preferred-with-fallback` | Provider préféré, puis liste de secours ordonnée |
| `load-balance` | Round-robin sur les providers éligibles |
| `agent-default` | Chaque persona utilise son provider/modèle par défaut |

**Priorité effective par appel LLM :** surcharge par session (provider + modèle par agent, mode Expert) → paramètres explicites → frontmatter persona → réglages globaux.

> ⚠️ Deux providers locaux ne peuvent pas partager la même `base_url`. Cochez la case **local** pour Ollama / LM Studio.

---

## Modes d'analyse

### Fast Decision (preset)

Preset pré-configuré pour décisions rapides et fiables : 4 agents (pm, architect, ux-expert, critic), 2 tours, désaccord forcé, auto-retry si débat faible, blocage si qualité insuffisante. Personnalisable depuis le formulaire Nouvelle session (lien « Personnaliser »).

> Idéal pour : décisions fréquentes ne nécessitant pas de paramétrage manuel.

### Chat multi-agent

Conversation libre avec les agents sélectionnés.

- `@pm` → seul le PM répond
- `@architect @critic` → plusieurs agents ciblés
- *(sans mention)* → tous les agents sélectionnés répondent
- Bouton **Stop** pour annuler une génération en cours

> Idéal pour : exploration rapide, brainstorming, questions ouvertes.

### Decision Room

Analyse structurée en plusieurs tours avec synthèse finale, **follow-up panel**, guardrails de qualité et **brief décisionnel** persisté.

> Idéal pour : décisions importantes nécessitant une analyse approfondie.

### Confrontation (Blue vs Red)

Débat structuré entre une **Blue Team** (défense) et une **Red Team** (attaque), avec synthèse finale.

> Idéal pour : stress test stratégique, challenger une idée avant de l'engager.

### Quick Decision

Analyse rapide (1 tour) + verdict immédiat.

> Idéal pour : arbitrage rapide, première évaluation.

### Stress Test

Mode "robustesse" : les agents attaquent systématiquement la décision pour en exposer les failles.

> Idéal pour : vérifier qu'une décision tient sous pression.

### Jury / Comité

Vote final multi-agents avec décision collective basée sur un seuil de consensus configurable.

> Idéal pour : validation finale, go / no-go.

### Options avancées (modes structurés)

- **Devil's Advocate** : si le consensus partiel dépasse le seuil (défaut `0.65`), un message *advocatus diaboli* est injecté après chaque tour.
- **Provider par agent** (mode Expert) : assigner un provider et modèle différents à chaque agent.
- **Auto-retry** : si le débat est trop faible (score < seuil), une nouvelle ronde est relancée automatiquement.

---

### Disponibilité des fonctionnalités par mode

| Fonctionnalité | Chat | DR | CF | QD | ST | Jury |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Mémoire des arguments | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Positions des agents | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Votes pondérés | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Décision automatique | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Graphe d'interactions | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Guardrails décisionnels | ✗ | ✓ | ✗ | ✗ | ✗ | ✓ |
| Score qualité 0–100 | ✗ | ✓ | ✗ | ✗ | ✗ | ✓ |
| Brief décisionnel persisté | ✗ | ✓ | ✗ | ✗ | ✗ | ✓ |
| Devil's Advocate | ✗ | ✓ | ✓ | ✗ | ✓ | ✓ |
| Seuil configurable | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Export enrichi | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |

> DR = Decision Room · CF = Confrontation · QD = Quick Decision · ST = Stress Test

---

## Decision Reliability Engine

Couche analytique intégrée aux runners Decision Room et Jury. Évalue la fiabilité de chaque décision selon 5 dimensions et persiste le résultat en base pour qu'il survive aux rechargements de page.

### Guardrails décisionnels (`DecisionGuardrailService`)

4 règles appliquées à la fin de chaque run :

| Règle | Condition de déclenchement | Effet |
|---|---|---|
| Contexte insuffisant | `context_quality.level = weak` + champs critiques manquants | `final_outcome → INSUFFICIENT_CONTEXT` |
| Débat trop faible | `debate_quality_score < 40` + densité < 0.2 + pas de désaccord | `auto_retry_triggered` (si option activée) |
| Faux consensus | `false_consensus_risk = high` | `GO_CONFIDENT → GO_QUALIFIED` |
| Auto-retry de vote | après nouvelle ronde, votes re-agrégés | `final_outcome` mis à jour |

### Score de qualité décisionnelle (`DecisionQualityScoreService`)

Score composite 0–100 calculé à partir de :

| Composante | Poids max |
|---|---|
| Qualité du contexte | 25 pts |
| Qualité du débat | 25 pts |
| Rapport d'evidence | 20 pts |
| Profil de risque (inverse) | 15 pts |
| Pénalité faux consensus | −15 pts max |
| Pénalité champs critiques manquants | −5 pts/champ |

Niveaux : **poor** (< 40) · **fragile** (40–64) · **medium** (65–79) · **strong** (≥ 80)

### Brief décisionnel (`DecisionSummaryService::buildDecisionBrief`)

Structure déterministe produite à la fin du run et persistée dans `sessions.decision_brief` :

```json
{
  "decision": "GO",
  "confidence": "high",
  "quality_score": 72,
  "why": "...",
  "main_risks": ["..."],
  "next_step": "..."
}
```

### Persistance

Les champs `result` (JSON) et `decision_brief` (JSON) sont écrits dans la table `sessions` à la fin de chaque run Decision Room / Jury. Lors du rechargement d'une session, `SessionController::show()` lit ces données persistées plutôt que de recalculer depuis zéro (fallback sur recalcul pour les anciennes sessions sans `result`).

### Context Assistant (`ContextCheckController`)

Endpoint `POST /api/context/check` : analyse l'objectif en cours de saisie (debounce 800 ms côté frontend) et retourne des questions de clarification si le contexte est insuffisant. S'affiche sous forme de bannière inline dans le formulaire Nouvelle session.

### Endpoint Decision Summary

`GET /api/sessions/{id}/decision-summary` : retourne le brief, les signaux de fiabilité, la qualité du contexte, les warnings de fiabilité et le résumé décisionnel — sans relancer de run.

---

## Deliberation Intelligence

Panneaux d'analyse disponibles pour toutes les sessions de délibération, via `/api/sessions/{id}/…` :

### Endpoints

| Rôle | Méthode | Chemin | LLM |
|---|---|---|---|
| Résumé décisionnel | GET | `/api/sessions/{id}/decision-summary` | non |
| Scoring personas | GET | `/api/sessions/{id}/persona-scores` | non |
| Timeline de confiance | GET | `/api/sessions/{id}/confidence-timeline` | non |
| Rapport de biais | GET | `/api/sessions/{id}/bias-report` | non |
| Evidence report | GET | `/api/sessions/{id}/evidence-report` | non |
| Profil de risque | GET | `/api/sessions/{id}/risk-profile` | non |
| Audit débat | GET | `/api/sessions/{id}/audit` | non |
| Graphe interactions | GET | `/api/sessions/{id}/graph` | non |
| Heatmap arguments | GET | `/api/sessions/{id}/argument-heatmap` | non |
| Replay | GET | `/api/sessions/{id}/replay` | non |
| Votes | GET | `/api/sessions/{id}/votes` | non |
| Post-mortem | GET, POST | `/api/sessions/{id}/postmortem` | non |
| Stats rétrospective | GET | `/api/postmortems/stats` | non |
| Devil's Advocate (manuel) | POST | `/api/sessions/{id}/devil-advocate/run` | oui |
| Overrides LLM par agent | GET | `/api/sessions/{id}/agent-providers` | — |
| Check contexte | POST | `/api/context/check` | non |

### Graphe d'interactions

Les arêtes sont enregistrées lorsque les messages portent une cible identifiable : bloc `## Target Agent` (priorité) puis assignation round-robin par agent et par tour lorsque le bloc est absent.

### Votes & décision

Le **seuil de consensus** (`sessions.decision_threshold`, défaut `0.55`) est configurable à la création et ajustable après coup depuis l'historique de session (`PUT /api/sessions/{id}/decision-threshold`).

### Scoring des personas

Indicateurs sans LLM : volumétrie, longueur moyenne, citations croisées → score d'influence. Mis en cache en SQLite.

### Timeline de confiance

Courbe par tour : confiance agrégée, position dominante (GO/NO-GO/ITERATE), marqueur consensus précoce/tardif. Affichée en SVG ; détail par tour en mode Expert.

### Détection de biais

Rapport heuristique sans LLM : groupthink, ancrage, confirmation, disponibilité, autorité — avec sévérité, preuve textuelle et recommandation.

### Post-mortem & Rétrospective

Bilan utilisateur sur l'issue réelle d'une décision (correct / partiel / incorrect). Bannière sur sessions > 30 jours sans bilan. **Administration → Rétrospective** agrège les statistiques.

---

## Fonctionnalités transverses

### Rerun intelligent

Relancer une session avec des variations : autre mode, autres agents, autre langue, plus de désaccord forcé, etc.

### Comparaison de sessions

Comparer 2 à 4 sessions côte à côte — artefact Markdown exportable.

### Action Plan

Génération automatique d'un plan d'action depuis la synthèse, enrichissable manuellement, persisté en SQLite.

### Exports

- **Markdown** et **JSON** : messages, verdict, votes, contexte, routing LLM, **brief décisionnel**, **score qualité**, **guardrails**, **auto-retry**
- **Mode redacted** : `?redacted=1` (masque secrets) · `?redacted=strong` (remplace messages par `[REDACTED]`)
- **Snapshots** : capture persistée d'une session

### Templates de session

Sessions pré-configurées (mode, agents, prompt starter). 5 templates système disponibles (seeding via `backend/tools/seed_templates.php`). Créez les vôtres via **Administration → Templates**.

Le formulaire **Nouvelle session** affiche une grille de templates système en haut pour un accès direct.

### Langues (UI)

Interface en **Français** et **Anglais**. Switch via **FR / EN** dans la sidebar.  
La langue des **réponses des agents** est définie par session.

### Complexité UI

Trois niveaux sélectionnables dans la sidebar : **Basique** · **Avancé** · **Expert**. Persisté en localStorage. Les éléments marqués `data-complexity="advanced"` ou `"expert"` sont masqués dans les niveaux inférieurs.

### Logs applicatifs

**Administration → Logs** : journaux LLM, événements UI, erreurs API.

- Rétention automatique : purge des logs > 90 jours (au plus une fois par 24h)
- Contenu des messages LLM remplacé par `[REDACTED: N chars]` avant persistance

---

## Concepts & terminologie

| Terme | Description |
|---|---|
| **Session** | Un "dossier" de conversation + configuration (mode, agents, langue…) |
| **Message** | Message user ou agent, attaché à une session |
| **Persona** | Description d'un agent (Markdown + frontmatter YAML) |
| **Soul** | Style comportemental / personnalité d'un agent |
| **Provider** | Backend LLM (Ollama, LM Studio, OpenAI-compatible) |
| **Template** | Configuration de session pré-remplie |
| **Context Document** | Document injecté dans les prompts de la session |
| **Verdict** | Synthèse structurée basée sur votes et arguments |
| **Snapshot** | Capture persistée de l'état d'une session |
| **Comparison** | Artefact comparant plusieurs sessions |
| **Action Plan** | Plan d'actions généré et enrichi manuellement |
| **Rerun** | Nouvelle session dérivée d'une session existante |
| **Fast Decision** | Preset Decision Room 4 agents, 2 tours, guardrails activés |
| **Guardrails** | Règles bloquantes évaluées après chaque run (contexte, débat, consensus) |
| **Decision Brief** | Résumé décisionnel structuré persisté en base (decision, confidence, why…) |
| **Quality Score** | Score 0–100 composite de fiabilité d'une décision |
| **Devil's Advocate** | Message qui challenge le consensus émergent (hors agrégation de votes) |
| **Timeline de confiance** | Évolution de la confiance et de la position dominante par tour (sans LLM) |
| **Rapport de biais** | Détection heuristique de biais cognitifs dans la structure du débat (sans LLM) |
| **Post-mortem** | Bilan utilisateur sur l'issue réelle d'une décision (correct / partiel / incorrect) |
| **uiComplexity** | Niveau de complexité UI : basic / advanced / expert (persisté localStorage) |
| **DR / CF / QD / ST / LA** | Decision Room / Confrontation / Quick Decision / Stress Test / Launch Assistant |

---

## Architecture

```
decision-room-ai/
├── backend/
│   ├── public/
│   │   ├── index.php               # Entry point (routeur, migrations, CORS)
│   │   └── .htaccess               # mod_rewrite rules
│   ├── config/
│   ├── src/
│   │   ├── Controllers/            # HTTP controllers
│   │   │   ├── ChatController.php
│   │   │   ├── ContextCheckController.php   # POST /api/context/check
│   │   │   ├── DecisionRoomController.php   # persiste result + decision_brief
│   │   │   ├── DecisionSummaryController.php # GET /api/sessions/{id}/decision-summary
│   │   │   ├── JuryController.php           # persiste result + decision_brief
│   │   │   ├── SessionController.php        # read-through result persisté
│   │   │   ├── ExportController.php         # lit result pour brief/guardrails
│   │   │   └── … (30+ controllers)
│   │   ├── Domain/
│   │   │   ├── DecisionReliability/
│   │   │   │   ├── DecisionGuardrailService.php    # 4 règles guardrails
│   │   │   │   ├── DecisionQualityScoreService.php # score 0–100
│   │   │   │   ├── DecisionReliabilityService.php  # enveloppe complète
│   │   │   │   ├── FalseConsensusDetector.php
│   │   │   │   ├── ContextQualityAnalyzer.php
│   │   │   │   ├── ContextClarificationService.php
│   │   │   │   ├── DevilAdvocateTriggerPolicy.php
│   │   │   │   └── ReliabilityConfig.php
│   │   │   ├── Orchestration/
│   │   │   │   ├── DecisionRoomRunner.php  # guardrails + qualityScore + brief + auto-retry
│   │   │   │   ├── JuryRunner.php          # idem
│   │   │   │   ├── PromptBuilder.php       # buildSynthesizerConstraintBlock()
│   │   │   │   ├── DecisionSummaryService.php # buildDecisionBrief()
│   │   │   │   └── … (runners, policies, services)
│   │   │   ├── Evidence/
│   │   │   ├── Risk/
│   │   │   ├── Learning/
│   │   │   ├── SocialDynamics/
│   │   │   ├── Providers/          # ProviderRouter, fallback, multi-LLM
│   │   │   └── …
│   │   ├── Http/                   # Router, Request, Response
│   │   └── Infrastructure/
│   │       ├── Persistence/
│   │       │   ├── Database.php    # singleton PDO SQLite
│   │       │   ├── Migration.php   # migrations idempotentes (addMissingColumns)
│   │       │   ├── SessionRepository.php  # update() générique
│   │       │   └── … (30+ repositories)
│   │       ├── Markdown/
│   │       └── Logging/Logger.php
│   ├── storage/
│   │   ├── database/app.sqlite     # Base SQLite (runtime)
│   │   ├── personas/               # Personas Markdown
│   │   ├── souls/                  # Souls Markdown
│   │   └── prompts/                # Prompts globaux Markdown
│   └── tools/                      # Scripts CLI de test et seeding
│       ├── seed_templates.php
│       ├── test_decision_quality_score.php
│       ├── test_fast_decision_guardrails.php
│       ├── test_synthesizer_constraints.php
│       ├── test_reliability_persistence.php
│       └── … (12+ scripts)
└── frontend/
    ├── index.html
    ├── src/
    │   ├── core/
    │   │   ├── store.js            # état global (uiComplexity, decisionBrief, …)
    │   │   ├── renderer.js         # sidebar + complexité badge/dropdown
    │   │   ├── router.js
    │   │   ├── events.js
    │   │   └── globalHandlers.js   # set-ui-complexity, toggle-complexity-dropdown
    │   ├── services/               # apiClient, sessionService, evidenceService, …
    │   └── features/
    │       ├── newSession/         # Fast Decision preset, template grid, context hint
    │       ├── chat/               # chat classique + Reactive Chat
    │       ├── decisionRoom/
    │       ├── confrontation/
    │       ├── quickDecision/
    │       ├── stressTest/
    │       ├── jury/
    │       ├── sessionHistory/     # brief, guardrails, quality score, reliability
    │       ├── comparisons/
    │       ├── launchAssistant/
    │       └── admin/              # providers, routing, personas, templates, logs, learning
    ├── styles/                     # tokens, layout, components, features
    ├── styles.css
    └── i18n.js                     # Runtime i18n FR/EN (toutes les clés embarquées)
```

### Schéma SQLite — colonnes notables (`sessions`)

| Colonne | Type | Description |
|---|---|---|
| `result` | TEXT NULL | JSON : guardrails, auto_retry, decision_quality_score, adjusted_decision, false_consensus, raw_decision |
| `decision_brief` | TEXT NULL | JSON : brief décisionnel (decision, confidence, quality_score, why, main_risks, next_step) |
| `context_quality_score` | REAL NULL | Score qualité du contexte (0–100) |
| `context_quality_level` | TEXT NULL | weak / medium / strong |
| `reliability_cap` | REAL NULL | Plafond de fiabilité (0.0–1.0) |
| `decision_threshold` | REAL | Seuil de consensus (défaut 0.55) |
| `devil_advocate_enabled` | INTEGER | 0/1 |
| `status` | TEXT | draft / completed |

### Principes

**Backend**
- PHP 8 sans framework, sans Composer
- SQLite (`storage/database/app.sqlite`)
- `ProviderRouter` centralise tous les appels LLM et instrumente les logs
- `addMissingColumns()` dans Migration.php : évolutions de schéma idempotentes et non destructives

**Frontend**
- 100% ES modules natifs (pas de bundler, pas de npm)
- Store global unique (`core/store.js`) — pas de state management externe
- Navigation via `data-nav`, actions via `data-action`
- Chaque feature expose `register<Feature>Feature()` (vues) et `register<Feature>Handlers()` (actions)
- `uiComplexity` (basic/advanced/expert) piloté par `data-complexity` sur les éléments DOM

---

## Sécurité & limites

> ⚠️ **Usage local uniquement — ne pas exposer publiquement.**

- **Pas d'authentification** : application non conçue pour un accès multi-utilisateurs ou réseau
- **Clés API stockées en SQLite** : ne pas publier la base ni partager les exports sans redaction
- **CORS permissif** côté backend (configuration locale/dev)
- **Export redacted** : utilisez `?redacted=1` ou `?redacted=strong` pour les partages externes

---

## Guide de contribution

### Ajouter une feature

1. Créer `frontend/src/features/<feature>/index.js` (vues)
2. Créer `frontend/src/features/<feature>/handlers.js` (actions + listeners)
3. Dans `frontend/src/main.js` :
   - Importer `register<Feature>Feature()` et `register<Feature>Handlers()`
   - Appeler les deux dans `bootstrapModuleArchitecture()`
4. Ajouter les endpoints backend + route dans `public/index.php`
5. Ajouter les traductions dans `frontend/i18n.js` (sections `fr` et `en`)
6. Vérifier le flux complet en UI ; respecter `data-ui="expert-only"` et `data-complexity`

### Ajouter une colonne SQLite

Ne jamais modifier le `CREATE TABLE`. Ajouter uniquement via `$this->addColumnIfMissing(...)` dans `Migration::addMissingColumns()`.

### Scripts de test CLI

```bash
php backend/tools/test_decision_quality_score.php
php backend/tools/test_fast_decision_guardrails.php
php backend/tools/test_synthesizer_constraints.php
php backend/tools/test_reliability_persistence.php
php backend/tools/test_learning_routes_signature.php
php backend/tools/test_reactive_thread_persistence.php
```

---

## Licence

MIT + commercial clause

In short:

- ✅ Personal & educational use: allowed

- ⚠️ Commercial use: requires a separate license

For commercial inquiries:

👉 contact: mehdy.driouech@dawp-engineering.com

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

Contact: [your email here]

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
