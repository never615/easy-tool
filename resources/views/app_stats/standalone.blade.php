<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $compatibilityTitle }}</title>
</head>
<body>
@include('easy-tool::app_stats.content', [
    'mode' => 'compatibility',
    'dataUrl' => null,
    'showRedisMemory' => false,
    'autoRefresh' => false,
    'compatibilityTitle' => $compatibilityTitle,
    'compatibilityGroups' => $compatibilityGroups,
])
</body>
</html>
