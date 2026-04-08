#!/usr/bin/env python3
"""Generate a simple text-based PDF from a Markdown file.

This intentionally supports only a small subset of Markdown so it can run
without external dependencies. It is good enough for operational guides and
checklists.
"""

from __future__ import annotations

import re
import sys
import textwrap
from pathlib import Path


PAGE_WIDTH = 612
PAGE_HEIGHT = 792
LEFT_MARGIN = 54
TOP_MARGIN = 54
BOTTOM_MARGIN = 54
FONT_SIZE = 11
LINE_HEIGHT = 15
MAX_CHARS = 88


def pdf_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def normalize_markdown(text: str) -> list[str]:
    lines: list[str] = []
    in_code_block = False

    for raw_line in text.splitlines():
        line = raw_line.rstrip()

        if line.startswith("```"):
            in_code_block = not in_code_block
            lines.append("")
            continue

        if in_code_block:
            lines.append("    " + line)
            continue

        line = line.replace("`", "")

        if not line.strip():
            lines.append("")
            continue

        if line.startswith("#"):
            heading = line.lstrip("#").strip()
            lines.append(heading.upper())
            lines.append("")
            continue

        if re.match(r"^\d+\.\s+", line):
            lines.append(line)
            continue

        if re.match(r"^-\s+", line):
            lines.append("* " + line[2:].strip())
            continue

        lines.append(line)

    return lines


def wrap_lines(lines: list[str]) -> list[str]:
    wrapped: list[str] = []

    for line in lines:
        if not line:
            wrapped.append("")
            continue

        indent = ""
        content = line

        numbered = re.match(r"^(\d+\.\s+)(.*)$", line)
        bulleted = re.match(r"^(\*\s+)(.*)$", line)

        if numbered:
            indent = numbered.group(1)
            content = numbered.group(2)
        elif bulleted:
            indent = bulleted.group(1)
            content = bulleted.group(2)

        available = MAX_CHARS - len(indent)
        wrapped_parts = textwrap.wrap(
            content,
            width=max(20, available),
            break_long_words=False,
            break_on_hyphens=False,
        )

        if not wrapped_parts:
            wrapped.append(indent.rstrip())
            continue

        wrapped.append(indent + wrapped_parts[0])
        continuation_prefix = " " * len(indent)
        for part in wrapped_parts[1:]:
            wrapped.append(continuation_prefix + part)

    return wrapped


def paginate(lines: list[str]) -> list[list[str]]:
    usable_height = PAGE_HEIGHT - TOP_MARGIN - BOTTOM_MARGIN
    lines_per_page = usable_height // LINE_HEIGHT

    pages: list[list[str]] = []
    current: list[str] = []

    for line in lines:
        current.append(line)
        if len(current) >= lines_per_page:
            pages.append(current)
            current = []

    if current:
        pages.append(current)

    return pages


def build_page_stream(lines: list[str]) -> bytes:
    start_y = PAGE_HEIGHT - TOP_MARGIN
    stream: list[str] = [
        "BT",
        f"/F1 {FONT_SIZE} Tf",
        f"{LEFT_MARGIN} {start_y} Td",
    ]

    first = True
    for line in lines:
        if first:
            first = False
        else:
            stream.append(f"0 -{LINE_HEIGHT} Td")
        stream.append(f"({pdf_escape(line)}) Tj")

    stream.append("ET")
    return "\n".join(stream).encode("latin-1", errors="replace")


def build_pdf(pages: list[list[str]]) -> bytes:
    objects: list[bytes] = []

    def add_object(data: bytes) -> int:
        objects.append(data)
        return len(objects)

    font_obj = add_object(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

    page_obj_ids: list[int] = []
    content_obj_ids: list[int] = []

    pages_placeholder = add_object(b"<< >>")

    for page_lines in pages:
        stream = build_page_stream(page_lines)
        content = (
            f"<< /Length {len(stream)} >>\nstream\n".encode("ascii")
            + stream
            + b"\nendstream"
        )
        content_obj_ids.append(add_object(content))

    for content_id in content_obj_ids:
        page_data = (
            f"<< /Type /Page /Parent {pages_placeholder} 0 R "
            f"/MediaBox [0 0 {PAGE_WIDTH} {PAGE_HEIGHT}] "
            f"/Resources << /Font << /F1 {font_obj} 0 R >> >> "
            f"/Contents {content_id} 0 R >>"
        ).encode("ascii")
        page_obj_ids.append(add_object(page_data))

    kids = " ".join(f"{page_id} 0 R" for page_id in page_obj_ids)
    objects[pages_placeholder - 1] = (
        f"<< /Type /Pages /Count {len(page_obj_ids)} /Kids [{kids}] >>".encode("ascii")
    )

    catalog_obj = add_object(f"<< /Type /Catalog /Pages {pages_placeholder} 0 R >>".encode("ascii"))

    pdf = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]

    for index, obj in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf.extend(f"{index} 0 obj\n".encode("ascii"))
        pdf.extend(obj)
        pdf.extend(b"\nendobj\n")

    xref_offset = len(pdf)
    pdf.extend(f"xref\n0 {len(objects) + 1}\n".encode("ascii"))
    pdf.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        pdf.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
    pdf.extend(
        (
            f"trailer\n<< /Size {len(objects) + 1} /Root {catalog_obj} 0 R >>\n"
            f"startxref\n{xref_offset}\n%%EOF\n"
        ).encode("ascii")
    )

    return bytes(pdf)


def main() -> int:
    if len(sys.argv) != 3:
      print("Usage: md_to_simple_pdf.py <input.md> <output.pdf>", file=sys.stderr)
      return 1

    input_path = Path(sys.argv[1])
    output_path = Path(sys.argv[2])

    source = input_path.read_text(encoding="utf-8")
    normalized = normalize_markdown(source)
    wrapped = wrap_lines(normalized)
    pages = paginate(wrapped)
    pdf_bytes = build_pdf(pages)
    output_path.write_bytes(pdf_bytes)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
