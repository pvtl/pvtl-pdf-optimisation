<?php
/*
Plugin Name:  PVTL PDF Optimisation
Description:  Optimises uploaded PDF files with Ghostscript, with optional qpdf linearization.
Version:      1.0.0
Author:       PVTL
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PVTL_PDF_Optimisation')) {
class PVTL_PDF_Optimisation
{
    private const BATCH_SIZE = 10;
    private const DEFAULT_PRESET = 'ebook';
    private const NOTICE_TRANSIENT = 'pvtl_pdf_optimisation_notice';
    private const PIVOTAL_LOGO_URL = 'https://www.pivotalagency.com.au/assets/images/pivotal.png';
    private const TOOLS_PAGE_SLUG = 'pvtl-pdf-optimisation';

    public static function init(): void
    {
        add_filter('wp_handle_upload', [self::class, 'optimise_uploaded_pdf']);
        add_action('admin_menu', [self::class, 'register_tools_page']);
        add_action('admin_notices', [self::class, 'render_admin_notice']);
        add_action('wp_ajax_pvtl_pdf_optimisation_batch', [self::class, 'handle_batch_request']);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('pvtl pdf-optimise', [self::class, 'cli_optimise_pdf']);
        }
    }

    public static function optimise_uploaded_pdf(array $upload): array
    {
        $filePath = $upload['file'] ?? '';

        if (!$filePath || !self::is_pdf($filePath)) {
            return $upload;
        }

        self::write_log('Upload detected for PDF optimisation.', [
            'file' => $filePath,
        ]);

        $result = self::optimise_file($filePath);

        if (is_wp_error($result)) {
            self::write_log('Upload optimisation failed.', [
                'file' => $filePath,
                'error' => $result->get_error_message(),
            ]);
            self::queue_admin_notice($result->get_error_message(), 'warning');
            return $upload;
        }

        if (!$result['optimised']) {
            self::write_log('Upload optimisation skipped because no smaller file was produced.', [
                'file' => $filePath,
                'before' => $result['before'],
                'after' => $result['after'],
            ]);
            return $upload;
        }

        self::write_log('Upload optimisation completed.', [
            'file' => $filePath,
            'before' => $result['before'],
            'after' => $result['after'],
            'saved' => $result['before'] - $result['after'],
        ]);

        return $upload;
    }

    public static function cli_optimise_pdf(array $args, array $assocArgs): void
    {
        unset($assocArgs);

        $filePath = $args[0] ?? '';

        if (!$filePath) {
            WP_CLI::error('Provide a PDF file path.');
        }

        $resolvedPath = self::resolve_cli_path($filePath);

        if (!$resolvedPath) {
            WP_CLI::error(sprintf('File not found: %s', $filePath));
        }

        $result = self::optimise_file($resolvedPath);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if (!$result['optimised']) {
            WP_CLI::success(
                sprintf(
                    'No smaller PDF was produced for %s. Size remains %s.',
                    $resolvedPath,
                    self::format_bytes($result['before'])
                )
            );

            return;
        }

        WP_CLI::success(
            sprintf(
                'Optimised %s from %s to %s.',
                $resolvedPath,
                self::format_bytes($result['before']),
                self::format_bytes($result['after'])
            )
        );
    }

    /**
     * @return array{optimised:bool,before:int,after:int}|WP_Error
     */
    public static function optimise_file(string $filePath)
    {
        self::write_log('Starting PDF optimisation.', [
            'file' => $filePath,
            'preset' => self::pdf_preset(),
        ]);

        if (!self::is_pdf($filePath)) {
            self::write_log('PDF optimisation rejected a non-PDF or unreadable file.', [
                'file' => $filePath,
            ]);
            return new WP_Error('pvtl_pdf_invalid_file', 'The selected file is not a PDF.');
        }

        $missingCommands = self::missing_required_commands();

        if ($missingCommands !== []) {
            self::write_log('PDF optimisation aborted because required binaries are missing.', [
                'file' => $filePath,
                'missing_commands' => $missingCommands,
            ]);
            return new WP_Error(
                'pvtl_pdf_missing_commands',
                sprintf(
                'PVTL PDF Optimisation requires: %s.',
                    implode(', ', $missingCommands)
                )
            );
        }

        $beforeBytes = filesize($filePath);

        if ($beforeBytes === false) {
            self::write_log('Unable to read source PDF size before optimisation.', [
                'file' => $filePath,
            ]);
            return new WP_Error('pvtl_pdf_unreadable_file', 'Unable to read the PDF size before optimisation.');
        }

        $tempCompressed = self::create_temp_pdf_path();
        $useQpdf = self::command_exists('qpdf');
        $tempLinearized = $useQpdf ? self::create_temp_pdf_path() : '';

        if (is_wp_error($tempCompressed)) {
            return $tempCompressed;
        }

        if ($useQpdf && is_wp_error($tempLinearized)) {
            self::cleanup_temp_files([$tempCompressed]);
            return $tempLinearized;
        }

        $ghostscript = self::run_command([
            'gs',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/' . self::pdf_preset(),
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-dDetectDuplicateImages=true',
            '-dCompressFonts=true',
            '-sOutputFile=' . $tempCompressed,
            $filePath,
        ]);

        if (!$ghostscript['success']) {
            self::cleanup_temp_files([$tempCompressed, $tempLinearized]);
            self::write_log('Ghostscript failed during PDF optimisation.', [
                'file' => $filePath,
                'error' => $ghostscript['message'],
            ]);

            return new WP_Error(
                'pvtl_pdf_ghostscript_failed',
                sprintf('Ghostscript failed while optimising the PDF: %s', $ghostscript['message'])
            );
        }

        $finalOutput = $tempCompressed;

        if ($useQpdf) {
            $qpdf = self::run_command([
                'qpdf',
                '--linearize',
                $tempCompressed,
                $tempLinearized,
            ]);

            if (!$qpdf['success']) {
                self::cleanup_temp_files([$tempCompressed, $tempLinearized]);
                self::write_log('qpdf failed during PDF optimisation.', [
                    'file' => $filePath,
                    'error' => $qpdf['message'],
                ]);

                return new WP_Error(
                    'pvtl_pdf_qpdf_failed',
                    sprintf('qpdf failed while linearising the PDF: %s', $qpdf['message'])
                );
            }

            $finalOutput = $tempLinearized;
        } else {
            self::write_log('qpdf not available; continuing with Ghostscript-only optimisation.', [
                'file' => $filePath,
            ]);
        }

        $afterBytes = filesize($finalOutput);

        if ($afterBytes === false) {
            self::cleanup_temp_files([$tempCompressed, $tempLinearized]);
            self::write_log('Unable to read optimised PDF size.', [
                'file' => $filePath,
            ]);
            return new WP_Error('pvtl_pdf_unreadable_result', 'Unable to read the optimised PDF size.');
        }

        if ($afterBytes >= $beforeBytes) {
            self::cleanup_temp_files([$tempCompressed, $tempLinearized]);
            self::write_log('PDF optimisation produced no size improvement.', [
                'file' => $filePath,
                'before' => $beforeBytes,
                'after' => $afterBytes,
            ]);

            return [
                'optimised' => false,
                'before' => $beforeBytes,
                'after' => $beforeBytes,
            ];
        }

        $replaced = self::replace_file($finalOutput, $filePath);
        self::cleanup_temp_files([$tempCompressed, $tempLinearized]);

        if (!$replaced) {
            self::cleanup_temp_files([$tempLinearized]);
            self::write_log('Failed to replace the original PDF with the optimised file.', [
                'file' => $filePath,
            ]);
            return new WP_Error('pvtl_pdf_replace_failed', 'Unable to replace the uploaded PDF with the optimised version.');
        }

        clearstatcache(true, $filePath);

        self::write_log('PDF optimisation completed successfully.', [
            'file' => $filePath,
            'before' => $beforeBytes,
            'after' => $afterBytes,
            'saved' => $beforeBytes - $afterBytes,
        ]);

        return [
            'optimised' => true,
            'before' => $beforeBytes,
            'after' => $afterBytes,
        ];
    }

    public static function render_admin_notice(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        $notice = get_transient(self::NOTICE_TRANSIENT);

        if (!$notice || !is_array($notice)) {
            return;
        }

        delete_transient(self::NOTICE_TRANSIENT);

        $class = $notice['class'] ?? 'notice-warning';
        $message = $notice['message'] ?? '';

        if (!$message) {
            return;
        }

        printf(
            '<div class="notice %1$s"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    public static function register_tools_page(): void
    {
        add_management_page(
            'PVTL PDF Optimisation',
            'PDF Optimisation',
            'upload_files',
            self::TOOLS_PAGE_SLUG,
            [self::class, 'render_tools_page']
        );
    }

    public static function render_tools_page(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die('You do not have permission to access this page.');
        }

        $missingCommands = self::missing_required_commands();
        $totalPdfs = self::count_pdf_attachments();
        $nonce = wp_create_nonce('pvtl_pdf_optimisation_batch');
        ?>
        <div class="wrap">
            <style>
                .pvtl-pdf-admin {
                    max-width: 900px;
                }

                .pvtl-pdf-admin__hero {
                    background: linear-gradient(135deg, #232323 0%, #3a3a3c 100%);
                    border-radius: 16px;
                    color: #ffffff;
                    margin: 20px 0 24px;
                    overflow: hidden;
                    padding: 28px 32px;
                    box-shadow: 0 14px 32px rgba(35, 35, 35, 0.18);
                }

                .pvtl-pdf-admin__logo {
                    display: block;
                    max-width: 220px;
                    margin-bottom: 20px;
                }

                .pvtl-pdf-admin__hero h1 {
                    color: #ffffff;
                    font-size: 30px;
                    margin: 0 0 10px;
                }

                .pvtl-pdf-admin__hero p {
                    color: rgba(255, 255, 255, 0.86);
                    font-size: 15px;
                    margin: 0;
                    max-width: 680px;
                }

                .pvtl-pdf-admin__card {
                    background: #ffffff;
                    border: 1px solid #e3e3e1;
                    border-radius: 16px;
                    box-shadow: 0 8px 24px rgba(35, 35, 35, 0.08);
                    padding: 24px;
                }

                .pvtl-pdf-admin__stats {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                    gap: 16px;
                    margin-bottom: 20px;
                }

                .pvtl-pdf-admin__stat {
                    background: #fcfcfa;
                    border-radius: 12px;
                    padding: 16px;
                }

                .pvtl-pdf-admin__stat-label {
                    color: #717171;
                    display: block;
                    font-size: 12px;
                    font-weight: 600;
                    letter-spacing: 0.04em;
                    margin-bottom: 6px;
                    text-transform: uppercase;
                }

                .pvtl-pdf-admin__stat-value {
                    color: #232323;
                    font-size: 22px;
                    font-weight: 700;
                    line-height: 1.2;
                }

                .pvtl-pdf-admin__actions {
                    align-items: center;
                    display: flex;
                    gap: 12px;
                    margin-top: 18px;
                }

                .pvtl-pdf-admin__button.button-primary {
                    background: #ffcb05;
                    border-color: #ffcb05;
                    box-shadow: none;
                    color: #232323;
                    min-height: 40px;
                    padding: 0 18px;
                    text-shadow: none;
                }

                .pvtl-pdf-admin__button.button-primary:hover,
                .pvtl-pdf-admin__button.button-primary:focus {
                    background: #e6b507;
                    border-color: #e6b507;
                    color: #232323;
                }

                .pvtl-pdf-admin__status {
                    background: #fcfcfa;
                    border-radius: 12px;
                    border-left: 4px solid #ffcb05;
                    margin-top: 20px;
                    min-height: 72px;
                    padding: 18px;
                }

                .pvtl-pdf-admin__status p {
                    margin: 0 0 10px;
                }

                .pvtl-pdf-admin__status p:last-child {
                    margin-bottom: 0;
                }
            </style>

            <div class="pvtl-pdf-admin">
                <div class="pvtl-pdf-admin__hero">
                    <img
                        src="<?php echo esc_url(self::PIVOTAL_LOGO_URL); ?>"
                        alt="Pivotal Agency"
                        class="pvtl-pdf-admin__logo"
                    >
                    <h1>PVTL PDF Optimisation</h1>
                    <p>Compress the PDFs already in the Media Library in safe batches with a workflow aligned to the Pivotal Agency toolset.</p>
                </div>

                <div class="pvtl-pdf-admin__card">
                    <div class="pvtl-pdf-admin__stats">
                        <div class="pvtl-pdf-admin__stat">
                            <span class="pvtl-pdf-admin__stat-label">PDF Attachments</span>
                            <span class="pvtl-pdf-admin__stat-value"><?php echo esc_html(number_format_i18n($totalPdfs)); ?></span>
                        </div>
                        <div class="pvtl-pdf-admin__stat">
                            <span class="pvtl-pdf-admin__stat-label">Compression Preset</span>
                            <span class="pvtl-pdf-admin__stat-value"><?php echo esc_html(self::pdf_preset()); ?></span>
                        </div>
                    </div>

                    <?php if ($missingCommands !== []) : ?>
                        <div class="notice notice-error inline">
                            <p>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        'This server is missing the required binaries: %s.',
                                        implode(', ', $missingCommands)
                                    )
                                );
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="pvtl-pdf-admin__actions">
                        <button
                            type="button"
                            class="button button-primary pvtl-pdf-admin__button"
                            id="pvtl-pdf-optimise-existing"
                            <?php disabled($totalPdfs < 1 || $missingCommands !== []); ?>
                        >
                            Compress Existing PDFs
                        </button>
                    </div>

                    <div id="pvtl-pdf-optimisation-status" class="pvtl-pdf-admin__status"></div>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const button = document.getElementById('pvtl-pdf-optimise-existing');
                const status = document.getElementById('pvtl-pdf-optimisation-status');

                if (!button || !status) {
                    return;
                }

                const nonce = <?php echo wp_json_encode($nonce); ?>;
                const total = <?php echo (int) $totalPdfs; ?>;

                const formatBytes = (bytes) => {
                    const units = ['B', 'KB', 'MB', 'GB'];
                    let size = Number(bytes || 0);
                    let unit = 0;

                    while (size >= 1024 && unit < units.length - 1) {
                        size /= 1024;
                        unit += 1;
                    }

                    return `${size.toFixed(1)} ${units[unit]}`;
                };

                const render = (summary) => {
                    status.innerHTML = `
                        <p><strong>Progress:</strong> ${summary.processed} of ${total} PDFs processed</p>
                        <p><strong>Optimised:</strong> ${summary.optimised}</p>
                        <p><strong>Skipped:</strong> ${summary.skipped}</p>
                        <p><strong>Errors:</strong> ${summary.errors}</p>
                        <p><strong>Saved:</strong> ${formatBytes(summary.savedBytes)}</p>
                        ${summary.message ? `<p>${summary.message}</p>` : ''}
                    `;
                };

                button.addEventListener('click', async () => {
                    button.disabled = true;

                    const summary = {
                        processed: 0,
                        optimised: 0,
                        skipped: 0,
                        errors: 0,
                        savedBytes: 0,
                        message: 'Starting batch optimisation...',
                    };

                    render(summary);

                    let offset = 0;
                    let done = false;

                    try {
                        while (!done) {
                            const body = new URLSearchParams({
                                action: 'pvtl_pdf_optimisation_batch',
                                nonce,
                                offset: String(offset),
                            });

                            const response = await fetch(ajaxurl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                },
                                body,
                            });

                            const payload = await response.json();

                            if (!response.ok || !payload.success) {
                                throw new Error(payload?.data?.message || 'Batch optimisation failed.');
                            }

                            const data = payload.data;

                            summary.processed += data.processed;
                            summary.optimised += data.optimised;
                            summary.skipped += data.skipped;
                            summary.errors += data.errors;
                            summary.savedBytes += data.savedBytes;
                            summary.message = data.done
                                ? 'Finished compressing existing PDFs.'
                                : `Processing batch... ${summary.processed} of ${total} done.`;

                            render(summary);

                            offset = data.nextOffset;
                            done = data.done;
                        }
                    } catch (error) {
                        summary.message = error.message || 'Batch optimisation failed.';
                        render(summary);
                    } finally {
                        button.disabled = false;
                    }
                });
            })();
        </script>
        <?php
    }

    public static function handle_batch_request(): void
    {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'You do not have permission to do that.'], 403);
        }

        check_ajax_referer('pvtl_pdf_optimisation_batch', 'nonce');

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        self::write_log('Starting batch PDF optimisation request.', [
            'offset' => $offset,
            'limit' => self::BATCH_SIZE,
        ]);
        $result = self::process_pdf_batch($offset, self::BATCH_SIZE);

        if (is_wp_error($result)) {
            self::write_log('Batch PDF optimisation request failed.', [
                'offset' => $offset,
                'error' => $result->get_error_message(),
            ]);
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        self::write_log('Batch PDF optimisation request completed.', $result);

        wp_send_json_success($result);
    }

    /**
     * @return array{
     *     done:bool,
     *     nextOffset:int,
     *     total:int,
     *     processed:int,
     *     optimised:int,
     *     skipped:int,
     *     errors:int,
     *     savedBytes:int
     * }|WP_Error
     */
    public static function process_pdf_batch(int $offset, int $limit)
    {
        $missingCommands = self::missing_required_commands();

        if ($missingCommands !== []) {
            return new WP_Error(
                'pvtl_pdf_missing_commands',
                sprintf('PVTL PDF Optimisation requires: %s.', implode(', ', $missingCommands))
            );
        }

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $total = self::count_pdf_attachments();
        $attachmentIds = $query->posts;

        if ($attachmentIds === []) {
            return [
                'done' => true,
                'nextOffset' => $offset,
                'total' => $total,
                'processed' => 0,
                'optimised' => 0,
                'skipped' => 0,
                'errors' => 0,
                'savedBytes' => 0,
            ];
        }

        $processed = 0;
        $optimised = 0;
        $skipped = 0;
        $errors = 0;
        $savedBytes = 0;

        foreach ($attachmentIds as $attachmentId) {
            $result = self::optimise_attachment((int) $attachmentId);
            $processed++;

            if ($result['status'] === 'optimised') {
                $optimised++;
                $savedBytes += max(0, $result['before'] - $result['after']);
                continue;
            }

            if ($result['status'] === 'error') {
                $errors++;
                continue;
            }

            $skipped++;
        }

        $nextOffset = $offset + count($attachmentIds);

        return [
            'done' => $nextOffset >= $total,
            'nextOffset' => $nextOffset,
            'total' => $total,
            'processed' => $processed,
            'optimised' => $optimised,
            'skipped' => $skipped,
            'errors' => $errors,
            'savedBytes' => $savedBytes,
        ];
    }

    private static function queue_admin_notice(string $message, string $type = 'warning'): void
    {
        $classes = [
            'error' => 'notice-error',
            'success' => 'notice-success',
            'warning' => 'notice-warning',
        ];

        set_transient(
            self::NOTICE_TRANSIENT,
            [
                'class' => $classes[$type] ?? $classes['warning'],
                'message' => $message,
            ],
            MINUTE_IN_SECONDS * 5
        );
    }

    private static function pdf_preset(): string
    {
        $preset = defined('PVTL_PDF_OPTIMISATION_PRESET')
            ? PVTL_PDF_OPTIMISATION_PRESET
            : self::DEFAULT_PRESET;

        $preset = apply_filters('pvtl_pdf_optimisation_preset', $preset);
        $allowed = ['screen', 'ebook', 'printer', 'prepress'];

        if (!in_array($preset, $allowed, true)) {
            return self::DEFAULT_PRESET;
        }

        return $preset;
    }

    private static function resolve_cli_path(string $filePath): string
    {
        $resolved = realpath($filePath);

        if ($resolved !== false) {
            return $resolved;
        }

        $uploads = wp_get_upload_dir();
        $prefixed = $uploads['basedir'] . '/' . ltrim($filePath, '/');
        $resolved = realpath($prefixed);

        return $resolved !== false ? $resolved : '';
    }

    /**
     * @return array{status:string,before:int,after:int,message:string}
     */
    private static function optimise_attachment(int $attachmentId): array
    {
        $filePath = get_attached_file($attachmentId);

        if (!$filePath || !self::is_pdf($filePath)) {
            self::write_log('Batch optimisation skipped a non-PDF or unreadable attachment.', [
                'attachment_id' => $attachmentId,
                'file' => $filePath,
            ]);
            return [
                'status' => 'skipped',
                'before' => 0,
                'after' => 0,
                'message' => 'Attachment is not a readable PDF.',
            ];
        }

        $result = self::optimise_file($filePath);

        if (is_wp_error($result)) {
            self::write_log('Batch optimisation hit an error for an attachment.', [
                'attachment_id' => $attachmentId,
                'file' => $filePath,
                'error' => $result->get_error_message(),
            ]);
            return [
                'status' => 'error',
                'before' => 0,
                'after' => 0,
                'message' => $result->get_error_message(),
            ];
        }

        if (!$result['optimised']) {
            self::write_log('Batch optimisation skipped an attachment because no smaller file was produced.', [
                'attachment_id' => $attachmentId,
                'file' => $filePath,
                'before' => $result['before'],
                'after' => $result['after'],
            ]);
            return [
                'status' => 'skipped',
                'before' => $result['before'],
                'after' => $result['after'],
                'message' => 'No smaller PDF was produced.',
            ];
        }

        self::write_log('Batch optimisation completed for an attachment.', [
            'attachment_id' => $attachmentId,
            'file' => $filePath,
            'before' => $result['before'],
            'after' => $result['after'],
            'saved' => $result['before'] - $result['after'],
        ]);

        return [
            'status' => 'optimised',
            'before' => $result['before'],
            'after' => $result['after'],
            'message' => 'Optimised successfully.',
        ];
    }

    private static function count_pdf_attachments(): int
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'application/pdf',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        return (int) $query->found_posts;
    }

    /**
     * @return list<string>
     */
    private static function missing_required_commands(): array
    {
        $required = ['gs'];
        $missing = [];

        foreach ($required as $command) {
            if (!self::command_exists($command)) {
                $missing[] = $command;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function missing_optional_commands(): array
    {
        $optional = ['qpdf'];
        $missing = [];

        foreach ($optional as $command) {
            if (!self::command_exists($command)) {
                $missing[] = $command;
            }
        }

        return $missing;
    }

    private static function command_exists(string $command): bool
    {
        $output = [];
        $exitCode = 1;

        exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && !empty($output);
    }

    private static function is_pdf(string $filePath): bool
    {
        return strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf' && is_readable($filePath);
    }

    /**
     * @return array{success:bool,message:string}
     */
    private static function run_command(array $parts): array
    {
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);

        return [
            'success' => $exitCode === 0,
            'message' => trim(implode("\n", $output)),
        ];
    }

    /**
     * @return string|WP_Error
     */
    private static function create_temp_pdf_path()
    {
        $temporary = wp_tempnam('pvtl-pdf-optimisation');

        if (!$temporary) {
            return new WP_Error('pvtl_pdf_temp_file_failed', 'Unable to create a temporary PDF file.');
        }

        @unlink($temporary);

        return $temporary . '.pdf';
    }

    private static function replace_file(string $sourceFile, string $destinationFile): bool
    {
        if (@rename($sourceFile, $destinationFile)) {
            return true;
        }

        if (!@copy($sourceFile, $destinationFile)) {
            return false;
        }

        @unlink($sourceFile);

        return true;
    }

    private static function cleanup_temp_files(array $files): void
    {
        foreach ($files as $file) {
            if ($file && file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private static function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $size, $units[$unit]);
    }

    private static function log_directory(): string
    {
        return plugin_dir_path(__FILE__) . 'logs';
    }

    private static function log_file_path(): string
    {
        return self::log_directory() . '/pvtl-pdf-optimisation.log';
    }

    private static function ensure_log_directory(): bool
    {
        $directory = self::log_directory();

        if (is_dir($directory)) {
            return true;
        }

        return wp_mkdir_p($directory);
    }

    private static function write_log(string $message, array $context = []): void
    {
        if (!self::ensure_log_directory()) {
            return;
        }

        $payload = sprintf(
            "[%s] %s%s\n",
            gmdate('c'),
            $message,
            $context !== [] ? ' ' . wp_json_encode($context) : ''
        );

        file_put_contents(self::log_file_path(), $payload, FILE_APPEND | LOCK_EX);
    }
}
}

PVTL_PDF_Optimisation::init();
