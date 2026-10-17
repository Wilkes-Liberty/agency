#!/usr/bin/env python3
"""Loopback-only receiver for synthetic evidence alert delivery checks."""

import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer


class Receiver(BaseHTTPRequestHandler):
    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        if self.path != "/alerts" or length < 1 or length > 4096:
            self.send_error(400)
            return
        payload = json.loads(self.rfile.read(length))
        expected = {
            "event", "status", "threshold_seconds", "pending_count",
            "oldest_pending_age_seconds", "pending_attempts",
        }
        if set(payload) != expected or payload["event"] != "agency_evidence_delivery":
            self.send_error(400)
            return
        if os.path.exists("/tmp/agency-synthetic-alert-receiver-fail"):
            self.send_error(503)
            return
        with open("/tmp/agency-synthetic-alerts.jsonl", "a", encoding="utf-8") as stream:
            stream.write(json.dumps(payload) + "\n")
        self.send_response(204)
        self.end_headers()

    def log_message(self, *args):
        pass


HTTPServer(("127.0.0.1", 8890), Receiver).serve_forever()
