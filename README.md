# Decision Arena

> **"Ne plus demander une réponse à une IA, mais observer une décision émerger d'un système."**

Decision Arena est un outil **local** de *Decision Intelligence* basé sur des **agents IA**. Plusieurs personas spécialisés (PM, Architecte, Critique, Juriste…) débattent, s'affrontent et votent — vous obtenez une décision argumentée, traçable et auditable, pas une réponse propre et consensuelle.

**Stack :** PHP 8+ · Vanilla JS (ES modules) · SQLite · Markdown — sans framework, sans bundler, sans dépendance externe.

**Statut du projet :** *alpha* / early stage. Les fonctionnalités et l’API peuvent encore bouger ; **des bugs sont courants**. Toute contribution pour corriger, documenter, tester ou améliorer le projet est **vivement appréciée** (retours, issues, correctifs).

---

## Sommaire

- [Pourquoi Decision Arena ?](#pourquoi-decision-arena-)
- [Nouveautés](#nouveautés)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Démarrage](#démarrage)
- [Configuration des providers LLM](#configuration-des-providers-llm)
- [Modes d'analyse](#modes-danalyse)
- [Decision Reliability Engine](#decision-reliability-engine)
- [Deliberation Intelligence](#deliberation-intelligence)
- [Fonctionnalités transverses](#fonctionnalités-transverses)
  - [Rerun intelligent](#rerun-intelligent)
  - [Human-in-the-loop — conteste](#human-in-the-loop--conteste-utilisateur)
  - [Comparaison de sessions](#comparaison-de-sessions)
  - [Action Plan](#action-plan)
  - [Exports](#exports)
  - [Personas, Souls & Decision Dynamics](#personas-souls--decision-dynamics)
  - [Agent Dynamics Recommendations](#agent-dynamics-recommendations)
  - [Templates de session](#templates-de-session)
  - [Scenario packs](#scenario-packs-parcours-guidés)
  - [Langues](#langues-ui)
  - [Complexité UI](#complexité-ui-deux-systèmes-orthogonaux)
  - [Logs applicatifs](#logs-applicatifs)
  - [Learning & politiques de prompts](#learning--politiques-de-prompts)
- [Concepts & terminologie](#concepts--terminologie)
- [Architecture](#architecture)
- [Sécurité & limites](#sécurité--limites)
- [Guide de contribution](#guide-de-contribution)
- [Tutoriel : utiliser Decision Arena](#tutoriel--utiliser-decision-arena)
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
| Rejouabilité | ✅ Rerun avec variations, comparaison de sessions, **re-run avec contexte de conteste** |
| Human-in-the-loop | ✅ Conteste tracée sur les messages (`meta_json`), intégration evidence **sans modifier** le `support_class`, pénalité qualité bornée, variante avec challenge injecté |
| Qualité décisionnelle | ✅ Guardrails, score 0–100, brief décisionnel, timeline, biais, Devil's Advocate, post-mortem |

---

## Nouveautés

Cette version consolide Decision Arena autour d'un flux plus guidé, plus auditable et plus utile après la décision :

- **Launch Assistant** : assistant de lancement qui recommande le mode, les agents, le nombre de tours et les réglages de départ selon votre intention.
- **Fast Decision** : preset prêt à l'emploi pour obtenir rapidement une décision structurée avec 4 agents, 2 tours, guardrails et auto-retry si le débat est trop faible.
- **Decision Reliability Engine** : score qualité 0–100, guardrails, faux consensus, qualité du contexte et brief décisionnel persisté dans la session.
- **Deliberation Intelligence** : audit du débat, graphe d'interactions, heatmap d'arguments, replay, timeline de confiance, rapport de biais, evidence report et profil de risque.
- **Reactive Chat** : échange multi-tour entre un agent principal et des agents challengers, avec presets `minimal`, `standard`, `intense`, synthèse finale et arrêt anticipé.
- **Human-in-the-loop / conteste** : possibilité de contester une réponse agent, tracer le désaccord, puis relancer une variante avec le contexte du challenge.
- **Context Assistant & Context Document** : analyse du contexte avant lancement, questions de clarification et document de contexte injecté dans les prompts.
- **Scenario packs, templates et builders** : parcours guidés, templates de session, création de personas custom et génération assistée côté admin.
- **Learning, post-mortem et prompt policies** : suivi des performances agents/modes, calibration, export learning et édition encadrée de certaines politiques de prompts.
- **UI modulaire FR/EN** : interface par features ES modules, niveaux `basic / advanced / expert`, sidebar de navigation et traductions embarquées.
- **Bring your own key (BYOK)** : section **Administration → Providers — Bring your own API key** pour enregistrer localement dans le navigateur (`localStorage` / clé `providerSettings`) les clés **OpenAI, Anthropic, Mistral AI, OpenRouter** ; affichage masqué, activation, sauvegarde/suppression, test via `POST /api/providers/models` (proxy PHP, pas d’appel direct navigateur → cloud). Les clés ne sont pas préremplies en clair dans le DOM.
- **Exports session** : prise en charge du rapport **qualité adversariale du jury** dans les exports enrichis ; correctif d’import `JuryAdversarialReportRepository` dans `ExportController`.
- **Navigation** : suppression du bouton redondant « Analyser en 1 clic » sur le tableau de bord ; retrait de l’entrée **Sessions** et de **Nouvelle session** dans la sidebar — accès à la liste via **Tableau de bord → Voir tout** ; nouvelle analyse via **Configurer une analyse** sur le tableau de bord (`goto-new-session`).
- **Administration** : hub regroupé par intention (Setup, Build, Run & Analyze, Avancé), fusion **Templates + scénarios** dans une même vue, hub simple épuré (cartes compactes), sections réservées au mode Expert via `data-ui="expert-only"`.
- **Quick Decision — réouverture** : une session **Décision rapide** déjà exécutée (`completed`) rouvre l’UI avec **résultats rehydratés** (messages `quick-decision`, votes, brief, fiabilité, verdict) à partir de `GET /api/sessions/{id}` et `GET /api/sessions/{id}/verdict`, au lieu d’un écran vide invitant à relancer sans raison.
- **Verdict — action recommandée** : si le modèle insère du JSON ou des *tradeoffs* dans *Recommended Action* (souvent après du texte ou dans un bloc de code Markdown), l’interface **isole le texte** et affiche une **matrice de compromis** lisible ; côté backend, `VerdictParser` **retire les blocs de code** avant persistance pour les nouveaux verdicts.

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
2. Allez dans **Administration → Providers** : créez vos **providers LLM** serveur (Ollama, LM Studio, OpenAI-compatible, etc.) comme d’habitude ; en option, renseignez la section **Bring your own API key** (clés cloud locales au navigateur — visible aussi en mode simple pour la partie principale)
3. Cliquez **Fetch models** sur un provider configuré côté serveur pour auto-découvrir les modèles
4. Créez votre première session depuis le **tableau de bord** (bouton **Configurer une analyse**), avec templates, **scenario packs** ou formulaire libre.

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

### Bring your own API key (BYOK)

Sous **Administration → Providers**, la section *Bring your own API key* permet de stocker **dans le navigateur** (pas en SQLite) jusqu’à quatre profils : **OpenAI**, **Anthropic**, **Mistral AI**, **OpenRouter**. Chaque carte propose : saisie de clé (masquée), activation, sauvegarde, test, suppression ; la **Base URL** reste réservée au **mode Expert** UI (`data-ui="expert-only"`). Les clés ne sont jamais préremplies en clair ; l’aperçu utilise un masque (`••••••••` + quatre derniers caractères). Persistance : **`localStorage`** / clé **`providerSettings`**. Le test appelle **`POST /api/providers/models`** avec le type `openai-compatible` (le serveur PHP relaie la requête vers l’URL configurée).

Les providers **BYOK** sont une couche **préparatoire** distincte des enregistrements SQLite : le **ProviderRouter** et les sessions continuent d’utiliser les providers serveur tant qu’un branchement explicite au BYOK n’est pas prévu.

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
- **Reactive Chat** (preset `minimal` / `standard` / `intense`) : fil structuré multi-tour via `POST /api/chat/reactive`

> Idéal pour : exploration rapide, brainstorming, questions ouvertes.

### Decision Room

Analyse structurée en plusieurs tours avec synthèse finale, **follow-up panel**, guardrails de qualité et **brief décisionnel** persisté.

> Idéal pour : décisions importantes nécessitant une analyse approfondie.

### Confrontation (Blue vs Red)

Débat structuré entre une **Blue Team** (défense) et une **Red Team** (attaque), avec synthèse finale.

> Idéal pour : stress test stratégique, challenger une idée avant de l'engager.

### Quick Decision

Analyse rapide (1 tour d’analyse agents + synthèse) + verdict, votes et brief décisionnel persistés en base.

À la **réouverture** d’une session terminée depuis le tableau de bord, l’app reconstruit l’état d’affichage (`qdResults`) à partir des données API ; le panneau *Verdict* formate l’**action recommandée** (texte + compromis structurés si le modèle a renvoyé du JSON).

> Idéal pour : arbitrage rapide, première évaluation.

### Stress Test

Mode "robustesse" : les agents attaquent systématiquement la décision pour en exposer les failles.

> Idéal pour : vérifier qu'une décision tient sous pression.

### Jury / Comité

Vote final multi-agents avec décision collective basée sur un seuil de consensus configurable.

> Idéal pour : validation finale, go / no-go.

### Launch Assistant

Wizard de recommandation guidant l'utilisateur vers le mode et la configuration optimale selon son intention (explorer / décider / tester).

**Endpoint :** `POST /api/launch-assistant/recommend`

**Flux :** description de l'intention → recommandation (mode, agents, tours, presets) → édition optionnelle → lancement direct.

> Idéal pour : première utilisation, utilisateurs qui ne connaissent pas encore les modes.

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
| Incertitude **conteste utilisateur** | jusqu’à **−20 pts** sur le score final (les classes de support evidence **ne changent pas** ; la contestation est traitée à part) |

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
| Claims evidence | GET | `/api/sessions/{id}/evidence-claims` | non |
| Evidence (recalcul) | POST | `/api/sessions/{id}/evidence/recompute` | oui |
| Profil de risque | GET | `/api/sessions/{id}/risk-profile` | non |
| Profil de risque (recalcul) | POST | `/api/sessions/{id}/risk-profile/recompute` | oui |
| Dynamique sociale (liens) | GET | `/api/sessions/{id}/relationships` | non |
| Dynamique sociale (événements) | GET | `/api/sessions/{id}/relationship-events` | non |
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
| Learning (aperçu) | GET | `/api/learning/overview` | non |
| Learning (agents / modes / calibration) | GET | `/api/learning/agents`, `/modes`, `/calibration` | non |
| Learning (recompute) | POST | `/api/learning/recompute` | non |
| Learning (export) | GET, POST | `/api/learning/export` | non |
| Politiques de prompts | GET, PUT | `/api/prompt-policies`, `/api/prompt-policies/{id}` | — |

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

**Corps JSON** (extrait utile) : `variations[]`, `target_mode`, `language`, `custom_instruction`, `keep_context_document`, et pour intégrer les messages de conteste depuis la session parente : `include_challenge_context` (bool), optionnellement `challenge_summary_fallback` (texte) **uniquement** si aucun message user de challenge n’est trouvé en base — le backend n’injecte **jamais** le verbatim et le fallback ensemble. La nouvelle session peut porter `rerun_reason` incluant `challenge_rerun`.

### Human-in-the-loop — conteste utilisateur

- **UI** : bouton *Contester* sur les réponses agents éligibles ; badges sur le fil (conteste, message contesté, réponse au conteste). Après un message de conteste : *Re-lancer la décision avec ce conteste* (`rerun-with-challenge`) ouvre une variante comme le rerun classique, avec injection du contexte de conteste.
- **API chat** : `POST /api/chat/send` accepte en mode `context_mode: "challenge"` les champs `challenge_origin` (id du message assistant contesté), `challenge_target_agent`, `challenge_level` (`soft` / `firm`). Les métadonnées sont stockées dans `messages.meta_json`.
- **Evidence** : claims marqués `challenge_flag` si liés à un message assistant « challenged » ; claim « message entier » de repli si l’extracteur n’a pas produit de claims ; rapport enrichi (`challenged_claims_count`, etc.). **Pas** de changement de `support_class` pour refléter la contestation — seulement confiance pondérée / métriques / score décisionnel.

### Comparaison de sessions

Comparer 2 à 4 sessions côte à côte — artefact Markdown exportable.

### Action Plan

Génération automatique d'un plan d'action depuis la synthèse, enrichissable manuellement, persisté en SQLite.

### Exports

- **Markdown** et **JSON** : messages, verdict, votes, contexte, routing LLM, **brief décisionnel**, **score qualité**, **guardrails**, **auto-retry** ; lorsque des données existent, section **qualité adversariale du jury** (`jury_adversarial`)
- **Mode redacted** : `?redacted=1` (masque secrets) · `?redacted=strong` (remplace messages par `[REDACTED]`)
- **Snapshots** : capture persistée d'une session

### Personas, Souls & Decision Dynamics

**Personas** (25 au total) :
- 10 personas standard : `pm`, `architect`, `critic`, `dev`, `qa`, `ux-expert`, `analyst`, `po`, `sm`, `synthesizer`
- 10 Six Thinking Hats : `hat-white` (faits), `hat-red` (émotions), `hat-black` (critique), `hat-yellow` (optimisme), `hat-green` (créativité), `hat-blue` (contrôle) + variantes
- Personas custom via **Administration → Personas** (CRUD + auto-generation LLM)

**Souls** : modificateurs de style comportemental superposés à la persona (ex. `critic.soul.md` rend le Critique plus incisif sans changer son rôle). Stockés dans `backend/storage/souls/`.

**Decision Dynamics** : configuration fine du comportement décisionnel par persona — résistance au consensus, sensibilité à l'évidence, tolérance au risque, preset (`balanced`, `contrarian`, `evidence-driven`…). Table `persona_decision_dynamics`.

### Agent Dynamics Recommendations

Analyse les patterns de comportement des agents sur les sessions passées et génère des suggestions d'optimisation appliquables depuis **Administration → Dynamiques agentes**.

**Endpoints :**
- `GET /api/analysis/agent-dynamics-suggestions` — recommandations calculées
- `POST /api/analysis/agent-dynamics-suggestions/apply` — application d'une suggestion

### Templates de session

Sessions pré-configurées (mode, agents, prompt starter). 5 templates système disponibles (seeding via `backend/tools/seed_templates.php`). Créez les vôtres via **Administration → Templates**.

Le formulaire **Nouvelle session** affiche une grille de templates système en haut pour un accès direct.

### Scenario packs (parcours guidés)

Presets orientés **profil / cas d’usage** (mode recommandé, personas, tours, seuil, etc.), stockés en SQLite (`scenario_persona_packs`). Grille sur **Nouvelle session** + gestion complète sous **Administration → Scenario packs** (CRUD custom, duplication des packs système). Préremplissage du formulaire via `POST /api/sessions/from-scenario-pack` ou `POST /api/scenario-packs/prefill` (même logique : retour de config, **sans** création de session).

### Langues (UI)

Interface en **Français** et **Anglais**. Switch via **FR / EN** dans la sidebar.  
La langue des **réponses des agents** est définie par session.

### Complexité UI (deux systèmes orthogonaux)

**uiMode** (toggle rapide, sidebar) — sans persistance :
- `simple` : affichage épuré, options avancées masquées
- `expert` : accès complet à tous les réglages

**uiComplexity** (profondeur globale, persisté en `localStorage`) :
- `basic` : ~60% des options masquées, lecture centrée sur la décision
- `advanced` : par défaut — équilibre productivité / contrôle
- `expert` : accès total (thresholds, persona editor, badges provider, statistiques)

Les deux systèmes sont **indépendants**. `uiComplexity` contrôle la visibilité via `data-ui-min="advanced|expert"` sur les éléments DOM. Sélectionnable via le badge + dropdown dans la sidebar.

### Logs applicatifs

**Administration → Logs** : journaux LLM, événements UI, erreurs API.

- Rétention automatique : purge des logs > 90 jours (au plus une fois par 24h)
- Contenu des messages LLM remplacé par `[REDACTED: N chars]` avant persistance

### Learning & politiques de prompts

- **Learning** (**Administration → Learning**) : métriques agrégées sur agents et modes, calibration, export Markdown/JSON (`GET` et `POST` `/api/learning/export`).
- **Politiques de prompts** : réglages persistés exposés par `GET/PUT /api/prompt-policies/{id}` (édition côté admin selon l’UI).

---

## Concepts & terminologie

| Terme | Description |
|---|---|
| **Session** | Un "dossier" de conversation + configuration (mode, agents, langue…) |
| **Message** | Message user ou agent, attaché à une session ; peut inclure **`meta_json`** (ex. fil conteste, statut `challenged`, réponse au conteste) |
| **Conteste (HITL)** | Désaccord utilisateur tracé sur les messages et l’evidence **sans** réécrire la vérité machine (`support_class`) |
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
| **Scenario pack** | Parcours guidé (recommandations mode/agents/tours…) — distinct d’un *template* de session |
| **Reactive Chat** | Variante de chat multi-tour avec presets (`minimal` / `standard` / `intense`) et persistance des métadonnées de fil |
| **BYOK** | *Bring your own API key* : clés API cloud stockées localement navigateur (`localStorage` `providerSettings`) depuis Admin → Providers ; distinct des providers SQLite |
| **challenge_rerun** | Motif de rerun : la variante a été créée avec `include_challenge_context` (voir bannière dans l’historique de session) |

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
│   │   │   ├── ChatController.php              # send (+ challenge HITL) + reactive
│   │   │   ├── ContextCheckController.php      # POST /api/context/check
│   │   │   ├── DecisionRoomController.php      # persiste result + decision_brief
│   │   │   ├── DecisionSummaryController.php   # GET /api/sessions/{id}/decision-summary
│   │   │   ├── JuryController.php              # persiste result + decision_brief
│   │   │   ├── SessionController.php           # read-through result persisté
│   │   │   ├── ExportController.php            # exports MD/JSON (+ reliability, jury_adversarial…)
│   │   │   ├── AgentDynamicsRecommendationController.php  # GET …/suggestions + POST …/apply
│   │   │   ├── LaunchAssistantController.php   # POST /api/launch-assistant/recommend
│   │   │   ├── ScenarioPackController.php
│   │   │   ├── EvidenceController.php
│   │   │   ├── RiskProfileController.php
│   │   │   ├── SocialDynamicsController.php
│   │   │   ├── LearningController.php
│   │   │   ├── PromptPolicyController.php
│   │   │   └── … (40 contrôleurs au total)
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
│   │   │   ├── Prompts/            # politiques éditables (ex. social-dynamics-policy)
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
│       ├── test_learning_export.php
│       ├── test_learning_routes_signature.php
│       ├── test_learning_layer.php
│       ├── test_reactive_thread_persistence.php
│       ├── test_evidence_layer.php
│       ├── test_risk_layer.php
│       ├── test_prompt_policy_service.php
│       ├── test_jury_adversarial.php
│       ├── test_jury_adversarial_report.php
│       ├── smoke_guardrails.php
│       ├── reliability_scenarios_manual.php
│       ├── social_dynamics_scenarios_manual.php
│       └── …
└── frontend/
    ├── index.html
    ├── src/
    │   ├── core/
    │   │   ├── store.js            # état global (uiComplexity, decisionBrief, providerSettings BYOK, …)
    │   │   ├── renderer.js         # sidebar + complexité badge/dropdown
    │   │   ├── router.js
    │   │   ├── events.js
    │   │   └── globalHandlers.js   # langue, clear-error, set-ui-mode, set-ui-complexity, toggle-complexity-dropdown
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
    │       └── admin/              # providers, routing, personas, templates, scenario packs, logs, learning, prompt policies
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
| `run_status` | TEXT NULL | JSON : état d’avancement des runs longs (poll possible via `GET …/run-status`) |
| `devil_advocate_enabled` | INTEGER | 0/1 |
| `status` | TEXT | draft / completed |

### Autres tables notables

| Table | Description |
|---|---|
| `context_document_chunks` | Chunks indexables du document de contexte (offset, content) |
| `context_document_chunks_fts` | Table virtuelle SQLite FTS5 — recherche plein texte sur les chunks |
| `agent_relationships` + `relationship_events` | Dynamique sociale entre agents (affinité, confiance, conflits) |
| `persona_decision_dynamics` | Configuration comportementale par persona (consensus_resistance, risk_tolerance…) |
| `jury_adversarial_reports` | Rapport qualité Jury (debate_quality_score, challenge_count, minority_report_present) |
| `learning_insights_cache` | Cache des métriques Learning par scope/agent/mode |
| `session_agent_providers` | Overrides provider/modèle par agent et par session |
| `session_risk_profiles` | Profil de risque persisté (risk_level, reversibility, recommended_threshold) |
| `evidence_reports` + `evidence_claims` | Claims structurés extraits des débats + rapport agrégé |
| `session_postmortems` | Bilan utilisateur post-décision (outcome, confidence, notes) |
| `app_settings` | Paramètres applicatifs clé/valeur (ex. `last_log_purge`) |

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

Le projet est en **phase alpha** : les retours d’expérience, les signalements de bugs et les petites PR ciblées sont particulièrement utiles.

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
php backend/tools/test_learning_export.php
php backend/tools/test_learning_layer.php
php backend/tools/test_evidence_layer.php
php backend/tools/test_risk_layer.php
php backend/tools/test_prompt_policy_service.php
php backend/tools/test_jury_adversarial.php
php backend/tools/test_jury_adversarial_report.php
php backend/tools/smoke_guardrails.php
php backend/tools/seed_templates.php
# manuels / scénarios
php backend/tools/reliability_scenarios_manual.php
php backend/tools/social_dynamics_scenarios_manual.php
```

---

## Tutoriel : utiliser Decision Arena

Ce tutoriel part d'une installation locale fonctionnelle et montre comment passer d'une question floue à une décision exploitable.

### 1. Ouvrir l'application

Ouvrez l'interface frontend :

```text
http://localhost/decision-room-ai/frontend/index.html
```

Si vous utilisez le serveur PHP intégré, gardez le backend lancé avec :

```bash
php -S localhost:8000 -t backend/public
```

### 2. Connecter un provider LLM

Allez dans **Administration → Providers**.

1. Ajoutez un provider : Ollama, LM Studio ou API compatible OpenAI.
2. Cliquez **Fetch models** pour découvrir les modèles disponibles.
3. Testez le provider.
4. Choisissez la stratégie de routage : provider unique, fallback, load-balancing ou provider par agent.

Pour démarrer simplement en local, utilisez Ollama avec `http://localhost:11434` et un modèle déjà installé.

### 3. Préparer la décision

Dans **Nouvelle session**, décrivez la décision à prendre avec le plus de contexte possible :

- l'objectif ;
- les contraintes ;
- les options déjà envisagées ;
- les risques connus ;
- les critères de réussite ;
- les informations qui ne doivent pas être supposées.

Le **Context Assistant** peut signaler un contexte faible et proposer des questions de clarification. Si vous avez un brief, une note produit ou un document de cadrage, ajoutez-le comme **Context Document** pour qu'il soit injecté dans les prompts.

### 4. Choisir le bon parcours

| Besoin | Parcours recommandé |
|---|---|
| Vous découvrez l'outil | **Launch Assistant** |
| Décision rapide mais structurée | **Fast Decision** |
| Analyse approfondie avec score qualité | **Decision Room** |
| Débat libre avec mentions `@agent` | **Chat multi-agent** |
| Challenger fortement une idée | **Confrontation** ou **Stress Test** |
| Obtenir un vote collectif final | **Jury / Comité** |
| Comparer plusieurs hypothèses | **Rerun** puis **Comparaison de sessions** |

Les **templates** et **scenario packs** préremplissent les agents, le mode, les tours et certains seuils. Ils sont pratiques pour répéter un même type d'analyse.

### 5. Configurer les agents

Sélectionnez les personas qui doivent participer au raisonnement : PM, Architecte, Critique, QA, UX, Analyste, Synthesizer, Six Thinking Hats, etc.

En mode avancé ou expert, vous pouvez aussi régler :

- le nombre de tours ;
- le seuil de consensus ;
- le désaccord forcé ;
- le Devil's Advocate ;
- le provider ou modèle par agent ;
- les Decision Dynamics : tolérance au risque, résistance au consensus, sensibilité à l'évidence.

### 6. Lancer et lire la session

Lancez le mode choisi, puis suivez les messages agent par agent. Selon le parcours, Decision Arena produit :

- des arguments et contre-arguments ;
- une synthèse ;
- des votes pondérés ;
- un verdict ;
- un brief décisionnel ;
- un score qualité 0–100 ;
- des warnings de fiabilité ;
- un plan d'action générable.

Le résultat important n'est pas seulement la réponse finale : regardez aussi les désaccords, les risques non résolus, les signaux de faux consensus et les hypothèses faibles.

### 7. Auditer la décision

Depuis l'historique de session, utilisez les panneaux d'analyse :

| Panneau | Utilité |
|---|---|
| **Decision Summary** | Lire la décision, la confiance, les risques majeurs et la prochaine étape |
| **Evidence** | Voir les claims extraits et leur niveau de support |
| **Risk Profile** | Comprendre le niveau de risque et la réversibilité |
| **Bias Report** | Repérer groupthink, ancrage, confirmation ou autorité |
| **Confidence Timeline** | Observer l'évolution du consensus par tour |
| **Graph / Heatmap / Replay** | Inspecter les interactions, arguments forts et déroulé du débat |
| **Post-mortem** | Renseigner plus tard si la décision réelle était correcte, partielle ou incorrecte |

### 8. Contester, relancer, comparer

Si une réponse agent est discutable, cliquez **Contester**. La conteste est tracée dans les métadonnées du message et peut être utilisée pour relancer une variante via **Re-lancer la décision avec ce conteste**.

Pour explorer plusieurs chemins :

1. Lancez un **Rerun** avec d'autres agents, une autre langue, plus de contradiction ou un autre mode.
2. Comparez 2 à 4 sessions dans **Comparaison de sessions**.
3. Exportez le résultat en Markdown ou JSON.
4. Utilisez `?redacted=1` ou `?redacted=strong` avant de partager un export externe.

### 9. Administrer et améliorer l'outil

Les écrans d'administration servent à maintenir le système :

- **Providers** : gérer les LLM SQLite, modèles, routage et fallback ; section **Bring your own API key** pour mémoriser des clés cloud dans le navigateur (OpenAI / Anthropic / Mistral / OpenRouter).
- **Personas** : consulter, créer ou générer des agents custom.
- **Templates** : préparer des sessions réutilisables.
- **Scenario packs** : créer des parcours guidés par cas d'usage.
- **Learning** : analyser les performances par agent et par mode.
- **Prompt policies** : ajuster les politiques de prompts exposées par l'application.
- **Logs** : diagnostiquer les appels LLM, erreurs backend et événements frontend.

---

## Licence

**Decision Arena Restricted License v1.0** (voir le texte complet ci-dessous). En résumé : usage personnel et éducatif autorisé ; usage commercial, redistribution publique et fork sans accord écrit interdits.

Pour toute licence commerciale :

👉 contact : mehdy.driouech@dawp-engineering.com

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
