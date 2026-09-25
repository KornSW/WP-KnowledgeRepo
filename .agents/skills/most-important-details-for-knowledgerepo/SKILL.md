---
name: most-important-details-for-knowledgerepo
description: the most important details for this project is maintained here
---


# [AI-Skill] KnowledgeRepo

## Auftrag und Verbindlichkeit

Dies ist die Übergabe für die Weiterpflege von **KornSW KnowledgeRepo**, WordPress-Stand **0.1.2**, und der korrespondierenden ASP.NET-Core-HTML-Oberfläche. Stand: 18.09.2026. Vor Änderungen den tatsächlich installierten Quellstand lesen. Diese Datei ist eine Projektdokumentation, kein Laufzeitbestandteil.

**Lieferkonvention:** Nur geänderte bzw. neue Dateien einzeln zum Download liefern, jeweils vollständig. Keine ZIPs und keine kleinteiligen Ersetzen-Fragmente. Zum Abschluss für jede Datei Zielpfad und „ersetzen“ oder „neu hinzufügen“ nennen. Bei C# vollständige Klassen/Dateien liefern. Bestehende Library-Dateien unter derselben Identität aktualisieren. Kein Deployment, Release oder fremdes Repository ohne entsprechenden Auftrag verändern.

**Self-Update und Versionsnummer werden von der Build-Pipeline gepflegt** (separates Projekt des Nutzers). Unmittelbar nach dem Check-in pflanzt sie in `kornsw-knowledgerepo.php` den `SELF-UPDATE`-Bootstrap-Block (`self-update.php`), `Update URI`/`Plugin URI` und die aktuelle `Version` wieder ein und pflegt `doc/versioninfo.json`, `doc/changelog.md`, `doc/kornsw-knowledgerepo.update.json`. Fehlt der Block oder steht eine ältere Version im Header (z. B. nach externem KI-Code), ist das **kein Fehler** und muss weder gemeldet noch manuell korrigiert werden. Diese Stellen nicht von Hand „reparieren“.

## 0. Verhältnis zum .NET-Referenzprojekt (Vorgabe des Nutzers, verbindlich)

Dieses Repository ist ein **WordPress/PHP-Nachbau** des .NET-Projekts https://github.com/SmartStandards/KnowledgeManagement (lokal: `C:\GIT\SmartStandards\KnowledgeManagement`). Es darf sich in **eigener Geschwindigkeit** entwickeln; es muss nicht jede Änderung dort sofort nachziehen.

1. **Contract = 1:1, ohne Ausnahme.** Maßgeblich ist ausschließlich der dort liegende Vertrag:
   - `dotnet/src/KnowledgeManagement/[Contract]/IKnowledgeRepository.cs`
   - sowie die davon verwendeten Typen im selben Ordner: `ContentLevel.cs`, `KnowledgeAreaKind.cs`, `KnowledgeResourceIdChange.cs`, `KnowledgeResourceInfo.cs`, `IKnowledgeRepositoryCacheControl.cs`

   Methodennamen, Parameter- und Out-Namen, Reihenfolge, Enum-Werte und dokumentierte Semantik (XML-Doc-Kommentare) müssen in `includes/Contract.php` und in der UJMW-Schnittstelle exakt gleich sein. Bei Abweichung gilt die .cs-Datei, nicht der PHP-Stand und nicht diese Übergabe.
2. **Grob mittracken:** Plattformunabhängige Stolperfallen (Semantik, Sync-/Joplin-Fallen, Sicherheit, Cache-Korrektheit) sowie Anforderungen an Brauchbarkeit und UI aus den `doc/[AI-Skill] *.md` des Referenzprojekts „einigermaßen“ nachziehen. .NET-spezifische Details (DI, ASP.NET-Core, Data Protection usw.) nicht übertragen.
3. **Unstimmigkeiten werden hier geführt.** Abweichungen, die sich aus der PHP/WordPress-Welt ergeben (Hosting, Hooks, Rollen, fehlende Libraries, Laufzeitmodell …), sind legitim und werden in diesem Repository diskutiert und dokumentiert.
4. **Immer nachfragen, bevor eine Unstimmigkeit aufgelöst wird.** Weder den PHP-Stand eigenmächtig an .NET angleichen noch umgekehrt eine WordPress-Abweichung eigenmächtig festschreiben. Konflikt benennen, Optionen vorschlagen, Entscheidung des Nutzers abwarten. Einzige Ausnahme: ein eindeutiger Verstoß gegen den Contract (Punkt 1) – aber auch dann vor der Änderung Bescheid geben.

## 1. Ziel und Referenzen

- Pluginname korrekt mit **Knowledge**; Slug `kornsw-knowledgerepo`; Author KornSW. Vorgesehener Repositoryname `WP-KnowledgeRepo`; Owner/Update-Integration bisher nicht festgelegt.
- Fachlicher Vertrag: `IKnowledgeRepository`, ursprünglich SmartStandards/AiSupport, Referenzrevision `3376257f4a4f476ea100ed2c83aec350311f8e60`. Insbesondere `doc/[AI-Skill] KnowledgeRepository.md` und der aktive Joplin-Handler unter `dotnet/src/SmartStandards.AiSupport.DemoWebService/[Experimental]`.
- Das .NET-Projekt wurde später nach `KnowledgeManagement.SmartStandards` umbenannt. Der für die HTML-Portierung bereitgestellte Controller und Vertrag verwenden diesen Namespace. Nicht blind alte Namespaces in neue Dateien kopieren.
- Aktuelle Referenzrepository-URL des Nutzers: https://github.com/SmartStandards/KnowledgeManagement . Die WordPress-Implementierung wurde nicht nachträglich vollständig auf jede Änderung dieses Repositories abgeglichen.
- Originalnorm und tatsächlichen Code gemeinsam lesen. Keine behauptete vollständige 1:1-Protokollkonformität ohne Vergleich und echte Clienttests.

