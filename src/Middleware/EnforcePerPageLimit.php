<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 统一限制 SQL 列表请求的 per_page 最大值。
 */
class EnforcePerPageLimit
{
    public const DEFAULT_PER_PAGE_MAX = 1000;

    public const DEFAULT_EXCLUDED_PATHS = [
        'api/lbs/location/histories',
        'api/lbs/location/histories/*',
        'admin/api/lbs/history_locator',
        'api/lbs/history_locator',
        'api/tp/lbs/history_locator',
    ];

    /**
     * 仅校验数值型 per_page 的上限，保留端点原有的最小值、整数和不分页兼容逻辑。
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $excludedPaths = config('easy_tool.pagination.per_page_limit_excluded_paths', []);
        $excludedPaths = is_array($excludedPaths) ? $excludedPaths : [];
        $excludedPaths = array_values(array_unique([
            ...self::DEFAULT_EXCLUDED_PATHS,
            ...$excludedPaths,
        ]));
        if ($request->is(...$excludedPaths)) {
            return $next($request);
        }

        $perPage = $request->input('per_page');
        $maximum = config('easy_tool.pagination.per_page_max', self::DEFAULT_PER_PAGE_MAX);
        $maximum = is_numeric($maximum) && (int)$maximum > 0 ? (int)$maximum : self::DEFAULT_PER_PAGE_MAX;
        $maximum = min($maximum, self::DEFAULT_PER_PAGE_MAX);

        if (is_numeric($perPage) && (float)$perPage > $maximum) {
            throw ValidationException::withMessages([
                'per_page' => ["The per page may not be greater than {$maximum}."],
            ]);
        }

        return $next($request);
    }
}
