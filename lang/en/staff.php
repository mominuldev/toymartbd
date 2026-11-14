<?php

declare(strict_types=1);

return [
    'conflict_self' => 'You cannot change your own role, status or password here.',
    'conflict_last_owner' => 'This is the last active owner. Make someone else an owner first.',
    'conflict_two_factor_on' => 'Two-factor is already on. Turn it off first to set it up again.',
    'conflict_two_factor_not_started' => 'Start the two-factor setup first.',
    'conflict_two_factor_required' => 'Your role can see money, so two-factor must stay on.',
    'conflict_owner_role' => 'The owner role always has every permission and cannot be edited.',
    'two_factor_invalid' => 'That code is not valid. Check the time on your phone and try the newest code.',
    'two_factor_expired' => 'The sign-in has expired. Enter your email and password again.',
    'two_factor_required' => 'Your role can see money: set up two-factor sign-in before continuing.',
    'owner_only_permission' => 'Only the owner role can hold this permission.',
];
