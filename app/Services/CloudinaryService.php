<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CloudinaryService
{
    /**
     * Upload a file to Cloudinary (or local storage if not configured).
     *
     * @param UploadedFile $file
     * @param string $folder
     * @param string $resourceType (image, video, raw)
     * @return array
     */
    public function upload(UploadedFile $file, string $folder = 'chat', string $resourceType = 'auto'): array
    {
        $mime = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();

        // Detect kind
        $kind = 'file';
        if (str_starts_with($mime, 'image/')) {
            $kind = 'image';
        } elseif (str_starts_with($mime, 'video/')) {
            $kind = 'video';
        }

        // Fallback to auto-detecting resourceType if not specified
        if ($resourceType === 'auto') {
            $resourceType = ($kind === 'file') ? 'raw' : $kind;
        }

        // Check if Cloudinary is configured
        if (env('CLOUDINARY_URL')) {
            try {
                // Initialize Cloudinary SDK directly to avoid config caching issues in Laravel package
                $cloudinary = new \Cloudinary\Cloudinary(env('CLOUDINARY_URL'));
                
                // Upload to Cloudinary
                $uploadApi = $cloudinary->uploadApi();
                $uploadedFile = $uploadApi->upload($file->getRealPath(), [
                    'folder' => $folder,
                    'resource_type' => $resourceType,
                ]);

                return [
                    'url' => $uploadedFile['secure_url'] ?? $uploadedFile['url'],
                    'provider' => 'cloudinary',
                    'storageKey' => $uploadedFile['public_id'],
                    'folder' => $folder,
                    'resourceType' => $resourceType,
                    'originalName' => $originalName,
                    'mimeType' => $mime,
                    'size' => $size,
                    'attachmentKind' => $kind,
                ];
            } catch (\Exception $e) {
                // Log and fallback to local
                logger()->error('Cloudinary upload failed: ' . $e->getMessage());
            }
        }

        // Local upload fallback
        $path = $file->store("uploads/{$folder}", 'public');
        $url = Storage::url($path);

        return [
            'url' => $url,
            'provider' => 'local',
            'storageKey' => $path,
            'folder' => $folder,
            'resourceType' => $resourceType,
            'originalName' => $originalName,
            'mimeType' => $mime,
            'size' => $size,
            'attachmentKind' => $kind,
        ];
    }

    /**
     * Delete a file from its provider.
     *
     * @param string $storageKey
     * @param string $provider (cloudinary or local)
     * @param string $resourceType
     * @return bool
     */
    public function delete(string $storageKey, string $provider = 'local', string $resourceType = 'image'): bool
    {
        if (empty($storageKey)) {
            return false;
        }

        if ($provider === 'cloudinary') {
            if (env('CLOUDINARY_URL')) {
                try {
                    $cloudinary = new \Cloudinary\Cloudinary(env('CLOUDINARY_URL'));
                    $uploadApi = $cloudinary->uploadApi();
                    $uploadApi->destroy($storageKey, [
                        'resource_type' => $resourceType,
                    ]);
                    return true;
                } catch (\Exception $e) {
                    logger()->error('Cloudinary deletion failed: ' . $e->getMessage());
                }
            }
            return false;
        }

        // Local deletion
        if (Storage::disk('public')->exists($storageKey)) {
            return Storage::disk('public')->delete($storageKey);
        }

        return false;
    }

    /**
     * Alias of upload() - returns ['url', 'public_id'] for backward compat.
     */
    public function uploadFile(UploadedFile $file, string $folder = 'attachments'): array
    {
        $result = $this->upload($file, $folder);
        return [
            'url'       => $result['url'],
            'public_id' => $result['storageKey'],
            'provider'  => $result['provider'],
        ];
    }
}
