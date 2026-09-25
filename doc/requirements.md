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
  - **GitHub (multi)** – mehrere Repositories mit gemeinsamen Einstellungen; jedes erscheint unter `<Mountpunkt>/<Repo-Name>`. Optional zeigt jedes Repo bei gesetztem Einstiegsverzeichnis (z. B. `/doc`) zusätzlich die `README.md` seiner Wurzel als ersten, nur lesbaren Eintrag „README“, sofern sie existiert und das Einstiegsverzeichnis keine eigene README hat.
  - **UJMW-Client** – ein entfernter `IKnowledgeRepository`-Dienst mit festem JWT oder durchgereichtem Aufrufer-Token (`[PASS-TROUGH]`).
  - **URL-Dokumente** – einzelne Markdown-/Text-/HTML-Dokumente von beliebigen HTTP(S)-URLs, nur lesend, mit optionalem Quellverweis.
  - **Linkliste** – Links als Liste oder Kacheln, nur lesend. Links können kommaseparierte Tags tragen. Dann wird die Liste zu einem Ordner mit je einer Seite pro Tag; Links ohne Tag stehen auf einer Seite mit dem Namen der Liste.
- Mehrdeutige Schreibziele und providerübergreifende Verschiebungen werden abgelehnt.
- **Reihenfolge:**
  - Die oberste Ebene ist alphabetisch sortiert.
  - Tiefer liegende Mountpunkte werden alphabetisch zwischen die Bereiche der übergeordneten Quelle einsortiert, und zwar vor dem ersten Geschwister, das alphabetisch danach kommt.
  - Die Reihenfolge innerhalb einer Quelle bleibt unverändert, auch wenn sie nicht alphabetisch ist.
- **Pflege im Admin:**
  - Quellen erscheinen als Liste in einer eigenen Spalte links; rechts wird die ausgewählte Quelle bearbeitet (Master/Detail).
  - Linklisten werden in einer kompakten, filterbaren Tabelle gepflegt. Hinzufügen und Entfernen geschieht ohne Seitenneuladen; auch sehr viele Links sind möglich.

### Wiki

- Aufruf unter `/wiki/…`; Navigation links, darunter die Gliederung „Auf dieser Seite“, Breadcrumb oben.
- Der erste Inhaltscontainer entlang eines Pfades bildet ein Dokument; tiefere Container erscheinen als Überschriften mit Anker.
- Suche im Dialog; Ergebnisse erscheinen schrittweise, höchstens 30 Treffer (Suchbegriff maximal 200 Zeichen).
- Bearbeiten (Ersetzen, sparsames Anfügen, Unterbereich anlegen), Aktualisieren (Cache verwerfen) und API-Zugang (JWT erzeugen/widerrufen) als dezente Dialoge.
- Drei Darstellungsmodi: **Neutral** (eigene Optik), **Themed** (Akzentfarbe des Themes) und **On-Page** (im Theme-Header/-Footer eingebettet).
- Mobile Darstellung ohne horizontales Scrollen der Seite.

### Joplin

- WebDAV-Endpunkt `/wiki/joplin/<Profil-ID>/`; Profil-ID frei wählbar, Sync-Zustand getrennt je Benutzer und Profil.
- Bereiche werden zu Notizbüchern und Notizen; Bilder/Anhänge zu Joplin-Ressourcen.
- Ein Löschen in Joplin blendet Inhalte nur im Profil aus – es löscht **nie** Wissen in der Quelle.

### UJMW

- Endpunkt `/wiki/ujmw/IKnowledgeRepository/<Operation>`, eine Operation pro POST, Vertrag identisch zur .NET-Schnittstelle.
- Maschinenlesbare Beschreibung unter `/wiki/ujmw/swagger.json` (OpenAPI 3, aus dem Code erzeugt). Der Dialog „API-Zugang“ im Wiki verlinkt sie.

### RAW

- `/wiki/raw/…` spiegelt den gesamten Wissensbaum als einfaches, selbstbeschreibendes Markdown für Menschen und einfache Agents. Vorbild ist der .NET-`KnowledgeRepositoryRawController`.
  - Navigationsbereiche liefern eine Liste der direkten Unterbereiche mit absoluten URLs.
  - Inhaltsdokumente liefern ihr vollständiges Markdown.
  - Ressourcen stehen unter `/wiki/raw/resources/<id>`.
- **Nur lesend** (GET), abweichend von .NET: Dort gibt es zusätzlich POST (Append) und DELETE (Truncate).
- Berechtigung wie im Wiki: Kanal „Seite“, also WordPress-Sitzung bzw. anonyme Seitenfreigabe.

### Berechtigungen

- Rollenmatrix je WordPress-Rolle und Kanal (Seite, Joplin, UJMW): kein Zugriff / lesen / schreiben. Kein impliziter Administrator-Bypass.
- Anonymer Zugriff je Kanal einzeln freigebbar, höchstens lesend.
- UJMW-Zugang über selbst ausgestellte JWTs; persönlicher Widerruf und globale Schlüsselrotation.

## Nicht-funktionale Anforderungen

- **Keine Zusatzsoftware auf dem Host:** kein Git, kein Composer, kein separater Dienst. WordPress ≥ 6.4, PHP ≥ 8.1.
- **Performance:** serverseitiger, verschlüsselter Dateicache (Standard 4 Stunden, abschaltbar). Warme Seitenaufrufe benötigen keine Quellzugriffe. Ein Seitenaufruf lädt nur die Bereiche auf seinem eigenen Pfad, nie den gesamten Baum aller Quellen.
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
