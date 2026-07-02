<?php

namespace App\Pipeline;

/**
 * MessagePipeline – Chain of Responsibility cho việc gửi tin nhắn.
 *
 * Các handlers chạy theo thứ tự:
 * 1. ConversationPermissionHandler – kiểm tra user có trong conversation
 * 2. ContentValidationHandler      – kiểm tra nội dung/file hợp lệ
 */
class MessagePipeline
{
    protected array $handlers;

    public function __construct(
        ConversationPermissionHandler $permissionHandler,
        ContentValidationHandler $contentHandler
    ) {
        $this->handlers = [$permissionHandler, $contentHandler];
    }

    public function process(array $context): void
    {
        $chain = array_reduce(
            array_reverse($this->handlers),
            fn($carry, $handler) => fn($ctx) => $handler->handle($ctx, $carry),
            fn($ctx) => $ctx   // Final no-op
        );

        $chain($context);
    }
}
