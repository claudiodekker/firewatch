<?php

namespace Workbench\App\Notifications;

use Illuminate\Notifications\Notification;

class OrderDelayed extends Notification
{
    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SilentChannel::class];
    }
}
