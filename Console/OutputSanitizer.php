<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

class OutputSanitizer
{
    private const ESCAPE_SEQUENCES = '/\x1B\[[0-?]*[ -\/]*[@-~]|\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?|\x1B[ -\/]*[0-~]?/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/';

    public function sanitize(mixed $value): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        $value = (string)preg_replace(self::ESCAPE_SEQUENCES, '', $value);
        $value = (string)preg_replace(self::CONTROL_CHARACTERS, '', $value);

        return OutputFormatter::escape($value);
    }
}
