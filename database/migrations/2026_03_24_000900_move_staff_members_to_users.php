<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('initials', 6)->nullable()->after('name');
            $table->boolean('is_bookable')->default(false)->after('role');
        });

        $this->backfillExistingUsers();

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')
                ->nullable()
                ->after('service_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        $staffToUserMap = $this->migrateStaffMembersToUsers();
        $this->migrateBookingAssignments($staffToUserMap);

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_member_id');
        });

        if (Schema::hasTable('staff_members')) {
            Schema::drop('staff_members');
        }
    }

    public function down(): void
    {
        Schema::create('staff_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('color')->nullable();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('staff_member_id')
                ->nullable()
                ->after('service_id')
                ->constrained('staff_members')
                ->nullOnDelete();
        });

        $userToStaffMap = $this->recreateStaffMembersFromUsers();
        $this->restoreBookingAssignments($userToStaffMap);

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['initials', 'is_bookable']);
        });
    }

    /**
     * @return array<int, int>
     */
    private function migrateStaffMembersToUsers(): array
    {
        if (! Schema::hasTable('staff_members')) {
            return [];
        }

        $staffRows = DB::table('staff_members')
            ->select('id', 'name', 'email', 'created_at', 'updated_at')
            ->orderBy('id')
            ->get();

        $map = [];

        foreach ($staffRows as $staff) {
            $name = trim((string) $staff->name);
            $email = $this->normalizeEmail($staff->email);
            $initials = $this->makeInitials($name);
            $userId = null;

            if ($email !== null) {
                $userId = DB::table('users')
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->value('id');
            }

            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    'name' => $name !== '' ? $name : 'Medarbejder',
                    'initials' => $initials,
                    'email' => $email ?? $this->generateFallbackEmail($name, (int) $staff->id),
                    'role' => User::ROLE_STAFF,
                    'is_bookable' => true,
                    'password' => Hash::make(Str::random(40)),
                    'remember_token' => Str::random(10),
                    'created_at' => $staff->created_at ?? now(),
                    'updated_at' => $staff->updated_at ?? now(),
                ]);
            } else {
                $existing = DB::table('users')
                    ->select('initials', 'role')
                    ->where('id', (int) $userId)
                    ->first();

                DB::table('users')
                    ->where('id', (int) $userId)
                    ->update([
                        'role' => $existing?->role === User::ROLE_OWNER ? User::ROLE_OWNER : User::ROLE_STAFF,
                        'is_bookable' => true,
                        'initials' => blank($existing?->initials) ? $initials : $existing?->initials,
                        'updated_at' => now(),
                    ]);
            }

            $map[(int) $staff->id] = (int) $userId;
        }

        return $map;
    }

    /**
     * @param array<int, int> $staffToUserMap
     */
    private function migrateBookingAssignments(array $staffToUserMap): void
    {
        if ($staffToUserMap === []) {
            return;
        }

        foreach ($staffToUserMap as $staffId => $userId) {
            DB::table('bookings')
                ->where('staff_member_id', $staffId)
                ->update(['staff_user_id' => $userId]);
        }
    }

    private function backfillExistingUsers(): void
    {
        $users = DB::table('users')
            ->select('id', 'name', 'role', 'initials')
            ->get();

        foreach ($users as $user) {
            DB::table('users')
                ->where('id', (int) $user->id)
                ->update([
                    'is_bookable' => $user->role === User::ROLE_STAFF ? true : false,
                    'initials' => blank($user->initials)
                        ? $this->makeInitials((string) $user->name)
                        : strtoupper(trim((string) $user->initials)),
                ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function recreateStaffMembersFromUsers(): array
    {
        $users = DB::table('users')
            ->select('id', 'name', 'email', 'created_at', 'updated_at')
            ->where('is_bookable', true)
            ->orderBy('id')
            ->get();

        $map = [];

        foreach ($users as $user) {
            $staffId = DB::table('staff_members')->insertGetId([
                'name' => (string) $user->name,
                'email' => $this->normalizeEmail($user->email),
                'color' => null,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now(),
            ]);

            $map[(int) $user->id] = (int) $staffId;
        }

        return $map;
    }

    /**
     * @param array<int, int> $userToStaffMap
     */
    private function restoreBookingAssignments(array $userToStaffMap): void
    {
        if ($userToStaffMap === []) {
            return;
        }

        foreach ($userToStaffMap as $userId => $staffId) {
            DB::table('bookings')
                ->where('staff_user_id', $userId)
                ->update(['staff_member_id' => $staffId]);
        }
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $normalized = mb_strtolower(trim($email));

        return $normalized !== '' ? $normalized : null;
    }

    private function generateFallbackEmail(string $name, int $staffId): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'staff';
        $candidate = "{$base}.{$staffId}@local.invalid";
        $suffix = 1;

        while (DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($candidate)])->exists()) {
            $candidate = "{$base}.{$staffId}.{$suffix}@local.invalid";
            $suffix++;
        }

        return mb_strtolower($candidate);
    }

    private function makeInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'NA';
    }
};
