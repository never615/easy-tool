<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\Config;

class GlobalConfigDefinitions
{
    public static function modules(): array
    {
        return [
            'basic' => [
                'title' => '基础业务配置',
                'description' => 'App Secret / 调试日志 / API 日志',
                'route' => 'configs.basic',
                'save_route' => 'configs.basic.save',
            ],
            'sms' => [
                'title' => '短信与告警配置',
                'description' => '融合通信 / 系统告警 / 报警模板',
                'route' => 'configs.sms',
                'save_route' => 'configs.sms.save',
            ],
            'location_algorithm' => [
                'title' => '定位算法配置',
                'description' => '定位算法参数 / Java 定位服务地址',
                'route' => 'configs.location_algorithm',
                'save_route' => 'configs.location_algorithm.save',
            ],
            'location_maintenance' => [
                'title' => '定位维护配置',
                'description' => '区域判定 / 报警日志清理 / 模拟定位',
                'route' => 'configs.location_maintenance',
                'save_route' => 'configs.location_maintenance.save',
            ],
            'location_debug' => [
                'title' => '定位日志配置',
                'description' => '网关原始数据 / 调试日志 / 异常日志',
                'route' => 'configs.location_debug',
                'save_route' => 'configs.location_debug.save',
            ],
        ];
    }

    public static function definitions(?string $module = null): array
    {
        $definitions = self::keyByConfigKey(array_merge(
            self::basicDefinitions(),
            self::smsDefinitions(),
            self::locationAlgorithmDefinitions(),
            self::locationMaintenanceDefinitions(),
            self::locationDebugDefinitions()
        ));

        if ($module === null) {
            return $definitions;
        }

        return array_filter($definitions, function (array $definition) use ($module) {
            return ($definition['module'] ?? null) === $module;
        });
    }

    public static function module(string $module): ?array
    {
        $modules = self::modules();

        return $modules[$module] ?? null;
    }

    public static function allowedModules(): array
    {
        return array_keys(self::modules());
    }

    private static function basicDefinitions(): array
    {
        return [
            self::definition(Config::APP_SECRET, '开放平台 App Secret', 'basic', '', 'string', '请求定位接口使用的签名密钥。'),
            self::definition('debug_log', '全局调试日志', 'basic', '0', 'boolean', '开启后部分业务会写额外调试日志。'),
            self::definition('close_owner_api_log_except', '关闭 Owner API 日志排除规则', 'basic', '0', 'boolean', '开启后 owner API 日志不再跳过 app.log.except 中的路径。'),
            self::definition(Config::HYTERA_DMR_MOCK_LOCATOR_NON_ERROR_LOG, '海能达模拟日志开关', 'basic', '1', 'boolean', '控制海能达模拟定位进程的非错误日志。'),
        ];
    }

    private static function smsDefinitions(): array
    {
        return [
            self::definition('rh_sms_url', '融合通信接口地址', 'sms', '104.0.44.119:30020', 'string', '不包含最终发送路径，代码会追加 /api/v3.0/msg/send/direct。'),
            self::definition('rh_sms_account', '融合通信账号', 'sms', 'znwx', 'string', '融合通信 X-Auth 账号。'),
            self::definition('rh_authorization_code', '融合通信授权码', 'sms', 'pdEKIusgG9', 'string', '融合通信 X-Sign 授权码。'),
            self::definition('log_sms_template_code', '系统日志短信模板', 'sms', 'API-ZWX-00001', 'string', '系统异常日志短信通知模板。'),
            self::definition('system_alarm_contact', '系统报警联系人', 'sms', '', 'string', '多个手机号用英文逗号分隔。'),
            self::definition('area_template_code', '区域/拆除报警短信模板', 'sms', 'API-ZWX-00001', 'string', '区域报警和定位器拆除报警共用模板。'),
            self::definition('block_alarm_device_code', '遮挡报警短信模板', 'sms', 'SMS_460895020', 'string', '定位器遮挡报警短信模板。'),
        ];
    }

    private static function locationAlgorithmDefinitions(): array
    {
        return [
            self::definition('location_args', '定位算法参数', 'location_algorithm', self::defaultLocationArgs(), 'json', 'JSON 对象。缺失字段会由代码内置默认值补齐。'),
            self::definition('java_location_url', 'Java 定位接口', 'location_algorithm', 'https://location.mall-to.com/api/location/weight_trilateral', 'string', '普通三边/权重定位服务地址。'),
            self::definition('java_location_iq2pos2_url', 'Java IQ 定位接口', 'location_algorithm', 'https://api.mall-to.com/api/location/iq2pos2', 'string', 'AOA IQ 定位服务地址。'),
            self::definition('java_location_angle2pos_url', 'Java Angle 定位接口', 'location_algorithm', 'https://location.mall-to.com/api/location/angle2pos', 'string', 'AOA angle 定位服务地址。'),
        ];
    }

