<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'message',
        'target_group',
        'target_roles',
        'target_path',
        'action_text',
        'action_url',
        'is_dismissible',
        'requires_action',
        'is_active',
        'guest_views',
        'guest_clicks',
        'expires_at',
    ];

    protected $casts = [
        'target_roles' => 'array',
        'is_dismissible' => 'boolean',
        'requires_action' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'system_announcement_user')
            ->withPivot('read_at', 'dismissed_at', 'action_taken_at')
            ->withTimestamps();
    }
}