## 2. Architektur: harte Grenzen

Immer ein Aggregator vor beliebig vielen Providerinstanzen. Jeder Eintrag besitzt eine stabile zufällige Instanz-ID, Providerkonfiguration und Mountpunkt. Gleichen Provider mehrfach unterstützen. IDs niemals bei einer gewöhnlichen Konfigurationsänderung neu vergeben: Aggregator-Ressourcen-IDs enthalten diese Identität.

| Datei im Plugin | Verantwortung |
|---|---|
| `kornsw-knowledgerepo.php` | WordPress-Header, Requires PHP 8.1/WP 6.4, Include-Reihenfolge, Aktivierung |
| `includes/Contract.php` | Methoden/Parameter, Failure, Pfadkonventionen, Markdownbaum, TreeRepository |
| `includes/Aggregator.php` | Mounts, virtuelle Vorfahren, Overlays, opake Ressourcen, Fehlerisolation |
| `includes/WordPressRepository.php` | veröffentlichte Beiträge ausgewählter Kategorien, nur lesend |
| `includes/ConfiguredRepository.php` | Provider `urls` (URL-Dokumente) und `links` (Linkliste), nur lesend, Download erst bei Inhaltszugriff |
| `includes/SearchSession.php` | Wiki-Suche als vom Browser getaktete Sitzung (`/_search`, dann `/_search/<id>`), verschlüsselter Zustand im Cacheverzeichnis |
| `includes/OpenApi.php` | generiert `/wiki/ujmw/swagger.json` aus `Contract::METHODS` |
| `includes/Raw.php` | `/wiki/raw/` nach .NET-`KnowledgeRepositoryRawController`, aber **nur GET** (Nutzerentscheid 25.09.2026) und Kanal `page` statt JWT; Aggregationen liefern nur Navigation, nie `GetAggregatedContent` |
| `includes/WikiPresentation.php` | Darstellungsmodi, Akzentfarben-Heuristik (siehe Abschnitt 6) |
| `includes/GitHubRepository.php` | GitHub-Objekt-API, Markdownstruktur und Commit-Transaktionen |
| `includes/Http.php` | HTTPS-JSON-Transport, UJMW-RemoteRepository |
| `includes/FileCache.php` | verschlüsselter Dateisystem-Cache, TTL, Generation, Locks |
| `includes/Auth.php` | Rollenmatrix, Basic, eigene JWTs, Secret-Verschlüsselung und SyncStore |
| `includes/Joplin.php` | WebDAV, Joplin-Items, Profile, Pending, Ressourcenidentitäten |
| `includes/Admin.php` | Einstellungen und providerabhängige Felder |
| `includes/Plugin.php` | Routing, Wiki, Suche, Dialoge, Anker, UJMW, Lifecycle |
| `assets/wiki.css` | verbindliche Optik der Wiki-Oberfläche |
| `vendor/Parsedown.php` | namespaced Parsedown 1.7.4, MIT; SafeMode |

PHP-Vertragsgrenze: `Repository::call(string $method, array $args): array`. UJMW behält .NET-Parameter- und Out-Namen; Rückgabewert unter `return`, Out-Werte auf derselben Ebene. Keine Umbenennung in frei erfundene REST-DTOs. Binärinhalt im JSON als Base64.

Provider kennen keine Joplin-IDs. UI und Joplin kennen keine GitHub-Dateipfade. Ressourcen sind keine Areas. UI darf Ressourcen-IDs weder dekodieren noch selbst neu zusammensetzen.

## 3. Semantik des Repositories

- Absolute logische Pfade ab `/`. Pfadsegmente sind providerabhängig; sichtbare Namen über `GetAreaName` erfragen.
- Reihenfolge ist Fachsemantik: Depth-first Pre-order mit natürlicher Geschwisterreihenfolge. Nicht pauschal alphabetisch sortieren. **Ausnahme im Aggregator (Nutzervorgabe 25.09.2026):**
  - Die Root-Ebene wird alphabetisch sortiert (`strnatcasecmp` auf `GetAreaName`).
  - Tiefere Mountpunkte werden vor dem ersten Geschwister eingefügt, das alphabetisch danach kommt.
  - Die Reihenfolge innerhalb einer Quelle bleibt unangetastet, auch wenn sie unsortiert ist.
  - Das ist eine WordPress-seitige Abweichung zum .NET-Aggregator.
- Linkliste mit Tags (`links[].tags`): Alias wird Aggregation (Level 1), je Tag eine Container-Seite, ungetaggte Links auf einer Seite namens wie der Alias. Ohne Tags bleibt die alte Form (eine Container-Seite), damit bestehende URLs/Sync-IDs stabil bleiben. Admin überträgt die Link-Tabelle als ein JSON-Feld `links_json` (wegen `max_input_vars`).
- ContentLevel: `0 BeyondContent`, `1 ContentAggregation`, `2 ContentContainer`. Aggregation besitzt keinen direkten Text.
- Canonical Ressourcenreferenz: `knowledge-resource:<opaque-id>`.
- Append ist **sparsamer hierarchischer Merge**, kein schlichtes Anhängen an eine Datei. Gleichnamige direkte Kinder rekursiv zusammenführen; vorhandene, im Payload nicht erwähnte Zweige erhalten; neue Geschwister hinten ergänzen. Mehrdeutige Überschriften ablehnen.
- Replace ersetzt den gesamten adressierten Inhaltsumfang atomar. Nicht als öffentlich sichtbares Truncate gefolgt von Append implementieren.
- Truncate erhält den adressierten Bereich; Delete entfernt ihn. Move hängt den Bereich selbst unter einen neuen Elternbereich. Das Ziel ist nicht der neue vollständige Kindpfad und wird nicht überschrieben.
- Rename/Move melden Ressourcen-ID-Änderungen zurück. Adapter erhalten dabei ihre eigenen stabilen externen IDs.
- Fähigkeiten sind unabhängig von globalen Rollen. Schreibrecht bedeutet Erlaubnis zum Versuch; ein Read-only-Provider darf `false` liefern. Die UI muss nicht alle Providergrenzen vorab erklären.

