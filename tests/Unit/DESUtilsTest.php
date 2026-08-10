<?php

namespace Mallto\Tool\Tests\Unit;

use Mallto\Tool\Utils\DESUtils;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DESUtilsTest extends TestCase
{
    public function test_un_pkcs_padding_keeps_legacy_empty_and_zero_padding_results(): void
    {
        $this->assertSame('', $this->unPkcsPadding(''));
        $this->assertSame('', $this->unPkcsPadding("\0"));
    }

    public function test_un_pkcs_padding_rejects_padding_larger_than_input(): void
    {
        $this->assertFalse($this->unPkcsPadding(chr(2)));
    }

    private function unPkcsPadding(string $value)
    {
        $method = new ReflectionMethod(DESUtils::class, 'unPkcsPadding');

        return $method->invoke(new DESUtils(), $value);
    }
}
