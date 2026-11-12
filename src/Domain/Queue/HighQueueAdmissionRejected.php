<?php

namespace Mallto\Tool\Domain\Queue;

/** Manual retry must retain its failed record when the queue did not accept it. */
final class HighQueueAdmissionRejected extends \RuntimeException
{
}
