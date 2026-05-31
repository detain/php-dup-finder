<?php
declare(strict_types=1);

namespace Acme\Chat\Language;

final class Cld2FfiDetector
{
    private \FFI $ffi;

    public function __construct(string $libraryPath)
    {
        if (!file_exists($libraryPath)) {
            throw new \InvalidArgumentException("missing lib: $libraryPath");
        }
        $this->ffi = \FFI::cdef(
            'int cld2_detect(const char *text, int len, char *out_lang, int out_size, double *confidence);',
            $libraryPath
        );
    }

    public function identify(string $message): ?string
    {
        $text = trim($message);
        if ($text === '') {
            return null;
        }
        $len = strlen($text);
        if ($len > 50_000) {
            $text = substr($text, 0, 50_000);
            $len  = 50_000;
        }
        $outLang   = \FFI::new('char[8]');
        $confidence = \FFI::new('double');
        $result = $this->ffi->cld2_detect($text, $len, $outLang, 8, \FFI::addr($confidence));
        if ($result !== 0) {
            return null;
        }
        $code = trim(\FFI::string($outLang));
        if ($code === '' || strlen($code) !== 2) {
            return null;
        }
        if ($confidence->cdata < 0.5) {
            return null;
        }
        return strtolower($code);
    }
}