    private static function locationMaintenanceDefinitions(): array
    {
        return [
            self::definition('area_check_times', '区域连续判定次数', 'location_maintenance', '6', 'integer', '定位器连续处于同一区域达到该次数后写区域日志。'),
            self::definition('location.fence_area_alarm_max_count', '报警日志每主体最大保留条数', 'location_maintenance', '100000', 'integer', '电子围栏和区域报警日志每个主体最多保留条数。'),
            self::definition('location.fence_area_alarm_max_months', '报警日志最长保留月数', 'location_maintenance', '12', 'integer', '电子围栏和区域报警日志最长保留月数。'),
            self::definition('location.fence_area_alarm_delete_batch_size', '报警日志清理批大小', 'location_maintenance', '10000', 'integer', '定时清理报警日志时每批删除数量。'),
            self::definition('online_debug_log', '定位器在线检查调试日志', 'location_maintenance', '0', 'boolean', '定位器在线状态检查任务调试日志。'),
            self::definition('mock_locator_log', '模拟定位调试日志', 'location_maintenance', '0', 'boolean', '模拟定位自定义进程调试日志。'),
        ];
    }

    private static function locationDebugDefinitions(): array
    {
        return array_merge(self::solutionLogDefinitions(), [
            self::definition('hytera_debug', '海能达 DMR UDP 调试日志', 'location_debug', '0', 'boolean', '海能达 DMR UDP 数据收发调试日志。'),
            self::definition('bolian_badge_error_log', '博联工卡异常日志', 'location_debug', '0', 'boolean', '博联 4G 工卡 UDP 处理异常时输出原始数据。'),
            self::definition('bolian_badge_debug', '博联工卡调试日志', 'location_debug', '0', 'boolean', '博联 4G 工卡解析调试日志。'),
            self::definition('ylwl_mwc_debug', '云里物里 MWC03 调试日志', 'location_debug', '0', 'boolean', '云里物里 MWC03 解析调试日志。'),
            self::definition('lance_diy_data_log', '蓝测 DIY 数据日志', 'location_debug', '0', 'boolean', '蓝测 DIY MQTT 原始数据日志。'),
        ]);
    }

    private static function solutionLogDefinitions(): array
    {
        $definitions = [];
        foreach (self::locationSolutions() as $solution => $label) {
            $definitions[] = self::definition($solution . '_log_original_data', $label . ' 原始数据日志', 'location_debug', '0', 'boolean', '网关上报原始数据日志。');
            $definitions[] = self::definition($solution . '_log_specify_gateway', $label . ' 指定网关日志', 'location_debug', '', 'string', '填写网关 MAC 时只输出该网关日志；留空关闭。');
            $definitions[] = self::definition($solution . '_log_debug_data', $label . ' 调试数据日志', 'location_debug', '0', 'boolean', '网关解析过程调试日志。');
            $definitions[] = self::definition($solution . '_error_log', $label . ' 异常日志', 'location_debug', '0', 'boolean', 'Socket 投递或解析异常时输出原始数据。');
        }

        return $definitions;
    }

    private static function locationSolutions(): array
    {
        return [
            'skylab' => 'Skylab TCP 网关',
            'skylab_mqtt' => 'Skylab MQTT 网关',
            'skylab_watch' => 'Skylab 手表协议',
            'skylab_new' => 'Skylab 新协议',
            'skylab_new_gateway' => 'Skylab 新协议网关',
            'sky_lab_aoa' => 'Skylab AOA',
            'ylwl_aoa' => '云里物里 AOA',
            'mallto1' => '墨兔协议 1',
            'mallto3' => '墨兔协议 3',
            'ylwl_gateway' => '云里物里网关',
            'w_b_mapper' => '微信 Beacon',
            'luojie_wristband_new' => '罗捷腕带',
            'huawei_ap_ble' => '华为 AP BLE',
            'bolian_badge' => '博联 4G 工卡',
            'lance_aoa' => '蓝测 AOA',
            'lance_aoa_mqtt' => '蓝测 AOA MQTT',
            'bolian_lora' => '博联 LoRa',
            'hytera' => '海能达',
            'Mwc03Mapper' => '云里物里 MWC03',
            'lierda_lora' => '利尔达 LoRa',
            'ovi_b2315_lora' => 'Ovi B2315 LoRa',
            'ovi_watch' => 'Ovi Watch',
        ];
    }

    private static function keyByConfigKey(array $definitions): array
    {
        $keyed = [];

        foreach ($definitions as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $keyed[$key] = $definition;
        }

        return $keyed;
    }

    private static function definition(
        string $key,
        string $name,
        string $module,
        string $defaultValue,
        string $type,
        string $remark
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'module' => $module,
            'type' => $type,
            'default_value' => $defaultValue,
            'value' => $defaultValue,
            'options' => $type === 'boolean' ? '0,1' : null,
            'remark' => $remark,
            'sort' => self::sortFor($module),
            'ui' => $type === 'json' ? 'textarea' : 'input',
        ];
    }

    private static function sortFor(string $module): int
    {
        $orders = array_flip(self::allowedModules());

        return (($orders[$module] ?? 0) + 1) * 1000;
    }

    private static function defaultLocationArgs(): string
    {
        return json_encode([
            'speed_const' => 25,
            'rssi_history_smooth' => 0.7,
            'use_beacon_count' => 4,
            'current_position_smooth' => 0.5,
            'dist_threshold' => 1.5,
            'beacon_count' => 10,
            'beacon_cache_time' => 3,
            'locator_cache_time' => 60,
            'max_dist_threshold' => 8,
            'force_update_time' => 3,
            'smooth_switch' => 0,
            'kalmanfilter_coor_q' => 0.04,
            'kalmanfilter_coor_r' => 0.1,
            'kalmanfilter_rssi_q' => 0.04,
            'kalmanfilter_rssi_r' => 0.1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
