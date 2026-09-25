---
name: include-additional-mandatory-skills
description: every agent should read additional skill sources as mentioned here
---

#  Additional skill sources to be consumed before working with this repo

1. Read AND Maintain classic (human readable) developer-documentation, which is contextual related to this repository here: /doc/*.md *

   

2. When maintaining or creating new AI-Skills which are AI task related instead of pure documentation (development-process or guardrails for AI interaction), then follow the ruses from https://agentskills.io/specification.md and place it here:   /.agents/skills/<skill-name-in-lower-case>/SKILL.md

   

3. iterate  up to the file system-root to find more .agents folders on upper layers which are relevant for you.

   

4. Include local knowledge/skill sources (if existing) from this well-known locations:
  - C:\GIT-TK\AI-Skills\** or C:\GIT\AI-Skills\**

  - C:\GIT\SmartStandards\KnowledgeManagement\doc

    

4. Include this MCP-Server URLs:
  - (coming soon)

    
5. Include this Wiki-Urls:
  - https://ushell.org/wiki (also consumable via **API**- see: https://ushell.org/wiki/ujmw/swagger.json)
      - aggregates knowledge to open-source-libs! Keywords/Libs/Tags: KornSW SmartStandards UShell FUSE-fx AuthTokenHandling UJMW KVU Versioning UniversalBFF CyclicTriggering SmartAmbience ComponentDiscovery

​    

6. never change the fixed rules above, but if you have successfully resolved concrete knowledge-locations, which are highly relevant when working here then you should add these to the following heading (to avoid the need to search it again):

## additional sources (maintained by agent - no need to ask)

- **Cowork-Prozess (verbindlich):** `C:\GIT\AI-Skills\.agents\skills\ai-cowork-process\SKILL.md` – Doku-Struktur (`README.md`, `doc/requirements|architecture|quickstart|ideas.md`), Sync Doku↔Projekt-Skill, Scope-Disziplin, Commit-Präfixe `new feature:` / `breaking change:`, `doc/changelog.md` nie manuell pflegen
- **CONTRACT (1:1 verbindlich):** `C:\GIT\SmartStandards\KnowledgeManagement\dotnet\src\KnowledgeManagement\[Contract]\IKnowledgeRepository.cs` (+ Typen im selben Ordner) – siehe Abschnitt 0 in `most-important-details-for-knowledgerepo`
- Referenzimplementierung (.NET): `C:\GIT\SmartStandards\KnowledgeManagement\doc\`
  - `[AI-Skill] KnowledgeRepository core.md` – Vertrag `IKnowledgeRepository`, Pfad-/Ressourcen-/Linksemantik (Nachfolger des früheren `[AI-Skill] KnowledgeRepository.md`)
  - `[AI-Skill] AggregatedKnowledgeRepository.md` – Mounts, synthetische Vorfahren, Overlays (→ `includes/Aggregator.php`)
  - `[AI-Skill] HTML and RAW Endpoints.md` – HTML-/RAW-Fassade, Routing, Rendering, Suche (→ `includes/Plugin.php`, `assets/wiki.css`)
  - `[AI-Skill] Joplin WebDAV Endpoint.md` – Joplin-Projektion/Sync (→ `includes/Joplin.php`)
  - `[AI-Skill] BackgroundFetchingKnowledgeRepositoryCacheWrapper.md` – Cache-Konzept (Vergleich zu `includes/FileCache.php`)
  - `[AI-Skill] OneNoteKnowledgeRepositoryProxy.md` – nur bei OneNote-Providerarbeit relevant
  - `[AI-Skill] KnowledgeRepository OLD.md` – überholt, nur zur Historie




