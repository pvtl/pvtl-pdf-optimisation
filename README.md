# PVTL PDF Optimisation

A WordPress plugin that optimises uploaded PDF files with Ghostscript, with optional qpdf linearization, and provides batch compression tools for existing Media Library PDFs.

## Why we created this plugin

PDF uploads are often much larger than they need to be for web delivery. Large files slow down page loads, consume storage, and make downloads slower for visitors. Manually compressing every PDF before upload is easy to forget, and existing Media Library files are rarely revisited.

We created PVTL PDF Optimisation to:

- **Automatically compress PDFs on upload** so new files are smaller without a manual step.
- **Reduce storage and bandwidth usage** for sites that host many documents.
- **Make existing PDFs easy to clean up** with a Tools admin page that compresses Media Library PDFs in safe batches.
- **Support CLI workflows** so individual files can be optimised outside the admin UI.

## What this plugin does

- **Optimises PDFs on upload** – When a PDF is uploaded, Ghostscript compresses it using a configurable quality preset (default: `ebook`). If qpdf is available, the result is also linearized for faster web viewing.
- **Keeps the original when compression does not help** – The original file is only replaced when the optimised version is smaller.
- **Batch-compresses existing Media Library PDFs** – Tools → PDF Optimisation processes attachments in batches and reports progress, savings, and errors.
- **Supports WP-CLI** – Optimise a single PDF path with `wp pvtl pdf-optimise <path>`.
- **Logs activity** – Writes optimisation events to `logs/pvtl-pdf-optimisation.log` for troubleshooting.

The plugin does **not**:

- Change non-PDF uploads.
- Replace a PDF when Ghostscript produces a larger or equal-sized file.
- Require qpdf (Ghostscript alone is enough; qpdf linearization is optional when installed).

## How it works

1. **On upload**, the plugin hooks into `wp_handle_upload`. If the file is a PDF and required binaries are available, it compresses the file with Ghostscript and optionally linearizes it with qpdf, then replaces the upload only when the result is smaller.
2. **On the Tools page** (Tools → PDF Optimisation), an admin can start a batch job that walks PDF attachments in groups of 10, showing progress and total bytes saved.
3. **Via WP-CLI**, a single file path (absolute, or relative to the uploads directory) can be optimised with the same pipeline.

Compression quality is controlled by the Ghostscript PDFSETTINGS preset. The default is `ebook`. Override it with the `PVTL_PDF_OPTIMISATION_PRESET` constant or the `pvtl_pdf_optimisation_preset` filter. Allowed values: `screen`, `ebook`, `printer`, `prepress`.

## Requirements

- WordPress (uses standard upload, admin, AJAX, and WP-CLI APIs).
- PHP 8.3 or newer.
- **Ghostscript** (`gs`) available on the server `PATH` (required).
- **qpdf** available on the server `PATH` (optional; enables linearization).

## Installation

### Composer / Wordpress Bedrock
```bash
composer require pvtl/pvtl-pdf-optimisation
```

### Manual
1. Upload plugin to `app/plugins/pvtl-pdf-optimisation/`
2. Activate plugin in WordPress admin
3. Ensure `gs` (and optionally `qpdf`) are installed on the server

## Author

Pivotal Agency Pty Ltd

## License

MIT
