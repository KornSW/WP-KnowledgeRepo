# Ideen

Mögliche Weiterentwicklungen. Nichts davon ist beschlossen; eine Idee wird erst nach ausdrücklicher Entscheidung zu einer Anforderung in [requirements.md](requirements.md).

## Authentifizierung

- **Externe JWT-Aussteller:** Tokens fremder Identity-Provider akzeptieren (Issuer, Signaturschlüssel/JWKS konfigurierbar).

## Cache und Performance

- **Quellengenaue Invalidierung:** Heute setzt jede WordPress-Beitragsänderung die globale Cache-Generation zurück, also auch für GitHub- und UJMW-Quellen. Eine Generation je Quelle würde unnötige Neuladungen vermeiden.
- **Hintergrund-Warmup:** Nach Ablauf oder Refresh den Cache vorab füllen, statt die erste Seite warten zu lassen.

## Bearbeiten

- **Konflikterkennung im Editor:** ETag/Compare-and-swap, damit gleichzeitig geöffnete Editoren sich nicht unbemerkt überschreiben.
- **Dokument-zu-Überschrift-Umbau:** Ein ganzes Dokument als Überschrift in ein anderes verschieben (und umgekehrt).

## Markdown

- **Reference-style- und HTML-Ressourcenlinks** vollständig als Ressourcen modellieren.
- Bessere HTML→Markdown-Übersetzung für Tabellen und komplexe Gutenberg-Layouts.

## Tests

- `tests/wordpress-provider.php` wieder ohne Anpassung lauffähig machen (fehlender `FileCache`-Stub).

## RAW

- Schreibzugriff wie im .NET-RawController (POST = Append, DELETE = Truncate).
- Automatisierter Live-Test gegen eine WordPress-Testinstanz und einen echten Joplin-Client.
