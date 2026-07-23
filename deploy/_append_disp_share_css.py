#!/usr/bin/env python3
from pathlib import Path

CSS = Path(__file__).resolve().parents[1] / "resources" / "css" / "app.css"
MARKER = "/* Import dispositions + share access UI */"
BLOCK = """

/* Import dispositions + share access UI */
.import-disposition-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.1rem;
    padding: 0.35rem 0.55rem;
    border-radius: 0.65rem;
    border: 1px solid #bbf7d0;
    background: #ecfdf5;
    color: #047857;
    cursor: pointer;
    font-family: var(--font-sans);
    text-align: left;
    min-width: 4.5rem;
}

.import-disposition-btn:hover {
    background: #d1fae5;
}

.import-disposition-btn__total {
    font-size: 0.875rem;
    font-weight: 700;
    line-height: 1.1;
}

.import-disposition-btn__label {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #059669;
}

.import-disposition-btn__top {
    font-size: 0.6875rem;
    color: #334155;
    max-width: 8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.import-disposition-empty {
    color: #94a3b8;
    font-size: 0.8125rem;
    font-weight: 600;
}

.import-agent-visibility {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.import-utility-modal {
    position: fixed;
    inset: 0;
    z-index: 90;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.import-utility-modal.is-open {
    display: flex;
}

.import-utility-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
}

.import-utility-modal__panel {
    position: relative;
    z-index: 1;
    width: min(52rem, 100%);
    max-height: min(88dvh, 44rem);
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 1rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
    overflow: hidden;
    font-family: var(--font-sans);
}

.import-utility-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #f1f5f9;
}

.import-utility-modal__title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 600;
    color: #0f172a;
}

.import-utility-modal__subtitle {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: #64748b;
}

.import-utility-modal__close {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    cursor: pointer;
    font-size: 1.25rem;
    line-height: 1;
}

.import-utility-modal__body {
    padding: 0.85rem 1rem 1rem;
    overflow: auto;
    min-height: 0;
    flex: 1 1 auto;
}

.import-utility-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
}

.import-utility-modal__empty {
    margin: 0.75rem 0;
    color: #94a3b8;
    text-align: center;
    font-size: 0.875rem;
}

.import-disposition-summary {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    margin-bottom: 0.85rem;
}

.import-disposition-summary__total {
    display: inline-flex;
    align-items: baseline;
    gap: 0.4rem;
    color: #047857;
}

.import-disposition-summary__total strong {
    font-size: 1.35rem;
}

.import-disposition-summary__total span {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.import-disposition-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.import-disposition-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #065f46;
    font-size: 0.75rem;
}

.import-disposition-chip em {
    font-style: normal;
    font-weight: 700;
    color: #047857;
}

.import-disposition-table-wrap {
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
}

.import-disposition-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}

.import-disposition-table th,
.import-disposition-table td {
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
}

.import-disposition-table thead th {
    position: sticky;
    top: 0;
    background: #ecfdf5;
    color: #047857;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.import-share-hint {
    margin: 0 0 0.75rem;
    font-size: 0.8125rem;
    color: #64748b;
    line-height: 1.45;
}

.import-share-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    max-height: min(22rem, 50vh);
    overflow: auto;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    padding: 0.35rem;
}

.import-share-row {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.45rem 0.55rem;
    border-radius: 0.55rem;
    cursor: pointer;
}

.import-share-row:hover {
    background: #f0fdf4;
}

@media (max-width: 767px) {
    .import-utility-modal {
        align-items: flex-end;
        padding: 0.5rem;
    }

    .import-utility-modal__panel {
        width: 100%;
        max-height: min(92dvh, calc(100dvh - 0.75rem));
        border-radius: 1rem 1rem 0.75rem 0.75rem;
    }
}
"""

text = CSS.read_text(encoding="utf-8")
if MARKER in text:
    text = text[: text.index(MARKER)].rstrip() + "\n" + BLOCK.lstrip("\n")
else:
    text = text.rstrip() + "\n" + BLOCK
CSS.write_text(text, encoding="utf-8", newline="\n")
print("OK", CSS.stat().st_size)
