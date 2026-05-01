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
- [Deliberation Intelligence](#deliberation-intelligence)
  - [Endpoints v2](#endpoints-v2)
- [Fonctionnalités transverses](#fonctionnalités-transverses)
- [Concepts & terminologie](#concepts--terminologie)
- [Architecture](#architecture)
- [Sécurité & limites](#sécurité--limites)
- [Guide de contribution](#guide-de-contribution)
- [Roadmap technique](#roadmap-technique)
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
| Traçabilité | ✅ Logs, snapshots, exports |
| Rejouabilité | ✅ Rerun avec variations, comparaison de sessions |
| Qualité décisionnelle | ✅ Scoring des personas, timeline de confiance, détection de biais (heuristiques), Devil's Advocate, post-mortem |

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

### Option A — AMPPS / XAMPP / WampServer (recommandée, sans commande)

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

Démarrez le serveur local depuis l'interface LM Studio avant de tester.

### OpenAI / API compatible

```
Type      : openai-compatible
Base URL  : https://api.openai.com
API Key   : votre clé
Model     : gpt-4o  (ou équivalent)
```

### Routage LLM

Le backend supporte plusieurs stratégies de sélection du provider, configurables dans **Administration → Providers** :

| Mode | Comportement |
|---|---|
| `single-primary` | Utilise toujours le provider principal désigné |
| `preferred-with-fallback` | Provider préféré, puis liste de secours ordonnée |
| `load-balance` | Round-robin sur les providers éligibles |
| `agent-default` | Chaque persona utilise son provider/modèle par défaut |

**Priorité effective par appel LLM :** surcharge par session (**Nouvelle session**, mode Expert : provider + modèle par agent) → paramètres explicités → frontmatter persona (`provider_id` / `model`) → réglages de routage globaux.

> ⚠️ Deux providers locaux ne peuvent pas partager la même `base_url`. Cochez la case **local** pour Ollama / LM Studio.

Le badge **Routing** dans l'historique de session affiche le mode actif au moment de la session.

---

## Modes d'analyse

### Chat multi-agent

Conversation libre avec les agents sélectionnés.

- `@pm` → seul le PM répond
- `@architect @critic` → plusieurs agents ciblés
- *(sans mention)* → tous les agents sélectionnés répondent
- Bouton **Stop** pour annuler une génération en cours

> Idéal pour : exploration rapide, brainstorming, questions ouvertes.

### Decision Room

Analyse structurée en plusieurs tours avec synthèse finale et **follow-up panel**.

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

### Options « Nouvelle session » (modes structurés)

- **Devil's Advocate** (toujours visible sur les modes concernés) : après chaque tour, si le consensus **partiel** dépasse le seuil (défaut `0.65`), un message **advocatus diaboli** est injecté (un appel LLM par déclenchement, hors pool de votes). Le seuil est réglable en **mode Expert**.
- **Provider par agent** (mode Expert uniquement) : assigner un provider et un modèle différent à chaque agent pour des désaccords **réels** au niveau modèle.

---

### Disponibilité des fonctionnalités par mode

| Fonctionnalité | Chat | Decision Room | Confrontation | Quick Decision | Stress Test | Jury |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Mémoire des arguments | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Positions des agents | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Votes pondérés | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Décision automatique | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Graphe d'interactions | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Heatmap d'arguments | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Replay | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Audit qualité | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Scoring personas / timeline confiance / biais | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Devil's Advocate (si activé à la création) | ✗ | ✓ | ✓ | ✗* | ✓ | ✓ |
| Seuil de consensus configurable | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Export enrichi | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |

\* Quick Decision : un seul tour — le mécanisme par tour peut ne pas s'appliquer comme sur les autres modes.

> **Note :** le mode Chat ne persiste pas de données de délibération (pas d'arguments structurés, pas de votes). Les panneaux Deliberation Intelligence s'affichent en mode "notice" pour les sessions chat.

---

## Deliberation Intelligence

Ensemble de panneaux d'analyse disponibles pour toutes les sessions de délibération, via `/api/sessions/{id}/…` :

### Endpoints v2

| Rôle | Méthode | Chemin | LLM |
|---|---|---|---|
| Scoring personas | GET | `/api/sessions/{id}/persona-scores` | non |
| Timeline de confiance | GET | `/api/sessions/{id}/confidence-timeline` | non |
| Rapport de biais (heuristiques) | GET | `/api/sessions/{id}/bias-report` | non |
| Devil's Advocate (déclenchement manuel) | POST | `/api/sessions/{id}/devil-advocate/run` | oui |
| Post-mortem (lecture / enregistrement) | GET, POST | `/api/sessions/{id}/postmortem` | non |
| Statistiques rétrospectives (admin) | GET | `/api/postmortems/stats` | non |
| Overrides provider/modèle par agent | GET | `/api/sessions/{id}/agent-providers` | — |

Les **endpoints « classiques »** restent disponibles sous le même préfixe : `audit`, `graph`, `argument-heatmap`, `replay`, `votes`, ainsi que `PUT /api/sessions/{id}/decision-threshold`.

### Audit du débat
`GET /api/sessions/{id}/audit`

Évalue la qualité des échanges, détecte les biais et mesure la profondeur du raisonnement.

### Graphe d'interactions
`GET /api/sessions/{id}/graph`

Visualise qui parle à qui, l'influence relative des agents et la structure du débat.

Les arêtes (`interaction_edges`) sont enregistrées lorsque les messages des modes structurés (Decision Room, Confrontation rounds, Stress Test, Jury…) portent une **cible identifiable** :

- bloc **`## Target Agent`** — id d’agent (priorité aux déclarations explicites du LLM lorsqu’elles sont valides pour le tour concerné),
- puis **assignation round-robin** par agent et par tour lorsque le bloc est absent (répartit les défis entre plusieurs interlocuteurs au lieu que tout converge vers « le dernier qui a parlé »).

Sans cible résolue pour un message donné, aucune arête n’est créée pour ce message.

### Heatmap des arguments
`GET /api/sessions/{id}/argument-heatmap`

Identifie les arguments dominants, détecte les zones de consensus et de divergence.

### Replay
`GET /api/sessions/{id}/replay`

Rejoue la timeline du débat pour comprendre l'évolution des positions.

### Votes & décision
`GET /api/sessions/{id}/votes`

Votes pondérés par agent + décision automatique (`GO` / `NO-GO` / `ITERATE`) basée sur le seuil.

Le **seuil de consensus** (`sessions.decision_threshold`, défaut `0.55`) est configurable à la création et ajustable après coup depuis l'historique de session (recalcul à la demande via `PUT /api/sessions/{id}/decision-threshold`).

### Scoring des personas (`GET /api/sessions/{id}/persona-scores`)

Indicateurs **sans LLM** : volumétrie des messages, longueur moyenne, citations croisées entre agents → score d’influence, badge dominant (actif / modéré / passif). Résultats mis en cache en SQLite jusqu’à l’arrivée de nouveaux messages.

### Timeline de confiance (`GET /api/sessions/{id}/confidence-timeline`)

Courbe **par tour** : confiance agrégée (signaux lexical + votes sur le dernier tour), position dominante (GO / NO-GO / ITERATE), marqueur de consensus précoce / tardif. Affichée en SVG côté historique ; détail par tour en **mode Expert**.

### Détection de biais (`GET /api/sessions/{id}/bias-report`)

Rapport **heuristique** (sans LLM) : groupthink, ancrage, confirmation, disponibilité, autorité — avec sévérité, preuve textuelle et recommandation. Mis en cache tant qu’aucun nouveau message.

### Devil's Advocate — déclenchement manuel

`POST /api/sessions/{id}/devil-advocate/run` (corps : `current_round`, `partial_confidence`) pour forcer ou tester une intervention ; prompt Markdown : `backend/storage/prompts/devil_advocate.md`.

### Post-mortem & rétrospective

- `GET|POST /api/sessions/{id}/postmortem` — bilan utilisateur (correct / partiel / incorrect, confiance rétrospective, notes).
- `GET /api/postmortems/stats` — agrégats (totaux, par mode, par agent présent dans la session).

Dans l’UI : **bannière** sur les sessions de plus de 30 jours sans bilan ; **badge** sur la carte session ; vue **Administration → Rétrospective** avec graphiques simples.

### Overrides LLM par session

`GET /api/sessions/{id}/agent-providers` — surcharge par agent enregistrée à la création (`agent_providers` sur `POST /api/sessions`).

---

## Fonctionnalités transverses

### Rerun intelligent

Relancer une session avec des variations : autre mode, autres agents, autre langue, plus de désaccord forcé, etc.

### Comparaison de sessions

Comparer 2 à 4 sessions côte à côte — artefact Markdown exportable.

### Action Plan

Génération automatique d'un plan d'action depuis la synthèse, enrichissable manuellement, persisté en SQLite.

### Post-mortem & Rétrospective

Après plusieurs semaines, vous pouvez **noter l’issue réelle** d’une décision (correct / partiel / incorrect) avec un niveau de confiance rétrospective. Les sessions listées peuvent afficher un **badge** de résultat. **Administration → Rétrospective** agrège les statistiques (totaux, par mode, par agent). Aucune génération LLM.

### Exports

- **Markdown** et **JSON** : messages, verdict, votes, contexte, routing LLM
- **Snapshots** : capture persistée d'une session pour archivage ou debug

### Personas, Souls & Prompts

- **Personas** : `backend/storage/personas/` (frontmatter YAML + corps Markdown)
- **Souls** : `backend/storage/souls/` (style comportemental de l'agent)
- **Prompts globaux** : `backend/storage/prompts/`

Créez et éditez via **Administration** ou en déposant directement des fichiers Markdown.

### Templates de session

Sessions pré-configurées (mode, agents, prompt starter). Créez les vôtres depuis **Administration → Templates**.

### Langues (UI)

Interface disponible en **Français** et **Anglais**. Switch via **FR / EN** dans la sidebar.  
La langue des **réponses des agents** est définie par session (formulaire "Nouvelle session").

Fichiers i18n : `frontend/i18n.js` (traductions embarquées **fr** / **en** ; clés `persona.score.*`, `timeline.*`, `devil.advocate.*`, `agent.provider.*`, `postmortem.*`, `bias.*`, etc.)

### Logs applicatifs

**Administration → Logs** : journaux LLM (prompts, réponses, routing), événements UI, erreurs API.

- Rétention automatique : purge des logs > 90 jours (au plus une fois par 24h)
- Le contenu des messages LLM est systématiquement remplacé par `[REDACTED: N chars]` avant persistance

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
| **DR / CF / QD / ST / LA** | Decision Room / Confrontation / Quick Decision / Stress Test / Launch Assistant |
| **Devil's Advocate** | Message qui challenge le consensus émergent (hors agrégation de votes) |
| **Timeline de confiance** | Évolution de la « confiance » et de la position dominante par tour (analyse sans LLM) |
| **Rapport de biais** | Détection heuristique de biais cognitifs dans la structure du débat (sans LLM) |
| **Post-mortem** | Bilan utilisateur sur l’issue réelle d’une décision (correct / partiel / incorrect) |
| **Cible d’interaction (`## Target Agent`)** | Marqueur en tête de réponse agent utilisé backend pour rattacher défis et arêtes du graphe ; peut être combiné avec une assignation automatique si le LLM ne le produit pas |

---

## UX / rendu SPA

À chaque `render()`, le bloc principal `#main-content` est reconstruit par `innerHTML`. Pour éviter que les clics (équipes, toggles panneaux, actions in-page) ne **remontent la page tout en haut**, tout en conservant une remise à zéro du défilement lors d’un **changement de vue**, `renderer.js` préserve lorsque pertinent la position de `#main-content` et celle du conteneur interne `.dr-content` ou `.chat-messages` pour les vues pleine hauteur.

---

## Architecture

```
decision-room-ai/
├── backend/
│   ├── public/
│   │   ├── index.php           # Entry point (routeur Apache)
│   │   └── .htaccess           # mod_rewrite rules
│   ├── config/                 # Config app et providers
│   ├── src/
│   │   ├── Controllers/        # HTTP controllers (Chat, DecisionRoom, Vote, Export…)
│   │   ├── Domain/             # Logique métier (runners, prompt builder, ProviderRouter)
│   │   ├── Http/               # Router, Request, Response
│   │   └── Infrastructure/     # Database, repositories, markdown loader, Logger
│   └── storage/
│       ├── db.sqlite            # Base SQLite (sessions, messages, votes, logs…)
│       ├── personas/            # Personas Markdown
│       ├── souls/               # Souls Markdown
│       └── prompts/             # Prompts globaux Markdown
└── frontend/
    ├── index.html
    ├── src/
    │   ├── core/               # router, store, shell, renderer, events
    │   ├── services/           # apiClient, sessionService, providerService, logService…
    │   └── features/           # Modules UI par fonctionnalité
    │       ├── newSession/
    │       ├── chat/
    │       ├── decisionRoom/
    │       ├── confrontation/
    │       ├── quickDecision/
    │       ├── stressTest/
    │       ├── jury/
    │       ├── sessionHistory/
    │       ├── comparisons/
    │       ├── launchAssistant/
    │       └── admin/
    ├── styles/                 # Tokens, layout, composants, features
    ├── styles.css              # Point d'entrée CSS
    ├── i18n.js                 # Runtime i18n
    └── i18n/
        ├── fr.json
        └── en.json
```

### Principes d'architecture

**Backend**
- PHP 8 sans framework, sans Composer
- SQLite (fichier unique `storage/db.sqlite`)
- `ProviderRouter` centralise tous les appels LLM et instrumente les logs

**Frontend**
- 100% ES modules natifs (pas de bundler, pas de npm)
- Store global unique (`core/store.js`) — pas de state management externe
- Navigation via `data-nav`, actions via `data-action` (pas d'event listeners ad hoc)
- Chaque feature expose `register<Feature>Feature()` (vues) et `register<Feature>Handlers()` (actions)

---

## Sécurité & limites

> ⚠️ **Usage local uniquement — ne pas exposer publiquement.**

- **Pas d'authentification** : l'application n'est pas conçue pour un accès multi-utilisateurs ou réseau
- **Clés API stockées en SQLite** : ne pas publier la base, être prudent avec les exports partagés
- **CORS permissif** côté backend (configuration locale/dev)
- **Streaming** : selon la configuration, les réponses sont affichées après complétion complète (pas de SSE)

---

## Guide de contribution

### Ajouter une feature

1. Créer `frontend/src/features/<feature>/index.js` (vues)
2. Créer `frontend/src/features/<feature>/handlers.js` (actions + listeners)
3. Dans `frontend/src/main.js` :
   - Importer `register<Feature>Feature()` et `register<Feature>Handlers()`
   - Appeler les deux dans `bootstrapModuleArchitecture()`
4. Ajouter les endpoints backend si nécessaire
5. Ajouter les traductions dans `frontend/i18n.js` (sections `fr` et `en`)
6. Vérifier le flux complet en UI ; respect du **mode simple / expert** (`data-ui="expert-only"`)

### Conventions

- Les vues retournent du HTML (string), injecté dans `#main-content`
- Les handlers doivent être idempotents et résilients aux éléments absents (null checks)
- Stocker les erreurs dans `state.error` — `errorBanner` gère l'affichage

---

## Mise à jour corrective (2026-05-01)

Ce lot corrige les points prioritaires issus de l’audit, sans refactor massif ni ajout de dépendances.

- `LearningController` aligné sur la convention routeur (`Request $request` partout), avec réponses cohérentes en `array` et export stabilisé.
- Persistance **Reactive Chat** corrigée dans `MessageRepository::create()` : `thread_type`, `thread_turn`, `reaction_role`, `reactive_thread_id` (compatibilité arrière conservée).
- Export Learning aligné côté API/UI : `GET /api/learning/export?format=markdown|json` (POST conservé pour compatibilité).
- Ouverture des sessions `mode=jury` corrigée dans `sessions/handlers.js` (navigation vers la vue Jury dédiée).
- Export session sécurisé ajouté : `redacted=1` (masquage secrets) et `redacted=strong` (messages/context docs remplacés par `[REDACTED]`).
- UX progressive introduite via `uiComplexity=basic|advanced|expert` (persisté localStorage), presets Reactive Chat et sections repliables dans Session History.

Scripts de vérification ajoutés :
- `backend/tools/test_learning_routes_signature.php`
- `backend/tools/test_reactive_thread_persistence.php`
- `backend/tools/test_learning_export.php`

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
