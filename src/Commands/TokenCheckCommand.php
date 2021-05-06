<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Laravel\Passport\Token;

/**
 *
 * Class TokenCheckCommand
 *
 * @package Mallto\Tool\Commands
 */
class TokenCheckCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'tool:token_check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '删除过期token';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->info("start");

        Token::query()
            ->where('expires_at', '<', Carbon::now())
            ->delete();

        $this->info("finish");

    }

}
