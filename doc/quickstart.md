# Quickstart

## Voraussetzungen

- WordPress ≥ 6.4, PHP ≥ 8.1 mit den Erweiterungen DOM, mbstring, OpenSSL, fileinfo.
- HTTPS für den produktiven Einsatz von Passwörtern und JWTs.
- Schreibbares `wp-content`, für Cache und Joplin-Sync-Zustand.
- Ausgehendes HTTPS zu GitHub, UJMW-Quellen und URL-Dokumenten.
- Der Webserver/Proxy muss den `Authorization`-Header und WebDAV-Verben (PROPFIND, MKCOL, MOVE …) an WordPress durchreichen.

## Installation

1. Plugin-ZIP unter **Plugins → Installieren → Plugin hochladen** installieren und aktivieren.
2. Im Admin-Menü **KnowledgeRepo** öffnen (auch über „Einstellungen“ in der Plugin-Zeile).
3. Mindestens eine Quelle hinzufügen (siehe unten) und speichern.
4. In der Rollenmatrix festlegen, wer Wiki, Joplin und UJMW nutzen darf. Anfangs ist nur der Administrator freigeschaltet.
5. `/wiki/` aufrufen. Liefert WordPress dort eine 404, die Permalinks prüfen – der Webserver muss die URL an WordPress weiterleiten.

Optional in `wp-config.php` – private, dauerhafte Pfade außerhalb des Webroots:

```php
define('KORNSW_KR_CACHE_DIR', '/srv/private/knowledgerepo-cache');   // wegwerfbar
define('KORNSW_KR_STATE_DIR', '/srv/private/knowledgerepo-state');   // NICHT wegwerfbar (Joplin-Sync)
```

## Quellen einrichten

| Provider | Wichtigste Felder | Hinweise |
|---|---|---|
| WordPress-Beiträge | Kategorien, „Kategorien als Navigationsebenen“ | Immer nur lesend. Ohne Haken stehen alle Beiträge direkt am Mountpunkt. |
| GitHub | `https://github.com/Owner/Repo`, Branch, Einstiegsverzeichnis, PAT | Für private Repos Leserechte, zum Schreiben *Contents: write*. Branch-Schutz bleibt wirksam. |
| GitHub (multi) | Repository-URLs (eine pro Zeile) | Jedes Repo erscheint unter `<Mountpunkt>/<Repo-Name>`; Branch, Einstieg und PAT gelten für alle. |
| UJMW-Client | Vertragsbasis-URL bis einschließlich `IKnowledgeRepository`, JWT oder `[PASS-TROUGH]` | Pass-through-Quellen erscheinen nur bei UJMW-Aufrufen, nicht im Wiki/Joplin. |
| URL-Dokumente | je Eintrag URL, relativer Mountpunkt, Dateialias, Format | Wird erst beim Öffnen geladen, max. 4 MiB. Quellverweis optional. |
| Linkliste | Dateialias, Darstellung (Liste/Kacheln), Links mit Titel und optionalem Icon | Ohne Icon-URL wird `/favicon.ico` der Zielseite verwendet. |

Beim Speichern bleiben bestehende Quellen-IDs erhalten. Eine Quelle deshalb **nicht** löschen und neu anlegen, nur um Einstellungen zu ändern – das würde Ressourcen-IDs und Joplin-Zuordnungen ändern.

## Wiki-Darstellung anpassen

Unter **Wiki-Darstellung** den Display-Modus wählen:

- **Neutral** – eigenständige Seite in der Standardoptik.
- **Themed** – eigenständige Seite, Akzentfarbe aus dem Theme.
- **On-Page** – Wiki innerhalb von Header und Footer des Themes.

Die Akzentfarbe wird automatisch erkannt und lässt sich im Feld **Akzentfarbe** überschreiben. Entwickler können sie zusätzlich über den Filter `kornsw_kr_accent_color` setzen.

## Joplin verbinden

1. In Joplin **Synchronisation → WebDAV** wählen.
2. URL: `https://<site>/wiki/joplin/<Profil-ID>/` – die Profil-ID ist frei wählbar, z. B. `laptop`. Für jedes lokale Joplin-Profil eine eigene ID verwenden.
3. Benutzername/Passwort des WordPress-Kontos eintragen (bei Freigabe auch `anonymous` / `anonymous`).
4. Synchronisieren. Erst mit einem leeren Testprofil beginnen.

