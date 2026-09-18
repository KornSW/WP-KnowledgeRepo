# KornSW KnowledgeRepo

WordPress-Plugin von **KornSW** für ein provider-neutrales Wissensrepository mit Browser-Wiki, Joplin-WebDAV und UJMW. Projekt-/Repositoryname: **WP-KnowledgeRepo**. Der GitHub-Owner ist noch nicht angegeben; deshalb enthält der Plugin-Header bewusst keine erfundene Repository-URL.

## Installation

1. `kornsw-knowledgerepo-0.1.0.zip` in WordPress unter **Plugins → Installieren → Plugin hochladen** installieren und aktivieren.
2. **Einstellungen → KnowledgeRepo (KornSW)** öffnen; derselbe Link steht in der Plugin-Zeile.
3. Mindestens eine Quelle hinzufügen, Mountpunkt und ggf. Kategorien auswählen, speichern.
4. Gewünschte Rollen für Seite, Joplin und UJMW freischalten. Anfänglich ist nur Administrator freigeschaltet; anonymer Zugriff bleibt aus.
5. `/wiki/` öffnen. Falls die Webserver-Konfiguration die URL nicht zu WordPress weiterleitet, sprechende Permalinks aktivieren bzw. den üblichen WordPress-Frontcontroller konfigurieren.

Das Source-ZIP ist für das Repository gedacht, nicht für den WordPress-Installer. Es enthält `README.md`, `doc/` und `tests/` neben dem Plugin-Ordner.

## Voraussetzungen

- WordPress 6.4 oder höher, PHP 8.1 oder höher.
- PHP-Erweiterungen DOM, mbstring, OpenSSL, fileinfo.
- HTTPS für produktive Verwendung von Passwörtern/JWTs.
- Schreibbares `wp-content` für verschlüsselte Sync-Zustände.
- Ausgehendes HTTPS zu GitHub bzw. konfigurierten UJMW-Quellen.
- Der Webserver muss `Authorization` sowie WebDAV-Verben an WordPress weiterreichen.
- Keine Git-Installation, kein Repository-Checkout, kein separater Dienst, kein Composer auf dem Zielhost erforderlich.

## Zugänge

| Zugang | URL | Anmeldung |
|---|---|---|
| Wiki | `/wiki/` und `/wiki/<Bereich>/<Unterbereich>` | WordPress-Sitzung und Rollenmatrix |
| Joplin | `/wiki/joplin/<frei-gewaehlte-Profil-ID>/` | WordPress-Benutzername/Passwort; optional `anonymous` / `anonymous` |
| UJMW | `/wiki/ujmw/IKnowledgeRepository/<Operation>` | `Authorization: Bearer <JWT>` |
| Ressourcen für das Wiki | `/wiki/_resource/<opaque-ID>` | Dieselbe Seitenberechtigung |

Die Pfade sind relativ zur WordPress-Website; Installationen unter Unterpfaden werden berücksichtigt. Für unterschiedliche lokale Joplin-Profile unterschiedliche URL-Profil-IDs verwenden. Benutzername und Profil-ID bilden zusammen den persistenten Sync-Schlüssel.

## Quellen

**WordPress:** immer read-only; veröffentlichte, nicht passwortgeschützte Beiträge aus explizit ausgewählten Kategorien. Kategorienhierarchie bleibt erhalten. Mehrfachzugeordnete Beiträge erscheinen mehrfach. Elternkategorien werden zur Navigation ergänzt, ohne damit automatisch deren Beiträge freizugeben. HTML wird für die Wissenssicht in Markdown überführt; Originalbeiträge bleiben unverändert. Shortcodes und dynamische Blöcke werden nicht ausgeführt.

**GitHub:** `https://github.com/Owner/Repository`, optional PAT, Branch und Einstiegsverzeichnis. Markdown-Dokumente und Überschriften bilden den Wissensbaum. Lesen und Schreiben erfolgen ausschließlich über GitHubs HTTPS-API, ohne Klonen. Eine Repository-Mutation erzeugt einen Commit; bei konkurrierendem Ref-Update wird die logische Operation bis zu dreimal auf dem neuen Stand versucht. Kein Force-Push. Ein PAT braucht für private Repositories Leserechte und für Schreibzugriff Contents-Schreibrechte. Branch-Schutzregeln bleiben wirksam.

**UJMW:** Vertragsbasis-URL bis einschließlich des Vertragsnamens, z. B. `https://host/IKnowledgeRepository`. Optional festes JWT oder exakt `[PASS-TROUGH]`. Pass-through-Quellen sind nur über eingehendes UJMW verfügbar und werden in Wiki/Joplin nicht eingebunden. Tokens werden nie ungeprüft durch Redirects weitergeleitet.

Alle Provider dürfen mehrfach konfiguriert werden. Jeder Eintrag besitzt eine stabile interne ID. Mountpunkte können tief liegen oder sich überlagern; mehrdeutige Schreibziele und providerübergreifende Moves werden abgelehnt.

## Tokens

In der Wiki-Oberfläche unter **API-Zugang** erzeugen berechtigte eingeloggte Benutzer ein JWT und können ihre bisherigen Tokens widerrufen. Die Laufzeit ist im Backend in Sekunden einstellbar. Signatur: HS256, mit eigenem zufälligem Schlüssel. Bei jedem UJMW-Aufruf werden Signatur, Aussteller, Zielgruppe, Zeitfenster, Benutzerexistenz, Widerruf und aktuelle Rollenrechte geprüft. Administratoren können alle Tokens gemeinsam widerrufen.

Iteration 0.1.0 akzeptiert ausschließlich eigene Tokens. Es gibt noch kein Eingabefeld für externe Verifikationsschlüssel. Anonymer UJMW-Zugang ist deshalb deaktiviert.

## UJMW-Beispiel

POST `/wiki/ujmw/IKnowledgeRepository/GetAreas`

Header: `Authorization: Bearer TOKEN`, `Content-Type: application/json`

```json
{"recurse":true,"startArea":"/"}
```

Antwort:

```json
{"return":["/Handbuch","/Handbuch/Einleitung"]}
```

`GetAreaCapabilities` liefert die benannten `out`-Felder direkt; `byte[]` wird als Base64-String transportiert. Protokoll- und Providerfehler liefern ein `fault`-Feld sowie passenden HTTP-Status. Eine fachlich abgelehnte `Try…`-Operation kann `{"return":false}` liefern. Alle Parameter einer Mutation müssen ausdrücklich angegeben werden.

## Dokumentation und Prüfstand

- [Architektur und Wartung](doc/ARCHITEKTUR.md)
- [Abnahme und bekannte Grenzen](doc/ABNAHME.md)
- [Vertrag](doc/UJMW-VERTRAG.md)
- Tests: `php tests/regression.php` und `php tests/wordpress-provider.php`

Die automatischen Tests verwenden einen simulierten GitHub-Objektdienst und WordPress-Testfunktionen. Ein vollständiger Live-Roundtrip mit echtem WordPress, Joplin und GitHub wurde hier nicht durchgeführt. Diese Version ist als erste Integrationstestversion zu behandeln, nicht als bereits nachgewiesen bitidentischer Port des gesamten .NET-Frameworks.
