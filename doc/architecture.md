# Architektur

## Überblick

```text
 Browser (Wiki)     Joplin (WebDAV)     Agents/Dienste (UJMW)
       │                  │                     │
       └──────────┬───────┴─────────────────────┘
                  ▼
     Plugin.php – Routing /wiki/…, Authentifizierung (Auth.php)
                  │
                  ▼
     Aggregator – Mounts, virtuelle Elternbereiche, Overlays, opake Ressourcen-IDs (lazy)
                  │
      ┌───────────┼───────────┬──────────────┬───────────────┐
      ▼           ▼           ▼              ▼               ▼
  WordPress    GitHub      UJMW-Client   URL-Dokumente    Linkliste
  (lesend)   (API, r/w)   (remote r/w)   (lesend)         (lesend)
      │           │           │
      └── FileCache (verschlüsselt, TTL, Generation) ──┘
```

Alle Kanäle sprechen denselben Vertrag `IKnowledgeRepository`. In PHP ist die Vertragsgrenze `Repository::call(string $method, array $args): array`; Methoden-, Parameter- und Out-Namen entsprechen exakt der .NET-Schnittstelle. Provider kennen keine Joplin-IDs, UI und Joplin keine GitHub-Dateipfade.

## Komponenten

| Datei | Verantwortung |
|---|---|
| `kornsw-knowledgerepo.php` | Plugin-Header, Include-Reihenfolge, Aktivierung, Self-Update-Bootstrap (von der Pipeline gepflegt) |
| `includes/Contract.php` | Vertragsmethoden, Fehler, Pfadkonventionen, Markdownbaum, `TreeRepository` als Basis der Lesesichten |
| `includes/Aggregator.php` | Mounts, synthetische Vorfahren, Overlays, Fehlerisolation, opake Ressourcen-IDs |
| `includes/WordPressRepository.php` | Projektion veröffentlichter Beiträge ausgewählter Kategorien |
| `includes/GitHubRepository.php` | GitHub-Objekt-API, Markdownstruktur, Commit-Transaktionen, Multi-Repository-Auflösung |
| `includes/ConfiguredRepository.php` | URL-Dokumente und Linklisten (nur lesend, Inhalt wird erst bei Bedarf geladen) |
| `includes/Http.php` | HTTPS-JSON-Transport, UJMW-Client |
| `includes/FileCache.php` | Verschlüsselter Dateicache mit TTL, Generation und Locks |
| `includes/Auth.php` | Rollenmatrix, Basic Auth, JWTs, Secret-Verschlüsselung, Sync-Zustandsspeicher |
| `includes/Joplin.php` | WebDAV, Joplin-Serialisierung, Profile, Pending-Abhängigkeiten |
| `includes/SearchSession.php` | Schrittweise Suche, vom Browser getaktet |
| `includes/WikiPresentation.php` | Darstellungsmodi Neutral/Themed/On-Page, Akzentfarben-Erkennung |
| `includes/OpenApi.php` | Erzeugt `swagger.json` aus dem Vertrag |
| `includes/Raw.php` | Nur lesende RAW-Fassade `/wiki/raw/` (Markdown-Navigation, Inhalte, Ressourcen) |
| `includes/Admin.php` | Einstellungsseite, providerabhängige Felder |
| `includes/Plugin.php` | Routing, Wiki-Seite, Dialoge, UJMW-Server, Lifecycle |
| `assets/wiki.css` | Referenzoptik; `wiki-themed.css` und `wiki-on-page.css` für die weiteren Modi |
| `vendor/Parsedown.php` | Parsedown 1.7.4 (MIT), namespaced, Safe Mode |

## Wissensmodell

