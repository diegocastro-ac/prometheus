<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

class ProfileService
{
    /**
     * Update personal data for a user.
     */
    public function updatePersonalData(int $userId, array $data): void
    {
        $user = User::find($userId);

        if (!$user) {
            return;
        }

        $user->name = $data['name'];
        $user->document_type = $data['document_type'];
        $user->document = $data['document'];
        $user->phone_number = $data['phone_number'];
        $user->save();
    }

    /**
     * Change password for a user.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = User::find($userId);

        if (!$user) {
            return false;
        }

        if (!Hash::check($currentPassword, $user->password)) {
            Notification::make()
                ->danger()
                ->title(__('filament-panels::pages/auth/login.messages.failed'))
                ->body(__('profile.notifications.wrong_password'))
                ->send();
            return false;
        }

        if (empty($newPassword)) {
            Notification::make()
                ->danger()
                ->title(__('filament-panels::pages/auth/login.messages.failed'))
                ->body(__('profile.notifications.new_password_required'))
                ->send();
            return false;
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        return true;
    }

    /**
     * Apply email change for a user.
     */
    public function applyEmailChange(int $userId, string $email): void
    {
        $user = User::find($userId);

        if (!$user) {
            return;
        }

        $user->email = $email;
        $user->email_verified_at = null;
        $user->save();
    }

    /**
     * Update complete profile including password and email if needed.
     */
    public function updateProfile(int $userId, array $data, bool $emailChanged): array
    {
        $user = User::find($userId);

        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $passwordChanged = false;

        if (!empty($data['current_password'])) {
            $passwordChanged = $this->changePassword($userId, $data['current_password'], $data['new_password']);
            if (!$passwordChanged) {
                return ['success' => false, 'message' => 'Password change failed'];
            }
        }

        $this->updatePersonalData($userId, $data);

        if ($emailChanged) {
            $this->applyEmailChange($userId, $data['email']);
        }

        $message = __('profile.notifications.profile_updated');
        if ($emailChanged) {
            $message .= ' ' . __('profile.notifications.email_changed');
        }
        if ($passwordChanged) {
            $message .= ' ' . __('profile.notifications.password_changed');
        }

        return ['success' => true, 'message' => $message];
    }
}