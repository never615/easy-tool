<?php

namespace Mallto\Tool\Domain\Log;

use Illuminate\Http\Request;

/** 规则只匹配完整路径及通配符，不区分 HTTP 方法或查询参数。 */
class OwnerApiLogFilter
{
    public function matches(Request $request, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule) || trim($rule) === '') {
                continue;
            }
            $path = trim($rule);
            if ($path !== '/') {
                $path = trim($path, '/');
            }
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
