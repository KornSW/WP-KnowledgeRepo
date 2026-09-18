# Abnahme – Version 0.1.0

## Durchgeführte automatische Prüfung

Ausgeführt mit PHP 8.1.34 über die PHP-WASM-CLI; zusätzliche Regressionen liefen mit PHP 8.5.10. Keine echten GitHub-Schreibzugriffe. GitHub wird als Blob-/Tree-/Commit-/Ref-Dienst simuliert, WordPress-Funktionen werden in den Standalone-Tests kontrolliert ersetzt.

`tests/regression.php` prüft 21 Szenarien:

1. Sparse Markdown-Merge erhält Zweige/Reihenfolge, Code-Fences erzeugen keine Überschriften.
2. Git-Baum, direkte Inhalte und Aggregation.
3. Ein Commit für mehrere Zweige; unveränderte Dateien bleiben bytegleich.
4. Konkurrierender Ref-Stand wird neu gelesen; Mutation wird einmal veröffentlicht, kein Force-Push.
5. Read-only-Git-Provider lehnt Änderungen ab.
6. Heading-Move erhält beide Eltern.
7. Gebundene Ressourcen bewegen sich mit; freie Ressourcen behalten Ort und reparierte relative Links.
8. Tiefe Aggregator-Mounts, Overlays und Ablehnung providerübergreifender Moves.
9. Stabile GET-/info.json-Bytes, numerische Ressourcenzeitstempel.
10. Joplin-DELETE zerstört keine Quellnotizen oder Ressourcen.
11. Kind vor Notizbuch wird nachträglich materialisiert.
12. Identischer PUT ist idempotent; Notizlinks bleiben Notizlinks.
13. Ressourcenimport, dokumentgebundener Screenshotname und kein Erfolg bei fehlgeschlagenem Ersatz.
14. Konflikt-Rebind ohne Wissensduplikat.
15. JWT, aktuelle Rollenrechte und persönlicher Tokenwiderruf.
16. Joplin-Read-only erlaubt Locks, lehnt Wissensänderungen ab.
17. Move plus Rename erhält stabile Joplin-Ressourcen-ID.
18. Notizbuch-Move erhält IDs der Kinder.
19. Pending-Notiz behält ihren Raw-Inhalt, bis Ressourcen eintreffen.
20. Verschlüsselter Zustand überlebt erneutes Öffnen, Benutzer bleiben getrennt.
21. Pass-through ohne eingehenden Token wird abgelehnt; Parameter werden typisiert geprüft.

`tests/wordpress-provider.php` prüft Kategorienhierarchie, Mehrfachzuordnung, publish-/Passwort-/Kategorie-Filterparameter, HTML-Ausgabe und Read-only-Verhalten.

Die Prüfung belegt die getestete Implementierungslogik. Sie ersetzt keine Live-Prüfung der WordPress-Hooks, des Hostings, eines echten .NET-UJMW-Clients und der jeweiligen Joplin-Version.

## Vor produktivem Einsatz noch auszuführen

| Prüfung | Erwartung |
|---|---|
| ZIP im Ziel-WordPress installieren | Plugin aktiv, Einstellungslink vorhanden, keine PHP-Warnung |
| Rollenmatrix ohne Freigabe | Alle drei Zugänge verweigert |
| Öffentliche Seite / anonymer Joplin-Zugang | Nur nach jeweiliger Freigabe lesbar |
| Bestehende WP-Beiträge | Ausgewählte Kategorien und Mehrfachzuordnungen korrekt, keine Beiträge geändert |
| Echter Joplin-Erstsync in leerem Testprofil | Notizbücher/Notizen/Bilder vollständig |
| Zweiter unveränderter Sync | Keine Konfliktschleife, keine künstlichen Änderungen |
| Joplin-Notiz in GitHub ändern | Commit im Testbranch, korrekte Markdown-Änderung |
| Screenshot, Notiz-Move/-Rename | Bild bleibt sichtbar, Ressourcen-ID stabil |
| Joplin-Delete | Quelle bleibt bestehen, nur Profilprojektion verschwindet |
| Zwei Joplin-Profile und zwei Benutzer | Getrennte Sync-Zustände |
| JWT erzeugen, verwenden, widerrufen | .NET-Client kann lesen; nach Widerruf 401 |
| Rollenentzug nach Tokenausstellung | Nächster Request verweigert |
| Externe UJMW-Quelle mit festem JWT / Pass-through | Antworten/Enums/out-Felder/Base64 kompatibel |
| Proxy/Host blockiert WebDAV oder Authorization | Hosting-Konfiguration anpassen, kein Workaround durch offenen Zugang |
| GitHub-Rate-Limit/Branch-Schutz | Fehler ohne falsche Erfolgsantwort, unveränderte Daten |

