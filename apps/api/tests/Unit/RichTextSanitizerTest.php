<?php

namespace Tests\Unit;

use App\Common\Support\RichTextSanitizer;
use Tests\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_it_keeps_editor_markup_and_removes_unsafe_content(): void
    {
        $sanitizer = app(RichTextSanitizer::class);
        $clean = $sanitizer->sanitize('<h2 style="text-align:center" onclick="alert(1)">标题</h2><script>alert(1)</script><a href="javascript:alert(1)">链接</a><img src="https://example.com/a.jpg" onerror="alert(1)">');

        $this->assertStringContainsString('<h2 style="text-align:center;">标题</h2>', $clean);
        $this->assertStringContainsString('https://example.com/a.jpg', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertSame("标题\n链接", $sanitizer->toPlainText($clean));
    }
}
