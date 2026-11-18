<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Modules\Core\Services\FileStorage;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\StructuredLogger;

/**
 * RegistrationController — the wizard registration flow.
 *
 * Routes (registered by the Core module):
 *   GET  /register, /register/service/{id}, /register/step/{n}
 *   POST /register/step/{n}
 *   GET  /register/success/{trackingCode}
 *
 * Phase 41 (v2.0.7) — only the document-upload handler is shipped here.
 * The full wizard (form rendering, step validation, persistence, success
 * page) is implemented in a later phase. handleDocumentUploads() is shipped
 * now so step4.php's v2-style named file inputs
 * (national_card_front, national_card_back, birth_cert, ownership_doc,
 * vocational_cert, education_degree, product_photos[], optional_N) have a
 * matching server-side handler that converts the named $_FILES entries
 * into documents rows via FileStorage::storeUpload().
 *
 * The handler is idempotent: missing optional fields are silently skipped.
 * Required fields are validated by the caller (the step4 POST handler)
 * before this method is called.
 */
final class RegistrationController extends Controller
{
    /**
     * The v2-style named file inputs that handleDocumentUploads() knows
     * about. Each entry maps the $_FILES key → the documents.doc_type
     * label that gets stored alongside the file.
     */
    public const DOC_FIELD_MAP = [
        'national_card_front' => 'national_card_front',
        'national_card_back'  => 'national_card_back',
        'birth_cert'          => 'birth_cert',
        'ownership_doc'       => 'ownership_doc',
        'vocational_cert'     => 'vocational_cert',
        'education_degree'    => 'education_degree',
        'optional_1'          => 'optional_1',
        'optional_2'          => 'optional_2',
        'optional_3'          => 'optional_3',
    ];

    /** Multi-file field (array of files under one $_FILES key). */
    public const DOC_FIELD_ARRAY = 'product_photos';

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly FileStorage $storage
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * Handle v2-style named document uploads from step4.
     *
     * Walks the expected $_FILES keys (national_card_front, national_card_back,
     * birth_cert, ownership_doc, vocational_cert, education_degree,
     * product_photos[], optional_1, optional_2, optional_3) and stores each
     * uploaded file via FileStorage::storeUpload(). Returns a result map:
     *
     *   [
     *     'stored'   => array<int, array{field:string, file_path:string,
     *                                    file_name:string, doc_type:string}>,
     *     'errors'   => array<int, array{field:string, error:string}>,
     *     'skipped'  => int,
     *   ]
     *
     * Missing optional fields are NOT errors — they're skipped silently.
     * Required-field enforcement is the caller's responsibility (the step4
     * POST handler validates before calling this).
     *
     * @param int    $applicantId   Applicants row id (for ownership of the
     *                              files; not used as a path component).
     * @param string $trackingCode  Applicant's tracking code (folder name).
     * @param array<string,bool> $sysActive  Toggle map (which optional
     *                                      fields are accepted). Missing
     *                                      keys default to true.
     *
     * @return array{stored:array<int,array<string,string>>,errors:array<int,array<string,string>>,skipped:int}
     */
    public function handleDocumentUploads(
        int $applicantId,
        string $trackingCode,
        array $sysActive = []
    ): array {
        $stored  = [];
        $errors  = [];
        $skipped = 0;

        $on = static fn (string $key): bool => $sysActive[$key] ?? true;

        // Single-file fields.
        foreach (self::DOC_FIELD_MAP as $field => $docType) {
            if (!$on($field)) {
                $skipped++;
                continue;
            }
            if (!isset($_FILES[$field])) {
                $skipped++;
                continue;
            }
            $file = $_FILES[$field];
            // Treat as "skipped" if no file was actually selected.
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                || ($file['size'] ?? 0) === 0
            ) {
                $skipped++;
                continue;
            }
            $result = $this->storage->storeUpload($file, $applicantId, $trackingCode, $docType);
            if ($result['success']) {
                $stored[] = [
                    'field'     => $field,
                    'doc_type'  => $docType,
                    'file_path' => (string)$result['file_path'],
                    'file_name' => (string)$result['file_name'],
                ];
            } else {
                $errors[] = [
                    'field' => $field,
                    'error' => (string)$result['error'],
                ];
            }
        }

        // Multi-file field: product_photos[].
        if ($on(self::DOC_FIELD_ARRAY) && isset($_FILES[self::DOC_FIELD_ARRAY])) {
            $entries = $_FILES[self::DOC_FIELD_ARRAY];
            // PHP normalises <input name="product_photos[]"> into parallel
            // arrays: name[], tmp_name[], error[], size[] are all indexed
            // by the same 0..N-1 index.
            $count = is_array($entries['name'] ?? null) ? count($entries['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if (($entries['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                    || ($entries['size'][$i] ?? 0) === 0
                ) {
                    $skipped++;
                    continue;
                }
                $file = [
                    'name'     => $entries['name'][$i] ?? '',
                    'type'     => $entries['type'][$i] ?? '',
                    'tmp_name' => $entries['tmp_name'][$i] ?? '',
                    'error'    => $entries['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $entries['size'][$i] ?? 0,
                ];
                $result = $this->storage->storeUpload($file, $applicantId, $trackingCode, self::DOC_FIELD_ARRAY);
                if ($result['success']) {
                    $stored[] = [
                        'field'     => self::DOC_FIELD_ARRAY,
                        'doc_type'  => self::DOC_FIELD_ARRAY,
                        'file_path' => (string)$result['file_path'],
                        'file_name' => (string)$result['file_name'],
                    ];
                } else {
                    $errors[] = [
                        'field' => self::DOC_FIELD_ARRAY,
                        'error' => (string)$result['error'],
                    ];
                }
            }
        } else {
            $skipped++;
        }

        return ['stored' => $stored, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * Stub for the wizard entry route — implemented in a later phase.
     *
     * @codeCoverageIgnore
     */
    public function start(Request $request, array $params = []): Response
    {
        return $this->render('registration/select_service', ['title' => 'انتخاب خدمت']);
    }

    /**
     * Stub for the step route — implemented in a later phase.
     *
     * @codeCoverageIgnore
     */
    public function step(Request $request, array $params = []): Response
    {
        return $this->render('registration/step4', ['title' => 'مرحلهٔ ۴']);
    }

    /**
     * Stub for the success route — implemented in a later phase.
     *
     * @codeCoverageIgnore
     */
    public function success(Request $request, array $params = []): Response
    {
        return $this->render('registration/success', ['title' => 'ثبت نهایی شد']);
    }
}