Keine echten Zugangsdaten in Git committen. Testen zuerst mit eigener Kategorie, eigenem Joplin-Profil und einem Testbranch.

## Bewusste Grenzen dieser Iteration

- Kein behaupteter vollständiger 1:1-Nachweis gegenüber allen .NET-Tests. Die genannten Kernfälle wurden nachgebildet; der echte Client-Roundtrip fehlt.
- WordPress-Inhalt wird nie zurückgeschrieben. HTML→Markdown ist eine Lesesicht; komplexe Gutenberg-/Tabellen-/Embed-Layouts sind nicht layouttreu. Externe Bilder bleiben externe Referenzen. Ausführbare Shortcodes werden nicht ausgeführt.
- GitHub arbeitet ausschließlich per API. Umfangreiche Quellbäume verursachen viele API-Aufrufe; aktuell kein persistenter Blob-Cache. Ein gekürzter API-Baum wird ausdrücklich als Fehler behandelt, nicht als vollständige Quelle.
- Einzelne Requests/Ressourcen sind auf 16 MiB, ein verschlüsselter Profilzustand vor Verschlüsselung auf 128 MiB begrenzt. Die Raw-Blobs werden Base64 im Zustandsdokument gespeichert. Das ist für kleine/mittlere Wissensbestände gedacht, nicht für große Mediatheken.
- Markdown-Baum ist ATX-basiert. Reference-style-Ressourcenlinks, HTML-Ressourcenattribute und sämtliche CommonMark-Sonderfälle sind nicht vollständig als Ressourcenmodell nachgebildet. Standard-Inline-Markdownbilder und -links werden unterstützt.
- Rename/Move von Dokumenten und Verzeichnissen sowie Heading-Moves zwischen Content-Containern werden unterstützt. Ein kompletter Dokument-zu-Heading-Strukturumbau ist nicht implementiert.
- Änderungen an betroffenen Markdown-Dokumenten können Zeilenenden/Leerzeilen/Überschriftenlevel normalisieren; unbetroffene Dokumente werden nicht absichtlich neu geschrieben.
- Explizites Git-Löschen ist endgültiges Entfernen im neuen Commit, ohne `.DELETED.md`-Soft-Delete. Git-Historie bleibt erhalten. Das betrifft ausdrücklich `TryDelete`, nicht Joplin-DELETE.
- Allgemeiner WebDAV-MOVE ist auf Raw-State-Dateien begrenzt. Semantisches Verschieben von Joplin-Notizen/Notizbüchern erfolgt über deren `parent_id`-PUT, wie beim Original. Kein universeller WebDAV-Dateiserver.
- Joplin E2EE wird für projiziertes Wissen nicht unterstützt. Verschlüsselte Inhalte können nicht serverseitig als Wissensbereiche verarbeitet werden.
- JWTs ausschließlich selbst ausgestellt. Keine externen Aussteller/Signaturschlüssel, kein anonymer UJMW-Zugang.
- Netzwerkfehler unmittelbar nach einem erfolgreichen externen Commit können ein unsicheres Ergebnis hinterlassen; keine verteilte Exactly-once-Garantie.
- Keine automatische GitHub-Updateintegration; dafür war kein konkretes Template/Repo-Owner vorgegeben. Repository-URL und Releaseabgleich sind nach Bekanntgabe des Owners nachzutragen.
- Keine Live-WordPress-/Joplin-Abnahme in dieser Arbeitsumgebung.

## Fehlerdiagnose

- **401 bei Joplin:** Username/Passwort, Weitergabe von Authorization und WordPress-Anmeldefilter prüfen.
- **403:** Rollenmatrix, Kanal, fehlendes Login/Nonce oder Read-only-Schreibversuch prüfen.
- **409 / `return:false`:** Provider lehnt Operation ab, Name belegt, mehrdeutiger Mount oder Strukturänderung nicht unterstützt.
- **503:** Remote-Dienst, GitHub-Limit, Profilsperre oder lokaler Speicher vorübergehend nicht verfügbar. Bei Profilsperre erneut versuchen.
- **Token nach Salt-Änderung / nicht lesbare Secrets:** Secrets erneut hinterlegen; verschlüsselten Sync-Zustand nicht unüberlegt verwerfen.
- **Wiki 404 außerhalb des Plugins:** Standard-WordPress-URL-Routing/Permalinks des Webservers prüfen.
