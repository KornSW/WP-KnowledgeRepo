# Architektur und Wartung – KornSW KnowledgeRepo 0.1.0

## Verbindliche Entscheidungen

- Name `KornSW KnowledgeRepo`, Author `KornSW`, Slug/Entry `kornsw-knowledgerepo`.
- Vorgesehenes Repository `WP-KnowledgeRepo`, Owner noch offen.
- README und doc neben Plugin-Ordner. Kein Changelog ohne gesonderten Auftrag.
- PHP-Adapter orientiert sich an SmartStandards/AiSupport, Commit `3376257f4a4f476ea100ed2c83aec350311f8e60` vom 17.09.2026.
- Maßgebliche Norm: `doc/[AI-Skill] KnowledgeRepository.md` im Original. Maßgebliche Joplin-Klassen unter `dotnet/src/SmartStandards.AiSupport.DemoWebService/[Experimental]`: aktueller Handler, Middleware und SyncStateStore. Die auskommentierte ältere Facade ist nicht die Implementierungsbasis.
- Bewusste Abweichung von GitBasedKnowledgeRepository: GitHub-Objekt-API statt LibGit2Sharp/Checkout.
- WordPress-Provider ausschließlich read-only. Die globale Rollenmatrix bleibt unabhängig von Providerfähigkeiten; abgelehnte Schreibversuche werden normal gemeldet.
- Kategorienhierarchie und Mehrfachzuordnungen beibehalten.
- Nur selbst ausgestellte JWTs in dieser Iteration. Externe Schlüssel später entscheiden.

## Schichten

| Datei | Verantwortung |
|---|---|
| `includes/Contract.php` | Operationen, Parameternamen, Fehler, Pfade, Markdownbaum, Basisklasse für geordnete Lesesichten |
| `includes/Http.php` | Sicherer JSON-Transport und UJMW-Client |
| `includes/WordPressRepository.php` | Kategorie-/Beitragsprojektion, HTML-Export, freigegebene Medien |
| `includes/GitHubRepository.php` | GitHub-Objekt-API, Markdownbereiche, Ressourcen, atomare Ref-Veröffentlichung |
| `includes/Aggregator.php` | Mounts, synthetische Eltern, Overlay-Lesen, konservative Mutation, opake Ressourcen-IDs |
| `includes/Auth.php` | Rollen, Basic Auth, eigene JWTs, Secret-Verschlüsselung, SyncStore |
| `includes/Joplin.php` | WebDAV-Verben, Joplin-Serialisierung, Projektionsidentitäten, Pending-Abhängigkeiten |
| `includes/Admin.php` | Konfiguration, Rollenmatrix, wiederholbare Quelleneinträge, Plugin-Einstellungslink |
| `includes/Plugin.php` | WordPress-Lifecycle, frühes Routing, Wiki, JWT-Ausgabe, UJMW-Server |
| `assets/wiki.css` | Responsive Wiki-Darstellung |
| `vendor/Parsedown.php` | Parsedown 1.7.4, MIT, lokal namespaced; Safe Mode und zusätzlich WordPress-HTML-Filter |

`Repository::call()` dient als einheitliche PHP-Vertragsgrenze. Die Operationen entsprechen den .NET-Methoden und behalten deren Parameter-/out-Namen. Provider kennen keine Joplin-IDs. Joplin kennt keine GitHub-Dateipfade. Ressourcen sind keine Wissensbereiche.

## Bereiche, Inhalt und Änderungen

Bereiche sind absolute logische Pfade. Rekursive Aufzählung ist Depth-first Pre-order, mit stabiler Reihenfolge. `ContentLevel` hat Werte 0 BeyondContent, 1 ContentAggregation, 2 ContentContainer. Aggregationen haben keinen direkten Text. Ressourcenverweise lauten `knowledge-resource:<opaque-id>`.

Markdown wird in einen geordneten Baum aus direktem Inhalt und Kindern zerlegt. ATX-Überschriften außerhalb von Code-Fences bilden Kinder. Append ist ein rekursiver, sparsamer Merge gleicher direkter Überschriften; abwesende Zweige bleiben unverändert und neue Geschwister kommen hinten hinzu. Replace ersetzt den adressierten Inhalt, Truncate erhält den adressierten Bereich. Move ordnet einen Bereich einem anderen Elternbereich zu, statt Text zu kopieren und die Quelle zu leeren.

GitHub-Verzeichnisse heißen logisch `[Ordner]`, Dokumente ohne `.md`, darunter folgen Überschriften. Doppelte Überschriften erhalten `~2`, `~3` usw. Alle Pfadinterpretationen liegen im Provider. Bei Verzeichnissen umfasst Aggregation nur direkte Markdown-Dokumente. Verzeichnisse nur mit Unterverzeichnissen sind BeyondContent. Nicht-Markdown-Dateien sind keine Bereiche. Symlink-Blobs werden ignoriert.

## GitHub-Schreibtransaktion

