<?php

namespace App\Services;

use App\Repositories\UserRepositoryInterface;
use App\Services\CloudinaryService;

class UserService
{
    protected $userRepository;
    protected $cloudinaryService;

    public function __construct(UserRepositoryInterface $userRepository, CloudinaryService $cloudinaryService)
    {
        $this->userRepository = $userRepository;
        $this->cloudinaryService = $cloudinaryService;
    }

    public function getProfile($userId)
    {
        return $this->userRepository->findById($userId);
    }

    public function searchUsers($keyword, $excludeUserId)
    {
        return $this->userRepository->search($keyword, $excludeUserId);
    }

    public function updateAvatar($user, $file)
    {
        // Upload to Cloudinary
        $folder = 'avatars';
        $publicId = 'avatar-' . $user->id . '-' . time();
        
        $result = $this->cloudinaryService->upload($file, $folder, 'image');
        $uploadedFileUrl = $result['url'] ?? null;
        
        if (!$uploadedFileUrl) {
            throw new \Exception("Upload failed");
        }

        $user = $this->userRepository->update($user->id, [
            'avatar' => $uploadedFileUrl
        ]);

        return $user;
    }
}
