<?php
namespace KornSW\KnowledgeRepo;

/** OpenAPI 3 document describing the actual UJMW wire contract, not REST-like approximations. */
final class OpenApi {
    public static function document(): array {
        $docs = self::docs(); $paths = [];
        foreach (Contract::METHODS as $method=>$defaults) {
            $doc = $docs[$method]; $properties = []; $example = [];
            foreach ($defaults as $name=>$default) {
                $schema = ['type'=>is_bool($default) ? 'boolean' : (is_int($default) ? 'integer' : 'string')];
                if ($name === 'kind') { $schema = ['oneOf'=>[['type'=>'integer','enum'=>[0,1]], ['type'=>'string','enum'=>['Structural','Content']]]]; }
                if ($name === 'content' && strpos($method,'Resource') !== false) { $schema['format'] = 'byte'; }
                $schema['description'] = $doc['params'][$name] ?? self::parameterDescription($name);
                if (!Contract::mutation($method)) { $schema['default'] = $default; }
                $properties[$name] = $schema;
                $example[$name] = self::example($method, $name, $default);
            }
            $request = ['type'=>'object','properties'=>$properties];
            if (Contract::mutation($method)) { $request['required'] = array_keys($properties); }
            $response = self::response($method, $doc);
            $responses = ['200'=>['description'=>'Successful call. For Try* operations, return=false means the requested mutation was not performed.', 'content'=>['application/json'=>['schema'=>$response]]]];
            foreach ([400=>'Invalid JSON or arguments.',401=>'Missing, invalid, expired or revoked JWT.',403=>'Channel or write permission denied.',404=>'Unknown operation or area/resource not found.',405=>'Use POST for service calls.',409=>'Conflict or unsupported operation.',413=>'Request exceeds 16 MiB.',502=>'Invalid upstream response.',503=>'Source unavailable or cache lock busy. Retry-After may be provided.'] as $status=>$description) {
                $responses[(string)$status] = ['description'=>$description,'content'=>['application/json'=>['schema'=>['$ref'=>'#/components/schemas/Fault']]]];
            }
            $security = [['LocalJWT'=>[]], ['RawJWT'=>[]]];
            if (!Contract::mutation($method) && !empty(Auth::settings()['permissions']['anonymous']['ujmw'])) { $security[] = (object)[]; }
            $paths['/' . $method] = ['post'=>['operationId'=>$method,'tags'=>[Contract::mutation($method) ? 'Mutations' : 'Reads'],
                'summary'=>strtok($doc['summary'], "\n"), 'description'=>$doc['summary'], 'security'=>$security,
                'requestBody'=>['required'=>true,'content'=>['application/json'=>['schema'=>$request,'example'=>$example]]], 'responses'=>$responses]];
        }
        return ['openapi'=>'3.0.3','info'=>['title'=>'KornSW KnowledgeRepo – IKnowledgeRepository (UJMW)', 'version'=>'0.1.9',
            'description'=>$docs['_interface']['summary'] . "\n\nWIRE PROTOCOL: Send POST to {server}/{operation}, Content-Type: application/json. Arguments are JSON properties with the exact casing documented below, not a params wrapper. Always send an object ({} is valid for defaulted reads). Return values use the property return; out parameters are sibling properties. GetAreaCapabilities has only out properties and no return field. Binary payloads are base64 strings. Logical area paths start with / and are sent unchanged in JSON (the special browser URL escaping does not apply). Resources are opaque IDs. There is no IKnowledgeRepository segment in the URL.\n\nAUTH: Supply a locally issued JWT as Authorization: Bearer TOKEN or Authorization: TOKEN. Obtain it from the wiki API dialog after WordPress login. WordPress cookies do not authorize UJMW calls. Anonymous reads with an empty header work only if enabled by the administrator; invalid nonempty tokens never fall back to anonymous. Mutations always require explicit write permission. Respect capabilities; Try* may return false for read-only providers.\n\nSTART: POST GetAreas with {\"recurse\":false,\"startArea\":\"/\"}, then GetAreaName and GetAreaCapabilities for returned paths. Iterate GetAreas to navigate, GetAggregatedContent to read a document, or GetAreasByKeyword to search. Avoid recursively loading an entire remote repository unless needed.\n\nERRORS: HTTP errors return {\"fault\":\"message\"}. Do not treat these as success. Read aggregation may preserve healthy sources and last-known-good cached content when another source is unavailable. Server-Timing provides phase/cache diagnostics without credentials."],
            'servers'=>[['url'=>rtrim(home_url('/wiki/ujmw/'), '/')]], 'paths'=>$paths,
            'components'=>['securitySchemes'=>[
                'LocalJWT'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'JWT'],
                'RawJWT'=>['type'=>'apiKey','in'=>'header','name'=>'Authorization','description'=>'Alternative: put the JWT itself in this header, without Bearer.']],
                'schemas'=>[
                    'Fault'=>['type'=>'object','required'=>['fault'],'properties'=>['fault'=>['type'=>'string']]],
                    'KnowledgeResourceInfo'=>['type'=>'object','required'=>['ResourceId','FileName','ContentType','Length'],'properties'=>[
                        'ResourceId'=>['type'=>'string','description'=>'Opaque identifier; never decode or reconstruct it.'],
                        'FileName'=>['type'=>'string'], 'ContentType'=>['type'=>'string'], 'Length'=>['type'=>'integer','format'=>'int64']]],
                    'KnowledgeResourceIdChange'=>['type'=>'object','required'=>['PreviousResourceId','CurrentResourceId'],'properties'=>[
                        'PreviousResourceId'=>['type'=>'string'], 'CurrentResourceId'=>['type'=>'string']]]]]];
    }
    private static function parameterDescription(string $name): string {
        return ['area'=>'Absolute logical area path.', 'resourceId'=>'Opaque resource identifier.', 'contentType'=>'MIME type of binary content.', 'content'=>'Base64-encoded binary resource bytes.', 'preferredFileName'=>'Suggested resource filename.'][$name] ?? $name;
    }
    private static function example(string $method, string $name, $default) {
        if ($name === 'keyword') { return 'example'; }
        if ($name === 'resourceId') { return 'opaque-resource-id'; }
        if ($name === 'contentType') { return 'text/plain'; }
        if ($name === 'preferredFileName') { return 'example.txt'; }
        if ($name === 'name' || $name === 'newName') { return 'Examples'; }
        if ($name === 'newContent' || $name === 'content') { return strpos($method, 'Resource') !== false ? 'aGVsbG8=' : "# Examples\nNew content"; }
        if ($name === 'contentAreaToMove') { return '/collection/document'; }
        if ($name === 'newParentArea') { return '/destination'; }
        return $default;
    }
    private static function response(string $method, array $doc): array {
        if ($method === 'GetAreaCapabilities') {
            $p = ['contentLevel'=>['oneOf'=>[['type'=>'integer','enum'=>[0,1,2]], ['type'=>'string','enum'=>['BeyondContent','ContentAggregation','ContentContainer']]],'description'=>'0: navigation; 1: aggregation without direct content; 2: concrete content container.']];
            foreach (['supportsSubAreas','canBeRenamed','canBeDeleted','canAddSubAreas','canAppendContent','canTruncate','supportsResources'] as $key) { $p[$key] = ['type'=>'boolean','description'=>$doc['params'][$key] ?? $key]; }
        } else {
            $return = ['type'=>Contract::mutation($method) || $method === 'HasDirectContent' ? 'boolean' : 'string'];
            if (in_array($method, ['GetAreas','GetAreasByKeyword'], true)) { $return = ['type'=>'array','items'=>['type'=>'string']]; }
            if ($method === 'GetResources') { $return = ['type'=>'array','items'=>['$ref'=>'#/components/schemas/KnowledgeResourceInfo']]; }
            if ($method === 'GetResourceContent') { $return['format'] = 'byte'; }
            if (!empty($doc['returns'])) { $return['description'] = $doc['returns']; }
            $p = ['return'=>$return];
            if ($method === 'TryAddResource') { $p['resourceId'] = ['type'=>'string','description'=>'New opaque ID on success; empty on failure.']; }
            if (in_array($method, ['TryRename','TryMoveContent'], true)) { $p['resourceIdChanges'] = ['type'=>'array','items'=>['$ref'=>'#/components/schemas/KnowledgeResourceIdChange']]; }
        }
        return ['type'=>'object','required'=>array_keys($p),'properties'=>$p];
    }
    private static function docs(): array {
        return json_decode(<<<'CONTRACT_DOCS'
{
  "_interface": {
    "summary": "Provides ordered hierarchical access to a logical knowledge repository.\n\nA knowledge repository exposes knowledge through absolute logical area paths.\nAreas form an ordered tree. The public contract deliberately abstracts from\nphysical persistence concepts such as files, directories, Git repositories,\nMarkdown documents, headings, notebooks, pages or database records.\n\nProviders MAY internally use any such concepts. Providers MAY also expose\nvirtual areas that have no direct physical representation, provided that their\nbehavior follows this contract.\n\nArea paths are absolute and begin with \"/\". The repository root itself is \"/\".\nArea paths are logical addresses and MUST NOT expose provider-specific physical\nstorage paths.\n\nAreas participate in textual content according to ContentLevel:\nContentLevel.BeyondContent for pure navigation,\nContentLevel.ContentAggregation for content-access scopes that do\nnot own direct content, and ContentLevel.ContentContainer for\nconcrete areas that may own direct textual content.\n\nArea ordering is semantically significant. Providers MUST preserve the natural\norder of sibling areas. Recursive enumeration MUST use pre-order traversal:\nparents are returned before descendants and siblings remain in their natural\nprovider-defined order.\n\nMutating operations are atomic from the consumer's perspective. An operation\neither completes fully or leaves the repository in its previous externally\nobservable state.",
    "params": {},
    "returns": ""
  },
  "GetAreas": {
    "summary": "Returns logical area paths below the specified start area.\n\nIf recurse is false, only direct children of\nstartArea are returned.\n\nIf recurse is true, all descendants are returned using\npre-order traversal. Each parent MUST appear before its descendants.\nSiblings MUST preserve their natural repository order.\n\nOrdering is part of repository semantics. Providers MUST NOT arbitrarily\nreorder areas alphabetically, by creation time or by storage iteration order\nunless that order is explicitly defined as the provider's natural order for\nthe affected structural level.\n\nFor Markdown-backed content structures, sibling order below the first\ncontent-bearing area MUST correspond to the order of the represented sections\nin the underlying content.\n\nVirtual aggregation areas are returned in the same way as physical areas when\nthey are part of the exposed logical tree. Their order MUST also be stable and\ndeterministic.",
    "params": {
      "recurse": "true to return the complete descendant tree; false to return direct children only.",
      "startArea": "The absolute logical area path at which enumeration starts. \"/\" represents\nthe repository root."
    },
    "returns": "The matching absolute logical area paths in stable natural hierarchical order."
  },
  "GetAreasByKeyword": {
    "summary": "Returns logical area paths that match the supplied keyword within the subtree\nrooted at startArea.\n\nMatching semantics are provider-defined but MUST remain provider-neutral from the\ncaller's perspective. A provider MAY match against logical area names, logical\npaths, direct textual content or another deterministic searchable representation.\n\nThe operation is read-only and MUST NOT mutate, normalize or rewrite the\nunderlying repository. Returned paths MUST be absolute logical area paths and MUST\nrefer to areas that are addressable through the same repository instance.\n\nProviders SHOULD preserve their natural stable repository order in the returned\nresult rather than introducing an unrelated ranking or storage order.",
    "params": {
      "keyword": "The keyword or search term to match.",
      "startArea": "The absolute logical area path at which searching starts."
    },
    "returns": "The matching absolute logical area paths in stable provider order."
  },
  "GetAreaName": {
    "summary": "Returns the provider-neutral logical display name of the specified area.\n\nConsumers MUST use this method when they need the human-readable identity of an\narea. They MUST NOT derive display names by decoding logical path segments because\npath-segment encoding is provider-specific.\n\nThe returned name represents only the addressed area's direct logical name and\ndoes not include ancestor names or physical storage syntax.",
    "params": {
      "area": "The absolute logical area path."
    },
    "returns": "The direct logical display name of the addressed area."
  },
  "GetAreaCapabilities": {
    "summary": "Returns the effective capabilities of the specified logical area.\n\nCapabilities describe what the current provider permits for this concrete area.\nThey MAY depend on provider type, source configuration, permissions, physical\nstorage restrictions, logical depth, virtual-area semantics or other\nprovider-specific constraints.\n\ncontentLevel describes how the area participates in content:\n\n- ContentLevel.BeyondContent means the area is purely structural.\n- ContentLevel.ContentAggregation means aggregated content can be\n  obtained through the area, but the area owns no direct textual content.\n- ContentLevel.ContentContainer means the area may own direct\n  textual content and may additionally contain subordinate content.\n\nsupportsSubAreas indicates whether the area can structurally\ncontain direct child areas. This is intentionally independent from\ncanAddSubAreas. A read-only or preconfigured area may expose\nexisting children while creation of additional children is forbidden.\n\ncanBeRenamed indicates whether the addressed area itself may\nbe renamed while preserving its direct content, descendants and sibling position.\n\ncanBeDeleted indicates whether the addressed area itself and\nits complete descendant tree may be removed.\n\ncanAddSubAreas indicates whether new direct child areas may be\ncreated below the area.\n\ncanAppendContent indicates whether\nTryAppendContent(string, string) is generally supported.\n\nFor a ContentLevel.ContentAggregation area, direct unstructured\ntext cannot be appended because the area owns no direct textual content.\nAppending may nevertheless be valid when the supplied content contains\nsufficient subordinate structure so that all content can be routed into\nsubordinate content areas.\n\nsupportsResources indicates whether textual content addressed\nthrough the area may use canonical knowledge-resource:<ResourceId>\nreferences and the repository can resolve the associated binary resources. Resource\nsupport does not make resources part of the area hierarchy.\n\ncanTruncate indicates whether\nTryTruncate(string) may clear the content tree below the area while\npreserving the addressed area itself. This may be true for\nContentLevel.ContentAggregation even though that area has no direct\ncontent, because truncation can remove its subordinate content structure.\n\nComposite operations derive their effective permission from these capabilities.\nTryReplace(string, string) requires both truncate and append\ncapability.\n\nTryMoveContent(string, string) is intentionally NOT derived from\ntruncate and append capabilities. Moving a logical content scope to a new parent\nis structurally different from copying textual payload into another area and then\nclearing the source. Whether a concrete pair of areas can participate in a move is\ntherefore validated by the provider when the move is attempted.\n\nA purely virtual aggregation area may legitimately report all mutation\ncapabilities as false while still supporting aggregated reads.",
    "params": {
      "area": "The absolute logical area path.",
      "contentLevel": "The area's effective content level.",
      "supportsSubAreas": "Whether the area structurally supports child areas.",
      "canBeRenamed": "Whether the addressed area itself may be renamed.",
      "canBeDeleted": "Whether the addressed area and descendants may be deleted.",
      "canAddSubAreas": "Whether new direct child areas may be created.",
      "canAppendContent": "Whether hierarchical content append is generally supported.",
      "canTruncate": "Whether the area's complete subordinate content tree may be cleared.",
      "supportsResources": "Whether textual content addressed through this area can reference and resolve binary\nresources through the repository resource contract. This capability is independent\nfrom whether the area itself is structural or content-bearing."
    },
    "returns": ""
  },
  "GetResources": {
    "summary": "Returns the resources referenced by the resource-capable document scope containing\nthe specified area.\n\nEvery returned KnowledgeResourceInfo.ResourceId is opaque and\nprovider-defined. Consumers MUST NOT interpret its value.",
    "params": {},
    "returns": ""
  },
  "GetResourceContent": {
    "summary": "Returns the complete binary content of one logical resource.",
    "params": {
      "resourceId": "The opaque provider-defined resource identifier."
    },
    "returns": ""
  },
  "TryAddResource": {
    "summary": "Atomically creates a new binary resource in the resource scope containing the\nsupplied area.\n\npreferredFileName is a non-binding descriptive hint. A provider\nmay preserve it when its storage model supports human-readable file names, or may\nchoose another physical representation when the supplied value is absent, invalid\nor collides with existing storage.",
    "params": {},
    "returns": ""
  },
  "TryReplaceResource": {
    "summary": "Atomically replaces the binary content of an existing logical resource.",
    "params": {},
    "returns": ""
  },
  "TryDeleteResource": {
    "summary": "Atomically deletes an unreferenced logical resource.\n\nProviders MUST reject deletion while exposed textual content still references\nknowledge-resource:<ResourceId>.",
    "params": {},
    "returns": ""
  },
  "HasDirectContent": {
    "summary": "Determines whether the specified area currently owns non-empty direct textual\ncontent.\n\nDirect content is content logically owned by the addressed area itself and\nexplicitly excludes all content belonging to descendant areas.\n\nFor ContentLevel.ContentAggregation, this method MUST always return\nfalse because aggregation areas never own direct textual content.\n\nFor ContentLevel.ContentContainer, the result reflects whether\ndirect textual content currently exists.\n\nFor ContentLevel.BeyondContent, direct textual content is not\nsemantically applicable and the provider should handle the request consistently\nwith its general invalid-operation policy.",
    "params": {
      "area": "The absolute logical area path."
    },
    "returns": "true if direct textual content exists; otherwise false."
  },
  "GetDirectContent": {
    "summary": "Returns only the direct textual content logically owned by the specified area.\nDescendant content MUST NOT be included.\n\nFor ContentLevel.ContentAggregation, this method MUST return\nstring.Empty because aggregation areas do not own direct content.\n\nFor ContentLevel.ContentContainer, the provider returns the direct\ncontent owned by the area.\n\nIn a Markdown-oriented provider, if a content container is represented by a\nheading, direct content corresponds to the textual block after that heading and\nbefore the first subordinate heading. The heading used to identify the area is\nstructural representation and is not itself direct content.",
    "params": {
      "area": "The absolute logical area path."
    },
    "returns": "The direct textual content of the area, or an empty string for a content aggregation area."
  },
  "GetAggregatedContent": {
    "summary": "Returns the complete textual representation accessible through the specified\ncontent-capable area, including subordinate content areas in natural hierarchical\norder.\n\nThis method is valid for both ContentLevel.ContentAggregation and\nContentLevel.ContentContainer.\n\nFor a content aggregation area, the returned value is assembled entirely from\nsubordinate content because the aggregation area itself owns no direct content.\nThe aggregation may represent a physical parent such as a notebook or may be\nvirtual and synthesized from content located in multiple unrelated physical or\nlogical source areas.\n\nFor a content container, the result includes the area's own direct content plus\nall subordinate content.\n\nAggregation MUST preserve the logical order exposed by the provider. The provider\nis responsible for rendering subordinate structure in a valid textual form.\n\nA virtual cross-cutting aggregation MAY intentionally project selected content\nfrom multiple source branches, for example all \"Conclusion\" sections or all code\nexample sections. Such an aggregation MUST be deterministic, read-consistent and\nexplicit through its area identity and reported capabilities.\n\nThis operation is read-only and MUST NOT mutate, normalize or rewrite the\nunderlying source repository.",
    "params": {
      "area": "The absolute logical content-capable area path."
    },
    "returns": "The complete aggregated textual content exposed through the area."
  },
  "TryDelete": {
    "summary": "Atomically deletes the specified logical area together with its complete\ndescendant tree.\n\nThis operation removes the addressed area itself and is therefore intentionally\ndifferent from TryTruncate(string), which preserves the addressed\narea and only clears its content scope.\n\nPhysical deletion semantics are provider-specific. A provider may delete a\ndirectory, document, page, virtual definition or another backing artifact.\nConsumers observe only the logical result.\n\nVirtual or synthesized areas may legitimately be non-deletable.\n\nUnaffected siblings MUST retain their content and relative order.",
    "params": {
      "area": "The absolute logical area path to delete."
    },
    "returns": "true if the complete deletion succeeded atomically; otherwise false."
  },
  "TryRename": {
    "summary": "Atomically renames the specified logical area while preserving its direct\ncontent, complete descendant tree and sibling position.\n\nOnly the addressed area's own logical name changes.\nDescendants remain descendants of the renamed area and preserve their order.\n\nThe provider MUST reject a rename that would create an ambiguous logical sibling\naddress.\n\nA provider may map this operation to a directory rename, document rename, heading\nrename, page rename or another provider-specific operation.\n\nVirtual aggregation areas may legitimately report that rename is unsupported.\n\nA provider-native rename may also change one or more opaque resource identifiers.\nSuch changes MUST be returned through resourceIdChanges so\nstateful adapters can update their mappings without recreating their own external\nresource identities.",
    "params": {
      "area": "The absolute logical area path to rename.",
      "newName": "The new direct logical name.",
      "resourceIdChanges": "Receives provider resource identifier changes caused by the rename."
    },
    "returns": "true if the rename succeeded atomically; otherwise false."
  },
  "TryAddSubArea": {
    "summary": "Atomically creates one new direct child area below the specified parent area.\n\nkind communicates only the semantic role requested by the\ncaller. It MUST NOT be encoded indirectly into name. Consumers\nmust therefore never need provider-specific naming conventions in order to request\na structural child versus a content-bearing child.\n\nA provider maps the requested semantic kind to its own representation and may\nreject combinations that it cannot represent below the supplied parent.\n\nThe new child is appended after existing direct children unless the provider's\nlogical model explicitly defines another stable natural insertion rule. Existing\nsiblings MUST NOT be reordered.\n\nThe method creates only the requested direct child area. Complex subtrees and\ndistributed additive changes should be performed through\nTryAppendContent(string, string).",
    "params": {
      "area": "The absolute logical parent area path.",
      "name": "The direct logical name of the new child area.",
      "kind": "The provider-neutral semantic kind requested for the new child."
    },
    "returns": "true if the child area was created atomically; otherwise false."
  },
  "TryAppendContent": {
    "summary": "Atomically performs a non-destructive sparse hierarchical merge of the supplied\ntextual content into the specified content-capable target area.\n\nThis operation is intentionally stronger than physical end-of-file appending.\nThe supplied content is interpreted as a relative logical content tree rooted at\nthe target area.\n\nFor ContentLevel.ContentContainer:\n\n- Unstructured content appearing before the first structural child in the input\n  is appended to the target area's existing direct-content region.\n- Structured child blocks are merged recursively into matching direct children\n  or appended as newly created children when no match exists.\n\nFor ContentLevel.ContentAggregation:\n\n- The incoming payload MUST NOT contain direct unstructured content for the\n  aggregation area itself because aggregation areas own no direct content.\n- The payload MAY contain subordinate structure.\n- That subordinate structure is merged recursively into existing descendants or\n  used to create new descendants when permitted.\n\nFor every incoming direct child at every recursion level:\n\n1. If a matching direct child already exists, the operation recursively merges\n   into that child.\n2. If no matching direct child exists, the incoming child subtree is appended as\n   a new child after existing siblings.\n3. Existing areas are never deleted, replaced, moved or reordered.\n4. Existing sibling order is preserved exactly.\n5. Newly created siblings preserve their incoming relative order.\n\nMatching is always performed against direct children of the current merge target.\nIt MUST NOT perform an implicit recursive global name search.\n\nThe incoming payload may therefore be sparse and may address multiple existing\nbranches in one call. A single append can update several distributed descendant\nbranches while also creating missing branches.\n\nIn providers that interpret structured text such as Markdown, structural levels\nin the payload MUST be interpreted relative to the target area's current logical\ndepth. Physical levels may need rebasing. The logical parent-child relationships\nare authoritative.\n\nWhen the payload contains canonical knowledge-resource:<ResourceId>\nreferences and the provider reports resource support for the affected content scope,\nevery successfully committed reference MUST remain resolvable after the mutation. A\nprovider whose physical resource storage is scope-local is responsible for creating\nany additional physical materialization required by the new content location without\nchanging the referenced ResourceId.\n\nProviders SHOULD avoid unnecessary rewrites of unaffected existing content.\nThis is especially important for version-controlled providers where small logical\nmutations should ideally create small physical diffs.\n\nIf any part of the incoming payload is invalid, cannot be routed, violates naming\nconstraints, exceeds provider-specific structural limits or cannot be persisted\natomically, the complete operation MUST fail and leave the repository unchanged.\n\nThis method is intended as the primary efficient additive mutation primitive,\nespecially for AI agents. A caller can address the nearest common ancestor of\nmultiple intended changes and provide one sparse structured payload rather than\nperforming many separate round trips.",
    "params": {
      "area": "The absolute logical content-capable target area path.",
      "content": "The direct and/or structured textual content to merge."
    },
    "returns": "true if the complete hierarchical append succeeded atomically; otherwise false."
  },
  "TryTruncate": {
    "summary": "Atomically clears the complete content scope represented by the specified\ncontent-capable area while preserving the addressed area itself.\n\nFor ContentLevel.ContentContainer, truncation removes:\n\n- all direct textual content owned by the area, and\n- all descendant areas together with their content.\n\nFor ContentLevel.ContentAggregation, the area owns no direct content,\nso truncation removes its complete subordinate content structure while preserving\nthe aggregation area itself.\n\nThe addressed area retains its own identity, name, parent relationship and sibling\nposition.\n\nRead-only or purely virtual aggregations may report truncation as unsupported.\n\nThe operation MUST be atomic. If any part of the represented content scope cannot\nbe removed, no externally observable partial mutation may remain.",
    "params": {
      "area": "The absolute logical content-capable area path."
    },
    "returns": "true if truncation completed atomically; otherwise false."
  },
  "TryReplace": {
    "summary": "Atomically replaces the complete content scope represented by the specified area\nwith the supplied new content.\n\nThe operation is semantically equivalent to a successful\nTryTruncate(string) followed by a successful\nTryAppendContent(string, string), but MUST be implemented as one\nexternally atomic mutation.\n\nFor ContentLevel.ContentContainer, the replacement payload may\ncontain direct content and subordinate structure.\n\nFor ContentLevel.ContentAggregation, the replacement payload MUST\nnot contain direct unstructured content for the aggregation area itself. It may\ncontain subordinate structure that reconstructs the aggregation's content scope.\n\nCanonical knowledge-resource:<ResourceId> references in the replacement\ncontent are part of the textual contract. A resource-capable provider MUST preserve\ntheir validity and MUST NOT silently rewrite a ResourceId merely because the physical\nresource scope or storage location changes.\n\nThe addressed area itself is preserved.\n\nBoth truncate and append capabilities are required. The provider SHOULD validate\nthe complete replacement payload before publishing any destructive changes.\nIf any part cannot be completed, the previous repository state MUST remain\nexternally unchanged.",
    "params": {
      "area": "The absolute logical content-capable target area path.",
      "newContent": "The complete replacement content scope."
    },
    "returns": "true if replacement completed atomically; otherwise false."
  },
  "TryMoveContent": {
    "summary": "Atomically reparents the complete logical scope addressed by\ncontentAreaToMove below\nnewParentArea.\n\nThis operation moves the addressed logical element itself. It does NOT copy the\nsource payload into newParentArea, does NOT replace or truncate\nnewParentArea, and does NOT interpret the new parent as the\nresulting address of the moved element.\n\nThe two parameters deliberately represent different structural levels:\n\n- contentAreaToMove identifies the existing logical child scope\n  whose parent relationship shall change.\n- newParentArea identifies the existing logical area that shall\n  become the parent of that moved scope.\n\nAfter a successful move, the moved scope keeps its logical name, its direct\ncontent and its complete descendant tree. Its former parent remains present and\nmerely loses that child. The new parent remains present and unchanged except for\ngaining the moved child at the provider-defined insertion position.\n\nExamples of the same abstract operation include:\n\n- moving a Markdown section from one section to another section,\n- moving a section from one document to another document,\n- moving a document from one collection/folder scope to another,\n- moving an equivalent content scope in a database-, API-, Git- or virtual-backed\n  provider.\n\nResources referenced by the moved textual scope are part of the moved knowledge\nsemantics. A provider MUST preserve their resolvability after the move.\n\nResource identifiers are provider-owned and may change when the provider-native\nidentity changes, for example when a FileBased resource path changes. Every such\nidentifier transition MUST be returned through\nresourceIdChanges. Resources that remain at their provider-native\nlocation keep their identifiers unchanged. Existing references outside the moved\nscope MUST remain valid as well.\n\nThe physical mechanism is entirely provider-specific. A provider may implement the\noperation through a filesystem move, a Markdown subtree rewrite, a database parent\nupdate, a Git rename, an API call or any other representation-specific mechanism.\nConsumers MUST NOT depend on any such representation detail.\n\nnewParentArea does not need to own direct textual content. A\npurely structural or aggregating area may be a valid new parent when the provider\ncan represent the moved scope below it. Conversely, a content-bearing area may be\nan invalid parent for a particular source type. The provider validates the concrete\nsource/parent combination.\n\nThe provider MUST reject a move when the new parent is the moved area itself, lies\ninside the moved area's descendant subtree, cannot structurally contain the moved\nscope, or would create an ambiguous/colliding direct child identity.\n\nMoving an area below its current parent MAY be treated as an idempotent successful\nno-op. Providers MUST preserve the natural relative order of unaffected siblings.\n\nThe complete operation MUST be atomic from the consumer's perspective. If the move\ncannot be completed, the previously observable logical tree and content MUST remain\nunchanged.",
    "params": {
      "contentAreaToMove": "The absolute logical area path of the existing scope that shall change its parent.\nThis path addresses the element being moved, not its former parent.",
      "newParentArea": "The absolute logical area path that shall become the parent of the moved scope.\nThis area is not replaced, truncated or otherwise used as the destination content\npayload itself.",
      "resourceIdChanges": "Receives provider resource identifier changes caused by the move."
    },
    "returns": "true if the complete reparenting operation succeeded atomically or was already in\nthe requested parent relationship; otherwise false."
  }
}
CONTRACT_DOCS
        , true);
    }
}
