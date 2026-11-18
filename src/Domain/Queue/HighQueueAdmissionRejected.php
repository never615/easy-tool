<?php

namespace Mallto\Tool\Domain\Queue;

/** 队列未接受任务时，人工重试必须保留其失败记录。 */
final class HighQueueAdmissionRejected extends QueueAdmissionRejected
{
}
