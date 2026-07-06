<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function ($user) {
            // Create a personal team for the user
            $team = \App\Models\Team::create([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);

            // Assign the team as the current team (without triggering update events loop)
            $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the current team of the user.
     */
    /**
     * Get the current team of the user.
     */
    public function currentTeam()
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get all teams the user belongs to.
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Get all teams owned by the user.
     */
    public function ownedTeams()
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Determine if the user belongs to the given team.
     *
     * @param  mixed  $team
     * @return bool
     */
    public function belongsToTeam($team)
    {
        return $this->teams->contains(function ($t) use ($team) {
            return $t->id === $team->id;
        }) || $this->ownsTeam($team);
    }

    /**
     * Determine if the user owns the given team.
     *
     * @param  mixed  $team
     * @return bool
     */
    public function ownsTeam($team)
    {
        return $this->id == $team->user_id;
    }

    /**
     * Rol del usuario en el team dado: 'owner' si es el dueño, el rol del pivot
     * `team_user` ('admin'|'member') si es miembro, o null si no pertenece.
     *
     * @param  mixed  $team
     */
    public function teamRole($team): ?string
    {
        if ($this->ownsTeam($team)) {
            return 'owner';
        }

        $membership = $this->teams->firstWhere('id', $team->id);

        return $membership?->pivot?->role;
    }

    /**
     * El usuario administra el team: es el dueño O tiene rol 'admin' en el pivot.
     * Los checks "solo owner" de la app (dashboard ejecutivo, empleados,
     * tolerancia, mutaciones de empresas/categorías) usan esta noción para que
     * un admin (ej. el CEO) vea/opere igual que el dueño. La gestión del team
     * en sí (invitar/quitar miembros, renombrar) sigue siendo solo del dueño.
     *
     * @param  mixed  $team
     */
    public function managesTeam($team): bool
    {
        return $this->ownsTeam($team) || $this->teamRole($team) === 'admin';
    }

    /**
     * Switch the user's context to the given team.
     *
     * @param  mixed  $team
     * @return bool
     */
    public function switchTeam($team)
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team->id,
        ])->save();

        return true;
    }

    /**
     * Get all of the teams the user belongs to or owns.
     *
     * @return \Illuminate\Support\Collection
     */
    public function allTeams()
    {
        return $this->ownedTeams->merge($this->teams)->sortBy('name');
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
