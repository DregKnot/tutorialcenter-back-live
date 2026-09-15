<?php

namespace App\Providers;

use App\Models\Payment;
use App\Observers\PaymentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped('student.session_announcements', fn () => new \ArrayObject());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);
        \App\Models\ClassSession::observe(\App\Observers\ClassSessionObserver::class);
        \App\Models\CoursesEnrollment::observe(\App\Observers\CoursesEnrollmentObserver::class);
    }
}