## 4. Providerentscheidungen

### WordPress

Immer read-only, auch für Administratoren. Nur `post`, `publish`, ohne Passwort; Kategorien explizit auswählbar. Keine Ausführung von Shortcodes, dynamischen Blöcken oder Contentfiltern beim Export.

`category_levels=true`: Kategorien einschließlich nötiger Vorfahren bilden logische Ordner. Ein mehrfach zugeordneter Beitrag erscheint in mehreren Bereichen. `false`: Kategorien filtern nur, Beiträge direkt am Mount; doppelte Treffer deduplizieren. Fehlende Einstellung bei bestehenden Quellen bedeutet aus Kompatibilitätsgründen `true`; neue Admin-Einträge beginnen ohne Haken. Keine automatische Migration, die alle vorhandenen URLs und Sync-Zuordnungen ändert.

HTML→Markdown ist eine vereinfachte Lesesicht. Komplexe Gutenberg-Layouts/Tabellen sind nicht originalgetreu. Lokale freigegebene Anhänge werden Ressourcen; externe Bilder bleiben externe Links. Änderungen an Beiträgen/Kategorien invalidieren den Cache über WordPress-Hooks.

### GitHub

**Niemals klonen**, kein Checkout und keine Git-Shell. Ausschließlich API, auch Schreibzugriff. URL Owner/Repo, Branch (leer = Default), Einstiegsverzeichnis und optional PAT. Remote-URLs HTTPS; Zugangsdaten nicht in URL. PAT verschlüsselt speichern.

Lesen löst Head und Einstiegsbaum auf, lädt den rekursiven Baum und benötigte Markdown-Blobs. `truncated` ist ein Fehler, kein vollständiger Baum. Symlinks ignorieren. Verzeichnisse logisch `[Ordner]`, Dateien ohne `.md`, Überschriften darunter; doppelte Überschriften erhalten disambiguierte Segmente.

Mutation: aktuellen Head **ungecacht** lesen → logische Änderung vorbereiten → Blobs → Tree mit `base_tree` → Commit mit Head als Parent → Ref mit `force:false` setzen. Erst Ref-Veröffentlichung macht Änderungen sichtbar. Konflikte maximal dreimal mit erneutem Lesen und erneuter Anwendung der logischen Operation. Unbetroffene Dateien unverändert lassen. Kein blindes Force-Push.

GitHub-Ressourcen-IDs und dokumentgebundene `.Res…`-Dateien sind private Providerkonventionen. Beim Move/Rename gebundene Ressourcen mitnehmen; freie Ressourcen behalten ihren Ort, relative Links korrigieren. Joplin-DELETE ist davon getrennt.

Expliziter User-Agent sowohl HTTP-Header als auch WordPress-Option. HTTP 403 ist nicht zwangsläufig ein User-Agent-Problem: PAT-Rechte, Organisations-SSO und Rate-Limit unterscheiden. Fehlermeldung zeigt gegebenenfalls Reset-Zeit UTC. Keine Zugangsdaten in Logs/Fehlertexten ausgeben.

### UJMW-Client

Vertragsbasis-URL plus fester JWT oder exakt historisch geschriebenes `[PASS-TROUGH]`. Den Marker nicht stillschweigend „korrigieren“! Eingehenden JWT nur an den konfigurierten vertrauenswürdigen Endpunkt weiterleiten.

Ohne eingehenden UJMW-Kontext werden Pass-through-Quellen im Wiki/Joplin ausgelassen. Seit 0.1.2 kann ein explizit anonymer UJMW-Request einen leeren Header weiterreichen. Ein gewöhnlicher fehlender Kontext darf nicht unbemerkt als anonymer Pass-through gelten; dafür existiert ein gesondertes Flag.

## 5. Berechtigungen und Zugänge

| Zugang | Route | Authentifizierung |
|---|---|---|
| Wiki | `/wiki/<area>` | WordPress-Sitzung und Kanal `page` |
| Suche | `/wiki/_search?q=...` | dieselbe Seitenberechtigung |
| Ressourcen | `/wiki/_resource/<id>` | dieselbe Seitenberechtigung |
| RAW (nur GET) | `/wiki/raw/<area>`, `/wiki/raw/resources/<id>` | dieselbe Seitenberechtigung |
| Joplin | `/wiki/joplin/<profile>/` | WordPress-Basic oder `anonymous`/`anonymous` |
| UJMW | `/wiki/ujmw/IKnowledgeRepository/<Operation>` | eigener JWT oder freigegebener anonymer Zugang |

Je WordPress-Rolle und Kanal 0/1/2 = kein Zugriff/lesen/schreiben. Höchstes explizites Rollenrecht gewinnt. Kein impliziter Administrator-Bypass. Einstellungen selbst benötigen `manage_options`.

Anonymous hat je Kanal eine Checkbox und maximal Leserecht. **Auch UJMW ist seit 0.1.2 freigebbar.** Leerer Authorization-Header bedeutet dort Benutzer 0, nicht die eventuell vorhandene WordPress-Cookiesitzung. Ohne Freigabe 403; mit ungültigem nichtleerem Token weiterhin 401, niemals anonyme Rückfallauthentifizierung.

JWT akzeptiert `Authorization: Bearer TOKEN` und `Authorization: TOKEN`. Nur eigene HS256-Tokens: Signatur, iss, aud, nbf, exp, sub, existierender User, Widerrufsversion prüfen. Rollen jedes Mal aktuell ermitteln. Persönlicher Widerruf und globale Schlüsselrotation vorhanden. Externe JWT-Aussteller/Signaturschlüssel bisher nicht implementiert.