1. Branch und aktuellen Commit lesen, Baum des konfigurierten Einstiegsverzeichnisses auflösen.
2. Benötigte Blobs über SHA in den Requestspeicher laden.
3. Logische Mutation im Speicher ausführen; Text-/Hierarchie-/Ressourcenänderungen gemeinsam vorbereiten.
4. Neue Blobs und einen Baum mit `base_tree` erstellen. Pfade außerhalb der Einstiegswurzel bleiben unverändert.
5. Genau einen Commit mit dem aktuellen Head als Parent erzeugen.
6. Branch-Ref mit `force:false` aktualisieren. Erst dieser Schritt veröffentlicht die Änderung.
7. Bei Konflikt aktuellen Stand erneut lesen und ursprüngliche logische Mutation neu anwenden, höchstens drei Versuche.

Nicht referenzierte GitHub-Objekte eines fehlgeschlagenen Versuchs können entstehen; sie ändern den sichtbaren Branch nicht. Ein fehlgeschlagener Netzwerkaufruf nach serverseitig erfolgreichem Ref-Update kann prinzipbedingt ein unsicheres Ergebnis liefern. Aufrufer sollten bei Wiederholungen zuerst lesen, insbesondere bei Append.

Es gibt keine lokalen Repositories, keine Git-Befehle und keinen Checkout. Inhalte liegen während des Requests im Arbeitsspeicher. Es gibt derzeit keinen persistenten Blob-Cache; API-Limits und große Dokumentmengen müssen beim Integrationstest geprüft werden.

## Ressourcen

GitHub-Ressourcen-IDs sind `1.` plus Base64URL des providerinternen relativen Pfades. Das ist eine private Providerentscheidung. Aggregator-IDs kapseln Instanz-ID und die unveränderte Child-ID in eigener opaker Kodierung. Adapter dürfen diese IDs nicht auswerten.

Markdown-Dateien speichern normale relative Links. Beim Lesen übersetzt der Provider vorhandene lokale Ressourcen in kanonische Verweise; beim Schreiben zurück in relative Links. Links zu Markdown-Dokumenten bleiben Dokumentlinks. Fehlende oder externe Ziele werden nicht ungefragt als lokale Ressourcen importiert.

Dateien mit `<Dokument>.Res…` gelten als dokumentgebunden. Beim Verschieben/Umbenennen werden sie zusammen mit dem Dokument bewegt und Ressourcen-ID-Änderungen zurückgegeben. Freie Ressourcen bleiben liegen; relative Verweise werden repariert. Einfügen ohne Dateinamen verwendet einen dokumentbezogenen `Res`-Namen und MIME-basierte Erweiterung. Referenzierte Ressourcen werden beim expliziten Löschen nicht entfernt, solange Referenzen gefunden werden.

## Joplin

Profil-Schlüssel entspricht der .NET-Middleware:

`SHA256("JoplinProfile:v1:" + UTF16Length(username) + ":" + username + ":" + UTF16Length(profileId) + ":" + profileId)`

Das Passwort gehört nicht zur Identität. Groß-/Kleinschreibung des übermittelten Benutzernamens bleibt relevant, wie beim Original. Keine automatische Profilvergabe. Dieselbe URL kann mit anderen Benutzern verwendet werden; daraus entsteht getrennte Ablage.

Die ersten 16 Bytes von SHA256 über `type:area` liefern anfängliche 32-Hex-IDs. Danach können Client-IDs über persistente Zuordnungen weiterverwendet werden. Structural/Aggregation wird zu Notizbuch, erster Container unter einem Nicht-Container zu einer Notiz. Tiefere Container verbleiben als Überschriften innerhalb der Notiz.

- GET/HEAD/PROPFIND erzeugen bei unverändertem Wissen keine neuen Transportversionen.
- PUT aktualisiert Notizen, Notizbücher oder Ressourcen; `parent_id` löst logischen Move aus.
- Kinder vor Eltern sowie Ressourcen vor/nach Metadaten werden als Pending verarbeitet.
- Bekannte Notizlinks `:/id` werden nicht in Ressourcenverweise umgedeutet. Unbekannte Ziele werden bis zum Eintreffen ihrer Metadaten zurückgestellt.
- Ressourcen haben eigene stabile Joplin-IDs. Repository-ID-Änderungen aktualisieren die bestehende Zuordnung.
- DELETE von projizierten Inhalten unterdrückt die Projektion; niemals automatisch `TryDelete`/`TryDeleteResource` der Quelle.
- Konfliktkopie mit neuer ID darf einen eindeutig zuordenbaren unterdrückten Bereich erneut binden.
- Vorübergehende Repositoryfehler werden mit 503/Retry-After beantwortet; keine absichtlich falsche Erfolgsantwort.
- Letzte Serialisierungszeile ist `type_: N`, ohne abschließenden Zeilenumbruch.
- Ressourcen enthalten `blob_updated_time` als numerische Unix-Millisekunden.
- Read-only verhindert fachliche Mutationen, nicht notwendige Locks/Metadaten.
- Stale Projektionsressourcen werden nach Quellenentfernung nicht weiter ausgeliefert.

