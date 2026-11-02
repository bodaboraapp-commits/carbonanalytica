"""Generate the organization declaration template offered on the documents step.

Run from the repository root:

    <bundled python> tools/generate-declaration-template.py

The output is a static asset served from public/assets/templates/, so the
wizard can offer a real declaration template without a document-generation
service. Re-run this script to regenerate it.
"""

from pathlib import Path

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Pt, Cm

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / "public" / "assets" / "templates" / "organization-declaration-template.docx"

FIELDS = [
    ("Legal organization name", "[LEGAL ORGANIZATION NAME]"),
    ("Registration number", "[REGISTRATION NUMBER]"),
    ("Country of registration", "[COUNTRY OF REGISTRATION]"),
    ("Registered address", "[REGISTERED ADDRESS]"),
    ("Primary contact", "[CONTACT NAME], [CONTACT ROLE]"),
    ("Contact email and phone", "[CONTACT EMAIL] · [CONTACT PHONE]"),
]

DECLARATIONS = [
    "The information provided in this onboarding submission is true and complete to the best of my knowledge.",
    "The organization exists, is legally registered in the country stated above, and is not suspended or struck off.",
    "The attached ownership structure (CR-12 or equivalent) lists all owners, partners, directors, trustees and authorised officials.",
    "The signatory is authorised to submit these documents on behalf of the organization and to receive correspondence about this verification.",
    "I will notify AI PDD Generator of any material change to the information above within 30 days.",
]


def set_cell_text(cell, text: str, bold: bool = False) -> None:
    cell.text = ""
    paragraph = cell.paragraphs[0]
    run = paragraph.add_run(text)
    run.bold = bold
    run.font.size = Pt(10)


def main() -> None:
    document = Document()

    section = document.sections[0]
    section.top_margin = Cm(2.2)
    section.bottom_margin = Cm(2.2)
    section.left_margin = Cm(2.4)
    section.right_margin = Cm(2.4)

    style = document.styles["Normal"]
    style.font.name = "Calibri"
    style.font.size = Pt(11)

    title = document.add_paragraph()
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    title_run = title.add_run("ORGANIZATION DECLARATION")
    title_run.bold = True
    title_run.font.size = Pt(18)

    subtitle = document.add_paragraph()
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
    subtitle_run = subtitle.add_run("AI PDD Generator — organization verification")
    subtitle_run.italic = True
    subtitle_run.font.size = Pt(10)

    document.add_paragraph()

    intro = document.add_paragraph(
        "This declaration is submitted together with the organization's registration certificate and "
        "ownership structure. Complete every placeholder before signing."
    )
    intro.alignment = WD_ALIGN_PARAGRAPH.LEFT

    document.add_paragraph()

    table = document.add_table(rows=0, cols=2)
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    for label, placeholder in FIELDS:
        row = table.add_row()
        set_cell_text(row.cells[0], label, bold=True)
        set_cell_text(row.cells[1], placeholder)
        row.cells[0].width = Cm(5.2)
        row.cells[1].width = Cm(11.2)

    document.add_paragraph()

    heading = document.add_paragraph()
    heading_run = heading.add_run("Declaration")
    heading_run.bold = True
    heading_run.font.size = Pt(13)

    for item in DECLARATIONS:
        paragraph = document.add_paragraph(item, style="List Number")
        paragraph.paragraph_format.space_after = Pt(4)

    document.add_paragraph()

    signature_heading = document.add_paragraph()
    signature_run = signature_heading.add_run("Signed for and on behalf of the organization")
    signature_run.bold = True
    signature_run.font.size = Pt(13)

    signature_rows = [
        ("Full name", ""),
        ("Role / title", ""),
        ("Signature", ""),
        ("Place", ""),
        ("Date (DD/MM/YYYY)", ""),
    ]
    signature_table = document.add_table(rows=0, cols=2)
    signature_table.style = "Table Grid"
    for label, placeholder in signature_rows:
        row = signature_table.add_row()
        set_cell_text(row.cells[0], label, bold=True)
        set_cell_text(row.cells[1], placeholder)
        row.cells[0].width = Cm(5.2)
        row.cells[1].width = Cm(11.2)

    document.add_paragraph()

    note = document.add_paragraph()
    note_run = note.add_run(
        "Save the signed declaration as a single PDF file and upload it in step 3 of organization onboarding. "
        "Files must be PDF, DOC or DOCX and no larger than the upload limit shown on that page."
    )
    note_run.italic = True
    note_run.font.size = Pt(9)

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    document.save(OUTPUT)
    print(f"wrote {OUTPUT}")


if __name__ == "__main__":
    main()
