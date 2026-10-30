#!/usr/bin/env python3
"""
Fix the SERVER_ROOT_CA macro in config.h.
Lines 154-166 (1-based) are missing the backslash line-continuation,
so the preprocessor macro ends after line 153 and the remaining
string literals end up at file scope, breaking every .cpp/.ino
that includes config.h.
"""
import sys, os

path = "config.h"
with open(path, "r", encoding="utf-8", newline="") as f:
    lines = f.readlines()

total = len(lines)
print(f"Total lines: {total}")

changed = 0
# 1-based lines 154..166 need a trailing ' \' added.
# In 0-based that is indices 153..165.
# The condition: line ends with \n (CRLF or LF) but NOT with '\\\n'
# i.e. it ends with "\"\\n\"\r\n" or "\"\\n\"\n" but not "\"\\n\" \\\r\n" etc.
for idx in range(153, 166):   # 0-based 153 to 165 inclusive
    raw = lines[idx]
    # Detect a line that is missing the continuation.
    # A continuation line looks like: ... \" \\\r\n  or  ... \" \\\n
    # A broken line looks like:       ... \"\r\n       or  ... \"\n
    stripped = raw.rstrip("\r\n")
    crlf = raw.endswith("\r\n")
    eol = "\r\n" if crlf else "\n"
    if stripped.endswith(" \\"):
        print(f"  Line {idx+1}: already has continuation, skipping")
    else:
        lines[idx] = stripped + " \\" + eol
        print(f"  Line {idx+1}: added continuation -> {repr(lines[idx])}")
        changed += 1

if changed:
    with open(path, "w", encoding="utf-8", newline="") as f:
        f.writelines(lines)
    print(f"\nFixed {changed} lines. Written back to {path}.")
else:
    print("\nNo changes needed.")

