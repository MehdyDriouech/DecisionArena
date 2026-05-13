# Release notes — Decision Arena (catalogue beta consolidé)

**Document source :** reprend intégralement le périmètre et la structure de [release-note-beta.md](release-note-beta.md).  
**Dernière mise à jour du présent fichier :** 2026-05-13 (enrichissements post–note beta du 2026-05-11).

**Audience :** contributeurs, utilisateurs techniques, early adopters locaux.  
Voir aussi : [README.md](README.md), [README_marketing.md](README_marketing.md), [context-llm.md](context-llm.md).

---

## Positionnement beta

Decision Arena est en phase **beta fonctionnelle** sur un noyau **Decision Intelligence local-first** : orchestration multi-agents, cycles de décision auditables, mémoire décisionnelle gouvernée, contextes stratégiques persistants, et outillage expert d’analyse.

---

## Fonctionnalités présentes (catalogue beta)

### 1) Moteur d’analyse multi-modes

- Chat multi-agent (libre + réactif)
- Decision Room (délibération structurée multi-rounds)
- Confrontation (Blue Team vs Red Team)
- Quick Decision (arbitrage court)
- Stress Test (robustesse / failure modes)
- Jury (vote collectif + seuil)
- Rerun / Fork / Pré-mortem (avec garde-fous lifecycle)

### 2) Workspace Analyses

- Vue `analyses` unifiée (alias legacy `sessions`)
- Filtres : query, mode, statut, contexte, date, verdict
- Lifecycle harmonisé :
  - persisté : `draft`, `running`, `completed`, `archived`
  - overlays dérivés : `blocked`, `fragile`, `rerun`, `forked`
- Actions : ouvrir, rejouer, créer variante, exporter, comparer, archiver/restaurer, supprimer
- Sélection multiple + actions bulk (archive, delete, compare)
- Entrée sidebar dédiée : **Historique d’analyses**

### 3) Session History / Relecture

- Historique détaillé d’une analyse avec panneaux progressifs
- Panneaux : replay, graph, heatmap, reliability, evidence, risk, bias, social dynamics, persona scores, timeline, LLM used, decision memory
- Lazy rendering des sections lourdes pour limiter le coût de rendu
- Modal rerun enrichi (variations, mode/langue cible, conservation contexte doc)

### 4) Strategic Contexts (couche d’organisation)

- CRUD contextes + activation workspace global
- Liste + détail + comparaison contextes (lecture seule)
- Timeline workspace contextuelle (+ mode legacy en expert)
- Strategic Narrative (load + recompute)
- Beliefs Engine (expert) : croyances, états, relations, timeline
- Memory Compiler (expert) : compilations dérivées stratégiques/social/risk/etc.
- Context Snapshots (expert) : capture, visualisation, diff, longitudinal
- Agent Context Memory (expert) : édition, append, consolidation, maintenance
- **Synchronisation forcée des `memory.md` agents (expert, 2026-05-13)** : `POST /api/strategic-contexts/{id}/agent-memories/sync` — reconstruction / complétion idempotente depuis les sessions **completed** liées au contexte et les Decision Memories liées (`strategic_context_memories`) ; prévisualisation `dry_run` ; marqueurs factuels `participant_context_sync:{session_id}` et `da-decision-memory-sync:{memory_id}` ; participants filtrés **roster ∪ voteurs** (exclut les agents uniquement présents dans des messages hors sélection) ; `synthesizer` / `devil_advocate` exclus par défaut.
- Situated Agent Chat (expert) : chat scopé contexte + mémoire + signaux sociaux
- Memory Governance panel (expert)
- Boutons cartes contexte : comparer + activer l’espace
- Panneau `memory.md` contextuel (ouverture robuste + copy)

### 5) Decision Memory

- Decision Memory repository persisté
- Recherche déterministe (FTS5 si dispo, fallback LIKE)
- Reuse UI Basic/Expert orientée réutilisation d’analyses
- Preview d’injection (explicite injecté/non injecté)
- Scope cognitive (contexte courant / all / archives)
- Similarité sémantique expérimentale (discovery only)

