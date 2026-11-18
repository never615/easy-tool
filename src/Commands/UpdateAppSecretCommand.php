<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\App\AppSecretUsecase;

/**
 * 更新应用的秘钥
 * UpdateAppSecret 类
 *
 * @package Mallto\Tool\Commands
 */
class UpdateAppSecretCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $name = 'tool:update_app_secret';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = '更新app的秘钥';

    /**
     * 安装目录。
     *
     * @var string
     */
    protected $directory = '';

    /**
     * @var AppSecretUsecase
     */
    private $appSecretUsecase;


    /**
     * UpdateAppSecret 构造函数。
     *
     * @param AppSecretUsecase $appSecretUsecase
     */
    public function __construct(AppSecretUsecase $appSecretUsecase)
    {
        parent::__construct();

        $this->appSecretUsecase = $appSecretUsecase;
    }


    /**
     * 执行控制台命令。
     *
     * @return void
     */
    public function handle()
    {
        $this->appSecretUsecase->update();
    }

}
