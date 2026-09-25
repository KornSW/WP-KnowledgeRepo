# KornSW KnowledgeRepo

## Was ist das?

Ein WordPress-Plugin, das Wissen aus mehreren Quellen unter einem gemeinsamen Baum zusammenführt und über drei Wege bereitstellt:

- ein **Wiki** im Browser,
- eine **Joplin**-Synchronisation (WebDAV),
- eine **UJMW**-Maschinen-API.

Mögliche Quellen sind WordPress-Beiträge, GitHub-Repositories, entfernte UJMW-Dienste, einzelne Web-Dokumente und Linklisten.

## Motivation

Wissen liegt verstreut: in Blogbeiträgen, in Markdown-Dateien auf GitHub, in anderen Diensten. KnowledgeRepo macht daraus einen einzigen, durchsuchbaren Wissensbaum – für Menschen im Browser oder in Joplin und für AI-Agents über eine stabile API. Wo die Quelle es erlaubt, lässt sich auf allen drei Wegen auch schreiben: Eine Änderung in Joplin wird z. B. zu einem Commit auf GitHub.

## Kurze Beispiele

- **Handbuch aus GitHub + Blog:** GitHub-Repository unter `/Handbuch` mounten, die Blog-Kategorie „Tutorials“ unter `/Tutorials` – beides erscheint gemeinsam unter `/wiki/`.
- **Offline in Joplin:** `https://<site>/wiki/joplin/laptop/` als WebDAV-Ziel eintragen und das Wissen lokal lesen und bearbeiten.
- **Agent-Zugriff:** Im Wiki unter „API-Zugang“ ein Token erzeugen und `POST /wiki/ujmw/IKnowledgeRepository/GetAggregatedContent` aufrufen.

## Abgrenzung

- **Kein Git auf dem Server:** GitHub wird ausschließlich über die API gelesen und geschrieben – kein Klonen, kein Checkout, kein Composer.
- **Vertragstreu:** Die Schnittstelle `IKnowledgeRepository` entspricht 1:1 dem .NET-Referenzprojekt [SmartStandards/KnowledgeManagement](https://github.com/SmartStandards/KnowledgeManagement), sodass .NET- und WordPress-Instanzen sich gegenseitig als Quelle einbinden können.
- **Sync-sicher:** Joplin-Löschungen löschen nie Quellinhalte, und bei einem Quellausfall bekommt der Sync keinen stillschweigend unvollständigen Baum.

## Dokumentation

- [Anforderungen](doc/requirements.md)
- [Architektur](doc/architecture.md)
- [Quickstart](doc/quickstart.md)
- [Ideen und künftige Richtungen](doc/ideas.md)
- [Changelog](doc/changelog.md)

Die UJMW-Schnittstelle beschreibt jede Installation selbst unter `/wiki/ujmw/swagger.json`.
