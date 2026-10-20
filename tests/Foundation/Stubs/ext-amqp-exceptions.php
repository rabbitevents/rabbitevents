<?php

// Exception classes of ext-amqp, for tests running without the extension.
if (!class_exists(AMQPException::class)) {
    class AMQPException extends Exception
    {
    }

    class AMQPQueueException extends AMQPException
    {
    }
}
