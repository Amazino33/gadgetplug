<?php

namespace App\Models\Assistant;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread, owned by one user inside one vendor. Ownership is checked
 * on both columns every time a conversation is reopened, so a user who is a
 * member of two vendors can never continue one vendor's thread in the other.
 */
class AssistantConversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }

    public function isOwnedBy(User $user, Vendor $vendor): bool
    {
        return (int) $this->user_id === (int) $user->id
            && (int) $this->vendor_id === (int) $vendor->id;
    }
}