Löschen in Joplin blendet Inhalte nur in diesem Profil aus; die Quelle bleibt unverändert.

## UJMW nutzen

1. Im Wiki (angemeldet, mit UJMW-Recht) **API-Zugang** öffnen und ein JWT erzeugen.
2. Aufruf:

```http
POST /wiki/ujmw/IKnowledgeRepository/GetAreas
Authorization: Bearer <TOKEN>
Content-Type: application/json

{"recurse": true, "startArea": "/"}
```

```json
{"return": ["/Handbuch", "/Handbuch/Einleitung"]}
```

Konventionen:

- Parameter- und Out-Namen entsprechen der .NET-Schnittstelle.
- Der Rückgabewert steht unter `return`, Out-Werte auf gleicher Ebene; `byte[]` wird als Base64 übertragen.
- Bei Mutationen (`Try…`) müssen alle Parameter angegeben werden.
- `{"return": false}` ist eine normale fachliche Ablehnung.
- Fehler liefern `{"fault": "…"}` mit passendem HTTP-Status.

Die vollständige, aus dem Code erzeugte Beschreibung aller Operationen steht unter `/wiki/ujmw/swagger.json`.

Ist anonymer UJMW-Lesezugriff freigegeben, darf der Authorization-Header leer bleiben. Tokens widerrufen: im Dialog **API-Zugang** (eigene Tokens) oder über Schlüsselrotation im Admin (alle Tokens).

## Fehlerdiagnose

| Symptom | Prüfen |
|---|---|
| 401 bei Joplin/UJMW | Zugangsdaten bzw. Token; kommt der `Authorization`-Header bei PHP an? |
| 403 | Rollenmatrix, Kanal, Login/Nonce, Schreibversuch auf eine nur lesende Quelle |
| 409 / `return:false` | Provider lehnt ab: Name belegt, mehrdeutiger Mount, Struktur nicht unterstützt |
| 503 | Quelle nicht erreichbar, GitHub-Rate-Limit (Reset-Zeit in der Meldung), Joplin-Profilsperre – erneut versuchen |
| Wiki zeigt veraltete Inhalte | **Aktualisieren** im Wiki oder Cache-Lebensdauer im Admin prüfen |
| Secrets/Sync nach Salt-Wechsel unlesbar | Tokens/PATs neu hinterlegen; Sync-Zustand nicht vorschnell löschen |
| `/wiki/` liefert Theme-404 | Permalinks/Webserver-Weiterleitung an WordPress |

## Live-Abnahme

Vor dem produktiven Einsatz mit eigener Testkategorie, eigenem Joplin-Profil und einem GitHub-Testbranch prüfen:

| Prüfung | Erwartung |
|---|---|
| Rollenmatrix ohne Freigabe | Alle drei Zugänge verweigert |
| Anonyme Freigabe je Kanal | Nur nach Freigabe lesbar, Schreiben abgelehnt |
| WordPress-Kategorien | Richtige Beiträge und Mehrfachzuordnungen; kein Beitrag verändert |
| Ausfall einer Quelle | Wiki zeigt die übrigen Quellen und eine Meldung; Joplin/UJMW melden einen Fehler |
| Joplin-Erstsync, danach zweiter Sync ohne Änderungen | Vollständig; keine Konfliktschleife, keine künstlichen Änderungen |
| Joplin-Notiz ändern (GitHub-Quelle) | Commit im Testbranch mit korrekter Markdown-Änderung |
| Screenshot einfügen, Notiz verschieben/umbenennen | Bild bleibt sichtbar, Ressourcen-ID stabil |
| Joplin-Delete | Quelle bleibt bestehen |
| Zwei Profile, zwei Benutzer | Getrennte Sync-Zustände |
| JWT erzeugen, nutzen, widerrufen; Rolle entziehen | Nach Widerruf bzw. Rollenentzug abgelehnt |
| Externe UJMW-Quelle (festes JWT / Pass-through) | Enums, Out-Felder und Base64 kompatibel |
| GitHub-Rate-Limit/Branch-Schutz | Fehler ohne falsche Erfolgsmeldung |

Keine echten Zugangsdaten in Git committen.
