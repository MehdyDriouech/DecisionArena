# Release notes — Decision Arena (alpha locale)

**Période / version :** stabilisation alpha locale (workflows principaux validés).  
**Audience :** contributeurs, utilisateurs techniques, early adopters locaux.

Voir aussi : [README.md](README.md), [README_marketing.md](README_marketing.md).

---

## Vue d’ensemble alpha

Cette release consolide Decision Arena autour d’un positionnement clair : **Decision Intelligence** avec débat multi-agent, synthèse traçable et garde-fous explicites — **local-first**, sans framework lourd, orienté auditabilité.

**Stable à des fins de démo et d’usage exploratoire** : Founder Sprint, CEO Challenge, Quick Decision, Stress Test, Jury, Confrontation.  
**Expérimental ou perfectible** : parsing dépendant du format des modèles, certains panneaux analytics, edge cases UI.

---

## Nouveautés majeures

- **Founder Sprint** — Preset produit : personas dédiés, clarification *Founder Interrogation*, réduction de scope et challenge explicite dans le prompt.
- **CEO Challenge** — Preset stratégique (moat, distribution, *what not to build*) avec la même mécanique runner existante.
- **Validation Logic** — Bloc produit pour signaux de succès et **kill criteria** mesurables (anti-vanity).
- **Decision Brief** — Brief décisionnel persisté, mieux relié aux résultats du run ; parcours Founder/CEO conservés.
- **Tableau de bord** — Accès simplifié (simple / expert), raccourcis vers les modes sans redondance majeure.
- **Nettoyage UX P2** — Cohérence *Quick Decision* (`rounds`), sortie propre des presets Founder lors d’un changement de mode manuel, injection **Question / Context** dans `initial_prompt` (mode simple), propagation `session_variant` pour le template **Pre-Mortem**.

---

## Améliorations UX

- Formulaire nouvelle session : modes, presets et starters plus cohérents entre eux.
- Quick Decision : alignement affichage / payload sur une analyse **un tour** côté usage courant.
- Réouverture de sessions Quick Decision terminées avec réhydratation des résultats.
- Verdict : meilleure séparation texte / compromis lorsque le modèle mélange JSON et prose.

---

## Fiabilité & garde-fous

- Avertissements **multi-runner** : qualité du contexte, faux consensus, seuils, retry automatique lorsque activé (Decision Room / presets rapides).
- **Reliability engine** : scores, guardrails, enrichissement des exports et de l’historique de session selon les modes.

---

## Corrections de bugs (non exhaustif)

- Noms de modèle invalides ou provider mal configuré : messages d’erreur et parcours admin clarifiés dans la pratique courante.
- **Jury** : correctifs runtime / persistance des rapports adversariaux côté exports.
- **Confrontation** : robustesse parsing JSON / résultats lorsque le modèle dévie du format attendu.
- **Decision Brief** : fallbacks lorsque le brief est partiel ou absent après run.
- **Founder Interrogation** : persistance dans le prompt initial lorsque les champs sont renseignés.
- **UI (dropdowns)** : le dispatcher global n’exécute plus `data-action` sur clic pour les contrôles de formulaire (`select/input/textarea`), ce qui évite des re-render qui empêchent la sélection d’options.
- **Suppression (SQLite FK)** : suppression “hard delete” plus robuste sur les entités liées (nettoyage des dépendances avant suppression).

---

## Limites connues

- **Alpha** : régressions possibles, comportements non couverts par des tests E2E systématiques.
- **Qualité des modèles** : résultats très variables selon le LLM (locaux plus petits vs modèles frontier).
- **Contrat de format** : synthèses, votes et blocs JSON dépendent encore du respect du format par le modèle.
- **Interface** : grande surface d’écran ; le mode *simple* réduit la charge mais n’élimine pas toute complexité.
- **Rôle** : outil d’aide à la réflexion — **ne remplace pas** une équipe, un juriste, ou une validation terrain.

---

## Suite plausible (hors engagement)

- Fiabilité et calibration plus poussées par mode.
- Replay / comparaison de sessions plus fluides.
- Analytics d’usage locales (agrégations légères).
- RAG / contexte documentaire plus guidé (sans sur-engineering).
- Evidence system : intégration plus systématique dans le cycle décisionnel.

---

## Fichiers utiles

| Sujet | Emplacement indicatif |
|---|---|
| Frontend modularisé | `frontend/src/features/` |
| API & contrôleurs | `backend/src/Controllers/` |
| Runners | `backend/src/Domain/Orchestration/` |
| Personas | `backend/storage/personas/` |
| Presets Founder / CEO (UI) | `frontend/src/features/newSession/` |

Merci aux personnes ayant retesté les flux critiques et signalé les incohérences UX.

---

## Outils expert : suppression & hygiène des données

- **Contextes stratégiques** : sélection multiple + “Supprimer sélection” (expert-only).
- **Decision Memory** : suppression d’une décision ou d’une sélection (expert-only), avec endpoint `DELETE /api/decision-memories/{id}`.