- Bereiche sind absolute logische Pfade ab `/`. Die Reihenfolge innerhalb einer Quelle ist fachlich (Depth-first Pre-order, natürliche Geschwisterfolge) und wird nicht umsortiert. Nur der Aggregator ordnet die Wurzel und eingehängte Mountpunkte alphabetisch ein (siehe unten).
- `ContentLevel`: 0 BeyondContent, 1 ContentAggregation (kein direkter Text), 2 ContentContainer.
- Ressourcen werden als `knowledge-resource:<opaque-id>` referenziert. IDs sind opak; nur der erzeugende Provider bzw. Aggregator interpretiert sie.
- Markdown wird in einen Baum aus Überschriften zerlegt (ATX, Code-Fences sind undurchsichtig).
  - **Append** ist ein sparsamer hierarchischer Merge.
  - **Replace** ersetzt atomar.
  - **Truncate** leert, **Delete** entfernt.
  - **Move** hängt einen Bereich unter einen neuen Elternbereich.

## Aggregator

Der Aggregator lädt **lazy**:

- Zu welcher Quelle ein Bereich gehört, ergibt sich aus den Mountpunkten und der Kinderliste des Elternbereichs.
- Eine Wiki-Seite fragt deshalb nur die Bereiche entlang ihres eigenen Pfads ab.
- Nur rekursive Aufrufe (Joplin, UJMW `GetAreas(recurse)`, Stichwortsuche) laden ganze Bäume, dann mit **einem** rekursiven Aufruf je Quelle.

Sortierung:

- Die Wurzel wird alphabetisch sortiert.
- Tiefer liegende Mountpunkte stehen vor dem ersten Geschwister, das alphabetisch danach kommt.
- Die Reihenfolge innerhalb einer Quelle bleibt unangetastet.

## Provider

**WordPress** ist immer nur lesend. Kategorien bilden optional Navigationsebenen, mehrfach zugeordnete Beiträge erscheinen mehrfach. HTML wird vereinfacht in Markdown übersetzt; Shortcodes und dynamische Blöcke werden nicht ausgeführt. Beitrags- und Kategorieänderungen invalidieren den Cache.

**GitHub** arbeitet ausschließlich über die HTTPS-API. Verzeichnisse erscheinen als `[Ordner]`, Markdown-Dateien ohne `.md`, Überschriften darunter. Eine Mutation läuft so ab:

1. aktuellen Head ungecacht lesen,
2. die logische Änderung im Speicher anwenden,
3. Blobs und Tree (`base_tree`) schreiben,
4. einen Commit mit dem Head als Parent erzeugen,
5. den Ref mit `force:false` setzen – erst dieser Schritt veröffentlicht die Änderung.

Bei einem Konflikt wird bis zu dreimal neu gelesen und erneut angewendet. **GitHub (multi)** fächert eine Quelle in mehrere GitHub-Mounts auf.

**UJMW-Client** ruft einen entfernten Dienst mit gleichem Vertrag auf. Pass-through-Quellen stehen nur zur Verfügung, wenn ein eingehender UJMW-Aufruf ein Token mitbringt.

**URL-Dokumente/Linkliste** sind konfigurierte, nur lesende Dokumente. Navigation lädt keine Fremdinhalte; der Download erfolgt erst beim Öffnen oder Durchsuchen. Eine Linkliste mit Tags wird zum Ordner mit einer Seite je Tag.

## Cache

- Der Dateicache liegt standardmäßig unter `WP_CONTENT_DIR/kornsw-knowledge-cache`, überschreibbar mit `KORNSW_KR_CACHE_DIR`.
- Inhalte sind mit AES-256-GCM verschlüsselt; die Dateinamen sind HMAC-Schlüssel.
- Jede Vertragsantwort einer Quelle ist ein Eintrag. Das gilt auch für eine rekursive Bereichsliste, die nicht in einen Eintrag je Knoten zerlegt wird.
- Eine globale Generation (`kornsw_kr_cache_epoch`) invalidiert den Cache. Auslöser sind Admin-Speichern, Wiki-Aktualisieren, WordPress-Änderungen, Remote-Schreibvorgänge und GitHub-Commits.
- Fehler werden höchstens 30 Sekunden zwischengespeichert.
- Der Cache ist wegwerfbar und **keine** Autorisierung.

