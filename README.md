# Decision Arena

Decision Arena est un outil **local** de *Decision Intelligence* basé sur des **agents IA**. Au lieu d’obtenir une seule réponse “propre”, vous observez une **décision émerger d’un système** : désaccords, votes pondérés, synthèse et artefacts d’analyse.

---

## Prérequis

- PHP 8.0+ avec les extensions :
  - `pdo_sqlite`
  - `curl`
  - `json`
- Apache (AMPPS) avec `mod_rewrite` activé
- Un provider LLM local (Ollama ou LM Studio) ou une API compatible OpenAI

---

## Débutant : installation pas à pas (Windows)

### 1) Installer un “serveur web local” (Apache + PHP)

Choisissez **un seul** de ces packages :

- **AMPPS** : `https://www.ampps.com/`
- **XAMPP** : `https://www.apachefriends.org/`
- **WampServer** : `https://www.wampserver.com/`

Objectif : avoir **Apache + PHP 8+** (et l’extension `pdo_sqlite`) qui servent le dossier `www/` (ou `htdocs/` selon le package).

### 2) Installer un provider LLM (Ollama ou LM Studio)

Choisissez **un seul** provider local :

- **Ollama** : `https://ollama.ai/`
  - Après installation, téléchargez un modèle, par exemple :
    - `ollama pull qwen2.5:14b`
- **LM Studio** : `https://lmstudio.ai/`
  - Téléchargez un modèle dans l’application
  - Lancez le serveur local (API) depuis LM Studio

### 3) Placer le projet au bon endroit

Copiez ce dossier projet dans le répertoire web du package choisi :

- AMPPS : `...\www\decision-room-ai\`
- XAMPP : `...\htdocs\decision-room-ai\`
- WAMP : `...\www\decision-room-ai\`

### 4) Démarrer et ouvrir l’application

1. Démarrez Apache (via AMPPS / XAMPP / WAMP).
2. Ouvrez `http://localhost/decision-room-ai/frontend/index.html`
3. Dans l’UI : **Administration → Providers** puis ajoutez votre provider (Ollama ou LM Studio) et testez-le.

---

## Démarrage

### Via AMPPS (recommandé — aucune commande)

1. Placez le projet dans le répertoire `www/` de AMPPS sous `decision-room-ai/`
2. Démarrez AMPPS
3. Ouvrez `http://localhost/decision-room-ai/frontend/index.html`

Le backend tourne automatiquement via Apache (aucune commande PHP nécessaire).

### Via serveur PHP intégré (alternative)

```bash
php -S localhost:8000 -t backend/public
```

Puis ouvrez `frontend/index.html` dans votre navigateur.

---

## Configuration des providers LLM

Allez dans **Administration → Providers** pour ajouter un provider.

Vous pouvez ensuite cliquer **Fetch models** pour auto-découvrir les modèles disponibles :

- **Ollama** via `/api/tags`
- **LM Studio** via `/v1/models`
- **OpenAI-compatible** via `/v1/models` (avec clé bearer si fournie)

Si la découverte échoue, l’entrée manuelle du modèle reste supportée.

### Ollama (recommandé en local)

1. Installez Ollama : `https://ollama.ai`
2. Téléchargez un modèle : `ollama pull qwen2.5:14b`
3. Ajoutez un Provider :
   - **ID** : `local-ollama`
   - **Type** : `ollama`
   - **Base URL** : `http://localhost:11434`
   - **Model** : `qwen2.5:14b`

### LM Studio

1. Installez LM Studio : `https://lmstudio.ai`
2. Chargez un modèle et démarrez le serveur local
3. Ajoutez un Provider :
   - **ID** : `local-lmstudio`
   - **Type** : `lmstudio`
   - **Base URL** : `http://localhost:1234`
   - **Model** : (nom du modèle chargé)

### OpenAI / API compatible

- **Type** : `openai-compatible`
- **Base URL** : `https://api.openai.com`
- **API Key** : votre clé
- **Model** : `gpt-4o` (ou équivalent)

### Routing (sélection provider/modèle)

Le backend supporte plusieurs stratégies de routage LLM :

