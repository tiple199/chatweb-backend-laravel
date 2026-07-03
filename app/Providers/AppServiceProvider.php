<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \App\Repositories\UserRepositoryInterface::class,
            \App\Repositories\UserRepository::class
        );
        $this->app->singleton(
            \App\Repositories\ConversationRepositoryInterface::class,
            \App\Repositories\ConversationRepository::class
        );
        $this->app->singleton(
            \App\Repositories\MessageRepositoryInterface::class,
            \App\Repositories\MessageRepository::class
        );
        $this->app->singleton(
            \App\Repositories\FriendRepositoryInterface::class,
            \App\Repositories\FriendRepository::class
        );
        $this->app->singleton(
            \App\Repositories\NoteRepositoryInterface::class,
            \App\Repositories\NoteRepository::class
        );
        $this->app->singleton(
            \App\Repositories\PollRepositoryInterface::class,
            \App\Repositories\PollRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Pipeline handlers
        $this->app->singleton(\App\Pipeline\ConversationPermissionHandler::class);
        $this->app->singleton(\App\Pipeline\ContentValidationHandler::class);
        $this->app->singleton(\App\Pipeline\MessagePipeline::class, function ($app) {
            return new \App\Pipeline\MessagePipeline(
                $app->make(\App\Pipeline\ConversationPermissionHandler::class),
                $app->make(\App\Pipeline\ContentValidationHandler::class)
            );
        });
    }
}
