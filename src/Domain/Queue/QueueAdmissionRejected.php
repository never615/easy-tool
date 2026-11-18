<?php

namespace Mallto\Tool\Domain\Queue;

/** Admission failed: callers must not treat an unqueued job as delivered. */
class QueueAdmissionRejected extends \RuntimeException
{
}
