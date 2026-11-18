<?php

namespace Mallto\Tool\Domain\Queue;

/** 准入失败：调用方不得把未入队的任务视为已投递。 */
class QueueAdmissionRejected extends \RuntimeException
{
}
