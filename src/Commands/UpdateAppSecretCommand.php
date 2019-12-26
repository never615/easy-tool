<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\App\AppSecretUsecase;

/**
 * 更新应用的秘钥
 * Class UpdateAppSecret
 *
 * @package Mallto\Tool\Commands
 */
class UpdateAppSecretCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tool:update_app_secret';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新app的秘钥';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * @var AppSecretUsecase
     */
    private $appSecretUsecase;


    /**
     * UpdateAppSecret constructor.
     *
     * @param AppSecretUsecase $appSecretUsecase
     */
    public function __construct(AppSecretUsecase $appSecretUsecase)
    {
        parent::__construct();

        $this->appSecretUsecase = $appSecretUsecase;
    }


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->appSecretUsecase->update();
    }

}
