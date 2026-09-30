<?php

namespace Workbench\App\Notifications;

use Illuminate\Notifications\Notification;

class SilentChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        //
    }
}
