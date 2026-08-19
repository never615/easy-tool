<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * QueueDiagnosticUsageController：展示队列诊断监控的只读使用文档。
 */
class QueueDiagnosticUsageController extends Controller
{
    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index()
    {
        return Admin::content(function (Content $content) {
            $content->header('队列诊断监控使用说明');
            $content->description('Redis / Horizon / 使用文档');
            $content->body($this->renderHtml());
        });
    }

    /**
     * 处理「renderHtml」相关的内部协作步骤。
     */
    private function renderHtml(): string
    {
        $document = $this->markdownToHtml($this->usageMarkdown());
        $indexUrl = $this->escape(route('queue_diagnostics.index'));

        return <<<HTML
<style>
    .queue-diag-usage-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:18px 22px; margin-bottom:14px; }
    .queue-diag-usage-actions { margin-bottom:14px; }
    .queue-diag-usage-doc { color:#344054; font-size:14px; line-height:1.75; }
    .queue-diag-usage-doc h1 { margin-top:0; font-size:24px; }
    .queue-diag-usage-doc h2 { margin-top:24px; padding-top:12px; border-top:1px solid #edf0f5; font-size:19px; }
    .queue-diag-usage-doc h3 { margin-top:18px; font-size:16px; }
    .queue-diag-usage-doc code { color:#344054; background:#f6f8fa; border-radius:3px; padding:2px 4px; }
    .queue-diag-usage-doc pre { background:#f6f8fa; border:1px solid #e4e7ec; border-radius:4px; padding:12px; overflow:auto; }
    .queue-diag-usage-doc pre code { padding:0; background:transparent; }
    .queue-diag-usage-doc blockquote { border-left:4px solid #d0d5dd; color:#667085; margin-left:0; padding-left:12px; }
    .queue-diag-usage-doc table { width:100%; border-collapse:collapse; margin:12px 0; }
    .queue-diag-usage-doc th, .queue-diag-usage-doc td { border:1px solid #e4e7ec; padding:7px 8px; vertical-align:top; }
    .queue-diag-usage-doc th { background:#f9fafb; }
</style>

<div class="queue-diag-usage-panel">
    <div class="queue-diag-usage-actions">
        <a class="btn btn-sm btn-default" href="{$indexUrl}">返回队列诊断监控</a>
    </div>
    <div class="queue-diag-usage-doc">
        {$document}
    </div>
</div>
HTML;
    }

    /**
     * 读取与本包一起发布的 Markdown 文档，确保后台说明与版本保持一致。
     */
    private function usageMarkdown(): string
    {
        $path = dirname(__DIR__, 3) . '/docs/queue-diagnostic-monitor-usage.md';
        $markdown = is_readable($path) ? file_get_contents($path) : false;

        if (is_string($markdown) && trim($markdown) !== '') {
            return $markdown;
        }

        return <<<MARKDOWN
# 队列诊断监控使用说明

使用文档暂时不可读取。请确认本次 EasyTool 发布内容包含 `docs/queue-diagnostic-monitor-usage.md`。
MARKDOWN;
    }

    /**
     * 处理「markdownToHtml」相关的内部协作步骤。
     */
    private function markdownToHtml(string $markdown): string
    {
        if (!class_exists(GithubFlavoredMarkdownConverter::class)) {
            return '<pre>' . $this->escape($markdown) . '</pre>';
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return (string)$converter->convert($markdown);
    }

    /**
     * 处理「escape」相关的内部协作步骤。
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
