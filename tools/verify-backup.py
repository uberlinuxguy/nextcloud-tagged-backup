#!/usr/bin/env python3
"""Verify a Tagged Backup archive against its manifest.json.

Reads the archive in a single streaming pass, so it works on pipes:

    age -d -i key.txt backup.tar.gz.age | python3 verify-backup.py -
    python3 verify-backup.py backup.tar.gz

Compression (gzip/bzip2/none) is detected automatically. Exit status is 0
if every file matches its recorded SHA-256, 1 otherwise.
"""
import hashlib
import json
import sys
import tarfile


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__, file=sys.stderr)
        return 2
    source = sys.argv[1]
    fileobj = sys.stdin.buffer if source == "-" else open(source, "rb")
    hashes = {}
    manifest = None
    with tarfile.open(fileobj=fileobj, mode="r|*") as archive:
        for member in archive:
            if not member.isfile():
                continue
            data = archive.extractfile(member)
            if member.name == "manifest.json":
                manifest = json.load(data)
                continue
            digest = hashlib.sha256()
            for chunk in iter(lambda: data.read(1 << 20), b""):
                digest.update(chunk)
            hashes[member.name] = digest.hexdigest()

    if manifest is None:
        print("ERROR: no manifest.json in archive", file=sys.stderr)
        return 1

    ok = bad = 0
    for entry in manifest["entries"]:
        if entry["type"] != "file":
            continue
        path = entry["path"]
        if "sha256" not in entry:
            print(f"WARN  {path}: {entry.get('error', 'no checksum recorded')}")
            hashes.pop(path, None)
            continue
        actual = hashes.pop(path, None)
        if actual is None:
            print(f"FAIL  {path}: missing from archive")
            bad += 1
        elif actual != entry["sha256"]:
            print(f"FAIL  {path}: checksum mismatch")
            bad += 1
        else:
            ok += 1
    for extra in sorted(hashes):
        print(f"WARN  {extra}: in archive but not in manifest")

    totals = manifest.get("totals", {})
    print(f"{ok} file(s) verified, {bad} failed; manifest reports "
          f"{totals.get('files')} files, {totals.get('problems')} problem(s); "
          f"created {manifest.get('createdAt')} by job \"{manifest['job']['name']}\"")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