### 6) Gouvernance, fiabilité, observabilité

- Guardrails décisionnels
- Détection faux consensus
- Score qualité décision
- Runtime cognitive QA modes (dev/qa/expert)
- Prompt injection trace & politiques
- Logs frontend/backend
- Erreurs UI normalisées sur plusieurs flux critiques

### 7) Administration & configuration

- Providers (Ollama, LM Studio, OpenAI-compatible)
- Routing provider (primary/fallback/load-balance)
- Personas + souls + persona maker
- Templates + template maker
- Scenario packs
- Prompt policies
- Learning / calibration
- Cognitive governance hub
- Logs / export

### 8) Export, comparaison, audit

- Export session (Markdown + JSON expert)
- Session comparisons (création + ouverture + export)
- Debate Audit
- Debate Replay
- Graph des interactions
- Heatmap argumentaire

---

## Points forts (beta)

- **Architecture sobre et maîtrisable** : PHP 8 + Vanilla JS + SQLite, sans framework lourd.
- **Traçabilité élevée** : sessions, votes, graph, replay, evidence, risk, logs.
- **Governance-native** : runtime QA, provenance, guardrails, prompt policy.
- **UX analyses convergente** : navigation unifiée, lifecycle lisible, CTA harmonisés.
- **Contextes stratégiques puissants** : narrative, beliefs, snapshots, compilations, chat situé, sync explicite des mémoires agents.
- **Compatibilité legacy préservée** : alias routes/vues et transitions non destructives.
- **Local-first réel** : fonctionnement autonome sans dépendance SaaS obligatoire.

---

## Points faibles / limites actuelles

- **Dette UX résiduelle** : certaines zones restent expertes et denses pour des profils non techniques.
- **i18n incomplète** : il reste des libellés hardcodés dans des sous-panneaux secondaires.
- **Complexité de surface** : forte richesse fonctionnelle, risque de surcharge cognitive sans onboarding.
- **Robustesse backend hétérogène** : certains flux historiques ont demandé des correctifs FK/transaction.
- **Couverture e2e non uniforme** : plusieurs parcours critiques sont testés manuellement plus qu’automatiquement.
- **Lisibilité produit** : la frontière entre « analyse », « session », « contexte », « memory » peut encore être clarifiée.

---

## Risques beta à surveiller

- Régressions cross-modules sur les actions bulk (delete/archive/compare)
- Divergence UX Basic vs Expert sur des actions transverses
- Perf sur gros historiques contextes/sessions sans pagination forte
- Erreurs API 500 masquées en frontend si payload d’erreur insuffisant

---

## Recommandations phase suivante

- Finaliser l’i18n systématique des vues expertes restantes
- Standardiser le contrat d’erreurs API (codes + messages + metadata)
- Renforcer les tests intégration sur flux bulk contextes/analyses
- Ajouter onboarding/copywriting orienté « raisonnement » pour réduire la charge cognitive
- Consolider métriques runtime/perf sur panels lourds (history + contexts expert)

---

## Résumé exécutif

La beta est **riche, utilisable et techniquement cohérente** pour des usages réels de décision assistée multi-agents.  
Les priorités restantes portent surtout sur la **polish UX**, la **normalisation des erreurs**, et la **stabilisation e2e** des parcours experts.

---

## Outils expert : suppression & hygiène des données

- **Contextes stratégiques** : sélection multiple + « Supprimer sélection » (expert-only).
- **Decision Memory** : suppression d’une décision ou d’une sélection (expert-only), avec endpoint `DELETE /api/decision-memories/{id}`.

---

## Fichiers utiles

| Sujet | Emplacement indicatif |
|---|---|
| Frontend modularisé | `frontend/src/features/` |
| API & contrôleurs | `backend/src/Controllers/` |
| Runners | `backend/src/Domain/Orchestration/` |
| Personas | `backend/storage/personas/` |
| Sync mémoires agents contexte | `backend/src/Domain/StrategicContext/AgentContextMemorySyncService.php` |
| Script QA sync forcée | `backend/tools/test_agent_context_memory_force_sync.php` |