Ein Joplin-PUT kann mehrere Repository-Operationen benötigen, z. B. Move, Rename und Replace. Jede Operation ist einzeln atomar; es gibt keine verteilte Gesamttransaktion über die ganze Sequenz. Zuordnungen werden nach jedem erfolgreich abgeschlossenen Struktur-/Ressourcenschritt gespeichert, damit Wiederholungen den erreichten Stand erkennen.

## Persistenz und Schutz

Option `kornsw_kr_settings` enthält TTL, Berechtigungsmatrix und geordnete Quellen mit stabiler zufälliger ID. PATs/feste JWTs sind per AES-256-GCM verschlüsselt; Schlüsselableitung aus WordPress Auth-Salt. Das Wiki stellt sie nicht dar. Die Adminseite zeigt nur „gespeichert“; leeres Secretfeld erhält vorhandenen Wert, Entfernen ist explizit.

Sync-Zustand liegt standardmäßig unter `WP_CONTENT_DIR/kornsw-knowledge-state-<sitehash>/`. `.state`-Dateien sind ebenfalls AES-256-GCM-verschlüsselt. Je Profil existieren Lock und Zustand; ein einzelnes atomar ersetztes Zustandsdokument enthält Projektionsdatensätze, Ressourcen, Raw-Dateien, Collections und Pending-Items. Änderungen der WordPress-Salts erfordern erneute Konfiguration der Secrets und einen geplanten Neuaufbau des Sync-Zustands. Nicht spontan Zustandsdateien löschen.

Optional in `wp-config.php`:

```php
define('KORNSW_KR_STATE_DIR', '/absoluter/dauerhafter/privater/pfad');
```

Verzeichnisrechte 0700, Dateirechte 0600 soweit vom Host unterstützt. `flock` serialisiert Requests desselben Profils; bei belegtem Lock gibt es 503. Beschädigte Zustände werden nicht stillschweigend ersetzt. Deaktivierung erhält Konfiguration und Sync-Zustand. Automatische Löschung bei Deinstallation ist absichtlich nicht implementiert.

## Authentifizierung und Routing

Frühes `init`-Routing fängt `/wiki` vor Theme-Ausgabe/Canonical Redirects ab. Keine Seite oder Shortcode-Anlage erforderlich. Unterverzeichnisinstallationen verwenden den Pfad von `home_url`. `/wiki/joplin`, `/wiki/ujmw` und `/wiki/_resource` sind reserviert.

Seite: vorhandene WordPress-Cookies. Änderungen/JWT-Ausgabe/Widerruf nur per POST mit Nonce und Login. Mehrere Rollen: höchste explizit gewährte Kanalberechtigung. Kein Administrator-Bypass außerhalb der konfigurierten Matrix; Einstellungen bleiben über manage_options administrierbar.

Joplin: HTTP Basic, Passwortprüfung über `wp_authenticate`. Anonymous ausschließlich exakt `anonymous`/`anonymous`, plus Freigabe der anonymen Joplin-Spalte. Der Username beeinflusst den Profilzustand. Anwendungspasswörter sind nicht gesondert zugesichert; normale WordPress-Authentifizierungsfilter können Einfluss haben.

UJMW: Bearer-JWT ausschließlich aus Header. Prüfung von HS256, HMAC, iss, aud, nbf, exp, sub, Benutzerexistenz und persönlicher Widerrufsversion. Rechte stehen nicht als veralteter Snapshot im Token, sondern kommen bei jedem Request aus aktuellen Rollen. Schlüsselrotation widerruft alle Tokens. Keine externe Tokenvalidierung vorbereitet oder vorgetäuscht.

Remote-URLs müssen HTTPS ohne eingebettete Zugangsdaten sein. `wp_safe_remote_request` prüft Ziele; Redirects sind deaktiviert. GitHub-Host ist fest `api.github.com`. Der konfigurierte Pass-through-Endpunkt ist eine ausdrücklich vertrauenswürdige Empfängerstelle für das eingehende JWT.

Alle Antworten sind nicht cachebar. Wiki-Markdown wird im Safe Mode gerendert und durch `wp_kses_post` gefiltert. Ressourcenauslieferung ist rollenabhängig, mit `nosniff`; nur ausgewählte Rasterbild-MIMEs sind inline, andere Typen als Download. JavaScript wird im Wiki durch CSP gesperrt.

## Weiterentwicklung

- Vor weiterem Abgleich Originalrevision bewusst aktualisieren; keine stillschweigende Mischung verschiedener Vertragsstände.
- Bestehende Quelleneinträge nicht neu erzeugen, wenn nur Einstellungen geändert werden: ihre IDs gehören zur Ressourcenidentität.
- Bei Änderungen an Joplin immer Regressionen für Byte-Stabilität, Pending, Ressourcen und Delete-Suppression ausführen.
- Vor öffentlichen Releases Live-Testmatrix aus ABNAHME.md durchführen.
- Owner/Repo-URL nachreichen, Plugin-Header ergänzen und vorhandene Releases auf Versionsstand prüfen.
