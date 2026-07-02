<?php

namespace App\Pipeline;

/**
 * Handler 2: Validate nội dung / file tin nhắn.
 */
class ContentValidationHandler
{
    // Max file sizes
    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    public function handle(array $context, callable $next)
    {
        $content = $context['content'] ?? null;
        $file    = $context['file']    ?? null;

        // Nếu không có cả content lẫn file → lỗi
        if (empty($content) && !$file) {
            throw new \Exception('Tin nhắn không được để trống', 400);
        }

        // Kiểm tra kích thước file
        if ($file && $file->getSize() > self::MAX_FILE_SIZE) {
            throw new \Exception('File vượt quá kích thước tối đa 50MB', 400);
        }

        return $next($context);
    }
}
