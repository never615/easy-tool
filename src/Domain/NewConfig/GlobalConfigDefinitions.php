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
                'description' => '报警日志清理 / 模拟定位',
                'route' => 'configs.location_maintenance',
                'save_route' => 'configs.location_maintenance.save',
            ],
            'beacon_area' => [
                'title' => 'BeaconArea配置',
                'description' => '历史 BeaconArea 区域判断 / 报警 / 清理配置',
                'route' => 'configs.beacon_area',
                'save_route' => 'configs.beacon_area.save',
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
            self::beaconAreaDefinitions(),
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
            self::definition('location.fence_area_alarm_max_count', '报警日志每主体最大保留条数', 'location_maintenance', '100000', 'integer', '电子围栏和区域报警日志每个主体最多保留条数。'),
            self::definition('location.fence_area_alarm_max_months', '报警日志最长保留月数', 'location_maintenance', '12', 'integer', '电子围栏和区域报警日志最长保留月数。'),
            self::definition('location.fence_area_alarm_delete_batch_size', '报警日志清理批大小', 'location_maintenance', '10000', 'integer', '定时清理报警日志时每批删除数量。'),
            self::definition('online_debug_log', '定位器在线检查调试日志', 'location_maintenance', '0', 'boolean', '定位器在线状态检查任务调试日志。'),
            self::definition('mock_locator_log', '模拟定位调试日志', 'location_maintenance', '0', 'boolean', '模拟定位自定义进程调试日志。'),
        ];
    }

    private static function beaconAreaDefinitions(): array
    {
        return [
            self::definition('area_check_times', 'BeaconArea 连续判定次数', 'beacon_area', '6', 'integer', '定位器连续处于同一 BeaconArea 区域达到该次数后写区域日志。'),
            self::definition('area_template_code', 'BeaconArea 报警短信模板', 'beacon_area', 'API-ZWX-00001', 'string', 'BeaconArea 区域报警短信模板；历史上也被定位器拆除报警复用。'),
            self::definition('location.beacon_area_check_interval', 'BeaconArea 扫描间隔毫秒', 'beacon_area', '10000', 'integer', 'LaravelS 定时任务 LocatorBeaconAreaCron 的执行间隔。', 'BEACON_AREA_CHECK_INTERVAL'),
            self::definition('location_delete_beacon_area_log', 'BeaconArea 日志默认保留天数', 'beacon_area', '365', 'integer', 'location:delete_beacon_area_log 的全局默认保留天数；主体 extra_config 中同名配置优先。'),
        ];
    }

    private static function locationDebugDefinitions(): array
    {
        return array_merge(self::solutionLogDefinitions(), [
            self::definition('hytera_debug', '海能达 DMR UDP 调试日志', 'location_debug', '0', 'boolean', '海能达 DMR UDP 数据收发调试日志。', null, self::familyMeta('hytera')),
            self::definition('bolian_badge_error_log', '博联工卡异常日志', 'location_debug', '0', 'boolean', '博联 4G 工卡 UDP 处理异常时输出原始数据。', null, self::familyMeta('bolian')),
            self::definition('bolian_badge_debug', '博联工卡调试日志', 'location_debug', '0', 'boolean', '博联 4G 工卡解析调试日志。', null, self::familyMeta('bolian')),
            self::definition('ylwl_mwc_debug', '云里物里 MWC03 调试日志', 'location_debug', '0', 'boolean', '云里物里 MWC03 解析调试日志。', null, self::familyMeta('ylwl')),
            self::definition('lance_diy_data_log', '蓝测 DIY 数据日志', 'location_debug', '0', 'boolean', '蓝测 DIY MQTT 原始数据日志。', null, self::familyMeta('lance')),
        ]);
    }

    private static function solutionLogDefinitions(): array
    {
        $definitions = [];
        foreach (self::locationSolutions() as $solution => $meta) {
            $label = $meta['label'];
            $familyMeta = self::familyMeta($meta['family_key']);

            $definitions[] = self::definition($solution . '_log_original_data', $label . ' 原始数据日志', 'location_debug', '0', 'boolean', self::logOriginalDataRemark($label), null, $familyMeta);
            $definitions[] = self::definition($solution . '_log_specify_gateway', $label . ' 指定网关日志', 'location_debug', '', 'string', self::logSpecifyGatewayRemark($label), null, array_merge($familyMeta, [
                'placeholder' => '填写解析后的网关蓝牙 MAC，例如 Skylab td_mac',
            ]));
            $definitions[] = self::definition($solution . '_log_debug_data', $label . ' 调试数据日志', 'location_debug', '0', 'boolean', self::logDebugDataRemark($label), null, $familyMeta);
            $definitions[] = self::definition($solution . '_error_log', $label . ' 异常日志', 'location_debug', '0', 'boolean', self::errorLogRemark($label), null, $familyMeta);
        }

        return $definitions;
    }

    private static function logOriginalDataRemark(string $label): string
    {
        return $label . ' 开关型配置。开启后打印该定位方案所有网关上报的原始数据；二进制上报会转成 hex 后写日志。';
    }

    private static function logSpecifyGatewayRemark(string $label): string
    {
        return $label . ' 字符串配置。value 填解析后的网关蓝牙 MAC（代码中 BaseGatewayParse::gatewayMapper 返回的 gatewayBluetoothMac，例如 Skylab 协议的 td_mac）；系统会精确匹配该值，命中后只打印该网关的原始上报数据和设备数量；留空关闭。';
    }

    private static function logDebugDataRemark(string $label): string
    {
        return $label . ' 开关型配置。开启后打印网关解析过程调试摘要，例如 gateway mac、devices count 等信息；不是完整原始上报数据。';
    }

    private static function errorLogRemark(string $label): string
    {
        return $label . ' 开关型配置。开启后在 Socket 投递或协议解析异常时输出原始数据和错误上下文，便于排查异常包。';
    }

    private static function locationSolutions(): array
    {
        return [
            'skylab' => self::solutionMeta('Skylab TCP 网关', 'skylab'),
            'skylab_mqtt' => self::solutionMeta('Skylab MQTT 网关', 'skylab'),
            'skylab_watch' => self::solutionMeta('Skylab 手表协议', 'skylab'),
            'skylab_new' => self::solutionMeta('Skylab 新协议', 'skylab'),
            'skylab_new_gateway' => self::solutionMeta('Skylab 新协议网关', 'skylab'),
            'sky_lab_aoa' => self::solutionMeta('Skylab AOA', 'skylab'),
            'ylwl_aoa' => self::solutionMeta('云里物里 AOA', 'ylwl'),
            'mallto1' => self::solutionMeta('墨兔协议 1', 'mallto'),
            'mallto3' => self::solutionMeta('墨兔协议 3', 'mallto'),
            'ylwl_gateway' => self::solutionMeta('云里物里网关', 'ylwl'),
            'w_b_mapper' => self::solutionMeta('微信 Beacon', 'wechat_beacon'),
            'luojie_wristband_new' => self::solutionMeta('罗捷腕带', 'luojie'),
            'huawei_ap_ble' => self::solutionMeta('华为 AP BLE', 'huawei'),
            'bolian_badge' => self::solutionMeta('博联 4G 工卡', 'bolian'),
            'lance_aoa' => self::solutionMeta('蓝测 AOA', 'lance'),
            'lance_aoa_mqtt' => self::solutionMeta('蓝测 AOA MQTT', 'lance'),
            'bolian_lora' => self::solutionMeta('博联 LoRa', 'bolian'),
            'hytera' => self::solutionMeta('海能达', 'hytera'),
            'Mwc03Mapper' => self::solutionMeta('云里物里 MWC03', 'ylwl'),
            'lierda_lora' => self::solutionMeta('利尔达 LoRa', 'lierda'),
            'ovi_b2315_lora' => self::solutionMeta('Ovi B2315 LoRa', 'ovi'),
            'ovi_watch' => self::solutionMeta('Ovi Watch', 'ovi'),
        ];
    }

    private static function solutionMeta(string $label, string $familyKey): array
    {
        return [
            'label' => $label,
            'family_key' => $familyKey,
        ];
    }

    private static function familyMeta(string $familyKey): array
    {
        $labels = [
            'skylab' => 'Skylab',
            'ylwl' => '云里物里',
            'mallto' => '墨兔',
            'wechat_beacon' => '微信 Beacon',
            'luojie' => '罗捷',
            'huawei' => '华为 AP',
            'bolian' => '博联',
            'lance' => '蓝测',
            'hytera' => '海能达',
            'lierda' => '利尔达',
            'ovi' => 'Ovi',
            'other' => '其他',
        ];

        return [
            'family_key' => $familyKey,
            'family_label' => $labels[$familyKey] ?? $labels['other'],
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
        string $remark,
        ?string $envKey = null,
        array $meta = []
    ): array {
        return array_merge([
            'key' => $key,
            'env_key' => $envKey,
            'name' => $name,
            'module' => $module,
            'type' => $type,
            'default_value' => $defaultValue,
            'value' => $defaultValue,
            'options' => $type === 'boolean' ? '0,1' : null,
            'remark' => $remark,
            'sort' => self::sortFor($module),
            'ui' => $type === 'json' ? 'textarea' : 'input',
        ], $meta);
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
