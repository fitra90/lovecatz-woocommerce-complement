"""Extract the J&T Cargo API Integration Manual to plain text for review."""

import re
import sys
from pypdf import PdfReader

SRC = r"E:\Downloads\JTC_API_Integration_Manual_251208_121336 (1).pdf"
OUT = r"C:\laragon\www\ddistillers\wp-content\plugins\lovecatz-woocommerce-complement\tmp\jtc-manual.txt"

reader = PdfReader(SRC)
print("pages:", len(reader.pages), file=sys.stderr)

chunks = []
for i, page in enumerate(reader.pages, start=1):
    try:
        text = page.extract_text() or ""
    except Exception as exc:  # noqa: BLE001
        text = ""
        print(f"page {i} failed: {exc}", file=sys.stderr)
    text = text.replace("\r\n", "\n")
    text = re.sub(r"\n{3,}", "\n\n", text)
    chunks.append(f"\n===== PAGE {i} =====\n{text}")

body = "".join(chunks)
with open(OUT, "w", encoding="utf-8") as fh:
    fh.write(body)

print("chars:", len(body), file=sys.stderr)
print("written:", OUT, file=sys.stderr)