Wiki-Änderungen, Refresh, Tokenausstellung und Widerruf: POST, eingeloggter User, WordPress-Nonce. Refresh benötigt Seitenzugang, nicht Schreibrecht. Tokens zusätzlich UJMW-Recht. Suchantworten JSON; Fehler müssen ebenfalls JSON sein.

Frühes `init`-Routing vor Theme/Canonical Redirects. Unterverzeichnisinstallationen über `home_url`; reservierte Routen nicht als gewöhnliche Areas verwenden. Authorization muss vom Proxy/Webserver bis PHP gelangen.

## 6. UI: ausdrücklich gewünschter Zustand

Die CSS-Datei aus 0.1.2 ist die visuelle Referenz: helle Oberfläche, grüne Akzente, Systemschrift, kompakter Kopf, dezente Aktionen, Kartenfläche für Inhalt.

- Oben links „Wissen“; rechts Suchfeld sowie kleine Aktionen Aktualisieren, Bearbeiten, API-Zugang (letztere nur WordPress).
- Suchergebnisse in einem nativen Dialog, keine eigene Suchseite. **Maximal 30 Treffer** (Nutzerentscheid 25.09.2026, bewusst weniger als die 100 der .NET-Fassade), Suchbegriff maximal 200 Zeichen. Die Suche läuft als vom Browser getaktete `SearchSession`: Der Baum wird schrittweise mit Zeitbudget je Heartbeat durchlaufen, Treffer erscheinen nach und nach.
- Breadcrumb-Leiste statt Zurück-Pfeil. Breadcrumb endet am Dokument.
- Links Bereiche; **darunter** „Auf dieser Seite“ als Dokumentgliederung. Seit 0.1.2 nicht mehr rechts.
- Erste ContentContainer-Area entlang der Ahnenkette bildet ein Dokument. Unter-Container sind Überschriften, keine separaten Seiten. Aufruf eines solchen Pfades leitet zum Dokument mit Fragment um.
- Anker aus stabiler logischer Identität oder Dokument/Überschrift/Vorkommen ableiten. Doppelte Überschriften müssen verschiedene IDs erhalten. Code-Fences dürfen keine Outline-Einträge erzeugen.
- Bearbeiten/API-Zugang öffnen Popups und dürfen den normalen Lesefluss nicht dominieren. Dialog schließen über X/Escape/Backdrop.
- Editor enthält kanonisches aggregiertes Markdown, keine bereits in HTTP-URLs umgeschriebenen Ressourcenreferenzen. Replace, sparse Append und Unterbereich anlegen bleiben verschiedene Aktionen.
- Fehler im Editor erhalten Eingabe und Dialog. Provider-`false` ist ein normaler Ablehnungsfall.
- Mobile Ansicht ohne horizontales Seitenoverflow; Code/Tabellen dürfen intern scrollen.
- **Darstellungsmodi** (`display_mode`, nur WordPress, `includes/WikiPresentation.php`): `neutral` = eigenständige Seite, nur `wiki.css` (Referenzoptik). `themed` = eigenständig + `wiki-themed.css` + inline `--accent`. `on_page` = Theme-Header/-Footer (klassisch `get_header()`, Blocktheme über Template-Parts), `wiki-on-page.css` (auf `#kornsw-wiki` gescoped, `all:revert`) + `wiki-themed.css`; Body-Klasse `kornsw-wiki-page`; eigene CSP entfällt dort. Akzent: Admin-Feld `accent_color`, sonst Heuristik (Global-Styles-Linkfarbe → Kadence → Palette `primary`,`accent`,… → Theme-Mods), Filter `kornsw_kr_accent_color`. Alle Grüntöne außer `--accent` sind in `wiki-themed.css` per `color-mix()` abgeleitet – neue Farbwerte in `wiki.css` dort ebenfalls nachziehen. On-Page hebt die Breitenbegrenzung des Theme-Inhaltswrappers auf (volle Inhaltsbreite der Seite, nicht Viewport). Assets werden mit `filemtime` versioniert.
- HTML/Suchergebnisdaten korrekt escapen. PHP: Parsedown SafeMode plus `wp_kses_post`. Inline-JavaScript mit frischem CSP-Nonce, kein `unsafe-inline` für Skripte. Browserantworten bleiben `no-cache`; der neue Cache ist serverseitig.
- Admin blendet ausschließlich passende Providerfelder ein und deaktiviert versteckte Inputs. Leeres Secretfeld erhält vorhandenes Secret; Entfernen nur explizit. WordPress benötigt keine PAT-/Branch-/URL-Felder.

## 7. Performance und Cache ab 0.1.2

**Ursache der früheren Langsamkeit:** Der Aggregator baut für jede Wiki-Seite die gesamte Mountstruktur auf. GitHub lud dabei auch sämtliche Markdown-Dokumente erneut, wenn der Benutzer eigentlich einen WordPress-Beitrag öffnete. WordPress parse-te Beiträge jedes Mal neu. Zusätzlich scannte die rekursive Baumsuche wiederholt alle Knoten.

Implementiert:

1. WordPress speichert aufbereitete Nodes und Attachment-Metadaten.
2. GitHub speichert Nodes, Datei-/Dokumenttabellen, Branch/Head/Tree und bereits gelesene Bytes (Base64 im Snapshot). Mutationen umgehen den Snapshot.
3. UJMW cached einzelne erfolgreiche Vertragsantworten nach Methode, kanonischen Argumenten, Quellkonfiguration und effektivem Token. Keine Token-übergreifende Freigabe von Antworten.
4. TreeRepository erzeugt für einen rekursiven Walk einmal einen Eltern→Kinder-Index statt quadratischer wiederholter Vollscans.
5. **Lazy Aggregator (25.09.2026):**
   - **Problem vorher:** `Aggregator::load()` rief bei *jedem* Request für jede Quelle `GetAreas(recurse:true)` auf. `CachedRepository` zerlegte das in einen gecachten `GetAreas`-Aufruf pro Knoten. Bei GitHub lud das jedes Verzeichnis und jeden Blob, denn Überschriften sind Areas. Ein kalter Cache bedeutete eine Datei pro Knoten.
   - **Jetzt – Besitz:** Wem ein Bereich gehört, ergibt sich aus den Mountpunkten plus der Kinderliste des Elternbereichs in der Quelle (`sourceHas`, top-down, memoisiert).
   - **Keine Existenzprüfung per 404:** `FileCache` cached keine Fehler, jeder 404 ginge also bei jedem Request erneut an die Quelle.
   - **Rekursive Aufrufe:** Joplin, UJMW-recurse und Keyword-Suche machen `prefetch()`, also genau einen rekursiven Aufruf je betroffener Quelle. `CachedRepository` cached rekursive Listen als *einen* Eintrag.
   - **Nicht zurückbauen:** Der Aggregator darf keinen globalen Baum mehr pro Request aufbauen.

