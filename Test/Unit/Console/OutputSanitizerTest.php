<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Yireo\CustomerCommands\Console\OutputSanitizer;

final class OutputSanitizerTest extends TestCase
{
    #[DataProvider('getValues')]
    public function testSanitize(mixed $value, string $expected): void
    {
        $this->assertSame($expected, (new OutputSanitizer())->sanitize($value));
    }

    #[DataProvider('getFormatterTags')]
    public function testFormatterTagsAreRenderedLiterally(string $value): void
    {
        $sanitized = (new OutputSanitizer())->sanitize($value);
        $rendered = (new OutputFormatter(true))->format($sanitized);

        $this->assertSame($value, $rendered);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function getFormatterTags(): array
    {
        return [
            'error tag' => ['<error>Foo</error>'],
            'hyperlink tag' => ['<href=https://evil.test>Foo</>'],
            'color tag' => ['<fg=red;options=blink>Foo</>'],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function getValues(): array
    {
        return [
            'plain text' => ['Dorpsstraat 5', 'Dorpsstraat 5'],
            'integer' => [42, '42'],
            'null' => [null, ''],
            'array' => [['foo'], ''],
            'utf8' => ['Müller-Lüdenscheidt é 漢字', 'Müller-Lüdenscheidt é 漢字'],
            'csi clear screen' => ["Foo\x1b[2JBar", 'FooBar'],
            'csi color' => ["\x1b[31;1mRed\x1b[0m", 'Red'],
            'osc title bel' => ["\x1b]0;Owned\x07Foo", 'Foo'],
            'osc8 hyperlink' => ["\x1b]8;;https://evil.test\x1b\\Click\x1b]8;;\x1b\\", 'Click'],
            'carriage return' => ["Visible\rHidden", 'VisibleHidden'],
            'newline and tab' => ["Foo\n\tBar", 'FooBar'],
            'null byte and del' => ["Foo\x00\x7fBar", 'FooBar'],
            'c1 control' => ["Foo\xc2\x9bBar", 'FooBar'],
            'single escape' => ["Foo\x1bcBar", 'FooBar'],
        ];
    }
}
