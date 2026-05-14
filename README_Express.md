# Decision Arena

> *Most AI tools give you an answer. Decision Arena gives you a structured disagreement.*  
> *(FR) La plupart des outils IA vous donnent une réponse. Decision Arena vous donne une contradiction structurée.*

**Decision Intelligence, local-first.** Plusieurs agents spécialisés débattent, votent et livrent une synthèse **auditable** — pas un oracle lissé.

| | |
|---|---|
| **Stack** | PHP 8+ · Vanilla JS (ES modules) · SQLite |
| **Statut** | Alpha — à lancer **chez vous**, pas un SaaS opaque |
| **Licence** | Voir [README.md](README.md) (licence restreinte) |

---

## Démo rapide (mental model)

1. **Founder Sprint** — Vous cadrez une idée ; le panel force le scope, la preuve et ce qu’il ne faut pas construire.
2. **CEO Challenge** — Même logique, angle stratégie / moat / risques de distribution.
3. **Multi-agent** — PM, architecte, critique, synthèse : les agents **ne sont pas d’accord** par design quand c’est pertinent.
4. **Validation Logic** — Signaux de succès mesurables et **kill criteria** explicites ; moins de métriques vanité.
5. **Decision Brief** — Sortie courte, actionnable, reliée au graphe, aux votes et aux garde-fous.

Pas de promesse de vérité absolue : un **système observable** dont vous pouvez suivre le raisonnement.

---

## Pourquoi c’est différent

| Chatbot / copilot classique | Decision Arena |
|---|---|
| Une voix, une réponse | Plusieurs rôles, désaccord piloté |
| Boîte noire | Graphe, votes, exports, replay |
| Cloud imposé | **Local-first** (Ollama, LM Studio, compatible OpenAI) |
| Consensus artificiel | **Contradiction** comme signal, pas comme bug |

---

## Pour qui

- Fondateurs · EM / lead devs · PM & PO · équipes produit/tech  
- Toute personne qui veut **challenger une décision** avec plusieurs agents, pas refaire un conseil d’administration abstrait.

**Ce n’est pas** : un produit “CEO enterprise” uniquement, une simulation géopolitique, ou un remplacement d’équipe humaine.

---

## Captures d’écran *(placeholders)*

Remplacez par vos visuels sous `docs/screenshots/` :

| Zone | Fichier suggéré |
|---|---|
| Tableau de bord | `dashboard.png` |
| Founder Sprint | `founder-sprint.png` |
| Decision Brief | `decision-brief.png` |
| Confrontation | `confrontation.png` |
| Graphe / fiabilité | `graph-reliability.png` |

---

## Essayer

1. Lisez l’installation dans [README.md](README.md).
2. Configurez un provider local (Ollama recommandé).
3. Lancez une session **Quick Decision** ou **Founder Sprint** et ouvrez le **Decision Brief**.

---

## Decision Memory (recherche déterministe + découverte optionnelle)

Le flux principal reste la navigation:

**Strategic Context → Decision Room / Chain → Decision Memory**

### Recherche par défaut (production)

- **SQLite FTS5 scoped search** (fallback LIKE si FTS5 indisponible)
- **Endpoint**: `GET /api/decision-memories/search`
- **Règle**: recherche **secondaire** à la navigation (pas d’omnibox, pas de chat search).

### Similar decisions (expérimental, Expert-only)

Option de découverte uniquement (jamais source de vérité):

- **Endpoint**: `GET /api/decision-memories/similar`
- **Feature flag**: `SEMANTIC_MEMORY_ENABLED=false` par défaut
- **Warnings**: “Similarity does not imply correctness.” + “These are prior decision records, not verified facts.”
- **Interdits**: aucune injection automatique dans les prompts, aucun auto-link, aucune “AI memory”.

---

## Contribuer & contact

Retours, bugs, améliorations de prompts et de personas : voir la section *Contribution* du README principal.  
Pour la licence commerciale : **mehdy.driouech@dawp-engineering.com**.

---

*Pas de “magie IA”. Un outil technique, local, qu’on peut auditer.*
