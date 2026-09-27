<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's membership of one workspace. */
class WorkspaceMember extends Model {
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'user_id', 'role_id', 'is_active'];

    protected function casts(): array {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo {
        return $this->belongsTo(Role::class);
    }
}
