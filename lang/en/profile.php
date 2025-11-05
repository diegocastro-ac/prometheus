<?php

return [
    'title' => 'My Profile',

    'sections' => [
        'personal_info' => 'Personal Information',
        'change_password' => 'Change Password',
        'email_verification' => 'Email Verification',
    ],

    'form' => [
        'name' => 'Full name',
        'email' => 'Email address',
        'document_type' => 'Document type',
        'document' => 'Document number',
        'phone_number' => 'Phone number',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'new_password_confirmation' => 'Confirm new password',
    ],

    'document_types' => [
        'CC' => 'Citizenship Card',
        'TI' => 'Identity Card',
        'CE' => 'Foreign ID',
        'PAS' => 'Passport',
        'NIT' => 'Tax ID',
    ],

    'descriptions' => [
        'password_hint' => 'Leave these fields blank if you do not want to change your password.',
    ],

    'buttons' => [
        'save' => 'Save changes',
        'send_verification' => 'Send verification email',
        'confirm' => 'Yes, change email',
        'cancel' => 'Cancel',
    ],

    'notifications' => [
        'profile_updated' => 'Your information has been updated successfully.',
        'email_changed' => 'Your new email address must be verified.',
        'password_changed' => 'Your password has been changed successfully.',
        'wrong_password' => 'The current password is incorrect.',
        'new_password_required' => 'You must enter a new password.',
        'email_verified' => 'Your email address is verified.',
        'email_not_verified' => 'Your email address is not verified.',
        'already_verified' => 'Your email address is already verified.',
        'verification_sent' => 'A verification link has been sent to your email address.',
    ],

    'modal' => [
        'confirm_email_change' => 'Confirm email change',
        'confirm_email_description' => 'By changing your email address, you will need to verify it again before you can use it. Do you want to continue?',
    ],
];