`kornsw_kr_settings.cache_ttl`: Sekunden, Default **14400**. Admin zeigt Stunden, 0 deaktiviert, Maximum 168 Stunden. `KORNSW_KR_CACHE_DIR` optional als absoluter privater Pfad; Default `WP_CONTENT_DIR/kornsw-knowledge-cache`.

```php
define('KORNSW_KR_CACHE_DIR', '/srv/private/knowledgerepo-cache');
```

Cache-Key HMAC über Site-URL, Generation und Provideridentität; keine Klartext-Tokens in Dateinamen. Inhalte AES-256-GCM wie Secrets. Dateien atomar über temporäre Datei/rename schreiben, 0600; Verzeichnis 0700 soweit möglich. 256 feste Lock-Dateien vermeiden unbegrenzte Lock-Sammlung; `flock` und erneutes Lesen nach Lock vermeiden parallele identische Neubefüllung.

Fehler werden höchstens 30 Sekunden gecached, nicht vier Stunden. Ein erneutes Refresh umgeht auch diese Fehlergeneration. Alte Dateien werden probabilistisch nach sieben Tagen bereinigt. Ein nicht nutzbarer Cache darf Lesen nicht grundsätzlich verhindern; Fallback auf Quelle.

Invalidierung über `kornsw_kr_cache_epoch`: Adminspeicherung, explizites Wiki-Refresh, WordPress-Beitrags-/Termänderungen, Remote-Schreibversuche, GitHub-Ref-Veröffentlichung. Generation verhindert, dass ein alter laufender Loader später den neuen Cache überschreibt. Erste Seite nach Ablauf/Refresh benötigt wieder Quellzugriffe; kein Hintergrund-Warmup implementiert. HTTP-Timeouts können den ersten Aufbau weiterhin verzögern.

**Cache ist keine Autorisierung.** Rollen und JWT-Prüfung bleiben vor dem Repositoryzugriff. Bei neuer Quellkonfiguration/Tokenänderung keine alten Snapshots verwenden. Cache ist wegwerfbar; SyncStore ist es nicht! Bei vielen WordPress-Änderungen invalidiert der derzeit globale Epoch auch andere Quellen. Das ist konservativ korrekt, aber ein möglicher späterer Optimierungspunkt. Keine ungezielten `updated_post_meta`-Hooks hinzufügen: Besuchszähler könnten dadurch den Cache bei jeder Seite löschen.

## 8. Fehlerisolation: Wiki vs. Sync

`Aggregator(..., tolerant:false)` bleibt Standard. Nur Wiki, Suche und Wiki-Ressourcen verwenden tolerant=true. Fehlerhafte Quelle melden und andere Quellen darstellen. Synthetische Mountvorfahren erhalten; Fehler getrennt als Quelle/Mount/Meldung sammeln. Fehler innerhalb eines Requests nicht bei jeder Operation wiederholen.

**Joplin und UJMW bleiben strikt.** Einen unvollständigen Baum bei Quellausfall nicht als erfolgreiche vollständige Antwort an den Sync liefern: das könnte Löschungen/Suppressionen provozieren. Kein pauschales catch→leere Liste. Mutationen über einen ausgefallenen überlappenden Mount nicht in einen anderen Provider umleiten.

Overlays sind lesbar; mehrdeutige Schreibziele sowie providerübergreifende Moves ablehnen. Kein „erster Provider gewinnt“ bei Mutationen.

## 9. Joplin: besonders fragile Stellen

Profilzustand ist Dateisystempersistenz, getrennt je Username und dynamischer Profil-ID. Schlüssel:

`SHA256("JoplinProfile:v1:" + UTF16Length(username) + ":" + username + ":" + UTF16Length(profileId) + ":" + profileId)`

.NET zählt UTF-16-Codeunits, PHP darf hier nicht UTF-8-Bytes zählen. Passwort gehört nicht zur Identität. Übermitteltes Username-Casing wie im Original behandeln.

Anfangs deterministische IDs aus SHA256 von `type:area`, später persistente Client-Zuordnungen. Strukturelle/Aggregationsbereiche werden Notizbücher, erste Container Notizen, untergeordnete Container bleiben Überschriften. Änderungen an dieser Grenze erzeugen erhebliche Sync-Migrationen.

- Unveränderte GET/HEAD/PROPFIND dürfen keine neuen Transportzeiten oder Bytes erzeugen.
- Schlusszeile `type_: N` ohne abschließenden Zeilenumbruch; Ressourcenzeit `blob_updated_time` numerische Unix-Millisekunden.
- Kinder vor Eltern und Blob vor Metadaten sind zulässig: Pending erhalten und später auflösen.
- `:/id` kann Notizlink sein; nicht blind in Ressource umwandeln.
- DELETE unterdrückt die Projektion im Profil; niemals ungefragt das Wissen löschen.
- Move/Rename aktualisiert Ressourcen-Mapping, externe Joplin-ID bleibt stabil.
- Read-only erlaubt notwendige Sync-Locks/Metadaten, verbietet fachliche Mutationen.
- Unterbrochene mehrstufige Schreibsequenzen dürfen keine falschen Erfolgsmeldungen liefern. Nach erfolgreichem Teilschritt Mapping speichern; keine behauptete verteilte Gesamttransaktion.
- E2EE für projiziertes Wissen nicht unterstützt.

