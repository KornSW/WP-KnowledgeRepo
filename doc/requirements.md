# Anforderungen

## Ziel

KornSW KnowledgeRepo macht Wissen aus verschiedenen Quellen (WordPress-Beiträge, GitHub-Repositories, externe UJMW-Dienste, Web-Dokumente, Linklisten) unter einem gemeinsamen Baum verfügbar – lesbar und, wo die Quelle es erlaubt, bearbeitbar. Zugriff erfolgt über drei Kanäle:

- **Wiki** im Browser für Menschen,
- **Joplin** (WebDAV-Sync) für die Arbeit mit einer lokalen Notiz-App,
- **UJMW** als Maschinen-API für Agents und andere Dienste.

Das Plugin ist ein WordPress/PHP-Nachbau des .NET-Projekts [SmartStandards/KnowledgeManagement](https://github.com/SmartStandards/KnowledgeManagement). Der Vertrag `IKnowledgeRepository` wird **1:1** übernommen (Methoden, Parameter- und Out-Namen, Enum-Werte, Semantik). Im Übrigen darf sich dieser Nachbau in eigener Geschwindigkeit entwickeln; legitime WordPress-spezifische Abweichungen werden in diesem Repository dokumentiert.

## Zielgruppen

- **Website-Betreiber**, die ihr Wissen aus WordPress und GitHub zentral bereitstellen wollen.
- **Leser/Autoren**, die im Browser nachschlagen, suchen und gelegentlich bearbeiten.
- **Joplin-Nutzer**, die das Wissen offline synchronisieren und bearbeiten.
- **Entwickler/AI-Agents**, die per UJMW lesen und schreiben.

## Funktionale Anforderungen

### Quellen und Mounts

- Beliebig viele Quellen, auch mehrfach vom gleichen Typ, jeweils mit Mountpunkt. Mountpunkte dürfen tief liegen und sich überlagern (lesbares Overlay).
- Jede Quelle hat eine stabile Identität; sie ändert sich bei normalen Konfigurationsänderungen nicht, da Ressourcen-IDs und Sync-Zuordnungen davon abhängen.
- Unterstützte Quellentypen:
  - **WordPress-Beiträge** – immer nur lesend; veröffentlichte, nicht passwortgeschützte Beiträge aus ausgewählten Kategorien. Kategorien wahlweise als Navigationsebenen oder nur als Filter.
  - **GitHub** – ein Repository (optional Branch, Einstiegsverzeichnis, PAT); lesen und schreiben ausschließlich über die GitHub-API, ohne Klonen.
  - **GitHub (multi)** – mehrere Repositories mit gemeinsamen Einstellungen; jedes erscheint unter `<Mountpunkt>/<Repo-Name>`.
  - **UJMW-Client** – ein entfernter `IKnowledgeRepository`-Dienst mit festem JWT oder durchgereichtem Aufrufer-Token (`[PASS-TROUGH]`).
  - **URL-Dokumente** – einzelne Markdown-/Text-/HTML-Dokumente von beliebigen HTTP(S)-URLs, nur lesend, mit optionalem Quellverweis.
  - **Linkliste** – eine Seite mit Links als Liste oder Kacheln, nur lesend.
- Mehrdeutige Schreibziele und providerübergreifende Verschiebungen werden abgelehnt.

### Wiki

- Aufruf unter `/wiki/…`; Navigation links, darunter die Gliederung „Auf dieser Seite“, Breadcrumb oben.
- Der erste Inhaltscontainer entlang eines Pfades bildet ein Dokument; tiefere Container erscheinen als Überschriften mit Anker.
- Suche im Dialog; Ergebnisse erscheinen schrittweise, die Treffermenge ist begrenzt.
- Bearbeiten (Ersetzen, sparsames Anfügen, Unterbereich anlegen), Aktualisieren (Cache verwerfen) und API-Zugang (JWT erzeugen/widerrufen) als dezente Dialoge.
- Drei Darstellungsmodi: **Neutral** (eigene Optik), **Themed** (Akzentfarbe des Themes) und **On-Page** (im Theme-Header/-Footer eingebettet).
- Mobile Darstellung ohne horizontales Scrollen der Seite.

### Joplin

- WebDAV-Endpunkt `/wiki/joplin/<Profil-ID>/`; Profil-ID frei wählbar, Sync-Zustand getrennt je Benutzer und Profil.
- Bereiche werden zu Notizbüchern und Notizen; Bilder/Anhänge zu Joplin-Ressourcen.
- Ein Löschen in Joplin blendet Inhalte nur im Profil aus – es löscht **nie** Wissen in der Quelle.

### UJMW

- Endpunkt `/wiki/ujmw/IKnowledgeRepository/<Operation>`, eine Operation pro POST, Vertrag identisch zur .NET-Schnittstelle.
- Maschinenlesbare Beschreibung unter `/wiki/ujmw/swagger.json` (OpenAPI 3, aus dem Code erzeugt).

### Berechtigungen

- Rollenmatrix je WordPress-Rolle und Kanal (Seite, Joplin, UJMW): kein Zugriff / lesen / schreiben. Kein impliziter Administrator-Bypass.
- Anonymer Zugriff je Kanal einzeln freigebbar, höchstens lesend.
- UJMW-Zugang über selbst ausgestellte JWTs; persönlicher Widerruf und globale Schlüsselrotation.

## Nicht-funktionale Anforderungen

- **Keine Zusatzsoftware auf dem Host:** kein Git, kein Composer, kein separater Dienst. WordPress ≥ 6.4, PHP ≥ 8.1.
- **Performance:** serverseitiger, verschlüsselter Dateicache (Standard 4 Stunden, abschaltbar). Warme Seitenaufrufe benötigen keine Quellzugriffe.
- **Robustheit:** Im Wiki blockiert eine ausgefallene Quelle nicht die anderen. Joplin und UJMW liefern dagegen nie einen stillschweigend unvollständigen Baum.
- **Sicherheit:** Zugangsdaten verschlüsselt gespeichert und nie in Logs oder URLs. HTML sicher gerendert, Skripte nur mit CSP-Nonce. Cache ersetzt keine Berechtigungsprüfung.
- **Datenschutz der Quellen:** Kein Schreibvorgang ohne explizite Aktion; kein Force-Push; WordPress-Beiträge werden nie verändert.

## Bewusste Grenzen

- WordPress-HTML wird vereinfacht in Markdown übersetzt; komplexe Gutenberg-Layouts sind nicht originalgetreu.
- Der Markdown-Baum basiert auf ATX-Überschriften und ist kein vollständiger CommonMark-Parser.
- Einzelne Requests/Ressourcen maximal 16 MiB, URL-Dokumente 4 MiB, Sync-Zustand je Profil 128 MiB – ausgelegt für kleine bis mittlere Wissensbestände, nicht für große Mediatheken.
- Keine Konflikterkennung gegen gleichzeitig geöffnete Editoren; keine Exactly-once-Garantie bei Netzwerkfehlern nach erfolgreichem Commit.
- Joplin-Ende-zu-Ende-Verschlüsselung wird für projiziertes Wissen nicht unterstützt.
- Nur selbst ausgestellte JWTs; externe Token-Aussteller sind nicht vorgesehen (siehe [Ideen](ideas.md)).