- `single-primary`
- `preferred-with-fallback`
- `load-balance` (round-robin)
- `agent-default`

⚠️ **Usage local uniquement** : les clés API sont stockées en SQLite.

---

## Modes d’analyse

### Chat multi-agent

Conversation libre avec les agents sélectionnés.

- `@pm` : seul le PM répond
- `@architect @critic` : plusieurs agents ciblés
- sans mention : tous les agents sélectionnés répondent

### Decision Room

Analyse structurée en plusieurs tours, avec synthèse finale et **follow-up panel**.

### Confrontation (Blue vs Red)

Débat structuré entre une équipe de défense (*Blue*) et une équipe d’attaque (*Red*), avec synthèse finale et follow-up.

### Quick Decision

Analyse rapide (1 tour) + verdict.

### Stress Test

Mode “attaque/robustesse” pour mettre une décision sous stress.

### Jury / Comité

Mode “comité” avec vote final multi-agents.

---

## Deliberation Intelligence (artefacts d’analyse)

Pour les sessions structurées, l’app expose des endpoints d’analyse sous `/api/sessions/{id}/…` :

- **Audit** : `GET /api/sessions/{id}/audit`
- **Graph** : `GET /api/sessions/{id}/graph`
- **Heatmap d’arguments** : `GET /api/sessions/{id}/argument-heatmap`
- **Replay** : `GET /api/sessions/{id}/replay`
- **Votes & décision** : `GET /api/sessions/{id}/votes` (et recompute)

---

## Sessions : sauvegarde, export, snapshots, rerun, comparaison

- **Exports** : Markdown et JSON (metadata, messages, contexte, verdict/votes si présents).
- **Snapshots** : sauvegarde persistée en SQLite (`session_snapshots`).
- **Rerun** : relancer une session avec variations (mode / agents / langue / etc.).
- **Comparaison** : comparer plusieurs sessions (artefact dédié).
- **Action Plan** : génération + enrichissement manuel (persisté en SQLite).

---

## Langues (UI)

L’application supporte **Français** et **Anglais** côté interface.

- Switch via boutons **FR / EN** (sidebar)
- Le choix est stocké dans `localStorage`
- La langue des **réponses des agents** est définie par session (formulaire “New Session”)

Fichiers :

- `frontend/i18n.js` (runtime)
- `frontend/i18n/fr.json`, `frontend/i18n/en.json`

---

## Personas, Souls, Prompts (Markdown)

- **Personas** : `backend/storage/personas/` (frontmatter YAML + corps Markdown)
- **Souls** : `backend/storage/souls/` (style/comportement)
- **Prompts globaux** : `backend/storage/prompts/`

Vous pouvez créer/éditer via l’UI (**Administration**) ou en déposant des fichiers Markdown (selon les dossiers supportés).

---

## Structure du projet

```
decision-room-ai/
├── backend/
│   ├── public/
│   │   ├── index.php           # Entry point
│   │   └── .htaccess           # Apache mod_rewrite rules
│   ├── config/                 # App and provider config
│   ├── src/
│   │   ├── Controllers/        # HTTP controllers
│   │   ├── Domain/             # Business logic, runners, prompt builder
│   │   ├── Http/               # Router, Request, Response
│   │   └── Infrastructure/     # Database, repositories, markdown loader
│   └── storage/                # SQLite DB, personas, souls, prompts
└── frontend/
    ├── index.html
    ├── src/
    │   ├── core/               # router, store, shell, renderer, events
    │   ├── services/           # api client, session, providers, etc.
    │   └── features/           # modules UI (new session, sessions, admin, etc.)
    ├── styles/                 # tokens, layout, components, features
    ├── styles.css              # point d’entrée CSS
    ├── i18n.js                 # i18n runtime module
    └── i18n/
        ├── fr.json             # traductions FR
        └── en.json             # traductions EN
```

---

## Sécurité & limites (important)

- **Pas d’authentification** : usage local uniquement (ne pas exposer publiquement).
- **Clés API en SQLite** : ne pas publier la base, prudence sur les exports partagés.
- **CORS permissif** côté backend (local/dev).
- Selon config, l’app peut ne pas streamer (réponses affichées après complétion).

---

## Licence

MIT
