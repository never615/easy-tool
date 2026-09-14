<?php

namespace Mallto\Tool\Domain\RedisMonitor;

/** 把只读 Redis 探针样本整理成脱敏、可交付的诊断结论。 */
class RedisDiagnosticAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $samples
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function analyze(array $samples, ?float $bandwidthLimitMbps, array $metadata): array
    {
        $strict = array_values(array_filter(array_column($samples, 'strict'), 'is_array'));
        $relaxed = array_values(array_filter(array_column($samples, 'relaxed'), 'is_array'));
        $strictSummary = $this->probeSummary($strict);
        $relaxedSummary = $this->probeSummary($relaxed);
        $network = $this->networkSummary($relaxed, $bandwidthLimitMbps);
        $identityComparable = $this->identityComparable($strict, $relaxed);
        $queueSummary = $this->queueSummary($samples);
        $conclusion = $this->conclusion(
            $strictSummary,
            $relaxedSummary,
            $network,
            $identityComparable
        );

        return [
            'schema_version' => 1,
            'metadata' => $metadata,
            'evidence_scope' => [
                'application_read_only_probe' => true,
                'can_prove_bandwidth_saturation_alone' => false,
                'required_platform_evidence' => [
                    'confirmed_interface_capacity',
                    'interface_drops_and_errors',
                    'tcp_retransmits',
                    'node_network_and_cpu_throttling',
                ],
            ],
            'summary' => [
                'conclusion_code' => $conclusion['code'],
                'conclusion' => $conclusion['text'],
                'identity_comparable' => $identityComparable,
                'strict_probe' => $strictSummary,
                'relaxed_probe' => $relaxedSummary,
                'network' => $network,
                'queues' => $queueSummary,
            ],
            'samples' => $samples,
        ];
    }

    public function toMarkdown(array $report): string
    {
        $summary = $report['summary'];
        $metadata = $report['metadata'];
        $network = $summary['network'];
        $strict = $summary['strict_probe'];
        $relaxed = $summary['relaxed_probe'];
        $queues = $summary['queues'];

        $lines = [
            '# Redis 网络与响应诊断报告',
            '',
            '## 结论',
            '',
            $summary['conclusion'],
            '',
            '该结论来自应用侧只读探针，只能确认现象的时间相关性；正式确认带宽饱和仍需平台网络证据。',
            '',
            '## 采样信息',
            '',
            '- 开始时间：'.$metadata['started_at'],
            '- 结束时间：'.$metadata['finished_at'],
            '- 计划时长：'.$metadata['duration_seconds'].' 秒',
            '- 采样间隔：'.$metadata['interval_ms'].' 毫秒',
            '- 严格探针：'.$metadata['strict_read_timeout_ms'].' 毫秒读取超时',
            '- 对照探针：'.$metadata['relaxed_read_timeout_ms'].' 毫秒读取超时',
            '- Redis 身份可比：'.($summary['identity_comparable'] ? '是' : '否或无法确认'),
            '',
            '## 响应结果',
            '',
            '| 探针 | 尝试次数 | 失败次数 | P50 | P95 | 最大耗时 |',
            '| --- | ---: | ---: | ---: | ---: | ---: |',
            sprintf('| 严格 | %d | %d | %s ms | %s ms | %s ms |', $strict['attempts'], $strict['failures'], $this->number($strict['latency_ms']['p50']), $this->number($strict['latency_ms']['p95']), $this->number($strict['latency_ms']['max'])),
            sprintf('| 对照 | %d | %d | %s ms | %s ms | %s ms |', $relaxed['attempts'], $relaxed['failures'], $this->number($relaxed['latency_ms']['p50']), $this->number($relaxed['latency_ms']['p95']), $this->number($relaxed['latency_ms']['max'])),
            '',
            '## Redis 网络吞吐',
            '',
            '- 平均出站：'.$this->number($network['output_mbps']['average']).' Mbps',
            '- 峰值出站：'.$this->number($network['output_mbps']['max']).' Mbps',
            '- 平均入站：'.$this->number($network['input_mbps']['average']).' Mbps',
            '- 峰值入站：'.$this->number($network['input_mbps']['max']).' Mbps',
        ];

        if ($network['supplied_bandwidth_limit_mbps'] !== null) {
            $lines[] = '- 外部提供的带宽上限：'.$this->number($network['supplied_bandwidth_limit_mbps']).' Mbps';
            $lines[] = '- 峰值相对使用率：'.$this->number($network['peak_utilization_percent']).'%';
        } else {
            $lines[] = '- 带宽上限：未知，无法仅凭应用侧吞吐确认“带宽已满”';
        }

        $lines = array_merge($lines, ['', '## 队列积压', '']);
        if (! $queues['comparable']) {
            $lines[] = '队列 Redis 与诊断 Redis 不可确认是同一端点，本报告不使用队列数据佐证。';
        } elseif ($queues['max_total'] === null) {
            $lines[] = '队列长度采集不可用。';
        } else {
            $lines[] = '- 采样期间最大总积压：'.$queues['max_total'];
            foreach ($queues['max_by_queue'] as $queue => $size) {
                $lines[] = '- `'.$queue.'` 最大积压：'.$size;
            }
        }

        $lines = array_merge($lines, [
            '',
            '## 证据边界',
            '',
            '- 报告不包含 Redis 地址、密码、业务 Key、任务内容或原始异常信息。',
            '- 高吞吐与超时同时出现只能说明高度相关，不能单独证明因果关系。',
            '- 平台侧还需提供接口容量、丢包、重传、节点网络及 CPU throttling 指标。',
            '',
        ]);

        return implode("\n", $lines);
    }

    /** @param array<int, array<string, mixed>> $probes */
    private function probeSummary(array $probes): array
    {
        $durations = [];
        $failures = [];
        foreach ($probes as $probe) {
            foreach ((array) ($probe['stages'] ?? []) as $stageName => $stage) {
                if (str_starts_with((string) $stageName, 'info_')) {
                    $durations[] = (float) ($stage['duration_ms'] ?? 0);
                }
                if (! ($stage['ok'] ?? false)) {
                    $key = $stageName.':'.($stage['error_kind'] ?? 'unknown');
                    $failures[$key] = ($failures[$key] ?? 0) + 1;
                }
            }
        }

        return [
            'attempts' => count($probes),
            'failures' => array_sum($failures),
            'failures_by_stage' => $failures,
            'monitor_stage_failures' => array_sum(array_filter(
                $failures,
                static fn (string $key): bool => str_starts_with($key, 'connection:')
                    || str_starts_with($key, 'info_memory:')
                    || str_starts_with($key, 'info_stats:'),
                ARRAY_FILTER_USE_KEY
            )),
            'monitor_stage_read_failures' => array_sum(array_filter(
                $failures,
                static fn (string $key): bool => (
                    str_starts_with($key, 'info_memory:')
                    || str_starts_with($key, 'info_stats:')
                ) && (
                    str_contains($key, ':timeout')
                    || str_contains($key, ':connection_read_error')
                ),
                ARRAY_FILTER_USE_KEY
            )),
            'latency_ms' => [
                'p50' => $this->percentile($durations, 50),
                'p95' => $this->percentile($durations, 95),
                'max' => $durations === [] ? null : round(max($durations), 3),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $probes */
    private function networkSummary(array $probes, ?float $limit): array
    {
        $inputRates = [];
        $outputRates = [];
        $invalidIntervals = 0;
        $previous = null;

        foreach ($probes as $probe) {
            $metrics = (array) ($probe['metrics'] ?? []);
            if (! isset($metrics['total_net_input_bytes'], $metrics['total_net_output_bytes'])) {
                continue;
            }

            if ($previous !== null) {
                $seconds = ((int) $probe['monotonic_ns'] - (int) $previous['monotonic_ns']) / 1_000_000_000;
                $sameIdentity = ($metrics['run_id_hash'] ?? null) === ($previous['metrics']['run_id_hash'] ?? null);
                $inputDelta = (int) $metrics['total_net_input_bytes'] - (int) $previous['metrics']['total_net_input_bytes'];
                $outputDelta = (int) $metrics['total_net_output_bytes'] - (int) $previous['metrics']['total_net_output_bytes'];

                if ($seconds <= 0 || ! $sameIdentity || $inputDelta < 0 || $outputDelta < 0) {
                    $invalidIntervals++;
                } else {
                    $inputRates[] = round(($inputDelta * 8) / $seconds / 1_000_000, 3);
                    $outputRates[] = round(($outputDelta * 8) / $seconds / 1_000_000, 3);
                }
            }
            $previous = $probe;
        }

        $maxOutput = $outputRates === [] ? null : max($outputRates);

        return [
            'valid_intervals' => count($outputRates),
            'invalid_intervals' => $invalidIntervals,
            'input_mbps' => $this->rateSummary($inputRates),
            'output_mbps' => $this->rateSummary($outputRates),
            'supplied_bandwidth_limit_mbps' => $limit,
            'peak_utilization_percent' => $limit !== null && $maxOutput !== null
                ? round(($maxOutput / $limit) * 100, 2)
                : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $strict @param array<int, array<string, mixed>> $relaxed */
    private function identityComparable(array $strict, array $relaxed): bool
    {
        $strictIds = $this->identities($strict);
        $relaxedIds = $this->identities($relaxed);

        return count($strictIds) === 1 && $strictIds === $relaxedIds;
    }

    /** @param array<int, array<string, mixed>> $probes */
    private function identities(array $probes): array
    {
        $ids = [];
        foreach ($probes as $probe) {
            $id = $probe['metrics']['run_id_hash'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /** @param array<int, array<string, mixed>> $samples */
    private function queueSummary(array $samples): array
    {
        $comparable = true;
        $maxByQueue = [];
        foreach ($samples as $sample) {
            $queues = (array) ($sample['queues'] ?? []);
            if (($queues['comparable'] ?? true) === false) {
                $comparable = false;
            }
            foreach ((array) ($queues['sizes'] ?? []) as $name => $row) {
                if (($row['ok'] ?? false) && is_numeric($row['size'] ?? null)) {
                    $maxByQueue[$name] = max($maxByQueue[$name] ?? 0, (int) $row['size']);
                }
            }
        }

        return [
            'comparable' => $comparable,
            'max_total' => $maxByQueue === [] ? null : array_sum($maxByQueue),
            'max_by_queue' => $maxByQueue,
        ];
    }

    private function conclusion(array $strict, array $relaxed, array $network, bool $identityComparable): array
    {
        if (! $identityComparable) {
            return ['code' => 'comparison_not_valid', 'text' => '严格探针与对照探针无法确认连接到同一 Redis 实例，本次不能形成有效对照结论。'];
        }
        if ($strict['monitor_stage_read_failures'] > 0 && $relaxed['monitor_stage_failures'] === 0) {
            $utilization = $network['peak_utilization_percent'];
            if ($utilization !== null && $utilization >= 90) {
                return ['code' => 'high_correlation_near_supplied_capacity', 'text' => '严格探针出现读取失败、1 秒对照探针正常，且 Redis 峰值出站接近外部提供的带宽上限；现象高度相关，疑似 Redis 或网络链路压力。'];
            }

            return ['code' => 'strict_read_failure_capacity_unknown', 'text' => '严格探针出现读取失败而 1 秒对照探针正常，说明 Redis 响应存在超过当前监控容忍度的延迟或抖动；带宽上限未知，暂不能确认带宽已满。'];
        }
        if ($strict['monitor_stage_failures'] > 0 && $relaxed['monitor_stage_failures'] > 0) {
            return ['code' => 'redis_unavailable_or_severely_degraded', 'text' => '严格探针和 1 秒对照探针均出现失败，Redis 当时可能不可用或严重退化，不能归因于单一的 100 毫秒阈值。'];
        }

        return ['code' => 'issue_not_reproduced', 'text' => '本次采样未复现严格探针读取失败，不能据此确认此前日志的直接原因。'];
    }

    /** @param array<int, float> $rates */
    private function rateSummary(array $rates): array
    {
        return [
            'average' => $rates === [] ? null : round(array_sum($rates) / count($rates), 3),
            'max' => $rates === [] ? null : round(max($rates), 3),
        ];
    }

    /** @param array<int, float> $values */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return round($values[max(0, $index)], 3);
    }

    private function number(mixed $value): string
    {
        return $value === null ? '不可用' : (string) $value;
    }
}
