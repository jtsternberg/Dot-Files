#!/usr/bin/env python3
# Alfred script filter: Obsidian's known vaults, most recently opened first.
# Opens by vault id, not name: names are folder basenames and two vaults can
# share one.
import json
import os
import sys
from urllib.parse import quote

VAULTS_JSON = os.path.expanduser("~/Library/Application Support/obsidian/obsidian.json")

try:
    vaults = json.load(open(VAULTS_JSON))["vaults"]
except (OSError, ValueError, KeyError):
    vaults = {}

items = []
for vid, v in sorted(vaults.items(), key=lambda kv: kv[1].get("ts", 0), reverse=True):
    path = v.get("path", "")
    # Vaults in /tmp and deleted dirs linger in obsidian.json.
    if not os.path.isdir(path):
        continue
    name = os.path.basename(path.rstrip("/"))
    home = os.path.expanduser("~")
    shown = "~" + path[len(home):] if path.startswith(home + "/") else path
    items.append({
        "uid": vid,
        "title": name,
        "subtitle": ("● open  " if v.get("open") else "") + shown,
        "arg": "obsidian://open?vault=" + quote(vid),
        "autocomplete": name,
        "match": name + " " + shown,
        "icon": {"type": "fileicon", "path": "/Applications/Obsidian.app"},
        "mods": {"cmd": {"arg": os.path.realpath(path), "subtitle": "Reveal in Finder"}},
    })

if not items:
    items.append({"title": "No Obsidian vaults found", "subtitle": VAULTS_JSON, "valid": False})

json.dump({"items": items}, sys.stdout)
