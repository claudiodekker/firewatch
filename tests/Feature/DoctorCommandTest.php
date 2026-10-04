<?php

it('says it is not implemented and fails, so it never reads as a passed check', function () {
    $this->artisan('firewatch:doctor')
        ->expectsOutputToContain(__('firewatch::messages.doctor_not_implemented'))
        ->assertFailed();
});