SyncStore separat, verschlüsselt, atomare Zustandsdatei, Profil-Lock mit 503 bei Konkurrenz. Standard `WP_CONTENT_DIR/kornsw-knowledge-state-<sitehash>`, Override `KORNSW_KR_STATE_DIR`. Beschädigten Zustand nicht automatisch als neues Profil ersetzen. Salt-Wechsel macht Secrets und SyncState unlesbar; Wiederherstellung planen, nicht reflexartig Dateien löschen.

## 10. Bekannte Grenzen und Prüfung

- PHP-Tests wurden mit simuliertem WordPress/GitHub ausgeführt, keine Live-Abnahme des Nutzerhostings/Joplin. Version 0.1.2: Syntaxprüfung sowie 21 bestehende Regressionen, zusätzliche Cache/Auth- und WordPress-Cachetests unter PHP 8.5.10. Frühere Versionen zusätzlich PHP 8.1.34.
- Stand 25.09.2026, geprüft unter PHP 8.1:
  - `tests/regression.php` (21 Szenarien) und `tests/aggregator-links.php` laufen unverändert standalone.
  - `tests/wordpress-provider.php` scheitert am fehlenden `FileCache`-Stub.
- Auf dem Entwicklerrechner (Windows) gibt es kein natives PHP. Funktionierender Weg: `@php-wasm/node` plus `@php-wasm/universal` per Node-Skript. Wichtig ist `loadNodeRuntime('8.1', { emscriptenOptions: { processId: 1 } })`, dazu das Repo per `createNodeFsMountHandler` mounten. `npx @php-wasm/cli` hat unter Windows einen Pfad-Bug (`C:\C:\…`).
- Menschenlesbare Doku folgt seit 25.09.2026 dem `ai-cowork-process`: `README.md`, `doc/requirements.md`, `doc/architecture.md`, `doc/quickstart.md` (inkl. Fehlerdiagnose und Live-Abnahme-Matrix), `doc/ideas.md`. Die früheren `ARCHITEKTUR.md`, `ABNAHME.md`, `TESTERGEBNIS.txt`, `UJMW-VERTRAG.md` (Stand 0.1.0) sind darin aufgegangen und gelöscht. Statische API-Doku gibt es bewusst nicht; maßgeblich ist das generierte `/wiki/ujmw/swagger.json` (`includes/OpenApi.php`).
- Kein echtes GitHub-Schreiben beim Testen. GitHub-API-Limits, Branchschutz, SSO und Netzwerkausfälle auf Zielsystem separat prüfen.
- Einzelrequests/Ressourcen 16 MiB, SyncState vor Verschlüsselung 128 MiB; keine Großmediathek.
- Markdownbaum und Ressourceninterpretation sind kein vollständiger CommonMark-Parser. Reference-style-/HTML-Ressourcenreferenzen nicht umfassend modelliert.
- Netzwerkfehler nach erfolgreichem externem Commit können ungewissen Erfolg bedeuten. Kein automatisches Wiederholen nichtidempotenter Appends ohne Prüfung.

Pflichttests bei Folgeänderungen: warme Reads ohne externe Aufrufe; TTL/0/Refresh; Token-/Source-Trennung; Fehlerquelle neben gesunder Quelle; niemals Teilbaum-Sync; aktuelle Rollen/Widerruf; anonymer Read und abgelehnte Mutation; unescaped Titel/Markdown/URL; doppelte Überschrift/Fence/Ankersuche; Pfadpräfixe; Joplin-Byte-Stabilität/Pending/DELETE/Ressourcen-Move.

## 11. ASP.NET-Core-HTML-Portierung: Dateien und Integration

Diese Ergänzung portiert ausschließlich die HTML-Fassade; sie implementiert weder neue Provider noch eine neue Joplin-/UJMW-Schnittstelle. Basis sind die beiden vom Nutzer gelieferten Dateien `KnowledgeRepositoryHtmlController.cs` und `KnowledgeRepositoryHttpRouteNames.cs`.

### Dateien

| Datei | Aktion |
|---|---|
| `KnowledgeRepositoryHtmlController.cs` | bisherige vollständige Datei ersetzen, Namespace `KnowledgeManagement.SmartStandards.Endpoints.Html` |
| `KnowledgeRepositoryHttpRouteNames.cs` | bisherige vollständige Datei ersetzen; alte Raw-/HTML-Konstanten unverändert, drei neue HTML-Routennamen |
| `KnowledgeRepositoryHtmlOptions.cs` | im ASP.NET-Core-Endpunktprojekt neu hinzufügen |

Bei SDK-Projekten werden neue C#-Dateien gewöhnlich automatisch aufgenommen. Bei Shared Projects oder expliziten `<Compile Include=...>`-Listen die Options-Datei zusätzlich in `.projitems`/`.csproj` aufnehmen. Keine gleichnamige zweite Controllerklasse hinzufügen. Keine PHP-Datei wird für diese Portierung geändert.

Der Konstruktor nimmt weiterhin `IKnowledgeRepository` entgegen; zusätzliche Argumente sind optional. Ohne Options-Registrierung: Lesen/Suche verfügbar entsprechend Host-Policy, Bearbeiten/Refresh gesperrt, Dateisystem-Cache aus. **Keine eigene JWT-Erzeugung, keine API-Zugang-Schaltfläche und kein Token-Aussteller** im .NET-Controller. Vorhandene Host-Authentifizierung kann weiterhin Cookies/JWTs verwenden; das ist unabhängig von der Oberfläche.

Beispiel in der bestehenden `Program.cs`, vor `builder.Build()` ergänzen. Vorhandene Registrierung von `IKnowledgeRepository` beibehalten:

