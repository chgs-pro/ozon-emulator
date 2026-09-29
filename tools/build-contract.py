"""Build a pinned Seller API contract profile from the swagger of the installed SDK (dev dependency phpsoftbox/ozon).

Run from any directory after `composer install`:
  python3 tools/build-contract.py fbs /v2/warehouse/list /v4/posting/fbs/unfulfilled/list ...
Writes contracts/<name>.json in the same shape as fbo.json: request/response schema per path
and the closed set of referenced component schemas. Original schemas are copied without edits.
"""
import hashlib, json, subprocess, sys
from datetime import date
from pathlib import Path

name, paths = sys.argv[1], sys.argv[2:]
root = Path(__file__).resolve().parents[1]
sdk = root / 'vendor/phpsoftbox/ozon'
source = sdk / 'docs/swagger.json'
raw = source.read_bytes()
spec = json.loads(raw)
installed = json.loads((root / 'vendor/composer/installed.json').read_text())
commit = next(p['source']['reference'] for p in installed['packages'] if p['name'] == 'phpsoftbox/ozon')

def schema(operation, kind):
    if kind == 'request':
        body = operation.get('requestBody', {}).get('content', {}).get('application/json', {}).get('schema')
        return body or {'type': 'object'}
    # Methods like exemplar set/update document 200 without a body schema: Ozon answers with an empty object.
    content = operation['responses']['200'].get('content')
    if not content:
        return {'type': 'object'}
    # A file method (act/get-pdf) describes its file as an object of the only non-JSON content type.
    return content['application/json']['schema'] if 'application/json' in content else next(iter(content.values()))['schema']

def content_type(operation):
    content = operation['responses']['200'].get('content') or {}
    return None if not content or 'application/json' in content else next(iter(content))

result = {'sourceCommit': commit, 'sourceSha256': hashlib.sha256(raw).hexdigest(), 'paths': {}, 'components': {'schemas': {}},
          'fixtureVersion': 1, 'verifiedAt': date.today().isoformat()}
pending = []
for path in paths:
    operation = spec['paths'][path]['post']
    result['paths'][path] = {'request': schema(operation, 'request'), 'response': schema(operation, 'response')}
    if content_type(operation):
        result['paths'][path]['responseContentType'] = content_type(operation)
    pending += [result['paths'][path]['request'], result['paths'][path]['response']]

def refs(node):
    if isinstance(node, dict):
        for key, value in node.items():
            if key == '$ref' and isinstance(value, str):
                yield value.split('/')[-1]
            else:
                yield from refs(value)
    elif isinstance(node, list):
        for value in node:
            yield from refs(value)

while pending:
    for ref in refs(pending.pop()):
        if ref not in result['components']['schemas']:
            result['components']['schemas'][ref] = spec['components']['schemas'][ref]
            pending.append(spec['components']['schemas'][ref])

target = root / 'contracts' / (name + '.json')
target.write_text(json.dumps(result, ensure_ascii=False, indent=1) + '\n')
print(target, len(result['paths']), 'paths,', len(result['components']['schemas']), 'schemas')
