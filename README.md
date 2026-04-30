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
| Seuil de consensus configurable | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Export enrichi | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |

> **Note :** le mode Chat ne persiste pas de données de délibération (pas d'arguments structurés, pas de votes). Les panneaux Deliberation Intelligence s'affichent en mode "notice" pour les sessions chat.

---

## Deliberation Intelligence

Ensemble de panneaux d'analyse disponibles pour toutes les sessions de délibération, via `/api/sessions/{id}/…` :

### Audit du débat
`GET /api/sessions/{id}/audit`

Évalue la qualité des échanges, détecte les biais et mesure la profondeur du raisonnement.

### Graphe d'interactions
`GET /api/sessions/{id}/graph`

Visualise qui parle à qui, l'influence relative des agents et la structure du débat.

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

---

## Fonctionnalités transverses

### Rerun intelligent

Relancer une session avec des variations : autre mode, autres agents, autre langue, plus de désaccord forcé, etc.

### Comparaison de sessions

Comparer 2 à 4 sessions côte à côte — artefact Markdown exportable.

### Action Plan

Génération automatique d'un plan d'action depuis la synthèse, enrichissable manuellement, persisté en SQLite.

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

Fichiers i18n : `frontend/i18n/fr.json`, `frontend/i18n/en.json`

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
5. Ajouter les traductions dans `frontend/i18n/fr.json` et `en.json`
6. Vérifier le flux complet en UI

### Conventions

- Les vues retournent du HTML (string), injecté dans `#main-content`
- Les handlers doivent être idempotents et résilients aux éléments absents (null checks)
- Stocker les erreurs dans `state.error` — `errorBanner` gère l'affichage

---

## Roadmap technique

1. Remplacer `API_BASE` hardcodé par une configuration dynamique (env / host)
2. Enrichir le mode diagnostic (corrélations session / provider dans les Logs)
3. Ajouter des tests de non-régression (smoke tests Playwright)
4. Normaliser toutes les réponses backend (shape consistent)
5. Documenter précisément le schéma SQLite (exporter `Migration.php` en doc)
6. Ajouter le streaming optionnel via SSE (impact important sur le backend)

---

## Licence

MIT