## Fehlerisolation

Wiki, Suche und Wiki-Ressourcen nutzen den Aggregator *tolerant*: Eine ausgefallene Quelle wird gemeldet, die anderen bleiben sichtbar. Joplin und UJMW sind *strikt*. Ein unvollständiger Baum würde sonst im Sync Löschungen auslösen können.

## Joplin

- Der Sync-Zustand ist je Benutzername und Profil-ID getrennt und verschlüsselt gespeichert, standardmäßig unter `WP_CONTENT_DIR/kornsw-knowledge-state-<sitehash>`, überschreibbar mit `KORNSW_KR_STATE_DIR`.
- Strukturelle und aggregierende Bereiche werden Notizbücher; der erste Container wird eine Notiz.
- Löschen in Joplin unterdrückt nur die Projektion im Profil.
- Mehrstufige Schreibsequenzen sind je Schritt atomar; es gibt keine verteilte Gesamttransaktion.

Anders als der Cache ist der Sync-Zustand **nicht** wegwerfbar.

## Authentifizierung und Routing

| Zugang | Route | Anmeldung |
|---|---|---|
| Wiki | `/wiki/<Bereich>` | WordPress-Sitzung (Kanal „Seite“) |
| Suche | `/wiki/_search?q=…`, danach `/wiki/_search/<id>` | wie Wiki |
| Ressourcen | `/wiki/_resource/<id>` | wie Wiki |
| RAW (nur GET) | `/wiki/raw/<Bereich>`, `/wiki/raw/resources/<id>` | wie Wiki |
| Joplin | `/wiki/joplin/<Profil>/` | Basic Auth mit WordPress-Konto oder `anonymous`/`anonymous` |
| UJMW | `/wiki/ujmw/IKnowledgeRepository/<Operation>` | eigenes JWT oder freigegebener anonymer Zugang |
| API-Beschreibung | `/wiki/ujmw/swagger.json` | – |

- Das Routing läuft früh im `init`-Hook, vor Theme und Canonical Redirects.
- JWTs sind HS256-signiert; Rollen werden bei jedem Aufruf aktuell ermittelt (optional kurz zwischengespeichert, Standard 30 Sekunden).
- Ein leerer Authorization-Header bedeutet bei UJMW „anonym“, ein ungültiges Token führt zu 401 – es gibt keinen Rückfall auf anonym.

## Darstellung

- Wiki-Markdown wird mit Parsedown im Safe Mode und zusätzlich `wp_kses_post` gerendert.
- Inline-JavaScript läuft nur mit frischem CSP-Nonce; im On-Page-Modus entfällt die eigene CSP zugunsten des Themes.
- Browserantworten sind `no-cache`; gecacht wird nur serverseitig.
- Die .NET-Referenz enthält eine HTML-Fassade mit bytegleichem Stylesheet. Änderungen an der gemeinsamen Optik werden bewusst in beiden Fassungen nachgezogen.

## Tests

Standalone-Suiten mit simuliertem WordPress und GitHub:

- `tests/regression.php`: Merge-Semantik, GitHub-Transaktionen, Aggregator-Overlays, Joplin-Byte-Stabilität/Pending/Delete, JWT/Widerruf.
- `tests/aggregator-links.php`: Lazy-Aggregator, Sortierung, Fehlerisolation, Linklisten mit Tags.
- `tests/wordpress-provider.php`: WordPress-Provider. Benötigt derzeit zusätzlich einen `FileCache`-Stub.

Ohne lokales PHP laufen die Suiten auch über `@php-wasm/node`. Eine Live-Abnahme ersetzen sie nicht (siehe [Quickstart](quickstart.md#live-abnahme)).
