<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MockInterviewer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'designation',
        'company',
        'years_of_experience',
        'skills',
        'bio',
        'internal_notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'skills' => 'array',
        'years_of_experience' => 'float',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(MockInterviewSlot::class, 'interviewer_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(MockInterview::class, 'interviewer_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(MockInterviewEvaluation::class, 'interviewer_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        $term = '%' . trim($term) . '%';
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('company', 'like', $term)
                ->orWhere('designation', 'like', $term);
        });
    }

    /**
     * Safe representation for student view (excludes private phone / internal notes).
     */
    public function toStudentArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'designation' => $this->designation,
            'company' => $this->company,
            'years_of_experience' => $this->years_of_experience,
            'skills' => $this->skills ?? [],
            'bio' => $this->bio,
            'is_active' => $this->is_active,
        ];
    }
}
