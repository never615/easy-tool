<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\AppStats\AppStatsPodRepository;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestCreator;
use Throwable;

/**
 * 创建由目标 Pod 本地执行的 task_tmpdir 清理请求。
 *
 * HTTP 只读取共享 Pod 快照并写入有界 Redis 控制记录；绝不扫描或删除本机文件。
 */
final class SwooleTaskTmpCleanupController extends Controller
{
    public function store(
        Request $request,
        AppStatsPodRepository $pods,
        SwooleTaskTmpCleanupRequestCreator $requests
    ): JsonResponse {
        $scope = (string) $request->input('scope', '');
        if (! in_array($scope, ['all', 'pod'], true)) {
            return $this->error('invalid_scope', 422);
        }

        $podState = $pods->read();
        if (! ($podState['ok'] ?? false)) {
            return $this->error('pod_state_unavailable', 503);
        }

        $eligibleTargets = $this->eligibleTargets((array) ($podState['pods'] ?? []));
        if ($scope === 'all') {
            $targets = $eligibleTargets;
        } else {
            $hostname = $request->input('hostname');
            $instanceId = $request->input('instance_id');
            if (! is_string($hostname) || $hostname === '' || ! is_string($instanceId) || $instanceId === '') {
                return $this->error('invalid_target', 422);
            }

            $targets = array_values(array_filter(
                $eligibleTargets,
                static fn (array $target): bool => $target['hostname'] === $hostname && $target['instance_id'] === $instanceId
            ));
            if ($targets === []) {
                return $this->error('target_unavailable', 422);
            }
        }

        if ($targets === []) {
            return $this->error('no_eligible_targets', 409);
        }

        $user = Admin::user();
        $requestedBy = $user?->getAuthIdentifier();
        if ($requestedBy === null) {
            return $this->error('unauthenticated', 403);
        }

        try {
            $created = $requests->create($targets, $requestedBy, time());
        } catch (Throwable) {
            return $this->error('cleanup_request_unavailable', 503);
        }

        return response()->json([
            'accepted' => true,
            'created' => $created['created'],
            'request_id' => $created['request_id'],
        ], 202);
    }

    /**
     * @param list<array<string, mixed>> $pods
     * @return list<array{hostname: string, instance_id: string}>
     */
    private function eligibleTargets(array $pods): array
    {
        $targets = [];
        foreach ($pods as $pod) {
            $hostname = $pod['hostname'] ?? null;
            $instanceId = $pod['instance_id'] ?? null;
            $taskTmp = $pod['task_tmp'] ?? null;
            if (! is_string($hostname) || $hostname === ''
                || ! is_string($instanceId) || $instanceId === ''
                || ($pod['status'] ?? null) !== 'online'
                || ! is_array($taskTmp)
                || ($taskTmp['status'] ?? null) !== 'normal'
                || ($taskTmp['process_enabled'] ?? false) !== true
                || ($taskTmp['instance_id'] ?? null) !== $instanceId) {
                continue;
            }

            $targets[] = [
                'hostname' => $hostname,
                'instance_id' => $instanceId,
            ];
        }

        return $targets;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'accepted' => false,
            'message' => $message,
        ], $status);
    }
}
