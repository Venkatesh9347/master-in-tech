<?php

namespace App\Services;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders and stores certificate PDF artifacts.
 *
 * Artifacts are stored on the private 'local' disk (storage/app/private) and
 * are only ever served through the authenticated, ownership-checked download
 * endpoint — never through a public URL or the web server.
 */
class CertificatePdfService
{
    public function disk(): string
    {
        return 'local';
    }

    /**
     * Relative path for a certificate's PDF on the target disk.
     */
    public function pathFor(Certificate $certificate): string
    {
        return 'certificates/' . $certificate->certificate_code . '.pdf';
    }

    /**
     * Render the certificate view to a PDF binary string.
     */
    public function render(Certificate $certificate): string
    {
        return Pdf::loadView('certificates.certificate', [
            'recipientName' => $certificate->user?->name ?? 'Student',
            'courseTitle' => $certificate->course?->title ?? '',
            'instructor' => $certificate->course?->instructor ?? '',
            'certificateCode' => $certificate->certificate_code,
            'issuedAt' => $certificate->issued_at,
        ])->output();
    }

    /**
     * Ensure the PDF artifact exists for this certificate, generating it on
     * first request. Returns the final relative path.
     */
    public function ensureFor(Certificate $certificate): string
    {
        $path = $this->pathFor($certificate);
        $disk = Storage::disk($this->disk());

        if ($disk->exists($path)) {
            if ($certificate->pdf_path !== $path) {
                $certificate->forceFill(['pdf_path' => $path])->save();
            }

            return $path;
        }

        $disk->put($path, $this->render($certificate));

        $certificate->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * Best-effort generation used at certificate creation time. Failures are
     * non-fatal: the download endpoint re-attempts via ensureFor().
     */
    public function attempt(Certificate $certificate): void
    {
        try {
            $this->ensureFor($certificate);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}