#!/usr/bin/env python3
"""Capture real loopback HTTP projections; never invent a golden response."""
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[6]
SEED = Path(os.environ.get('AGENCY_SYNTHETIC_SEED', '/tmp/agency-synthetic-seed.json'))
OUTPUT = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/agency-participant-projections.json')
if '/agency_host_http_test' not in os.environ.get('AGENCY_SYNTHETIC_DB', ''):
    raise SystemExit('The disposable synthetic host environment is required.')
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=no'], cwd=ROOT, text=True).strip():
    raise SystemExit('Commit the exporter source before capturing evidence.')
lock = json.loads((ROOT / 'composer.lock').read_text())
pin = next(item['source']['reference'] for item in lock['packages'] if item['name'] == 'drupal/agency')
if not re.fullmatch('[a-f0-9]{40}', head) or not re.fullmatch('[a-f0-9]{40}', pin):
    raise SystemExit('Immutable exporter and requested references are required.')
seed = json.loads(SEED.read_text())
if set(seed['subjects']) != {'person-one', 'person-two'}:
    raise SystemExit('Use only the versioned two-subject seed.')
base = 'http://127.0.0.1:8889'
form = urllib.parse.urlencode({'grant_type': 'client_credentials', 'client_id': 'synthetic-bff',
                              'client_secret': 'synthetic-only', 'scope': 'discovery'}).encode()
with urllib.request.urlopen(urllib.request.Request(base + '/oauth/token', form)) as response:
    token = json.load(response)['access_token']


def read(path, subject=None):
    headers = {'Authorization': 'Bearer ' + token}
    if subject:
        headers['X-Agency-Identity-Token'] = seed['subjects'][subject]['identity_token']
    try:
        response = urllib.request.urlopen(urllib.request.Request(base + path, headers=headers))
    except urllib.error.HTTPError as error:
        response = error
    with response:
        payload = json.load(response)
        assert response.headers.get('Referrer-Policy') == 'no-referrer'
        assert 'no-store' in response.headers.get('Cache-Control', '')
        assert 'sign_in_state' not in payload
        return {'http_status': response.status, 'body': payload}


projections = {}
for subject, record in seed['subjects'].items():
    projections[subject + '_workspace'] = read('/api/agency/discovery/workspaces/' + record['relationship_id'], subject)
    projections[subject + '_invitation'] = read('/api/agency/discovery/invitations/' + record['invitation_token'], subject)
projections['cross_person'] = read('/api/agency/discovery/workspaces/' + seed['subjects']['person-one']['relationship_id'], 'person-two')
artifact = {
    'exporter_host_sha': head,
    'requested_package_sha': pin,
    'producing_host_sha': None,
    'producing_package_sha': None,
    'producer_verification': 'not_established: the HTTP listener has not attested its running source',
    'command': 'python3 web/modules/custom/agency_host/tests/fixtures/export-projections.py ' + str(OUTPUT),
    'captured_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'transport': 'real Drupal HTTP with synthetic proof validator and directory',
    'coverage': 'current seeded states only; not an exhaustive state catalog or real IdP qualification',
    'projections': projections,
}
OUTPUT.write_text(json.dumps(artifact, indent=2) + '\n')
print(str(OUTPUT))
