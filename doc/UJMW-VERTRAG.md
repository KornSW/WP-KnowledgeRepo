# UJMW-Vertrag 0.1.0

Basis: `/wiki/ujmw/IKnowledgeRepository/`. Jede Operation ist ein POST auf Basis + Methodenname. Authentifizierung ausschließlich `Authorization: Bearer JWT`. Ein fehlender oder falscher Token führt zu 401; fehlende Kanal-/Schreibrechte zu 403. JSON-Body ist ein Objekt mit den folgenden Parametern; out-Parameter gehören nur in die Antwort.

| Operation | Requestfelder | Antwort |
|---|---|---|
| GetAreas | recurse: bool, startArea: string = "/" | return: string[] |
| GetAreasByKeyword | keyword: string, startArea: string = "/" | return: string[] |
| GetAreaName | area: string | return: string |
| GetAreaCapabilities | area: string | contentLevel, supportsSubAreas, canBeRenamed, canBeDeleted, canAddSubAreas, canAppendContent, canTruncate, supportsResources |
| GetResources | area: string | return: KnowledgeResourceInfo[] |
| GetResourceContent | resourceId: string | return: Base64-string |
| HasDirectContent | area: string | return: bool |
| GetDirectContent | area: string | return: string |
| GetAggregatedContent | area: string | return: string |
| TryDelete | area: string | return: bool |
| TryRename | area: string, newName: string | return: bool, resourceIdChanges: KnowledgeResourceIdChange[] |
| TryAddSubArea | area: string, name: string, kind: 0 oder 1 | return: bool |
| TryAppendContent | area: string, content: string | return: bool |
| TryTruncate | area: string | return: bool |
| TryReplace | area: string, newContent: string | return: bool |
| TryMoveContent | contentAreaToMove: string, newParentArea: string | return: bool, resourceIdChanges: KnowledgeResourceIdChange[] |
| TryAddResource | area: string, preferredFileName: string, contentType: string, content: Base64-string | return: bool, resourceId: string |
| TryReplaceResource | resourceId: string, contentType: string, content: Base64-string | return: bool |
| TryDeleteResource | resourceId: string | return: bool |

Alle Requestfelder von Mutation-Operationen müssen vorhanden sein; leere Strings sind möglich, sofern fachlich sinnvoll. Unbekannte zusätzliche Requestfelder, beispielsweise `_`, werden nicht in den Repositoryvertrag übernommen. Ein anwendungsspezifischer Sidechannel wird weder erzeugt noch weitergeleitet.

`ContentLevel` wird numerisch ausgegeben: 0 = BeyondContent, 1 = ContentAggregation, 2 = ContentContainer. Beim Empfangen externer Fähigkeiten werden auch diese Enum-Namen berücksichtigt. `KnowledgeAreaKind` akzeptiert 0/1 sowie Structural/Content.

`KnowledgeResourceInfo`: PascalCase-Felder `ResourceId`, `FileName`, `ContentType`, `Length`. `KnowledgeResourceIdChange`: `PreviousResourceId`, `CurrentResourceId`. Der äußere UJMW-Umschlag verwendet die ursprünglichen Parameternamen und `return`.

```json
{
  "return": true,
  "resourceIdChanges": [
    {
      "PreviousResourceId": "opaque-old",
      "CurrentResourceId": "opaque-new"
    }
  ]
}
```

Ein Fehler liefert z. B. HTTP 503 und `{"fault":"Remote-Dienst nicht erreichbar."}`. Fachliche Ablehnung darf eine normale Antwort mit `return:false` sein. Bei TryRename/TryMoveContent enthält sie zusätzlich ein leeres `resourceIdChanges`-Array; bei TryAddResource eine leere `resourceId`.

Client-Konfiguration: die Basis-URL inklusive IKnowledgeRepository, ohne Methodenname hinterlegen. Das Plugin ergänzt `/<Operation>`. Ein gespeicherter Token wird als Bearer-Header gesendet. `[PASS-TROUGH]` übernimmt ausschließlich den bereits am eingehenden UJMW-Kanal validierten Roh-Token; keine automatische Ausgabe eines anderen Tokens.
