<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class NewConfigUsageController extends Controller
{
    public function index()
    {
        return Admin::content(function (Content $content) {
            $content->header('使用说明');
            $content->description('配置中心 / 使用说明');
            $content->body($this->renderHtml());
        });
    }

    private function renderHtml(): string
    {
        $document = $this->markdownToHtml($this->usageMarkdown());

        return <<<HTML
<style>
    .new-config-usage-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:18px 22px; margin-bottom:14px; }
    .new-config-usage-doc { color:#344054; font-size:14px; line-height:1.75; }
    .new-config-usage-doc h1 { margin-top:0; font-size:24px; }
    .new-config-usage-doc h2 { margin-top:24px; padding-top:12px; border-top:1px solid #edf0f5; font-size:19px; }
    .new-config-usage-doc h3 { margin-top:18px; font-size:16px; }
    .new-config-usage-doc code { color:#344054; background:#f6f8fa; border-radius:3px; padding:2px 4px; }
    .new-config-usage-doc pre { background:#f6f8fa; border:1px solid #e4e7ec; border-radius:4px; padding:12px; overflow:auto; }
    .new-config-usage-doc pre code { padding:0; background:transparent; }
    .new-config-usage-doc blockquote { border-left:4px solid #d0d5dd; color:#667085; margin-left:0; padding-left:12px; }
    .new-config-usage-doc table { width:100%; border-collapse:collapse; margin:12px 0; }
    .new-config-usage-doc th, .new-config-usage-doc td { border:1px solid #e4e7ec; padding:7px 8px; vertical-align:top; }
    .new-config-usage-doc th { background:#f9fafb; }
</style>

<div class="new-config-usage-panel">
    <div class="new-config-usage-doc">
        {$document}
    </div>
</div>
HTML;
    }

    private function usageMarkdown(): string
    {
        $path = base_path('docs/configuration-usage.md');
        if (is_file($path) && is_readable($path)) {
            return (string)file_get_contents($path);
        }

        return <<<MARKDOWN
# 配置使用说明

当前环境没有找到 `docs/configuration-usage.md`。请确认部署包是否包含项目文档目录，或在代码仓库中查看该文件。
MARKDOWN;
    }

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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
