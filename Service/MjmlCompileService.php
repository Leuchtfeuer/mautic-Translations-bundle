<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class MjmlCompileService
{
    public function __construct(
        private LoggerInterface $logger,
        private CoreParametersHelper $parametersHelper,
    ) {
    }

    /**
     * Compile MJML into HTML.
     *
     * Strategy:
     *  A) mjml CLI (if available)
     *  B) graceful fallback (very light tag mapping so preview shows translated text)
     *
     * @return array{success: bool, html?: string, error?: string}
     */
    public function compile(string $mjml, ?string $template = null): array
    {
        // A) Try mjml CLI
        $cli = $this->findMjmlCli();
        if (null !== $cli) {
            $r = $this->compileViaCli($cli, $mjml);
            if ($r['success']) {
                return $r;
            }
            $this->log('[LeuchtfeuerTranslations][MJML] CLI compile failed, falling back', ['error' => $r['error'] ?? 'unknown']);
        }

        // B) Fallback: minimal tag mapping so preview shows translated text
        $html = $this->veryLightFallback($mjml);

        return ['success' => true, 'html' => $html];
    }

    private function findMjmlCli(): ?string
    {
        // Prefer absolute candidates first (fast path)
        $absoluteCandidates = ['/usr/bin/mjml', '/usr/local/bin/mjml', '/bin/mjml'];
        foreach ($absoluteCandidates as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        // Then look up in PATH
        $finder = new ExecutableFinder();
        $found  = $finder->find('mjml');

        return $found ?: null;
    }

    /**
     * Uses temporary files and the mjml CLI to compile.
     */
    private function compileViaCli(string $cli, string $mjml): array
    {
        // Use configured Mautic tmp path if available; fallback to system tmp
        $tmpDir = (string) ($this->parametersHelper->get('tmp_path') ?: sys_get_temp_dir());

        $in  = @tempnam($tmpDir, 'mjml_in_') ?: null;
        $out = @tempnam($tmpDir, 'mjml_out_') ?: null;

        if (!$in || !$out) {
            return ['success' => false, 'error' => 'Unable to create temp files'];
        }

        file_put_contents($in, $mjml);

        $process = new Process([$cli, $in, '-o', $out]);
        $process->setTimeout(30);
        $this->log('[LeuchtfeuerTranslations][MJML] invoking CLI', ['cmd' => $process->getCommandLine()]);
        $process->run();

        $ok   = is_file($out) && filesize($out) > 0;
        $html = $ok ? @file_get_contents($out) : false;

        @unlink($in);
        @unlink($out);

        if (!$ok || $html === false) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Unknown MJML CLI error');
            return ['success' => false, 'error' => $err];
        }

        return ['success' => true, 'html' => $html];
    }

    /**
     * Extremely light fallback so previews don't stay stale if CLI is missing.
     * Not a full MJML renderer—just unwraps key tags to reasonable HTML.
     *
     * @see https://mjml.io/documentation/
     * @see https://github.com/mjmlio/mjml
     */
    private function veryLightFallback(string $mjml): string
    {
        $html = $mjml;

        // Strip mjml/mj-head wrappers; keep <mj-preview> text in a meta-ish div
        $html = preg_replace('/<\/?mjml[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-head[^>]*>.*?<\/mj-head>/is', '', $html);

        // mj-preview → hidden preview block
        $html = preg_replace('/<mj-preview>(.*?)<\/mj-preview>/is', '<div style="display:none;visibility:hidden;">$1</div>', $html);

        // mj-text → p
        $html = preg_replace('/<mj-text\b[^>]*>(.*?)<\/mj-text>/is', '<p>$1</p>', $html);

        // mj-button → <a>
        $html = preg_replace('/<mj-button\b([^>]*)>(.*?)<\/mj-button>/is', '<p><a$1>$2</a></p>', $html);
        // fix attributes like mjml-style on <a>
        $html = preg_replace('/<a([^>]*)\bmj-?[a-z0-9_-]+="[^"]*"([^>]*)>/i', '<a$1$2>', $html);

        // mj-image → <img>
        $html = preg_replace('/<mj-image\b([^>]*)\/?>/is', '<img $1 />', $html);

        // unwrap sections/columns/body
        $html = preg_replace('/<\/?mj-body[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-section[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?mj-column[^>]*>/i', '', $html);

        // Remove mj-raw wrappers but keep inner HTML intact
        $html = preg_replace('/<mj-raw>(.*?)<\/mj-raw>/is', '$1', $html);

        // Wrap if bare
        if (!preg_match('/<html\b/i', $html)) {
            $html = "<!doctype html>\n<html><body>\n".$html."\n</body></html>";
        }

        return $html;
    }

    private function log(string $msg, array $ctx = []): void
    {
        $this->logger->info($msg, $ctx);
    }
}
