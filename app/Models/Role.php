<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Support\CapabilityRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Role extends Model {
    use BelongsToWorkspace;

    // workspace_id and is_admin are fillable so a brand-new workspace can be
    // given its roles before anyone is inside it. Controllers only pass
    // validated keys, and never is_admin.
    protected $fillable = ['workspace_id', 'name', 'label', 'is_admin'];

    protected function casts(): array {
        return ['is_admin' => 'boolean'];
    }

    public function members(): HasMany {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return list<string> */
    public function capabilityNames(): array {
        // Read from the registry, not stored: a capability added later
        // reaches every workspace's Admin without touching the data.
        if ($this->is_admin) {
            return CapabilityRegistry::names();
        }

        return DB::table('role_capabilities')->where('role_id', $this->id)->orderBy('capability')->pluck('capability')->all();
    }

    /** Replace this role's capabilities. Every name must be in CapabilityRegistry. */
    public function syncCapabilities(array $names): void {
        $unknown = array_values(array_filter($names, fn (string $name) => ! CapabilityRegistry::exists($name)));

        if ($unknown) {
            throw new InvalidArgumentException('Unknown capabilities: ' . implode(', ', $unknown));
        }

        DB::transaction(function () use ($names) {
            DB::table('role_capabilities')->where('role_id', $this->id)->delete();
            DB::table('role_capabilities')->insert(
                array_map(fn (string $name) => ['role_id' => $this->id, 'capability' => $name], array_values(array_unique($names))),
            );
        });
    }
}