```csharp
using KnowledgeManagement.SmartStandards.Endpoints.Html;
using Microsoft.AspNetCore.DataProtection;

builder.Services.AddAntiforgery();
builder.Services.AddDataProtection();

builder.Services.AddSingleton(new KnowledgeRepositoryHtmlOptions {
  // null/leer = kein Dateisystem-Cache. Absoluter privater Ordner, nicht wwwroot.
  CacheDirectory = builder.Configuration["KnowledgeRepo:CacheDirectory"],
  CacheLifetime = TimeSpan.FromHours(4),
  // Stabile, installationsspezifische Kennung. Bei Quell-/Mount-/Credentialwechsel ändern.
  CacheNamespace = "my-knowledge-repository-v1",
  CanEdit = context =>
    context.User.Identity?.IsAuthenticated == true &&
    context.User.IsInRole("KnowledgeEditor"),
  // Zusätzlich zur immer enthaltenen Benutzer-/Claim-/Header-Identität:
  CacheScope = context => context.User.FindFirst("tenant_id")?.Value ?? "default"
});
```

`KnowledgeEditor` ist ein Beispiel für eine Rolle des Hosts, keine neu angelegte Frameworkrolle. Den booleschen Hook an die tatsächliche Berechtigungsprüfung der Anwendung anpassen. Er steuert **Bearbeiten und Refresh gemeinsam**, wird pro Seitenaufruf und nochmals vor jedem POST geprüft. Die Suche benötigt diesen Hook nicht; sie unterliegt wie Lesen der Host-Zugriffskontrolle.

`AddControllers()`/`MapControllers()` wie bisher nötig. ASP.NET-Core-Authentifizierung und Lese-Autorisierung sind Aufgabe des Hosts. Dieselbe Lese-Policy auf HTML-Seiten, Suche und Raw-Ressourcen anwenden; der Controller erfindet hierfür keine zweite Rollenmatrix. Bei bloßer UI-Versteckung niemals stehenbleiben: manipulierte POSTs ohne Hook-Freigabe liefern 403, ohne gültiges Antiforgery-Token 400. Keine Freigabe nur aus einem vom Client gelieferten Formularwert ableiten.

Bei persistentem Cache und/oder mehreren Instanzen Data-Protection-Keyring gemäß Hoststandard dauerhaft und gegebenenfalls gemeinsam persistieren (`PersistKeysToFileSystem` und gemeinsamer ApplicationName). Andernfalls können alte Cachedateien nach Neustarts nicht lesbar sein und werden neu geladen. Private Ordnerrechte/ACLs beim Deployment setzen; die C#-Fassung verändert keine Systemberechtigungen. Reverse Proxy muss PathBase/Forwarded Headers nach Hoststandard korrekt setzen.

### Routen und URL-Erzeugung

Die physische Basisroute bleibt `[Route("api/knowledge")]`. Links werden ausschließlich über benannte Routen erzeugt; keine fest eingebauten `/wiki`-Pfade. PathBase wurde mit `/tenant` getestet.

| Verb | Relativ zur Controllerroute | Bedeutung |
|---|---|---|
| GET | leer | Wurzel |
| GET | `{**area}` | Dokument/Navigation bzw. Redirect auf Dokument#Anker |
| GET | `_search?q=...` | JSON-Suchergebnisse, maximal 100 |
| POST | `_refresh` | Cache invalidieren, optional Provider-Refresh-Hook, Ziel-URL als JSON |
| POST | `_edit` | `TryReplace`, `TryAppendContent` oder `TryAddSubArea`, Ziel-URL als JSON |

Die Literalsegmente `_search`, `_refresh`, `_edit` sind reserviert. Sie dürfen nicht als normale Root-Areanamen verwendet werden. Suchrouten lösen vor der Catch-all-Route auf. Ressourcen verwenden unverändert den bestehenden Namen `_RawResource` mit opakem `resourceId`; der Raw-Controller muss weiterhin registriert sein. Keine absoluten Hosts in Ressourcencaches speichern.

Edit sendet `area`, `operation`, `content` bzw. `name`/`kind` und Antiforgery-Token. Formularinhalt maximal 16 MiB. Provider-`false` ergibt 409 mit verständlicher Meldung, kein behaupteter Erfolg. Fehlermeldungen bleiben im Dialog, Eingabe erhalten. Erfolg navigiert zur serverseitig erzeugten Ziel-URL. Fehlerdetails nicht an den Browser leaken, über ILogger protokollieren.

Die UI ruft `TryReplace` direkt auf, niemals Truncate+Append als zwei Aufrufe. Wie im WordPress-Editor gibt es noch keine ETag-/Compare-and-swap-Sperre gegen gleichzeitig geöffnete Editoren. Das Repository garantiert atomare Einzeloperationen, nicht automatisch Konflikterkennung gegen den ursprünglichen Editorstand. Bei Folgearbeit nicht fälschlich eine Exactly-once-/Lost-update-Garantie behaupten.

### Optionaler .NET-Cache

Dieser Cache gehört **nur zur HTML-Fassade**. Andere UJMW-/Raw-/Joplin-Endpunkte werden dadurch nicht gecached. Er hält einzelne Repository-Lesewerte (Name, Level, Children, Aggregation, direkte Texte, Suchergebnis) fest; pro Request zusätzlich Memoisierung. Kein Cache von HTML, Antiforgery-Tokens, Hook-Ergebnissen oder mutierenden Aufrufen.

