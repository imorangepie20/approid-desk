#!/usr/bin/env python3
"""Local Hindsight operations. Never print credentials or provider error bodies."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT / 'infra/hindsight/compose.yaml'
IMAGE = 'ghcr.io/vectorize-io/hindsight:0.10.2@sha256:d1840062a5b79940ab7a9f4809ceb90fc776d4ad737cd9329e9b5836cc64ab70'
VOLUME = 'approid-desk-hindsight-data'
# Official Hindsight tested list, checked 2026-10-03. Never silently choose an unlisted model.
CANDIDATES = ['gemini-3.5-flash', 'gemini-3.1-flash-lite', 'gemini-3.1-pro-preview']


def stamp():
    return dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')


def backup_file(path):
    if path.exists():
        dest = Path.home() / '.local/state/approid-desk-hindsight/backups' / stamp()
        dest.mkdir(parents=True, mode=0o700)
        backup = dest / path.name
        shutil.copy2(path, backup)
        backup.chmod(0o600)
        return backup


def settings(path):
    values = {}
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        if line.strip() and not line.lstrip().startswith('#'):
            key, sep, value = line.partition('=')
            if not sep:
                raise ValueError('Invalid local settings format')
            values[key.strip()] = value.strip()
    return values


def api_json(url, key):
    req = urllib.request.Request(url, headers={'x-goog-api-key': key})
    with urllib.request.urlopen(req, timeout=180) as response:
        return json.load(response)


def select_model(path):
    values = settings(path)
    key = values.get('HINDSIGHT_API_LLM_API_KEY', '')
    if not key:
        raise ValueError('Enter the API key in the external local settings file first')
    print('External Gemini models.list request; generation/retain tests can incur API charges.')
    available, token = set(), ''
    while True:
        query = urllib.parse.urlencode({'pageSize': 1000, **({'pageToken': token} if token else {})})
        result = api_json('https://generativelanguage.googleapis.com/v1beta/models?' + query, key)
        for model in result.get('models', []):
            if 'generateContent' in model.get('supportedGenerationMethods', []):
                available.add(model['name'].removeprefix('models/'))
        token = result.get('nextPageToken', '')
        if not token:
            break
    matches = [model for model in CANDIDATES if model in available]
    if not matches:
        raise ValueError('No overlap with the recorded Hindsight tested models; review official list')
    previous = values.get('HINDSIGHT_API_LLM_MODEL', '')
    chosen = previous if previous in matches else matches[0]
    backup_file(path)
    lines = path.read_text(encoding='utf-8-sig').splitlines()
    lines = [line for line in lines if not line.startswith('HINDSIGHT_API_LLM_MODEL=')]
    lines.append('HINDSIGHT_API_LLM_MODEL=' + chosen)
    path.write_text('\n'.join(lines) + '\n', encoding='utf-8')
    (path.parent / 'model-verification.json').write_text(json.dumps({
        'checked_at_utc': stamp(), 'selected': chosen, 'listed_candidates': matches,
        'generation_test': 'pending; list visibility does not prove quota or generation access',
        'source': 'https://hindsight.vectorize.io/developer/models',
    }, indent=2) + '\n')
    print('Selected model:', chosen, '(generation/quota test pending)')


def compose(args, env, capture=False):
    return subprocess.run(['docker', 'compose', '-f', str(COMPOSE), *args],
                          env=env, check=True, capture_output=capture, text=True)


def probe():
    for port, path in [(8888, '/health'), (9999, '/')]:
        with urllib.request.urlopen(f'http://127.0.0.1:{port}{path}', timeout=15) as r:
            print(f'HTTP {port}: {r.status}')
    url = 'http://127.0.0.1:8888/mcp/approid-desk/'
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}

    def rpc(payload):
        req = urllib.request.Request(url, data=json.dumps(payload).encode(), headers=headers)
        with urllib.request.urlopen(req, timeout=180) as response:
            session = response.headers.get('Mcp-Session-Id')
            if session:
                headers['Mcp-Session-Id'] = session
            body = response.read().decode()
        if not body:
            return {}
        if body.lstrip().startswith('{'):
            return json.loads(body)
        for line in body.splitlines():
            if line.startswith('data: '):
                value = json.loads(line[6:])
                if value.get('id') == payload.get('id'):
                    return value
        raise ValueError('No matching MCP response')

    init = rpc({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
        'protocolVersion': '2024-11-05', 'capabilities': {},
        'clientInfo': {'name': 'approid-local-probe', 'version': '1.0'}}})
    if 'error' in init:
        raise ValueError('MCP initialization failed')
    headers['MCP-Protocol-Version'] = init['result']['protocolVersion']
    rpc({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
    result = rpc({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}})
    names = sorted(tool['name'] for tool in result.get('result', {}).get('tools', []))
    if not {'retain', 'recall'} <= set(names):
        raise ValueError('Expected retain/recall tools missing')
    print('MCP handshake and tools/list passed:', ', '.join(names))
    print('This probe is not verification of either GUI client or of memory persistence.')
    return rpc


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['check-models', 'validate', 'up', 'status', 'stop', 'restart', 'backup', 'probe'])
    parser.add_argument('--env-file', default=os.environ.get('HINDSIGHT_ENV_FILE'))
    args = parser.parse_args()
    if not args.env_file:
        parser.error('Set HINDSIGHT_ENV_FILE to the external local hindsight.env')
    path = Path(args.env_file).resolve(strict=True)
    if path.is_relative_to(ROOT):
        raise ValueError('Secrets must be outside the Git repository')
    env = dict(os.environ, HINDSIGHT_ENV_FILE=str(path))
    if args.action == 'check-models':
        select_model(path)
    elif args.action == 'validate':
        compose(['config', '--quiet'], env)
        print('Compose validation passed (no resolved settings printed).')
    elif args.action == 'up':
        values = settings(path)
        if not values.get('HINDSIGHT_API_LLM_API_KEY') or not values.get('HINDSIGHT_API_LLM_MODEL'):
            raise ValueError('Key and verified model required: enter key, then run check-models')
        record = json.loads((path.parent / 'model-verification.json').read_text())
        if values['HINDSIGHT_API_LLM_MODEL'] != record['selected']:
            raise ValueError('Model changed; rerun check-models')
        print('Starting Hindsight: Gemini startup verification and subsequent memory operations may incur charges.', flush=True)
        compose(['up', '-d', '--wait', '--wait-timeout', '600'], env)
    elif args.action == 'probe':
        probe()
    elif args.action == 'backup':
        # Cold archive: stop the one service for a consistent embedded PostgreSQL backup.
        running = bool(compose(['ps', '--status', 'running', '-q', 'hindsight'], env, True).stdout.strip())
        dest = path.parent / 'backups'
        dest.mkdir(parents=True, exist_ok=True)
        target = dest / ('hindsight-data-' + stamp() + '.tgz')
        subprocess.run(['docker', 'volume', 'inspect', VOLUME], check=True, stdout=subprocess.DEVNULL)
        compose(['stop', 'hindsight'], env)
        try:
            with target.open('xb') as output:
                subprocess.run(['docker', 'run', '--rm', '--network', 'none', '--user', '0',
                    '--entrypoint', 'tar', '-v', VOLUME + ':/data:ro', IMAGE,
                    'czf', '-', '-C', '/data', '.'], stdout=output, check=True)
            backup_file(path)
            print('Cold database archive:', target)
        finally:
            if running:
                print('Restart may perform billable Gemini startup verification.', flush=True)
                compose(['start', 'hindsight'], env)
    else:
        if args.action == 'restart':
            print('Restart may perform billable Gemini startup verification.', flush=True)
        compose({'status': ['ps'], 'stop': ['stop', 'hindsight'],
                 'restart': ['restart', 'hindsight']}[args.action], env)


if __name__ == '__main__':
    try:
        main()
    except urllib.error.HTTPError as error:
        print(f'HTTP request failed: status {error.code}; response body withheld.', file=sys.stderr)
        sys.exit(1)
    except subprocess.CalledProcessError as error:
        print(f'Local command failed: exit {error.returncode}.', file=sys.stderr)
        sys.exit(1)
    except ValueError as error:
        # Only our static validation messages; JSON errors can include input snippets.
        print('Invalid JSON.' if isinstance(error, json.JSONDecodeError) else str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('Operation failed; details withheld to protect local configuration. Check paths/network.', file=sys.stderr)
        sys.exit(1)