- Ohne Ordner oder bei Lifetime ≤ 0 ist der Dateisystem-Cache aus; Request-Memoisierung bleibt.
- Gecachte Payloads sind mit ASP.NET-Core Data Protection geschützt. Dateien liegen im Unterverzeichnis SHA256(CacheNamespace); Schlüssel berücksichtigen Namespace/Generation, Host/PathBase, Authentifizierungsart, Claims einschließlich Rollen/Issuer, Authorization-Header und optionalen zusätzlichen CacheScope.
- Claims werden für die Cacheidentität sortiert; fachliche Area-Reihenfolgen ausdrücklich nicht.
- Bei mandanten-/kontextabhängigen Repositories alle zusätzlichen Sichtbarkeitsdimensionen in CacheScope aufnehmen. Keine Roh-Tokens oder frei vom Benutzer gelieferte Pfade für Dateinamen verwenden.
- Atomare temp→rename-Dateiersetzung; feste Locks gegen gleichzeitige Befüllung innerhalb eines Prozesses. Mehrprozesszugriffe sind durch atomare Dateien gegen Teilreads geschützt, aber mehrere Prozesse können dieselbe Quelle parallel laden. Kein verteilter Single-flight behauptet.
- Generation in `epoch` invalidiert alle Sichtbarkeits-Scopes dieses Repository-Namespace. Vor und nach einer Mutation invalidieren; alte Loader schreiben nur in ihre alte Generation.
- Cache-Lesefehler/Entschlüsselungsfehler sind Misses. Schreibfehler beim Befüllen werden protokolliert, Quelle bleibt lesbar. Fehler der Quelle werden nicht als leere erfolgreiche Bäume persistiert.
- Refresh invalidiert zuerst den HTML-Cache. Optional `RefreshRepository` setzen, wenn der konkrete Provider selbst einen eigenen Cache besitzt; die Fassade kann einen unbekannten Providercache nicht automatisch leeren. Callback muss den Providercache invalidieren, nicht Inhalte verändern.
- Externe Änderungen über andere Endpunkte erscheinen spätestens nach TTL, bei manuellem Refresh früher. Externe Schreibpfade können ebenfalls eine eigene Invalidierungsintegration benötigen.
- Alte Cachedateien werden begrenzt und opportunistisch bereinigt. Das ist keine harte Speicherplatzquote; bei großen Installationen zusätzlich Betriebsbereinigung vorsehen. Cachedateien dürfen gelöscht werden, Data-Protection-Keyring oder SyncState nicht damit verwechseln.

### UI-Gleichheit und bewusste Grenzen

Das `GetPageCss()`-Literal ist **bytegleich** zum WordPress-Stylesheet 0.1.2. Farben, Maße, Schrift, Header, Breadcrumbs, Area-/Outline-Spalte, Dialoge und responsive Breakpoints sind übernommen. Keine zusätzliche CSS-Datei oder Frontend-Bibliothek erforderlich. Änderungen an der gemeinsamen Optik später in beiden Fassungen bewusst synchronisieren.

JavaScript entspricht den WordPress-Dialog-/Such-/Outline-Abläufen, ergänzt um echte ASP.NET-Core-POSTs und Antiforgery. Styles und Skript tragen einen frischen CSP-Nonce. Keine JWT-Dialoge enthalten. Keine WordPress-Login-URL im .NET-Frontend; Login gehört zum Host.

Der vorhandene paketfreie C#-Markdownrenderer wurde erhalten und um korrekt gepaarte Backtick-/Tilde-Fences, sichere URL-Behandlung und stabile Heading-IDs ergänzt. **Visuelle UI-Gleichheit bedeutet keine vollständige Parsergleichheit zu Parsedown.** Verschachtelte Listen, komplexe Inline-Syntax, reference-style Links und CommonMark-Sonderfälle bleiben Grenzen dieses Renderers. Alle normalen Texte/Attribute werden HTML-encodiert; rohes HTML wird als Text angezeigt. Nicht ungefragt Raw-HTML freigeben, um vermeintliche Formatierungsprobleme zu beheben.

Dokumentgrenze: erster Container in der Ahnenkette, einschließlich möglicher Root-Container. Unterbereiche werden auf Dokument+Anker umgeleitet; Suche verwendet dieselbe Ankerzuordnung. Doppelte Überschriften erhalten eigene IDs, Fences erzeugen keine Outlinepunkte. Editor nutzt Original-Markdown mit `knowledge-resource:`; nur die gerenderte Ansicht erhält HTTP-Ressourcenlinks.

Die Fassade kennt nur **ein** IKnowledgeRepository. Wenn dessen Aggregator bei einer ausgefallenen Quelle den kompletten Aufruf abbricht, kann der Controller nicht selbst andere Provider isolieren; das muss im .NET-Aggregator gelöst werden. Kein pauschales Catch-and-empty hinzufügen. Die übernommene Behandlung von `InvalidOperationException` für unauflösbare HTML-Bereiche kann 404 liefern; andere erfasste Quellfehler liefern 503. Fehlertypen ggf. mit dem tatsächlichen Providerframework abstimmen.

### Validierung dieses Übergabestands

- .NET-8-Build erfolgreich, 0 Warnungen/0 Fehler; ASP.NET-Core-Shared-Framework, keine zusätzlichen NuGet-Pakete für die Portierung.
- HTTP-Tests mit einer simulierten IKnowledgeRepository-Implementierung: PathBase, Unicode/Sonderzeichen, benannte Ressourcenroute, Suchtreffer#Anker, doppelte Überschriften, Fences, keine JWT-UI, warmer Cache ohne weitere Repository-Reads, Hook-403, Antiforgery-400, Refresh und anschließendes frisches Lesen, Edit und Cacheinvalidierung.
- Chromium-Browserprüfung gegen den laufenden Testserver: Outline links unter Areas, geschlossene Dialoge beim Start, Bearbeiten/Escape, kanonische Ressourcen im Editor, Suchdialog/Ankerklick, echte Refresh-/Edit-POSTs, 390px-Mobile ohne horizontalen Seitenoverflow, keine JavaScriptfehler. Desktop-/Mobile-Ansicht visuell geprüft.
- Kein Test gegen das vollständige Projekt des Nutzers, dessen konkrete DI-/Auth-Konfiguration, produktive Provider oder echten Joplin-Client. Die Projektintegration muss die oben beschriebenen Dienste und unveränderten Vertragsmethoden bereitstellen.
